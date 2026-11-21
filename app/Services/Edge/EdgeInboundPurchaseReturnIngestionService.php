<?php

namespace App\Services\Edge;

use App\Models\Master\EdgeDevice;
use App\Models\Tenant\Branch;
use App\Models\Tenant\EdgeInboundPurchaseReturnIngestion;
use App\Models\Tenant\PurchaseReturn;
use App\Models\Tenant\User;
use App\Services\Purchasing\PurchaseReturnService;
use App\Support\Edge\EdgeIdentity;
use App\Support\EdgeRuntime;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * OFFLINE EDGE — F3: Cloud ingestion of Edge purchase-return events, exactly once, finance-complete-or-refuse.
 *
 * A thin shell around the EXISTING official Purchase Return path: PurchaseReturnService::createDraft + post (returnable
 * validation against the goods-receipt lines, official branch stock OUT by FEFO, supplier subledger credit, Dr 2100 /
 * Cr 1400 GL through the production-fixed JournalPostingService::postPurchaseReturn). Nothing about stock, subledger,
 * AP or GL is reproduced here. The registry claim, the official posting and EdgeFinancePostingVerifier::
 * verifyPostedPurchaseReturn share ONE transaction: incomplete effects roll everything back and the event is never
 * APPLIED. Terminal business refusals answer `refused` with the envelope identity (EdgeIngestionVerdicts).
 */
class EdgeInboundPurchaseReturnIngestionService
{
    public function __construct(
        private readonly EdgeBootstrapService $canonical,
        private readonly EdgeActivationEpochService $epochs,
        private readonly PurchaseReturnService $returns,
        private readonly EdgeFinancePostingVerifier $financeVerifier,
    ) {
    }

