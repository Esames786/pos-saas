<?php

namespace App\Services\Edge;

use App\Models\Master\EdgeDevice;
use App\Models\Tenant\Branch;
use App\Models\Tenant\EdgeInboundSupplierFinanceIngestion;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\SupplierPayment;
use App\Models\Tenant\User;
use App\Services\Finance\ManualJournalService;
use App\Services\Finance\SupplierPayableService;
use App\Support\Edge\EdgeIdentity;
use App\Support\EdgeRuntime;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * OFFLINE EDGE — F2: Cloud ingestion of Edge supplier-finance events, exactly once, finance-complete-or-refuse.
 *
 * A thin authenticated shell around the EXISTING canonical authorities — nothing financial is re-implemented here:
 *   SUPPLIER_PAYMENT               → SupplierPayableService::recordPayment (payment row + supplier subledger + optional
 *                                    bill allocation + cash/bank movement + Dr 2100 / Cr cash-bank GL, ONE transaction,
 *                                    null GL = failure, overpayment fails closed — no supplier advance account exists)
 *   SUPPLIER_AP_JOURNAL_ADJUSTMENT → ManualJournalService::post (the Online General Journal: JournalService::post +
 *                                    cash/bank movements + subledger mirror of every AP line naming its supplier)
 *
 * Exactly once: registry row per event_uuid (same uuid + same hash → already_applied; different hash → conflict, no
 * mutation). The official posting and the registry claim share one transaction; EdgeFinancePostingVerifier then
 * proves subledger + AP control + cash/bank + balanced GL are all present, or the whole transaction rolls back and
 * the event is never APPLIED. Cloud permissions are authoritative: the actor must hold the Online permission.
 */
class EdgeInboundSupplierFinanceIngestionService
{
    public function __construct(
        private readonly EdgeBootstrapService $canonical,
        private readonly EdgeActivationEpochService $epochs,
        private readonly SupplierPayableService $supplierPayable,
        private readonly ManualJournalService $manualJournals,
        private readonly EdgeFinancePostingVerifier $financeVerifier,
    ) {
    }

