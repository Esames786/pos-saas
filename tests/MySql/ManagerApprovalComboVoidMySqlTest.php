<?php

namespace Tests\MySql;

use App\Models\Tenant\ManagerApproval;
use App\Models\Tenant\SalesOrder;
use App\Services\Sales\KotCancellationService;
use App\Services\Sales\ManagerApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * MANAGER-APPROVAL-COMBO-VOID-1 — "Manager approval does not authorize this action".
 *
 * Maalik ki shikayat: cashier deal ki quantity kam kare, manager PIN daale, aur bill aage na
 * barhe. Kal raat (18 Sep) aath baar PIN daala gaya, aath baar rad hua, aur aakhir cashier ko
 * poora order cancel karna para.
 *
 * Sabab: client aur server DO ALAG sawaalon par faisla karte thay. POS poochta hai "ye combo
 * hai?" — combo ho to hamesha `void_kot_items` (jama) approval banwata hai. Server poochta tha
 * "kitni lines cancel hueen?" — ek ho to `void_kot_item` (wahid) maangta tha. Jis combo me
 * sirf EK line cancel hoti, dono ka jawab alag ho jata aur consume() ki pehli shart toot jati.
 *
 * Prod par sabit: `void_kot_items` jin ke payload me ek line thi — 29 me se 29 rad, sifar
 * istisna. Jin me do ya zyada thin, sab kaamyab.
 *
 * Doc: docs/plans/manager-approval-combo-void-2026-09-19.md
 *
 * ⚠️ Is file ke aakhri paanch test us DARWAZE par pehra dete hain jo is hal se khul sakta tha.
 * Agar wo RED na hon to "hal" dar-asal manzoori ko bemani kar raha hoga.
 */
class ManagerApprovalComboVoidMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $cashierId;
    private int $managerId;
    private int $productId;
    private int $reasonId;
    private int $terminalId;

    protected function setUp(): void
    {
        parent::setUp();
        // ⚠️ Spatie ka registrar DEFAULT connection se permissions parhta hai. Test me default
        // `master` hai, is liye ye satar ke baghair grant tenant par jata hai magar check master
        // par hota hai — aur `can()` hamesha false. Prod me ye masla nahi kyunki wahan tenant hi
        // default hota hai.
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'sales_order_line_cancellations', 'manager_approvals', 'manager_pins', 'print_jobs',
            'sale_payments', 'sales_order_lines', 'sales_orders', 'void_reasons', 'shifts',
            'terminals', 'products', 'categories', 'model_has_permissions', 'branches', 'users',
        ]);
        // ⚠️ `permissions` is NOT in that list, deliberately — it is migration-owned and emptying
        // it strips other suites' permission sets out from under them.

        // Line cancel par manager ki manzoori lazmi — bilkul Kashif Food ki tarah.
        $this->branchId = $this->makeBranch(['held_kot_line_cancellation_approval_mode' => 'manager_required']);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->cashierId = $this->makeUser(['default_branch_id' => $this->branchId]);
        $this->managerId = $this->makeUser(['default_branch_id' => $this->branchId]);
        $this->productId = $this->makeProduct($this->makeCategory());

        DB::connection('tenant')->table('manager_pins')->insert([
            'user_id' => $this->managerId, 'pin_hash' => Hash::make('password@'),
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->reasonId = (int) DB::connection('tenant')->table('void_reasons')->insertGetId([
            'name' => 'Customer changed mind', 'reason_type' => 'void',
            'requires_manager_approval' => 1, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // ⚠️ Tarteeb ahem hai: guard ka context grant se PEHLE. Spatie default guard se tay
        // karta hai ke permission kis guard ki hai; pehle grant kar do to wo kisi aur guard par
        // chali jati hai aur `can()` phir bhi `false` kehta hai.
        $cashier = \App\Models\Tenant\User::on('tenant')->findOrFail($this->cashierId);
        \Illuminate\Support\Facades\Auth::shouldUse('tenant');
        $this->actingAs($cashier, 'tenant');

        $this->grantVoidPermission($this->cashierId);

        // Sabit karo ke harness waqai wo kar paya jo us ne kehna tha — warna neeche ka har
        // "rad ho gaya" wala test permission ki wajah se pass hota rehta, bug ki wajah se nahi.
        $this->assertTrue(
            \App\Models\Tenant\User::on('tenant')->findOrFail($this->cashierId)->can('tenant.pos.void-kot-item'),
            'fixture khud toota hua hai: cashier ke paas void ki permission honi chahiye'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Jo toota hua tha
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * 🚨 ASAL GUARD — ek component wale deal ki cancellation.
     *
     * POS combo par HAMESHA `void_kot_items` banwata hai. Agar us combo ka sirf ek component
     * kitchen gaya ho (ya deal ka component hi ek ho — Kashif Food par aise 12 deals hain,
     * jin me "Singaporean Rice (Regular) (Midnight)" bhi hai), to server ke paas ek hi line
     * aati hai. Pehle yahi surat rad hoti thi.
     *
     * Ye test aaj ke code par RED hai.
     */
    public function test_ek_line_wali_cancellation_jama_manzoori_se_chalti_hai(): void
    {
        $sale = $this->heldSaleWithLines([2.0]);
        $line = $sale->lines->first();

        $approval = $this->approve('void_kot_items', [
            'sales_order_id' => $sale->id,
            'cancellations'  => [['line_id' => (int) $line->id, 'quantity' => 2.0]],
        ]);

        $this->cancel($sale, [$this->entry($line->id, 2.0, $approval->id)]);

        $this->assertNotNull(ManagerApproval::find($approval->id)->consumed_at,
            'ek line wali jama manzoori istemal honi chahiye — yahi wo surat hai jo tooti thi');
        $this->assertSame(1, DB::connection('tenant')->table('sales_order_line_cancellations')
            ->where('sales_order_line_id', $line->id)->count(),
            'cancellation waqai darj honi chahiye, sirf approval nishan-zada nahi');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Jo pehle se chal raha tha — na tootay
    // ══════════════════════════════════════════════════════════════════════════

    /** Sadi line ka raasta (prod par 406 rows) jyun ka tyun chale. */
    public function test_sadi_line_wahid_manzoori_se_ab_bhi_chalti_hai(): void
    {
        $sale = $this->heldSaleWithLines([3.0]);
        $line = $sale->lines->first();

        $approval = $this->approve('void_kot_item', [
            'sales_order_id'      => $sale->id,
            'sales_order_line_id' => (int) $line->id,
            'quantity'            => 3.0,
        ]);

        $this->cancel($sale, [$this->entry($line->id, 3.0, $approval->id)]);

        $this->assertNotNull(ManagerApproval::find($approval->id)->consumed_at);
    }

    /** Kai lines wala combo (prod par 17 me se 14 kaamyab) jyun ka tyun chale. */
    public function test_kai_lines_jama_manzoori_se_ab_bhi_chalti_hain(): void
    {
        $sale = $this->heldSaleWithLines([2.0, 1.0]);
        [$a, $b] = [$sale->lines[0], $sale->lines[1]];

        $approval = $this->approve('void_kot_items', [
            'sales_order_id' => $sale->id,
            'cancellations'  => collect([
                ['line_id' => (int) $a->id, 'quantity' => 2.0],
                ['line_id' => (int) $b->id, 'quantity' => 1.0],
            ])->sortBy('line_id')->values()->all(),
        ]);

        $this->cancel($sale, [
            $this->entry($a->id, 2.0, $approval->id),
            $this->entry($b->id, 1.0, $approval->id),
        ]);

        $this->assertNotNull(ManagerApproval::find($approval->id)->consumed_at);
        $this->assertSame(2, DB::connection('tenant')->table('sales_order_line_cancellations')->count());
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 🚨 Wo darwaza jo is hal se khul sakta tha — paanch taale
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Kai lines par WAHID manzoori qubool na ho.
     *
     * Ye sab se ahem taala hai. Wahid payload sirf EK line pin karta hai. Agar usay kai lines
     * par qubool kar liya jaye to manager ne ek line dekhi hoti aur cancel kai hotin — yani
     * manzoori ka matlab hi khatam.
     */
    public function test_kai_lines_par_wahid_manzoori_qubool_nahi_hoti(): void
    {
        $sale = $this->heldSaleWithLines([2.0, 1.0]);
        [$a, $b] = [$sale->lines[0], $sale->lines[1]];

        $approval = $this->approve('void_kot_item', [
            'sales_order_id'      => $sale->id,
            'sales_order_line_id' => (int) $a->id,
            'quantity'            => 2.0,
        ]);

        $this->assertRejectedWith('grouped cancellation', function () use ($sale, $a, $b, $approval) {
            $this->cancel($sale, [
                $this->entry($a->id, 2.0, $approval->id),
                $this->entry($b->id, 1.0, $approval->id),
            ]);
        });
    }

    /** Manzoori jis quantity par mili thi, cancel usi ki ho. */
    public function test_quantity_badal_do_to_manzoori_rad_ho_jati_hai(): void
    {
        $sale = $this->heldSaleWithLines([5.0]);
        $line = $sale->lines->first();

        $approval = $this->approve('void_kot_items', [
            'sales_order_id' => $sale->id,
            'cancellations'  => [['line_id' => (int) $line->id, 'quantity' => 1.0]],
        ]);

        $this->assertRejectedWith('does not match this action', function () use ($sale, $line, $approval) {
            $this->cancel($sale, [$this->entry($line->id, 4.0, $approval->id)]);
        });
    }

    /** Doosre cashier ki manzoori se cancel na ho. */
    public function test_doosre_cashier_ki_manzoori_rad_hoti_hai(): void
    {
        $sale = $this->heldSaleWithLines([1.0]);
        $line = $sale->lines->first();
        $doosra = $this->makeUser(['default_branch_id' => $this->branchId]);

        $approval = $this->approve('void_kot_items', [
            'sales_order_id' => $sale->id,
            'cancellations'  => [['line_id' => (int) $line->id, 'quantity' => 1.0]],
        ], $doosra);

        $this->assertRejectedWith('another cashier request', function () use ($sale, $line, $approval) {
            $this->cancel($sale, [$this->entry($line->id, 1.0, $approval->id)]);
        });
    }

    /** Ek manzoori sirf ek baar. */
    public function test_ek_manzoori_dobara_istemal_nahi_hoti(): void
    {
        $sale = $this->heldSaleWithLines([4.0]);
        $line = $sale->lines->first();

        $approval = $this->approve('void_kot_items', [
            'sales_order_id' => $sale->id,
            'cancellations'  => [['line_id' => (int) $line->id, 'quantity' => 1.0]],
        ]);

        $this->cancel($sale, [$this->entry($line->id, 1.0, $approval->id)]);

        $this->assertRejectedWith('already been used', function () use ($sale, $line, $approval) {
            $this->cancel($sale->fresh('lines'), [$this->entry($line->id, 1.0, $approval->id)]);
        });
    }

    /** Ek order ki manzoori doosre order par na chale. */
    public function test_ek_order_ki_manzoori_doosre_order_par_nahi_chalti(): void
    {
        $sale  = $this->heldSaleWithLines([1.0]);
        $doosra = $this->heldSaleWithLines([1.0]);
        $line  = $doosra->lines->first();

        $approval = $this->approve('void_kot_items', [
            'sales_order_id' => $sale->id,
            'cancellations'  => [['line_id' => (int) $sale->lines->first()->id, 'quantity' => 1.0]],
        ]);

        $this->assertRejectedWith('does not match this action', function () use ($doosra, $line, $approval) {
            $this->cancel($doosra, [$this->entry($line->id, 1.0, $approval->id)]);
        });
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Madadgar
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Rad hona kaafi nahi — SAHI wajah se rad hona chahiye.
     *
     * ⚠️ Sirf `expectException(ValidationException::class)` likhna yahan BE-MAANI hota: is raaste
     * par permission ki kami, ghalat reason id, "quantity kitchen se zyada" — sab wohi exception
     * phenkte hain. Aisa test tab bhi hara rehta jab manzoori ki bandish poori tarah toot chuki
     * ho, kyunki koi aur cheez pehle hi gir jaati. Is liye har guard apni asal wajah par bandha
     * hai, aur `$needle` na milne par test us waqt ka asal paigham dikhata hai.
     */
    private function assertRejectedWith(string $needle, callable $act): void
    {
        try {
            $act();
        } catch (ValidationException $e) {
            $all = collect($e->errors())->flatten()->implode(' | ');
            $this->assertStringContainsString($needle, $all,
                "rad to hua magar DOOSRI wajah se — mila: {$all}");

            return;
        }

        $this->fail("ye rad hona chahiye tha ({$needle}) magar chal gaya");
    }

    /** Wohi method jo HeldSaleController::store cancellation ke liye call karta hai. */
    private function cancel(SalesOrder $sale, array $cancellations): void
    {
        app(KotCancellationService::class)->recordLineCancellations(
            $sale->loadMissing('lines', 'branch'),
            $cancellations,
            $this->cashierId,
            (string) $this->terminalId,
        );
    }

    private function entry(int $lineId, float $quantity, int $approvalId): array
    {
        return [
            'line_id' => $lineId, 'quantity' => $quantity,
            'reason_id' => $this->reasonId, 'manager_approval_id' => $approvalId,
        ];
    }

    /** Asli PIN raaste se manzoori — koi banawati row nahi. */
    private function approve(string $actionType, array $payload, ?int $requestedBy = null): ManagerApproval
    {
        return app(ManagerApprovalService::class)
            ->verifyPin('password@', $actionType, $requestedBy ?? $this->cashierId, $payload);
    }

    /** Held sale jis ki har line kitchen ja chuki ho. */
    private function heldSaleWithLines(array $sentQuantities): SalesOrder
    {
        $saleId = $this->makeSale($this->branchId, [
            'status' => 'held', 'order_type' => 'dine_in', 'terminal_id' => $this->terminalId,
        ]);

        foreach ($sentQuantities as $qty) {
            $this->makeSaleLine($saleId, $this->productId, [
                'quantity' => $qty, 'kot_sent_quantity' => $qty,
            ]);
        }

        return SalesOrder::on('tenant')->with(['lines', 'branch'])->findOrFail($saleId);
    }

    /**
     * ⚠️ `model_has_permissions` me seedha row daalna kaam NAHI karta — Spatie apne rishte khud
     * banata hai aur apni cache row bhi rakhta hai. Yehi wohi tareeqa hai jo
     * CancelFreesTableMySqlTest par chal raha hai.
     *
     * `permissions` table ko kabhi truncate na karna: wo migration ki milkiyat hai aur us ko
     * khali karne se doosre suites (catering ka permission set) tootte hain.
     */
    private function grantVoidPermission(int $userId): void
    {
        DB::connection('tenant')->table('cache')->where('key', 'like', '%spatie.permission.cache%')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        \App\Models\Tenant\User::on('tenant')->findOrFail($userId)->givePermissionTo(
            \Spatie\Permission\Models\Permission::on('tenant')->firstOrCreate(
                ['name' => 'tenant.pos.void-kot-item', 'guard_name' => 'tenant']
            )
        );
    }
}