    public function ingest(array $envelope): array
    {
        if (EdgeRuntime::isBranchServer()) {
            throw new RuntimeException('EdgeInboundPurchaseReturnIngestionService is Cloud-only; it must never run on a Branch Server.');
        }
        $eventUuid = (string) ($envelope['event_uuid'] ?? '');
        $contentHash = (string) ($envelope['content_hash'] ?? '');
        if (! EdgeIdentity::isValid($eventUuid, EdgeIdentity::FORMAT_ULID)) {
            return $this->refuse($envelope, 'EVENT_UUID_INVALID', 'the envelope has no valid canonical event_uuid');
        }
        if ((string) ($envelope['envelope_schema_version'] ?? '') !== EdgePurchaseReturnEnvelopeBuilder::SCHEMA) {
            return $this->refuse($envelope, 'SCHEMA_UNSUPPORTED', 'unsupported purchase-return envelope schema version');
        }
        if (! $this->hashMatches($envelope, $contentHash)) {
            return $this->refuse($envelope, 'HASH_INVALID', 'the content hash is not self-consistent');
        }

        $existing = EdgeInboundPurchaseReturnIngestion::query()->where('event_uuid', $eventUuid)->first();
        if ($existing !== null) {
            if ($existing->isApplied()) {
                if (! hash_equals((string) $existing->content_hash, $contentHash)) {
                    return $this->conflictAck($existing, $contentHash);
                }

                return array_merge($existing->ack_payload ?? [], ['status' => 'already_applied']);
            }
            if ($existing->status === EdgeInboundPurchaseReturnIngestion::STATUS_CONFLICT) {
                return $existing->ack_payload;
            }
            if (! hash_equals((string) $existing->content_hash, $contentHash)) {
                return $this->conflictAck($existing, $contentHash);
            }
        }

        try {
            [$device, $branch] = $this->validateAuthority($envelope);
        } catch (IngestionRefusal $e) {
            return $this->refuse($envelope, $e->refusalCode, $e->getMessage());
        }

        try {
            return EdgeIngestionAuthority::run((int) $branch->id, function () use ($envelope, $device, $branch, $eventUuid, $contentHash) {
                return DB::connection('tenant')->transaction(function () use ($envelope, $device, $branch, $eventUuid, $contentHash) {
                    $ingestionUuid = (string) Str::ulid();
                    $registry = $this->claimRegistry($envelope, $device, $branch, $contentHash, $ingestionUuid);
                    $ack = $this->applyReturn($envelope, $branch, $registry, $eventUuid, $contentHash, $ingestionUuid);
                    Log::info('[edge-purchase-return-ingest] applied', ['event_uuid' => $eventUuid, 'official' => $ack['official_return_no'] ?? null]);

                    return $ack;
                });
            });
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062 && str_contains((string) $e->getMessage(), 'event_uuid')) {
                $winner = EdgeInboundPurchaseReturnIngestion::query()->where('event_uuid', $eventUuid)->first();
                if ($winner && $winner->isApplied()) {
                    return hash_equals((string) $winner->content_hash, $contentHash) ? $winner->ack_payload : $this->conflictAck($winner, $contentHash);
                }
            }

            return $this->recordException($envelope, $contentHash, 'DB_ERROR', $e->getMessage());
        } catch (IngestionRefusal $e) {
            if (EdgeIngestionVerdicts::isTerminal($e->refusalCode)) {
                return $this->refuse($envelope, $e->refusalCode, $e->getMessage());
            }

            return $this->recordException($envelope, $contentHash, $e->refusalCode, $e->getMessage());
        } catch (Throwable $e) {
            return $this->recordException($envelope, $contentHash, 'INGEST_FAILED', $e->getMessage());
        }
    }

    private function applyReturn(array $envelope, Branch $branch, EdgeInboundPurchaseReturnIngestion $registry, string $eventUuid, string $contentHash, string $ingestionUuid): array
    {
        $conn = DB::connection('tenant');
        $actor = $this->resolveActor($envelope);

        $grnId = (int) ($envelope['goods_receipt']['cloud_grn_id'] ?? 0);
        $grn = $grnId > 0 ? $conn->table('goods_receipts')->where('id', $grnId)->first() : null;
        if (! $grn) {
            throw new IngestionRefusal('GRN_UNKNOWN', 'the source goods receipt does not exist in this tenant');
        }
        if ((int) $grn->branch_id !== (int) $branch->id) {
            throw new IngestionRefusal('GRN_MISMATCH', 'the source goods receipt belongs to another branch');
        }
        $supplierId = (int) ($envelope['supplier']['cloud_supplier_id'] ?? 0);
        if ((int) $grn->supplier_id !== $supplierId) {
            throw new IngestionRefusal('GRN_MISMATCH', 'the source goods receipt belongs to another supplier');
        }
        $supplier = $conn->table('suppliers')->where('id', $supplierId)->whereNull('deleted_at')->first();
        if (! $supplier) {
            throw new IngestionRefusal('SUPPLIER_UNKNOWN', 'the supplier does not exist in this tenant');
        }

        $lines = is_array($envelope['lines'] ?? null) ? $envelope['lines'] : [];
        if ($lines === []) {
            throw new IngestionRefusal('PURCHASE_RETURN_INVALID', 'the event carries no return lines');
        }
        $grnLines = $conn->table('goods_receipt_lines')->where('goods_receipt_id', $grnId)->get()->keyBy('id');
        $draftLines = [];
        foreach ($lines as $l) {
            $src = $grnLines[(int) ($l['cloud_grn_line_id'] ?? 0)] ?? null;
            if (! $src) {
                throw new IngestionRefusal('GRN_LINE_UNKNOWN', 'a return line does not resolve to a line of the source goods receipt');
            }
            if ((int) $src->product_id !== (int) ($l['product_id'] ?? 0) || (int) ($src->product_variant_id ?? 0) !== (int) ($l['product_variant_id'] ?? 0)) {
                throw new IngestionRefusal('GRN_LINE_UNKNOWN', 'a return line names a different product / variant than the received line');
            }
            $qty = round((float) ($l['quantity'] ?? 0), 3);
            if ($qty <= 0) {
                throw new IngestionRefusal('PURCHASE_RETURN_INVALID', 'a return line has no quantity');
            }
            $draftLines[] = [
                'product_id' => (int) $src->product_id, 'product_variant_id' => $src->product_variant_id !== null ? (int) $src->product_variant_id : null,
                'source_line_id' => (int) $src->id, 'quantity' => $qty,
                'unit_cost' => (float) ($l['unit_cost'] ?? 0) > 0 ? round((float) $l['unit_cost'], 4) : (float) $src->unit_cost,   // canonical default: the GRN line's cost
                'reason_code' => $l['reason_code'] ?? null, 'notes' => null,
            ];
        }
        $reason = $envelope['reason_code'] ?? null;
        if ($reason !== null && ! in_array((string) $reason, PurchaseReturn::REASON_CODES, true)) {
            throw new IngestionRefusal('PURCHASE_RETURN_INVALID', 'unknown return reason');
        }
        if ($reason === null && ! collect($draftLines)->contains(fn ($l) => ! empty($l['reason_code']))) {
            throw new IngestionRefusal('PURCHASE_RETURN_INVALID', 'a return reason is required (header or per line)');
        }
        $returnDate = $this->dateOrRefuse($envelope['return_date'] ?? $envelope['business_date'] ?? null);

        try {
            $draft = $this->returns->createDraft([
                'branch_id' => (int) $branch->id, 'supplier_id' => $supplierId, 'goods_receipt_id' => $grnId, 'purchase_order_id' => $grn->purchase_order_id ?? null,
                'return_date' => $returnDate, 'reason_code' => $reason, 'notes' => isset($envelope['notes']) ? mb_substr((string) $envelope['notes'], 0, 1000) : null,
            ], $draftLines, (int) $actor->id);
            $posted = $this->returns->post($draft, (int) $actor->id);
        } catch (RuntimeException $e) {
            // The canonical authority said no: more than the receipt line still holds, or official stock no longer covers it.
            throw new IngestionRefusal('PURCHASE_RETURN_REFUSED', $e->getMessage());
        }
        $this->afterOfficialPosting();
        $this->financeVerifier->verifyPostedPurchaseReturn($posted->fresh(['lines']), $draftLines);

        $ack = [
            'status' => 'applied', 'sale_uuid' => $eventUuid, 'event_uuid' => $eventUuid, 'event_type' => EdgePurchaseReturnEnvelopeBuilder::EVENT,
            'content_hash' => $contentHash, 'ingestion_uuid' => $ingestionUuid, 'official_return_no' => (string) $posted->return_no, 'purchase_return_id' => (int) $posted->id,
            'grand_total' => round((float) $posted->grand_total, 4), 'activation_epoch' => (int) $envelope['activation_epoch'], 'config_revision' => $envelope['config_revision'] ?? null,
            'ingested_at' => now()->toIso8601String(),
        ];
        $registry->update([
            'status' => EdgeInboundPurchaseReturnIngestion::STATUS_APPLIED, 'failure_code' => null, 'supplier_id' => $supplierId, 'goods_receipt_id' => $grnId,
            'purchase_return_id' => (int) $posted->id, 'official_return_no' => (string) $posted->return_no, 'amount' => round((float) $posted->grand_total, 4),
            'ack_payload' => $ack, 'ingested_at' => now(),
        ]);

        return $ack;
    }

    /** Test seam (config-driven — a container rebinding does not reach the bridged kernel request). */
    protected function afterOfficialPosting(): void
    {
        if (config('edge.testing.fail_after_official_purchase_return', false) === true) {
            throw new RuntimeException('TEST SEAM: simulated failure after the official purchase return posted');
        }
    }

    private function validateAuthority(array $envelope): array
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
        if (($envelope['event_type'] ?? '') !== EdgePurchaseReturnEnvelopeBuilder::EVENT) {
            throw new IngestionRefusal('EVENT_INVALID', 'the envelope event_type does not match its schema');
        }

        return [$device, $branch];
    }

    /** Cloud permissions are authoritative: the operator must hold the Online permissions to create AND post a return. */
    private function resolveActor(array $envelope): User
    {
        $user = User::on('tenant')->find((int) ($envelope['actor']['user_id'] ?? 0));
        if (! $user) {
            throw new IngestionRefusal('ACTOR_UNKNOWN', 'the operator does not resolve to a tenant user');
        }
        foreach (EdgePurchaseReturnProjectionService::PERMISSIONS as $permission) {
            if ((string) $user->status !== 'active' || ! $user->can($permission)) {
                throw new IngestionRefusal('ACTOR_UNAUTHORIZED', "the operator is not permitted to {$permission} on the Cloud");
            }
        }

        return $user;
    }

    private function claimRegistry(array $envelope, EdgeDevice $device, Branch $branch, string $contentHash, string $ingestionUuid): EdgeInboundPurchaseReturnIngestion
    {
        return EdgeInboundPurchaseReturnIngestion::updateOrCreate(['event_uuid' => (string) $envelope['event_uuid']], [
            'content_hash' => $contentHash, 'envelope_schema_version' => (string) $envelope['envelope_schema_version'],
            'tenant_id' => (int) $device->tenant_id, 'branch_id' => (int) $branch->id, 'device_public_uuid' => (string) $device->public_uuid,
            'activation_epoch' => (int) $envelope['activation_epoch'], 'config_revision' => $envelope['config_revision'] ?? null,
            'ingestion_uuid' => $ingestionUuid, 'status' => EdgeInboundPurchaseReturnIngestion::STATUS_EXCEPTION, 'failure_code' => 'IN_PROGRESS',
        ]);
    }

    private function conflictAck(EdgeInboundPurchaseReturnIngestion $existing, string $incomingHash): array
    {
        return [
            'status' => 'conflict', 'failure_code' => 'ENVELOPE_CONFLICT', 'sale_uuid' => (string) $existing->event_uuid, 'event_uuid' => (string) $existing->event_uuid,
            'content_hash' => (string) $existing->content_hash, 'incoming_content_hash' => $incomingHash,
            'message' => 'this event_uuid was already ingested with different content; the first accepted truth is authoritative',
        ];
    }

    private function refuse(array $envelope, string $code, string $message): array
    {
        $uuid = (string) ($envelope['event_uuid'] ?? '');
        $ack = ['status' => 'refused', 'failure_code' => $code, 'sale_uuid' => $uuid, 'event_uuid' => $uuid, 'content_hash' => (string) ($envelope['content_hash'] ?? ''), 'message' => $message];
        $this->persistTerminal($envelope, (string) ($envelope['content_hash'] ?? ''), EdgeInboundPurchaseReturnIngestion::STATUS_REFUSED, $code, $message, $ack);

        return $ack;
    }

    private function recordException(array $envelope, string $contentHash, string $code, string $message): array
    {
        $uuid = (string) ($envelope['event_uuid'] ?? '');
        $ack = ['status' => 'exception', 'failure_code' => $code, 'sale_uuid' => $uuid, 'event_uuid' => $uuid, 'content_hash' => $contentHash, 'message' => $message];
        $this->persistTerminal($envelope, $contentHash, EdgeInboundPurchaseReturnIngestion::STATUS_EXCEPTION, $code, $message, $ack);

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
                $row = EdgeInboundPurchaseReturnIngestion::query()->where('event_uuid', $uuid)->lockForUpdate()->first();
                if ($row && $row->isApplied()) {
                    return;
                }
                EdgeInboundPurchaseReturnIngestion::updateOrCreate(['event_uuid' => $uuid], [
                    'content_hash' => $contentHash, 'envelope_schema_version' => (string) ($envelope['envelope_schema_version'] ?? ''),
                    'tenant_id' => (int) ($envelope['tenant_id'] ?? 0), 'branch_id' => (int) ($envelope['branch_id'] ?? 0), 'device_public_uuid' => (string) ($envelope['device_public_uuid'] ?? ''),
                    'activation_epoch' => (int) ($envelope['activation_epoch'] ?? 0), 'config_revision' => $envelope['config_revision'] ?? null,
                    'ingestion_uuid' => $row?->ingestion_uuid ?? (string) Str::ulid(), 'status' => $status, 'failure_code' => $code,
                    'last_error' => mb_substr($message, 0, 2000), 'ack_payload' => $ack,
                ]);
            });
        } catch (Throwable $e) {
            Log::warning('[edge-purchase-return-ingest] could not persist terminal outcome', ['event_uuid' => $uuid, 'error' => $e->getMessage()]);
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

    private function dateOrRefuse(mixed $value): string
    {
        try {
            $s = trim((string) $value);
            if ($s === '') {
                throw new RuntimeException('empty');
            }

            return Carbon::parse($s)->toDateString();
        } catch (Throwable) {
            throw new IngestionRefusal('PURCHASE_RETURN_INVALID', 'the envelope return_date is not a valid date');
        }
    }
}