    public function ingest(array $envelope): array
    {
        if (EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('EdgeInboundSupplierFinanceIngestionService is Cloud-only; it must never run on a Branch Server.');
        }
        $eventUuid = (string) ($envelope['event_uuid'] ?? '');
        $contentHash = (string) ($envelope['content_hash'] ?? '');
        if (! EdgeIdentity::isValid($eventUuid, EdgeIdentity::FORMAT_ULID)) {
            return $this->refuse($envelope, 'EVENT_UUID_INVALID', 'the envelope has no valid canonical event_uuid');
        }
        $schema = (string) ($envelope['envelope_schema_version'] ?? '');
        if (! EdgeSupplierFinanceEnvelopeBuilder::isFinanceSchema($schema)) {
            return $this->refuse($envelope, 'SCHEMA_UNSUPPORTED', 'unsupported supplier-finance envelope schema version');
        }
        if (! $this->hashMatches($envelope, $contentHash)) {
            return $this->refuse($envelope, 'HASH_INVALID', 'the content hash is not self-consistent');
        }

        $existing = EdgeInboundSupplierFinanceIngestion::query()->where('event_uuid', $eventUuid)->first();
        if ($existing !== null) {
            if ($existing->isApplied()) {
                if (! hash_equals((string) $existing->content_hash, $contentHash)) {
                    return $this->conflictAck($existing, $contentHash);
                }

                return array_merge($existing->ack_payload ?? [], ['status' => 'already_applied']);
            }
            if ($existing->status === EdgeInboundSupplierFinanceIngestion::STATUS_CONFLICT) {
                return $existing->ack_payload;
            }
            if (! hash_equals((string) $existing->content_hash, $contentHash)) {
                return $this->conflictAck($existing, $contentHash);
            }
        }

        try {
            [$device, $branch] = $this->validateAuthority($envelope, $schema);
        } catch (IngestionRefusal $e) {
            return $this->refuse($envelope, $e->refusalCode, $e->getMessage());
        }

        try {
            return EdgeIngestionAuthority::run((int) $branch->id, function () use ($envelope, $device, $branch, $eventUuid, $contentHash, $schema) {
                return DB::connection('tenant')->transaction(function () use ($envelope, $device, $branch, $eventUuid, $contentHash, $schema) {
                    $ingestionUuid = (string) Str::ulid();
                    $registry = $this->claimRegistry($envelope, $device, $branch, $contentHash, $ingestionUuid);

                    if ($schema === EdgeSupplierFinanceEnvelopeBuilder::SCHEMA_PAYMENT) {
                        $ack = $this->applyPayment($envelope, $branch, $registry, $eventUuid, $contentHash, $ingestionUuid);
                    } else {
                        $ack = $this->applyApJournal($envelope, $branch, $registry, $eventUuid, $contentHash, $ingestionUuid);
                    }
                    Log::info('[edge-supplier-finance-ingest] applied', ['event_uuid' => $eventUuid, 'event_type' => $envelope['event_type'] ?? null, 'official' => $ack['official_reference_no'] ?? null]);

                    return $ack;
                });
            });
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062 && str_contains((string) $e->getMessage(), 'event_uuid')) {
                $winner = EdgeInboundSupplierFinanceIngestion::query()->where('event_uuid', $eventUuid)->first();
                if ($winner && $winner->isApplied()) {
                    return hash_equals((string) $winner->content_hash, $contentHash) ? $winner->ack_payload : $this->conflictAck($winner, $contentHash);
                }
            }

            return $this->recordException($envelope, $contentHash, 'DB_ERROR', $e->getMessage());
        } catch (IngestionRefusal $e) {
            // A business refusal from inside the transaction (the canonical authority said no, an identity did not
            // resolve, the finance verifier found the posting incomplete): everything rolled back. Answer REFUSED (422)
            // so the appliance classifies by code — terminal verdicts become PERMANENT_SYNC_FAILURE for a supervisor,
            // FINANCE_* verifier refusals stay retryable (the next attempt may complete).
            return $this->refuse($envelope, $e->refusalCode, $e->getMessage());
        } catch (Throwable $e) {
            return $this->recordException($envelope, $contentHash, 'INGEST_FAILED', $e->getMessage());
        }
    }

    // ── SUPPLIER_PAYMENT ─────────────────────────────────────────────────────────────────────────────────────────

    private function applyPayment(array $envelope, Branch $branch, EdgeInboundSupplierFinanceIngestion $registry, string $eventUuid, string $contentHash, string $ingestionUuid): array
    {
        $conn = DB::connection('tenant');
        $actor = $this->resolveActor($envelope, EdgeSupplierFinanceProjectionService::PERMISSIONS['supplier_payment']);

        $supplierId = (int) ($envelope['supplier']['cloud_supplier_id'] ?? 0);
        $supplier = $conn->table('suppliers')->where('id', $supplierId)->whereNull('deleted_at')->first();
        if (! $supplier) {
            throw new IngestionRefusal('SUPPLIER_UNKNOWN', 'the supplier does not exist in this tenant');
        }
        if ((string) $supplier->status !== 'active') {
            throw new IngestionRefusal('SUPPLIER_INACTIVE', 'the supplier is inactive');
        }

        $cbId = (int) ($envelope['cash_bank_account']['cloud_cash_bank_account_id'] ?? 0);
        $cb = $cbId > 0 ? $conn->table('cash_bank_accounts')->where('id', $cbId)->first() : null;
        if (! $cb) {
            throw new IngestionRefusal('CASH_BANK_REQUIRED', 'a supplier payment requires the Cash/Bank account it is paid from');
        }
        if (! (bool) $cb->is_active) {
            throw new IngestionRefusal('CASH_BANK_INACTIVE', 'the Cash/Bank account is inactive');
        }
        if ($cb->account_id === null) {
            throw new IngestionRefusal('CASH_BANK_UNMAPPED', 'the Cash/Bank account is not mapped to a chart-of-accounts account, so no GL entry could be posted');
        }

        $billId = isset($envelope['purchase_bill']['cloud_bill_id']) ? (int) $envelope['purchase_bill']['cloud_bill_id'] : 0;
        $bill = null;
        if ($billId > 0) {
            $bill = $conn->table('purchase_bills')->where('id', $billId)->first();
            if (! $bill) {
                throw new IngestionRefusal('BILL_UNKNOWN', 'the Purchase Bill does not exist');
            }
            if ((int) $bill->supplier_id !== $supplierId) {
                throw new IngestionRefusal('BILL_MISMATCH', 'the Purchase Bill belongs to another supplier');
            }
            if (! in_array((string) $bill->status, ['posted', 'partial'], true)) {
                throw new IngestionRefusal('BILL_MISMATCH', 'the Purchase Bill is not open (status ' . $bill->status . ')');
            }
        }

        $amount = round((float) ($envelope['amount'] ?? 0), 2);
        $method = (string) ($envelope['payment_method'] ?? '');
        if ($amount < 0.01 || ! in_array($method, EdgeSupplierFinanceProjectionService::PAYMENT_METHODS, true)) {
            throw new IngestionRefusal('PAYMENT_INVALID', 'the payment needs a positive amount and a known payment method');
        }
        $paymentDate = $this->dateOrRefuse($envelope['payment_date'] ?? $envelope['business_date'] ?? null, 'PAYMENT_INVALID', 'payment_date');

        $data = [
            'supplier_id' => $supplierId,
            'branch_id' => (int) $branch->id,
            'cash_bank_account_id' => (int) $cb->id,
            'purchase_bill_id' => $bill ? (int) $bill->id : null,
            'payment_date' => $paymentDate,
            'amount' => $amount,
            'payment_method' => $method,
            'reference_no' => $this->optString($envelope['reference_no'] ?? null, 100),
            'bank_name' => $this->optString($envelope['bank_name'] ?? null, 100),
            'account_no' => $this->optString($envelope['account_no'] ?? null, 100),
            'transaction_ref' => $this->optString($envelope['transaction_ref'] ?? null, 100),
            'cheque_no' => $this->optString($envelope['cheque_no'] ?? null, 100),
            'cheque_date' => ! empty($envelope['cheque_date']) ? $this->dateOrRefuse($envelope['cheque_date'], 'PAYMENT_INVALID', 'cheque_date') : null,
            'notes' => $this->optString($envelope['notes'] ?? null, 1000),
        ];

        try {
            $payment = $this->supplierPayable->recordPayment($data, (int) $actor->id);
        } catch (RuntimeException $e) {
            // The canonical authority said no (e.g. the payment would leave a negative payable — advances unsupported).
            throw new IngestionRefusal('PAYMENT_REFUSED', $e->getMessage());
        }
        $this->afterOfficialPosting();
        $this->financeVerifier->verifyPostedSupplierPayment($payment->fresh(), $this->supplierPayable->apAccountIds());

        $ack = $this->buildAck($eventUuid, $contentHash, $ingestionUuid, EdgeSupplierFinanceEnvelopeBuilder::EVENT_PAYMENT, (string) $payment->payment_no, (int) $envelope['activation_epoch'], $envelope['config_revision'] ?? null)
            + ['supplier_payment_id' => (int) $payment->id, 'cloud_supplier_id' => $supplierId, 'amount' => $amount];
        $registry->update([
            'status' => EdgeInboundSupplierFinanceIngestion::STATUS_APPLIED,
            'failure_code' => null,
            'supplier_id' => $supplierId,
            'supplier_payment_id' => (int) $payment->id,
            'official_reference_no' => (string) $payment->payment_no,
            'amount' => $amount,
            'ack_payload' => $ack,
            'ingested_at' => now(),
        ]);

        return $ack;
    }

    // ── SUPPLIER_AP_JOURNAL_ADJUSTMENT ───────────────────────────────────────────────────────────────────────────

    private function applyApJournal(array $envelope, Branch $branch, EdgeInboundSupplierFinanceIngestion $registry, string $eventUuid, string $contentHash, string $ingestionUuid): array
    {
        $conn = DB::connection('tenant');
        $actor = $this->resolveActor($envelope, EdgeSupplierFinanceProjectionService::PERMISSIONS['manual_journal']);
        $lines = is_array($envelope['lines'] ?? null) ? $envelope['lines'] : [];
        if (count($lines) < 2) {
            throw new IngestionRefusal('JOURNAL_INVALID', 'a journal needs at least two lines');
        }
        $entryDate = $this->dateOrRefuse($envelope['entry_date'] ?? $envelope['business_date'] ?? null, 'JOURNAL_INVALID', 'entry_date');
        $description = trim((string) ($envelope['description'] ?? ''));
        if ($description === '' || mb_strlen($description) > 500) {
            throw new IngestionRefusal('JOURNAL_INVALID', 'the journal needs a description (max 500 characters)');
        }

        $data = ['entry_date' => $entryDate, 'description' => $description, 'reference_no' => $this->optString($envelope['reference_no'] ?? null, 100), 'lines' => []];
        $supplierIds = [];
        foreach ($lines as $l) {
            $accountId = (int) ($l['cloud_account_id'] ?? 0);
            $account = $conn->table('accounts')->where('id', $accountId)->first();
            if (! $account) {
                throw new IngestionRefusal('ACCOUNT_UNKNOWN', 'a journal line names an account that does not exist');
            }
            if ((string) $account->code !== (string) ($l['account_code'] ?? '')) {
                throw new IngestionRefusal('ACCOUNT_MISMATCH', 'a journal line names account ' . $accountId . ' as code ' . ($l['account_code'] ?? '') . ' but the chart says ' . $account->code);
            }
            if (! (bool) $account->is_active) {
                throw new IngestionRefusal('ACCOUNT_INACTIVE', 'a journal line posts to an inactive account (' . $account->code . ')');
            }
            $cbId = isset($l['cloud_cash_bank_account_id']) ? (int) $l['cloud_cash_bank_account_id'] : 0;
            if ($cbId > 0) {
                $cb = $conn->table('cash_bank_accounts')->where('id', $cbId)->first();
                if (! $cb) {
                    throw new IngestionRefusal('CASH_BANK_UNKNOWN', 'a journal line names a Cash/Bank account that does not exist');
                }
                if (! (bool) $cb->is_active) {
                    throw new IngestionRefusal('CASH_BANK_INACTIVE', 'a journal line names an inactive Cash/Bank account');
                }
            }
            $supplierId = isset($l['cloud_supplier_id']) ? (int) $l['cloud_supplier_id'] : 0;
            if ($supplierId > 0) {
                $supplier = $conn->table('suppliers')->where('id', $supplierId)->whereNull('deleted_at')->first();
                if (! $supplier) {
                    throw new IngestionRefusal('SUPPLIER_UNKNOWN', 'a journal line names a supplier that does not exist');
                }
                if ((string) $supplier->status !== 'active') {
                    throw new IngestionRefusal('SUPPLIER_INACTIVE', 'a journal line names an inactive supplier');
                }
                $supplierIds[$supplierId] = $supplierId;
            }
            $data['lines'][] = [
                'account_id' => $accountId,
                'branch_id' => (int) $branch->id,
                'cash_bank_account_id' => $cbId > 0 ? $cbId : null,
                'counterparty_type' => $supplierId > 0 ? 'supplier' : null,
                'supplier_id' => $supplierId > 0 ? $supplierId : null,
                'description' => $this->optString($l['description'] ?? null, 255),
                'debit' => round((float) ($l['debit'] ?? 0), 4),
                'credit' => round((float) ($l['credit'] ?? 0), 4),
            ];
        }

        try {
            $this->manualJournals->assertApLinesNameTheirSupplier($data['lines']);   // the canonical AP rule
        } catch (ValidationException $e) {
            throw new IngestionRefusal('AP_SUPPLIER_REQUIRED', collect($e->errors())->flatten()->first() ?? 'an Accounts Payable line must name its supplier');
        }
        try {
            $entry = $this->manualJournals->post($data, (int) $actor->id);
        } catch (InvalidArgumentException $e) {
            throw new IngestionRefusal('JOURNAL_INVALID', $e->getMessage());
        } catch (RuntimeException $e) {
            throw new IngestionRefusal('JOURNAL_REFUSED', $e->getMessage());
        }
        $this->afterOfficialPosting();
        // The lines the event described (account / amounts / supplier / cash-bank dimension) — what the posted entry must equal.
        $expected = array_values(array_filter($data['lines'], fn ($l) => $l['debit'] > 0 || $l['credit'] > 0));
        $this->financeVerifier->verifyPostedManualJournal($entry->fresh(), $expected, $this->supplierPayable->apAccountIds());

        $ack = $this->buildAck($eventUuid, $contentHash, $ingestionUuid, EdgeSupplierFinanceEnvelopeBuilder::EVENT_AP_JOURNAL, (string) $entry->entry_no, (int) $envelope['activation_epoch'], $envelope['config_revision'] ?? null)
            + ['journal_entry_id' => (int) $entry->id, 'cloud_supplier_ids' => array_values($supplierIds), 'amount' => round((float) $entry->total_debit, 4)];
        $registry->update([
            'status' => EdgeInboundSupplierFinanceIngestion::STATUS_APPLIED,
            'failure_code' => null,
            'supplier_id' => count($supplierIds) === 1 ? (int) array_key_first($supplierIds) : null,
            'journal_entry_id' => (int) $entry->id,
            'official_reference_no' => (string) $entry->entry_no,
            'amount' => round((float) $entry->total_debit, 4),
            'ack_payload' => $ack,
            'ingested_at' => now(),
        ]);

        return $ack;
    }

    /** Test seam (config-driven — a container rebinding does not reach the bridged kernel request). */
    protected function afterOfficialPosting(): void
    {
        if (config('edge.testing.fail_after_official_supplier_finance', false) === true) {
            throw new RuntimeException('TEST SEAM: simulated failure after the official supplier-finance posting');
        }
    }

    // ── shared ───────────────────────────────────────────────────────────────────────────────────────────────────

    private function validateAuthority(array $envelope, string $schema): array
    {
        $device = EdgeDevice::query()->where('public_uuid', (string) ($envelope['device_public_uuid'] ?? ''))->first();
        if (! $device) {
            throw new IngestionRefusal('DEVICE_UNKNOWN', 'no such Edge device');
        }
        if ($device->isRevoked() || $device->active_slot !== EdgeDevice::ACTIVE_SLOT) {
            throw new IngestionRefusal('DEVICE_REVOKED', 'the Edge device is revoked or not the active slot');
        }
        if ((int) $device->tenant_id !== (int) ($envelope['tenant_id'] ?? 0)) {
            throw new IngestionRefusal('WRONG_TENANT', 'the envelope tenant does not match the device');
        }
        if ((int) $device->branch_id !== (int) ($envelope['branch_id'] ?? 0)) {
            throw new IngestionRefusal('WRONG_BRANCH', 'the envelope branch does not match the device');
        }
        $branch = Branch::on('tenant')->find((int) $envelope['branch_id']);
        if (! $branch) {
            throw new IngestionRefusal('BRANCH_UNKNOWN', 'the branch does not resolve in this tenant');
        }
        $current = $this->epochs->currentGeneration((int) $device->tenant_id, (int) $branch->id);
        if ($current === 0 || (int) ($envelope['activation_epoch'] ?? -1) !== $current) {
            throw new IngestionRefusal('STALE_ACTIVATION', "activation epoch {$envelope['activation_epoch']} is not the current generation {$current}");
        }
        $expectedType = $schema === EdgeSupplierFinanceEnvelopeBuilder::SCHEMA_PAYMENT ? EdgeSupplierFinanceEnvelopeBuilder::EVENT_PAYMENT : EdgeSupplierFinanceEnvelopeBuilder::EVENT_AP_JOURNAL;
        if (($envelope['event_type'] ?? '') !== $expectedType) {
            throw new IngestionRefusal('EVENT_INVALID', 'the envelope event_type does not match its schema');
        }

        return [$device, $branch];
    }

    /** Cloud permissions are authoritative: the operator who acted on the appliance must hold the Online permission. */
    private function resolveActor(array $envelope, string $permission): User
    {
        $user = User::on('tenant')->find((int) ($envelope['actor']['user_id'] ?? 0));
        if (! $user) {
            throw new IngestionRefusal('ACTOR_UNKNOWN', 'the operator does not resolve to a tenant user');
        }
        if ((string) $user->status !== 'active' || ! $user->can($permission)) {
            throw new IngestionRefusal('ACTOR_UNAUTHORIZED', "the operator is not permitted to {$permission} on the Cloud");
        }

        return $user;
    }

    private function claimRegistry(array $envelope, EdgeDevice $device, Branch $branch, string $contentHash, string $ingestionUuid): EdgeInboundSupplierFinanceIngestion
    {
        return EdgeInboundSupplierFinanceIngestion::updateOrCreate(['event_uuid' => (string) $envelope['event_uuid']], [
            'event_type' => (string) ($envelope['event_type'] ?? ''),
            'content_hash' => $contentHash,
            'envelope_schema_version' => (string) $envelope['envelope_schema_version'],
            'tenant_id' => (int) $device->tenant_id,
            'branch_id' => (int) $branch->id,
            'device_public_uuid' => (string) $device->public_uuid,
            'activation_epoch' => (int) $envelope['activation_epoch'],
            'config_revision' => $envelope['config_revision'] ?? null,
            'ingestion_uuid' => $ingestionUuid,
            'status' => EdgeInboundSupplierFinanceIngestion::STATUS_EXCEPTION,
            'failure_code' => 'IN_PROGRESS',
        ]);
    }

    private function buildAck(string $eventUuid, string $contentHash, string $ingestionUuid, string $eventType, string $officialNo, int $epoch, ?int $configRevision): array
    {
        return [
            'status' => 'applied',
            'sale_uuid' => $eventUuid,            // transport identity (the outbox row's key)
            'event_uuid' => $eventUuid,
            'event_type' => $eventType,
            'content_hash' => $contentHash,
            'ingestion_uuid' => $ingestionUuid,
            'official_reference_no' => $officialNo,
            'activation_epoch' => $epoch,
            'config_revision' => $configRevision,
            'ingested_at' => now()->toIso8601String(),
        ];
    }

    private function conflictAck(EdgeInboundSupplierFinanceIngestion $existing, string $incomingHash): array
    {
        return [
            'status' => 'conflict',
            'failure_code' => 'ENVELOPE_CONFLICT',
            'sale_uuid' => (string) $existing->event_uuid,
            'event_uuid' => (string) $existing->event_uuid,
            'content_hash' => (string) $existing->content_hash,
            'incoming_content_hash' => $incomingHash,
            'message' => 'this event_uuid was already ingested with different content; the first accepted truth is authoritative',
        ];
    }

    private function refuse(array $envelope, string $code, string $message): array
    {
        $uuid = (string) ($envelope['event_uuid'] ?? '');
        // The ACK names the envelope it answers (uuid + hash) so the appliance's sender can verify the identity and
        // classify the verdict; a refusal never carries an ingestion identity.
        $ack = ['status' => 'refused', 'failure_code' => $code, 'sale_uuid' => $uuid, 'event_uuid' => $uuid, 'content_hash' => (string) ($envelope['content_hash'] ?? ''), 'message' => $message];
        $this->persistTerminal($envelope, (string) ($envelope['content_hash'] ?? ''), EdgeInboundSupplierFinanceIngestion::STATUS_REFUSED, $code, $message, $ack);

        return $ack;
    }

    private function recordException(array $envelope, string $contentHash, string $code, string $message): array
    {
        $uuid = (string) ($envelope['event_uuid'] ?? '');
        $ack = ['status' => 'exception', 'failure_code' => $code, 'sale_uuid' => $uuid, 'event_uuid' => $uuid, 'content_hash' => $contentHash, 'message' => $message];
        $this->persistTerminal($envelope, $contentHash, EdgeInboundSupplierFinanceIngestion::STATUS_EXCEPTION, $code, $message, $ack);

        return $ack;
    }

    private function persistTerminal(array $envelope, string $contentHash, string $status, string $code, string $message, array $ack): void
    {
        $uuid = (string) ($envelope['event_uuid'] ?? '');
        if (! EdgeIdentity::isValid($uuid, EdgeIdentity::FORMAT_ULID)) {
            return;
        }
        try {
            DB::connection('tenant')->transaction(function () use ($envelope, $uuid, $contentHash, $status, $code, $message, $ack) {
                $row = EdgeInboundSupplierFinanceIngestion::query()->where('event_uuid', $uuid)->lockForUpdate()->first();
                if ($row && $row->isApplied()) {
                    return;
                }
                EdgeInboundSupplierFinanceIngestion::updateOrCreate(['event_uuid' => $uuid], [
                    'event_type' => (string) ($envelope['event_type'] ?? ''),
                    'content_hash' => $contentHash,
                    'envelope_schema_version' => (string) ($envelope['envelope_schema_version'] ?? ''),
                    'tenant_id' => (int) ($envelope['tenant_id'] ?? 0),
                    'branch_id' => (int) ($envelope['branch_id'] ?? 0),
                    'device_public_uuid' => (string) ($envelope['device_public_uuid'] ?? ''),
                    'activation_epoch' => (int) ($envelope['activation_epoch'] ?? 0),
                    'config_revision' => $envelope['config_revision'] ?? null,
                    'ingestion_uuid' => $row?->ingestion_uuid ?? (string) Str::ulid(),
                    'status' => $status,
                    'failure_code' => $code,
                    'last_error' => mb_substr($message, 0, 2000),
                    'ack_payload' => $ack,
                ]);
            });
        } catch (Throwable $e) {
            Log::warning('[edge-supplier-finance-ingest] could not persist terminal outcome', ['event_uuid' => $uuid, 'error' => $e->getMessage()]);
        }
    }

    private function hashMatches(array $envelope, string $contentHash): bool
    {
        if ($contentHash === '') {
            return false;
        }
        $copy = $envelope;
        unset($copy['content_hash']);

        return hash_equals(hash('sha256', $this->canonical->canonicalJson($copy)), $contentHash);
    }

    private function dateOrRefuse(mixed $value, string $code, string $field): string
    {
        try {
            $s = trim((string) $value);
            if ($s === '') {
                throw new InvalidArgumentException('empty');
            }

            return Carbon::parse($s)->toDateString();
        } catch (Throwable) {
            throw new IngestionRefusal($code, "the envelope {$field} is not a valid date");
        }
    }

    private function optString(mixed $v, int $max): ?string
    {
        $s = $v === null ? '' : trim((string) $v);

        return $s === '' ? null : mb_substr($s, 0, $max);
    }
}
