<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeSyncOutbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * OFFLINE EDGE — F2: the appliance's READ-ONLY warm supplier-finance projection + its own pending events.
 *
 *   LOCAL AVAILABLE PAYABLE = the latest AUTHORITATIVE Cloud payable (as of the projection) adjusted by the local
 *   events the Cloud has NOT yet applied — exactly the events whose uuid is absent from the projection's
 *   applied-event set. An ACK that precedes the next refresh therefore never double-applies (the event is still
 *   subtracted until the projection that includes it arrives), and a refresh never re-applies it (it is then in the set).
 *
 * Freshness (same rule as the F1 returnable cache): the cached watermark equals the last advertised one, or the
 * projection was received strictly after the last acknowledged heartbeat. Anything else → not current → the
 * finance actions FAIL CLOSED with a business message. The appliance never guesses a payable balance.
 */
class EdgeSupplierFinanceCacheService
{
    public const T_SUPPLIERS = 'edge_supplier_finance_suppliers';
    public const T_BILLS = 'edge_supplier_finance_bills';
    public const T_CASH_BANK = 'edge_supplier_finance_cash_bank_accounts';
    public const T_ACCOUNTS = 'edge_supplier_finance_accounts';
    public const T_LEDGER = 'edge_supplier_finance_ledger_entries';
    public const T_APPLIED = 'edge_supplier_finance_applied_events';
    public const T_EVENTS = 'edge_local_supplier_finance_events';
    public const T_EFFECTS = 'edge_local_supplier_finance_effects';

    public function __construct(private readonly EdgeBranchContext $context)
    {
    }

    // ── projection lifecycle ─────────────────────────────────────────────────────────────────────────────────────

