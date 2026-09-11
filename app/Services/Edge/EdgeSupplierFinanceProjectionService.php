<?php

namespace App\Services\Edge;

use App\Models\Tenant\Branch;
use App\Models\Tenant\EdgeInboundSupplierFinanceIngestion;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\SupplierPayment;
use App\Services\Finance\SupplierPayableService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * OFFLINE EDGE — F2: the Cloud's READ-ONLY warm supplier-finance projection for one branch appliance.
 *
 * What the appliance needs to run "Record Supplier Payment" and a supplier-aware "General Journal" without
 * Internet — and nothing more (NOT the finance database): supplier identity / code / name / active state and the
 * AUTHORITATIVE payable balance; the open Purchase Bills (identity, number, outstanding) a payment may be allocated
 * to; the usable Cash/Bank account configuration (identity, COA mapping, active state); the active chart of accounts
 * with the canonical Accounts Payable family (2100 + descendants — SupplierPayableService::apAccountIds); the recent
 * official supplier ledger rows (bounded window) so the Edge Supplier Ledger page shows the Online ledger; and the
 * set of Edge events the Cloud has already APPLIED (so the appliance subtracts exactly the local events the Cloud
 * balance does not yet include — never double-applying after an ACK that precedes a refresh).
 *
 * The watermark is a content fingerprint advertised on every heartbeat; when it moves the standby pulls the package.
 */
class EdgeSupplierFinanceProjectionService
{
    public const PERMISSIONS = [
        'supplier_ledger' => 'tenant.suppliers.ledger',
        'supplier_payment' => 'tenant.supplier-payments.store',
        'manual_journal' => 'tenant.finance.manual-journals.store',
    ];

    public const PAYMENT_METHODS = ['cash', 'bank_transfer', 'cheque', 'card', 'other'];

    public function __construct(private readonly SupplierPayableService $supplierPayable)
    {
    }

    /** The live canonical rules the appliance must honour (mirrored, never re-invented). */
    public function rules(): array
    {
        return [
            'cash_bank_required' => true,             // SupplierPaymentController: cash_bank_account_id REQUIRED (active)
            'purchase_bill_optional' => true,         // purchase_bill_id nullable — payment on account
            'ledger_only_payment_possible' => false,  // no cash/bank → recordPayment rolls back (null GL = failure)
            'supplier_advance_supported' => false,    // SupplierPayableService::assertNoSupplierAdvance fails closed
            'payment_methods' => self::PAYMENT_METHODS,
            'ap_control_code' => '2100',
        ];
    }

    private function ledgerWindowStart(): Carbon
    {
        return now()->subDays(max(1, (int) config('edge.supplier_finance.ledger_window_days', 30)));
    }

    private function appliedWindowStart(): Carbon
    {
        return now()->subDays(max(1, (int) config('edge.supplier_finance.applied_window_days', 45)));
    }

    public function watermark(int $branchId): array
    {
        $conn = DB::connection('tenant');
        $parts = [];

        $s = $conn->table('suppliers')->whereNull('deleted_at')
            ->selectRaw('COUNT(*) c, COALESCE(MAX(id),0) mi, MAX(updated_at) mu, COALESCE(SUM(current_balance),0) sb, SUM(status = \'active\') a')->first();
        $parts[] = 'sup:' . $s->c . ':' . $s->mi . ':' . $s->mu . ':' . $s->sb . ':' . $s->a;

        $b = $conn->table('purchase_bills')->whereIn('status', ['posted', 'partial'])
            ->selectRaw('COUNT(*) c, COALESCE(MAX(id),0) mi, MAX(updated_at) mu, COALESCE(SUM(balance_due),0) sb, COALESCE(SUM(amount_paid),0) sp')->first();
        $parts[] = 'bill:' . $b->c . ':' . $b->mi . ':' . $b->mu . ':' . $b->sb . ':' . $b->sp;

        $cb = $conn->table('cash_bank_accounts')
            ->selectRaw('COUNT(*) c, COALESCE(MAX(id),0) mi, MAX(updated_at) mu, COALESCE(SUM(current_balance),0) sb, COALESCE(SUM(is_active),0) a, COALESCE(SUM(account_id),0) coa')->first();
        $parts[] = 'cb:' . $cb->c . ':' . $cb->mi . ':' . $cb->mu . ':' . $cb->sb . ':' . $cb->a . ':' . $cb->coa;

        $acc = $conn->table('accounts')->orderBy('id')->get(['id', 'code', 'parent_id', 'is_active', 'type', 'name'])
            ->map(fn ($a) => $a->id . ':' . $a->code . ':' . $a->parent_id . ':' . (int) $a->is_active . ':' . $a->type . ':' . $a->name)->implode(',');
        $parts[] = 'acc:' . substr(hash('sha256', $acc), 0, 24);

        $l = $conn->table('supplier_ledgers')->where('created_at', '>=', $this->ledgerWindowStart())
            ->selectRaw('COUNT(*) c, COALESCE(MAX(id),0) mi')->first();
        $parts[] = 'led:' . $l->c . ':' . $l->mi;

        $r = $conn->table('edge_inbound_supplier_finance_ingestions')->where('branch_id', $branchId)
            ->where('status', EdgeInboundSupplierFinanceIngestion::STATUS_APPLIED)
            ->selectRaw('COUNT(*) c, COALESCE(MAX(id),0) mi')->first();
        $parts[] = 'app:' . $r->c . ':' . $r->mi;
        $pr = $conn->table('edge_inbound_purchase_return_ingestions')->where('branch_id', $branchId)->where('status', 'applied')->selectRaw('COUNT(*) c, COALESCE(MAX(id),0) mi')->first();
        $parts[] = 'prapp:' . $pr->c . ':' . $pr->mi;

        return ['watermark' => 'sf:' . substr(hash('sha256', implode('|', $parts) . '|' . $branchId), 0, 40), 'as_of' => now()->toIso8601String()];
    }

    public function package(Branch $branch): array
    {
        $conn = DB::connection('tenant');
        $branchId = (int) $branch->id;
        $wm = $this->watermark($branchId);
        $apIds = $this->supplierPayable->apAccountIds();

        $suppliers = $conn->table('suppliers')->whereNull('deleted_at')->orderBy('name')->get()
            ->map(fn ($s) => [
                'cloud_supplier_id' => (int) $s->id,
                'code' => (string) $s->code,
                'name' => (string) $s->name,
                'status' => (string) $s->status,
                'current_balance' => round((float) $s->current_balance, 4),
                'updated_at' => $s->updated_at ? Carbon::parse($s->updated_at)->toIso8601String() : null,
            ])->values()->all();

        $bills = $conn->table('purchase_bills')->whereIn('status', ['posted', 'partial'])->orderByDesc('bill_date')->orderByDesc('id')->get()
            ->map(fn ($b) => [
                'cloud_bill_id' => (int) $b->id,
                'cloud_supplier_id' => (int) $b->supplier_id,
                'branch_id' => (int) $b->branch_id,
                'bill_no' => (string) $b->bill_no,
                'supplier_invoice_no' => $b->supplier_invoice_no,
                'bill_date' => $b->bill_date ? Carbon::parse($b->bill_date)->toDateString() : null,
                'due_date' => $b->due_date ? Carbon::parse($b->due_date)->toDateString() : null,
                'status' => (string) $b->status,
                'grand_total' => round((float) $b->grand_total, 4),
                'amount_paid' => round((float) $b->amount_paid, 4),
                'balance_due' => round((float) $b->balance_due, 4),
                'updated_at' => $b->updated_at ? Carbon::parse($b->updated_at)->toIso8601String() : null,
            ])->values()->all();

        $coaCodes = $conn->table('accounts')->pluck('code', 'id');
        $cashBank = $conn->table('cash_bank_accounts')->orderBy('code')->get()
            ->map(fn ($c) => [
                'cloud_cash_bank_account_id' => (int) $c->id,
                'code' => (string) $c->code,
                'name' => (string) $c->name,
                'account_type' => (string) $c->account_type,
                'branch_id' => $c->branch_id !== null ? (int) $c->branch_id : null,
                'coa_account_id' => $c->account_id !== null ? (int) $c->account_id : null,
                'coa_code' => $c->account_id !== null ? (string) ($coaCodes[(int) $c->account_id] ?? '') : null,
                'bank_name' => $c->bank_name,
                'is_default' => (bool) $c->is_default,
                'is_active' => (bool) $c->is_active,
                'current_balance' => round((float) $c->current_balance, 4),
                'updated_at' => $c->updated_at ? Carbon::parse($c->updated_at)->toIso8601String() : null,
            ])->values()->all();

        $accounts = $conn->table('accounts')->orderBy('sort_order')->orderBy('code')->get()
            ->map(fn ($a) => [
                'cloud_account_id' => (int) $a->id,
                'code' => (string) $a->code,
                'name' => (string) $a->name,
                'type' => (string) $a->type,
                'normal_balance' => (string) $a->normal_balance,
                'parent_cloud_account_id' => $a->parent_id !== null ? (int) $a->parent_id : null,
                'is_active' => (bool) $a->is_active,
                'is_ap' => in_array((int) $a->id, $apIds, true),
                'sort_order' => (int) $a->sort_order,
            ])->values()->all();

        // Recent OFFICIAL supplier ledger rows (bounded), labelled with the Edge event they came from where applicable.
        $perSupplier = max(10, (int) config('edge.supplier_finance.ledger_max_rows_per_supplier', 100));
        $ledgerRows = $conn->table('supplier_ledgers as l')->leftJoin('users as u', 'u.id', '=', 'l.created_by_user_id')
            ->where('l.created_at', '>=', $this->ledgerWindowStart())
            ->orderByDesc('l.id')
            ->get(['l.*', 'u.name as created_by_name']);
        $paymentIds = $ledgerRows->where('reference_type', SupplierPayment::class)->pluck('reference_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $journalIds = $ledgerRows->where('reference_type', JournalEntry::class)->pluck('reference_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $eventByPayment = $paymentIds === [] ? collect() : EdgeInboundSupplierFinanceIngestion::query()->where('status', EdgeInboundSupplierFinanceIngestion::STATUS_APPLIED)
            ->whereIn('supplier_payment_id', $paymentIds)->pluck('event_uuid', 'supplier_payment_id');
        $eventByJournal = $journalIds === [] ? collect() : EdgeInboundSupplierFinanceIngestion::query()->where('status', EdgeInboundSupplierFinanceIngestion::STATUS_APPLIED)
            ->whereIn('journal_entry_id', $journalIds)->pluck('event_uuid', 'journal_entry_id');
        $counts = [];
        $ledger = [];
        foreach ($ledgerRows as $l) {
            $sid = (int) $l->supplier_id;
            $counts[$sid] = ($counts[$sid] ?? 0) + 1;
            if ($counts[$sid] > $perSupplier) {
                continue;
            }
            $eventUuid = null;
            if ((string) $l->reference_type === SupplierPayment::class) {
                $eventUuid = $eventByPayment[(int) $l->reference_id] ?? null;
            } elseif ((string) $l->reference_type === JournalEntry::class) {
                $eventUuid = $eventByJournal[(int) $l->reference_id] ?? null;
            }
            $ledger[] = [
                'cloud_ledger_id' => (int) $l->id,
                'cloud_supplier_id' => $sid,
                'entry_type' => (string) $l->entry_type,
                'direction' => (string) $l->direction,
                'amount' => round((float) $l->amount, 4),
                'balance_after' => round((float) $l->balance_after, 4),
                'reference_type' => $l->reference_type,
                'reference_id' => $l->reference_id !== null ? (int) $l->reference_id : null,
                'reference_no' => $l->reference_no,
                'notes' => $l->notes,
                'created_by_name' => $l->created_by_name,
                'edge_event_uuid' => $eventUuid !== null ? (string) $eventUuid : null,
                'created_at' => $l->created_at ? Carbon::parse($l->created_at)->toIso8601String() : null,
            ];
        }

        // F3 purchase returns credit the supplier through the same subledger: their applied events belong to this set too, so
        // the appliance stops subtracting a pending purchase return exactly when the Cloud payable includes it.
        $appliedPurchaseReturns = \App\Models\Tenant\EdgeInboundPurchaseReturnIngestion::query()
            ->where('branch_id', $branchId)->where('status', \App\Models\Tenant\EdgeInboundPurchaseReturnIngestion::STATUS_APPLIED)
            ->where('ingested_at', '>=', $this->appliedWindowStart())
            ->orderBy('id')->get(['event_uuid', 'official_return_no', 'ingested_at'])
            ->map(fn ($r) => ['event_uuid' => (string) $r->event_uuid, 'event_type' => 'purchase_return', 'official_reference_no' => $r->official_return_no, 'applied_at' => $r->ingested_at?->toIso8601String()])->values()->all();
        $applied = EdgeInboundSupplierFinanceIngestion::query()
            ->where('branch_id', $branchId)->where('status', EdgeInboundSupplierFinanceIngestion::STATUS_APPLIED)
            ->where('ingested_at', '>=', $this->appliedWindowStart())
            ->orderBy('id')->get(['event_uuid', 'event_type', 'official_reference_no', 'ingested_at'])
            ->map(fn ($r) => [
                'event_uuid' => (string) $r->event_uuid,
                'event_type' => (string) $r->event_type,
                'official_reference_no' => $r->official_reference_no,
                'applied_at' => $r->ingested_at?->toIso8601String(),
            ])->values()->all();

        return [
            'branch_id' => $branchId,
            'watermark' => $wm['watermark'],
            'as_of' => $wm['as_of'],
            'ledger_window_days' => (int) config('edge.supplier_finance.ledger_window_days', 30),
            'applied_window_days' => (int) config('edge.supplier_finance.applied_window_days', 45),
            'rules' => $this->rules(),
            'permissions' => self::PERMISSIONS,
            'ap_account_ids' => array_values($apIds),
            'suppliers' => $suppliers,
            'bills' => $bills,
            'cash_bank_accounts' => $cashBank,
            'accounts' => $accounts,
            'ledger_entries' => $ledger,
            'applied_events' => array_merge($applied, $appliedPurchaseReturns),
        ];
    }
}