    /** Replace the projection wholesale (one transaction) and stamp the watermark / as_of on the binding. */
    public function apply(array $package): array
    {
        $meta = $this->context->requireCurrent();
        $now = now();
        $asOf = isset($package['as_of']) ? Carbon::parse($package['as_of']) : $now;
        $stats = [];
        DB::connection('tenant')->transaction(function () use ($package, $meta, $now, $asOf, &$stats) {
            $conn = DB::connection('tenant');
            $ts = fn ($v) => $v ? Carbon::parse($v) : null;

            $conn->table(self::T_SUPPLIERS)->delete();
            $rows = array_map(fn ($s) => [
                'cloud_supplier_id' => (int) $s['cloud_supplier_id'], 'code' => (string) $s['code'], 'name' => (string) $s['name'], 'status' => (string) $s['status'],
                'cloud_payable' => round((float) $s['current_balance'], 4), 'cloud_updated_at' => $ts($s['updated_at'] ?? null), 'refreshed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ], (array) ($package['suppliers'] ?? []));
            $this->insertChunks($conn, self::T_SUPPLIERS, $rows);
            $stats['suppliers'] = count($rows);

            $conn->table(self::T_BILLS)->delete();
            $rows = array_map(fn ($b) => [
                'cloud_bill_id' => (int) $b['cloud_bill_id'], 'cloud_supplier_id' => (int) $b['cloud_supplier_id'], 'branch_id' => (int) $b['branch_id'],
                'bill_no' => (string) $b['bill_no'], 'supplier_invoice_no' => $b['supplier_invoice_no'] ?? null, 'bill_date' => $b['bill_date'] ?? null, 'due_date' => $b['due_date'] ?? null,
                'status' => (string) $b['status'], 'grand_total' => round((float) $b['grand_total'], 4), 'amount_paid' => round((float) $b['amount_paid'], 4), 'balance_due' => round((float) $b['balance_due'], 4),
                'cloud_updated_at' => $ts($b['updated_at'] ?? null), 'refreshed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ], (array) ($package['bills'] ?? []));
            $this->insertChunks($conn, self::T_BILLS, $rows);
            $stats['bills'] = count($rows);

            $conn->table(self::T_CASH_BANK)->delete();
            $rows = array_map(fn ($c) => [
                'cloud_cash_bank_account_id' => (int) $c['cloud_cash_bank_account_id'], 'code' => (string) $c['code'], 'name' => (string) $c['name'], 'account_type' => (string) $c['account_type'],
                'branch_id' => $c['branch_id'] ?? null, 'coa_account_id' => $c['coa_account_id'] ?? null, 'coa_code' => $c['coa_code'] ?? null, 'bank_name' => $c['bank_name'] ?? null,
                'is_default' => (bool) ($c['is_default'] ?? false), 'is_active' => (bool) ($c['is_active'] ?? false), 'cloud_balance' => round((float) ($c['current_balance'] ?? 0), 4),
                'cloud_updated_at' => $ts($c['updated_at'] ?? null), 'refreshed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ], (array) ($package['cash_bank_accounts'] ?? []));
            $this->insertChunks($conn, self::T_CASH_BANK, $rows);
            $stats['cash_bank_accounts'] = count($rows);

            $conn->table(self::T_ACCOUNTS)->delete();
            $rows = array_map(fn ($a) => [
                'cloud_account_id' => (int) $a['cloud_account_id'], 'code' => (string) $a['code'], 'name' => (string) $a['name'], 'type' => (string) $a['type'],
                'normal_balance' => (string) $a['normal_balance'], 'parent_cloud_account_id' => $a['parent_cloud_account_id'] ?? null, 'is_ap' => (bool) ($a['is_ap'] ?? false),
                'is_active' => (bool) ($a['is_active'] ?? false), 'sort_order' => (int) ($a['sort_order'] ?? 0), 'refreshed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ], (array) ($package['accounts'] ?? []));
            $this->insertChunks($conn, self::T_ACCOUNTS, $rows);
            $stats['accounts'] = count($rows);

            $conn->table(self::T_LEDGER)->delete();
            $rows = array_map(fn ($l) => [
                'cloud_ledger_id' => (int) $l['cloud_ledger_id'], 'cloud_supplier_id' => (int) $l['cloud_supplier_id'], 'entry_type' => (string) $l['entry_type'], 'direction' => (string) $l['direction'],
                'amount' => round((float) $l['amount'], 4), 'balance_after' => round((float) $l['balance_after'], 4), 'reference_type' => $l['reference_type'] ?? null, 'reference_id' => $l['reference_id'] ?? null,
                'reference_no' => $l['reference_no'] ?? null, 'notes' => $l['notes'] ?? null, 'created_by_name' => $l['created_by_name'] ?? null, 'edge_event_uuid' => $l['edge_event_uuid'] ?? null,
                'cloud_created_at' => $ts($l['created_at'] ?? null), 'refreshed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ], (array) ($package['ledger_entries'] ?? []));
            $this->insertChunks($conn, self::T_LEDGER, $rows);
            $stats['ledger_entries'] = count($rows);

            $conn->table(self::T_APPLIED)->delete();
            $rows = array_map(fn ($e) => [
                'event_uuid' => (string) $e['event_uuid'], 'event_type' => (string) $e['event_type'], 'official_reference_no' => $e['official_reference_no'] ?? null,
                'applied_at' => $ts($e['applied_at'] ?? null), 'refreshed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ], (array) ($package['applied_events'] ?? []));
            $this->insertChunks($conn, self::T_APPLIED, $rows);
            $stats['applied_events'] = count($rows);

            $meta->forceFill([
                'supplier_finance_cache_watermark' => (string) ($package['watermark'] ?? ''),
                'supplier_finance_cache_as_of' => $asOf,
                'supplier_finance_cache_refreshed_at' => $now,
                'supplier_finance_rules' => json_encode(['rules' => $package['rules'] ?? [], 'permissions' => $package['permissions'] ?? [], 'ledger_window_days' => $package['ledger_window_days'] ?? null]),
            ])->save();
        });

        return $stats;
    }

    private function insertChunks($conn, string $table, array $rows): void
    {
        foreach (array_chunk($rows, 200) as $chunk) {
            if ($chunk !== []) {
                $conn->table($table)->insert($chunk);
            }
        }
    }

    public function freshness(): array
    {
        $meta = $this->context->current();
        if (! $meta) {
            return ['ok' => false, 'reasons' => ['appliance not bound'], 'watermark' => null, 'as_of' => null];
        }
        $seen = $meta->standby_supplier_finance_watermark_seen !== null ? (string) $meta->standby_supplier_finance_watermark_seen : null;
        $cached = $meta->supplier_finance_cache_watermark !== null && $meta->supplier_finance_cache_watermark !== '' ? (string) $meta->supplier_finance_cache_watermark : null;
        $asOf = $meta->supplier_finance_cache_as_of ? Carbon::parse($meta->supplier_finance_cache_as_of) : null;
        $lastAck = $meta->authority_last_ack_at ? Carbon::parse($meta->authority_last_ack_at) : null;
        $reasons = [];
        $ok = false;
        if ($cached === null) {
            $reasons[] = 'no supplier-finance information has been received from the Cloud';
        } elseif ($seen === null) {
            $reasons[] = 'the Cloud never advertised a supplier-finance watermark';
        } elseif ($cached === $seen) {
            $ok = true;
        } elseif ($asOf !== null && $lastAck !== null && $asOf->greaterThan($lastAck)) {
            $ok = true;
        } else {
            $reasons[] = 'the supplier-finance information does not equal the last advertised Cloud position';
        }

        return ['ok' => $ok, 'reasons' => $reasons, 'watermark' => $cached, 'as_of' => $asOf?->toIso8601String()];
    }

    /** The canonical rules + permission names the projection carried (mirrored, never invented). */
    public function rules(): array
    {
        $meta = $this->context->current();
        $stored = $meta && $meta->supplier_finance_rules ? json_decode((string) $meta->supplier_finance_rules, true) : null;
        $rules = is_array($stored['rules'] ?? null) ? $stored['rules'] : [];

        return [
            'cash_bank_required' => (bool) ($rules['cash_bank_required'] ?? true),
            'purchase_bill_optional' => (bool) ($rules['purchase_bill_optional'] ?? true),
            'ledger_only_payment_possible' => (bool) ($rules['ledger_only_payment_possible'] ?? false),
            'supplier_advance_supported' => (bool) ($rules['supplier_advance_supported'] ?? false),
            'payment_methods' => (array) ($rules['payment_methods'] ?? ['cash', 'bank_transfer', 'cheque', 'card', 'other']),
            'offline_payment_methods' => (array) config('edge.supplier_finance.offline_payment_methods', ['cash', 'bank_transfer', 'cheque', 'other']),
            'ap_control_code' => (string) ($rules['ap_control_code'] ?? '2100'),
        ];
    }

    // ── lookups ──────────────────────────────────────────────────────────────────────────────────────────────────

    public function supplier(int $cloudSupplierId, bool $lock = false): ?object
    {
        $q = DB::connection('tenant')->table(self::T_SUPPLIERS)->where('cloud_supplier_id', $cloudSupplierId);
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->first();
    }

    public function suppliers(bool $activeOnly = false): array
    {
        $rows = DB::connection('tenant')->table(self::T_SUPPLIERS)->when($activeOnly, fn ($q) => $q->where('status', 'active'))->orderBy('name')->get();
        $pending = $this->pendingPayableDeltas();

        return $rows->map(function ($s) use ($pending) {
            $p = $pending[(int) $s->cloud_supplier_id] ?? ['delta' => 0.0, 'events' => 0];

            return $this->supplierView($s, $p['delta'], $p['events']);
        })->values()->all();
    }

    /**
     * The supplier's LOCAL AVAILABLE PAYABLE. With $lock the supplier row AND the pending effects are read as LOCKING
     * reads (latest committed state, not the transaction's earlier snapshot) — the mutation paths use this so two
     * terminals serialise on the supplier row and the second one computes the payable the first one left behind.
     */
    public function position(int $cloudSupplierId, bool $lock = false): ?array
    {
        $s = $this->supplier($cloudSupplierId, $lock);
        if (! $s) {
            return null;
        }
        $p = $this->pendingPayableDeltas($cloudSupplierId, $lock)[$cloudSupplierId] ?? ['delta' => 0.0, 'events' => 0];

        return $this->supplierView($s, $p['delta'], $p['events']);
    }

    private function supplierView(object $s, float $pendingDelta, int $pendingEvents): array
    {
        return [
            'cloud_supplier_id' => (int) $s->cloud_supplier_id,
            'code' => (string) $s->code,
            'name' => (string) $s->name,
            'status' => (string) $s->status,
            'cloud_payable' => round((float) $s->cloud_payable, 2),
            'pending_delta' => round($pendingDelta, 2),
            'available_payable' => round((float) $s->cloud_payable + $pendingDelta, 2),
            'pending_events' => $pendingEvents,
        ];
    }

    /** Σ payable_delta of local events the Cloud has NOT applied yet, per supplier. */
    public function pendingPayableDeltas(?int $cloudSupplierId = null, bool $lock = false): array
    {
        $conn = DB::connection('tenant');
        $rows = $conn->table(self::T_EFFECTS . ' as e')
            ->whereNotNull('e.cloud_supplier_id')
            ->when($cloudSupplierId !== null, fn ($q) => $q->where('e.cloud_supplier_id', $cloudSupplierId))
            ->whereNotIn('e.event_uuid', fn ($q) => $q->select('event_uuid')->from(self::T_APPLIED))
            ->groupBy('e.cloud_supplier_id')
            ->selectRaw('e.cloud_supplier_id, SUM(e.payable_delta) as delta, COUNT(DISTINCT e.event_uuid) as events')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->cloud_supplier_id] = ['delta' => (float) $r->delta, 'events' => (int) $r->events];
        }

        return $out;
    }

    public function openBills(int $cloudSupplierId, bool $lock = false): array
    {
        $conn = DB::connection('tenant');
        $bills = $conn->table(self::T_BILLS)->where('cloud_supplier_id', $cloudSupplierId)->whereIn('status', ['posted', 'partial'])->orderByDesc('bill_date')->orderByDesc('cloud_bill_id')->get();
        $pending = $conn->table(self::T_EFFECTS)->whereNotNull('cloud_bill_id')->whereIn('cloud_bill_id', $bills->pluck('cloud_bill_id')->all())
            ->whereNotIn('event_uuid', fn ($q) => $q->select('event_uuid')->from(self::T_APPLIED))
            ->groupBy('cloud_bill_id')->selectRaw('cloud_bill_id, SUM(-payable_delta) as allocated')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->pluck('allocated', 'cloud_bill_id');

        return $bills->map(function ($b) use ($pending) {
            $allocated = round((float) ($pending[(int) $b->cloud_bill_id] ?? 0), 2);

            return [
                'cloud_bill_id' => (int) $b->cloud_bill_id, 'bill_no' => (string) $b->bill_no, 'supplier_invoice_no' => $b->supplier_invoice_no,
                'bill_date' => $b->bill_date, 'due_date' => $b->due_date, 'status' => (string) $b->status,
                'grand_total' => round((float) $b->grand_total, 2), 'amount_paid' => round((float) $b->amount_paid, 2), 'balance_due' => round((float) $b->balance_due, 2),
                'pending_allocated' => $allocated, 'available' => round(max(0, (float) $b->balance_due - $allocated), 2),
            ];
        })->values()->all();
    }

    public function bill(int $cloudBillId): ?object
    {
        return DB::connection('tenant')->table(self::T_BILLS)->where('cloud_bill_id', $cloudBillId)->first();
    }

    public function cashBankAccounts(bool $usableOnly = true): array
    {
        $conn = DB::connection('tenant');
        $rows = $conn->table(self::T_CASH_BANK)->when($usableOnly, fn ($q) => $q->where('is_active', true)->whereNotNull('coa_account_id'))->orderBy('code')->get();
        $pending = $conn->table(self::T_EFFECTS)->whereNotNull('cloud_cash_bank_account_id')
            ->whereNotIn('event_uuid', fn ($q) => $q->select('event_uuid')->from(self::T_APPLIED))
            ->groupBy('cloud_cash_bank_account_id')->selectRaw('cloud_cash_bank_account_id, SUM(cash_delta) as delta')->pluck('delta', 'cloud_cash_bank_account_id');

        return $rows->map(fn ($c) => [
            'cloud_cash_bank_account_id' => (int) $c->cloud_cash_bank_account_id, 'code' => (string) $c->code, 'name' => (string) $c->name, 'account_type' => (string) $c->account_type,
            'branch_id' => $c->branch_id !== null ? (int) $c->branch_id : null, 'coa_account_id' => $c->coa_account_id !== null ? (int) $c->coa_account_id : null, 'coa_code' => $c->coa_code,
            'bank_name' => $c->bank_name, 'is_default' => (bool) $c->is_default, 'is_active' => (bool) $c->is_active,
            'cloud_balance' => round((float) $c->cloud_balance, 2), 'pending_delta' => round((float) ($pending[(int) $c->cloud_cash_bank_account_id] ?? 0), 2),
            'projected_balance' => round((float) $c->cloud_balance + (float) ($pending[(int) $c->cloud_cash_bank_account_id] ?? 0), 2),
        ])->values()->all();
    }

    public function cashBankAccount(int $cloudId): ?object
    {
        return DB::connection('tenant')->table(self::T_CASH_BANK)->where('cloud_cash_bank_account_id', $cloudId)->first();
    }

    public function accounts(bool $activeOnly = true): array
    {
        return DB::connection('tenant')->table(self::T_ACCOUNTS)->when($activeOnly, fn ($q) => $q->where('is_active', true))->orderBy('sort_order')->orderBy('code')->get()
            ->map(fn ($a) => [
                'cloud_account_id' => (int) $a->cloud_account_id, 'code' => (string) $a->code, 'name' => (string) $a->name, 'type' => (string) $a->type,
                'normal_balance' => (string) $a->normal_balance, 'parent_cloud_account_id' => $a->parent_cloud_account_id !== null ? (int) $a->parent_cloud_account_id : null,
                'is_ap' => (bool) $a->is_ap, 'is_active' => (bool) $a->is_active,
            ])->values()->all();
    }

    public function account(int $cloudAccountId): ?object
    {
        return DB::connection('tenant')->table(self::T_ACCOUNTS)->where('cloud_account_id', $cloudAccountId)->first();
    }

    public function apAccountIds(): array
    {
        return DB::connection('tenant')->table(self::T_ACCOUNTS)->where('is_ap', true)->pluck('cloud_account_id')->map(fn ($v) => (int) $v)->all();
    }

    public function isApplied(string $eventUuid): bool
    {
        return DB::connection('tenant')->table(self::T_APPLIED)->where('event_uuid', $eventUuid)->exists();
    }

    // ── local events ─────────────────────────────────────────────────────────────────────────────────────────────

    public function recordEvent(array $event, array $effects): void
    {
        $conn = DB::connection('tenant');
        $now = now();
        $conn->table(self::T_EVENTS)->insert([
            'event_uuid' => $event['event_uuid'], 'event_type' => $event['event_type'], 'branch_id' => (int) $event['branch_id'],
            'terminal_id' => $event['terminal_id'] ?? null, 'user_id' => $event['user_id'] ?? null, 'business_date' => $event['business_date'],
            'description' => $event['description'] ?? null, 'reference_no' => $event['reference_no'] ?? null, 'amount' => round((float) $event['amount'], 4),
            'payload' => json_encode($event['payload']), 'envelope_schema_version' => $event['envelope_schema_version'], 'content_hash' => $event['content_hash'],
            'finance_watermark' => $event['finance_watermark'] ?? null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ($effects as $e) {
            $conn->table(self::T_EFFECTS)->insert([
                'event_uuid' => $event['event_uuid'], 'cloud_supplier_id' => $e['cloud_supplier_id'] ?? null, 'cloud_cash_bank_account_id' => $e['cloud_cash_bank_account_id'] ?? null,
                'cloud_bill_id' => $e['cloud_bill_id'] ?? null, 'payable_delta' => round((float) ($e['payable_delta'] ?? 0), 4), 'cash_delta' => round((float) ($e['cash_delta'] ?? 0), 4),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /** PENDING SYNC vs official: derived from the outbox row + the applied set, never guessed. */
    public function syncStateFor(string $eventUuid): array
    {
        $row = EdgeSyncOutbox::on('tenant')->where('sale_uuid', $eventUuid)->first();
        $applied = $this->isApplied($eventUuid);
        $state = match (true) {
            $applied => 'official',
            $row === null => 'missing',
            $row->state === EdgeSyncOutbox::STATE_ACKNOWLEDGED => 'synced',
            $row->state === EdgeSyncOutbox::STATE_FAILED_PERMANENT => 'failed',
            default => 'pending',
        };
        $labels = [
            'pending' => 'PENDING SYNC — recorded on this branch server; the Cloud has not posted it yet',
            'synced' => 'POSTED AT CLOUD — official; the ledger refresh will show it',
            'official' => 'OFFICIAL — posted at the Cloud',
            'failed' => 'REFUSED BY THE CLOUD — needs a supervisor',
            'missing' => 'NOT QUEUED — integrity problem, needs a supervisor',
        ];
        $ack = $row?->ack_payload;
        $ack = is_string($ack) ? json_decode($ack, true) : $ack;

        return [
            'state' => $state, 'label' => $labels[$state], 'outbox_state' => $row?->state, 'attempts' => $row ? (int) $row->attempts : 0,
            'last_error' => $row?->last_error, 'official_reference_no' => is_array($ack) ? ($ack['official_reference_no'] ?? null) : null,
            'acknowledged_at' => $row && $row->acknowledged_at ? Carbon::parse($row->acknowledged_at)->toIso8601String() : null,
        ];
    }

    public function event(string $eventUuid): ?array
    {
        $row = DB::connection('tenant')->table(self::T_EVENTS)->where('event_uuid', $eventUuid)->first();

        return $row ? $this->eventView($row) : null;
    }

    public function eventView(object $row): array
    {
        $effects = DB::connection('tenant')->table(self::T_EFFECTS)->where('event_uuid', $row->event_uuid)->get()
            ->map(fn ($e) => ['cloud_supplier_id' => $e->cloud_supplier_id !== null ? (int) $e->cloud_supplier_id : null, 'cloud_cash_bank_account_id' => $e->cloud_cash_bank_account_id !== null ? (int) $e->cloud_cash_bank_account_id : null,
                'cloud_bill_id' => $e->cloud_bill_id !== null ? (int) $e->cloud_bill_id : null, 'payable_delta' => round((float) $e->payable_delta, 2), 'cash_delta' => round((float) $e->cash_delta, 2)])->values()->all();

        return [
            'event_uuid' => (string) $row->event_uuid, 'event_type' => (string) $row->event_type, 'business_date' => (string) $row->business_date,
            'description' => $row->description, 'reference_no' => $row->reference_no, 'amount' => round((float) $row->amount, 2),
            'payload' => json_decode((string) $row->payload, true), 'content_hash' => (string) $row->content_hash, 'finance_watermark' => $row->finance_watermark,
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(), 'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
            'effects' => $effects, 'sync' => $this->syncStateFor((string) $row->event_uuid),
        ];
    }

    public function recentEvents(?string $type = null, int $limit = 50): array
    {
        return DB::connection('tenant')->table(self::T_EVENTS)->when($type !== null, fn ($q) => $q->where('event_type', $type))->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($r) => $this->eventView($r))->values()->all();
    }

    /**
     * The Supplier Ledger: the OFFICIAL Cloud rows (window) + the appliance's PROVISIONAL rows (local events the
     * Cloud has not applied) — newest first. Once an event is applied and the projection refreshed, its provisional
     * row disappears and the official row (labelled with the event) is the ONE visible transaction.
     */
    public function ledger(int $cloudSupplierId): array
    {
        $conn = DB::connection('tenant');
        $s = $this->supplier($cloudSupplierId);
        $official = $conn->table(self::T_LEDGER)->where('cloud_supplier_id', $cloudSupplierId)->orderByDesc('cloud_created_at')->orderByDesc('cloud_ledger_id')->get()
            ->map(fn ($l) => [
                'kind' => 'official', 'cloud_ledger_id' => (int) $l->cloud_ledger_id, 'date' => $l->cloud_created_at ? Carbon::parse($l->cloud_created_at)->toIso8601String() : null,
                'entry_type' => (string) $l->entry_type, 'reference_no' => $l->reference_no, 'description' => $l->notes,
                'debit' => (string) $l->direction === 'debit' ? round((float) $l->amount, 2) : 0.0, 'credit' => (string) $l->direction === 'credit' ? round((float) $l->amount, 2) : 0.0,
                'balance_after' => round((float) $l->balance_after, 2), 'user' => $l->created_by_name, 'edge_event_uuid' => $l->edge_event_uuid, 'sync' => null,
            ])->values()->all();

        $provisional = [];
        if ($s) {
            $events = $conn->table(self::T_EFFECTS . ' as e')->join(self::T_EVENTS . ' as ev', 'ev.event_uuid', '=', 'e.event_uuid')
                ->where('e.cloud_supplier_id', $cloudSupplierId)->whereNotIn('e.event_uuid', fn ($q) => $q->select('event_uuid')->from(self::T_APPLIED))
                ->orderBy('ev.id')->get(['ev.*', 'e.payable_delta']);
            $running = (float) $s->cloud_payable;
            foreach ($events as $ev) {
                $delta = (float) $ev->payable_delta;
                $running += $delta;
                $payload = json_decode((string) $ev->payload, true) ?: [];
                $provisional[] = [
                    'kind' => 'provisional', 'event_uuid' => (string) $ev->event_uuid, 'date' => Carbon::parse($ev->created_at)->toIso8601String(),
                    'entry_type' => $ev->event_type === EdgeSupplierFinanceEnvelopeBuilder::EVENT_PAYMENT ? 'payment' : 'journal_adjustment',
                    'reference_no' => $ev->reference_no ?: ($ev->event_type === EdgeSupplierFinanceEnvelopeBuilder::EVENT_PAYMENT ? strtoupper((string) ($payload['payment_method'] ?? '')) : null),
                    'description' => $ev->description ?: ($payload['notes'] ?? null),
                    'debit' => $delta > 0 ? round($delta, 2) : 0.0, 'credit' => $delta < 0 ? round(-$delta, 2) : 0.0,
                    'balance_after' => round($running, 2), 'user' => null, 'edge_event_uuid' => (string) $ev->event_uuid, 'sync' => $this->syncStateFor((string) $ev->event_uuid),
                ];
            }
        }
        $rows = array_merge(array_reverse($provisional), $official);

        return ['rows' => $rows, 'official_count' => count($official), 'provisional_count' => count($provisional)];
    }

    // ── handback / reconciliation ────────────────────────────────────────────────────────────────────────────────

    /**
     * What a controlled handback must know about supplier finance: events still syncing, events the Cloud refused
     * permanently, and divergence (an event without an outbox row; an acknowledged row whose ACK is not an applied
     * verdict; an acknowledged event the refreshed projection — taken after the ACK — does not list as applied).
     */
    public function handbackFindings(): array
    {
        $conn = DB::connection('tenant');
        $schemas = EdgeSupplierFinanceEnvelopeBuilder::SCHEMAS;
        $pending = EdgeSyncOutbox::on('tenant')->whereIn('envelope_schema_version', $schemas)->whereIn('state', [EdgeSyncOutbox::STATE_PENDING, EdgeSyncOutbox::STATE_LEASED])->count();
        $failed = EdgeSyncOutbox::on('tenant')->whereIn('envelope_schema_version', $schemas)->where('state', EdgeSyncOutbox::STATE_FAILED_PERMANENT)->count();
        $details = [];
        $divergent = 0;
        $meta = $this->context->current();
        $asOf = $meta && $meta->supplier_finance_cache_as_of ? Carbon::parse($meta->supplier_finance_cache_as_of) : null;
        $events = $conn->table(self::T_EVENTS)->orderBy('id')->get(['event_uuid', 'event_type', 'amount']);
        $outbox = EdgeSyncOutbox::on('tenant')->whereIn('sale_uuid', $events->pluck('event_uuid')->all())->get()->keyBy('sale_uuid');
        $applied = $conn->table(self::T_APPLIED)->whereIn('event_uuid', $events->pluck('event_uuid')->all())->pluck('event_uuid')->flip();
        foreach ($events as $ev) {
            $uuid = (string) $ev->event_uuid;
            $row = $outbox[$uuid] ?? null;
            if ($row === null) {
                $divergent++;
                $details[] = "{$ev->event_type} {$uuid} has no outbox row";
                continue;
            }
            if ($row->state === EdgeSyncOutbox::STATE_ACKNOWLEDGED) {
                $ack = is_string($row->ack_payload) ? json_decode($row->ack_payload, true) : $row->ack_payload;
                $status = is_array($ack) ? (string) ($ack['status'] ?? '') : '';
                if (! in_array($status, ['applied', 'already_applied'], true) || (string) ($ack['event_uuid'] ?? $ack['sale_uuid'] ?? '') !== $uuid) {
                    $divergent++;
                    $details[] = "{$ev->event_type} {$uuid} acknowledged without an applied verdict";
                    continue;
                }
                $ackedAt = $row->acknowledged_at ? Carbon::parse($row->acknowledged_at) : null;
                if ($asOf !== null && $ackedAt !== null && $asOf->greaterThan($ackedAt) && ! isset($applied[$uuid])) {
                    $divergent++;
                    $details[] = "{$ev->event_type} {$uuid} was acknowledged but the Cloud projection taken later does not list it as applied";
                }
            }
        }

        return ['pending' => $pending, 'failed' => $failed, 'divergent' => $divergent, 'details' => $details];
    }
}
