<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\TenantBillingController;
use App\Models\Master\BillingPaymentMethod;
use App\Models\Master\SubscriptionInvoice;
use App\Models\Master\SubscriptionPayment;
use App\Models\Master\Tenant;
use App\Services\Saas\SubscriptionBillingService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * CLOUD-BILLING-1A-HARDEN — tenant proof-upload authorization + isolation.
 *
 * Proves the tenant billing surface enforces:
 *   • a tenant cannot upload proof for, or read proof of, ANOTHER tenant's invoice/payment (404);
 *   • an inactive OR incomplete directory method is refused at proof time (server-side, not hide-only);
 *   • a fully-paid / void invoice refuses new proof;
 *   • proof lands ONLY on the private `local` disk, never a public one.
 *
 * These controller/service paths touch the MASTER DB + storage only (no tenant DB), so the tenant is
 * bound directly (app()->instance('tenant', ...)) rather than provisioning a second tenant database.
 * No real account details — placeholders only.
 */
class CloudBillingProofAuthorizationMySqlTest extends MySqlTenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::connection('master')->table('subscription_payments')->delete();
        DB::connection('master')->table('subscription_invoices')->delete();
        DB::connection('master')->table('billing_payment_methods')->delete();
        DB::connection('master')->table('tenants')->delete();
        $this->startSession();
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant');
        parent::tearDown();
    }

    public function test_a_tenant_cannot_upload_proof_for_another_tenants_invoice(): void
    {
        [$tenantA] = $this->makeTenant('aa');
        [$tenantB, $invoiceB] = $this->makeTenant('bb', withInvoice: true);

        app()->instance('tenant', $tenantA); // acting as A, target B's invoice
        $request = $this->proofRequest($invoiceB, $this->activeMethodCode());

        $this->expectException(NotFoundHttpException::class);
        try {
            app(TenantBillingController::class)->uploadPaymentProof($request, $invoiceB);
        } finally {
            $this->assertSame(0, SubscriptionPayment::count(), 'no payment persisted for a foreign invoice');
        }
    }

    public function test_a_tenant_cannot_read_another_tenants_proof(): void
    {
        [$tenantA] = $this->makeTenant('aa');
        [$tenantB, $invoiceB] = $this->makeTenant('bb', withInvoice: true);
        $paymentB = SubscriptionPayment::create([
            'subscription_invoice_id' => $invoiceB->id, 'tenant_id' => $tenantB->id,
            'payment_method_code' => 'easypaisa', 'amount' => 100, 'currency_code' => 'PKR',
            'payment_date' => now()->toDateString(), 'status' => 'pending',
            'proof_path' => 'billing-proofs/'.$tenantB->id.'/'.$invoiceB->id.'/x.jpg',
        ]);

        app()->instance('tenant', $tenantA); // acting as A, target B's invoice+proof

        $this->expectException(NotFoundHttpException::class);
        app(TenantBillingController::class)->downloadProof($invoiceB, $paymentB);
    }

    public function test_an_inactive_or_incomplete_method_is_refused_at_proof_time(): void
    {
        [$tenant, $invoice] = $this->makeTenant('cc', withInvoice: true);
        // inactive-but-configured, and active-but-incomplete — both must be refused
        BillingPaymentMethod::create(['code' => 'inactive_pm', 'display_name' => 'Inactive', 'is_active' => false, 'account_title' => 'H', 'account_number' => '0000000000']);
        BillingPaymentMethod::create(['code' => 'incomplete_pm', 'display_name' => 'Incomplete', 'is_active' => true]);

        app()->instance('tenant', $tenant);

        foreach (['inactive_pm', 'incomplete_pm', 'does_not_exist'] as $code) {
            $request = $this->proofRequest($invoice, $code);
            $response = app(TenantBillingController::class)->uploadPaymentProof($request, $invoice);
            $this->assertSame(302, $response->getStatusCode(), "code {$code} must be refused");
        }
        $this->assertSame(0, SubscriptionPayment::count(), 'no payment persisted for a refused method');
    }

    public function test_a_paid_invoice_refuses_new_proof(): void
    {
        [$tenant, $invoice] = $this->makeTenant('dd', withInvoice: true);
        $invoice->update(['status' => 'paid', 'paid_amount' => 100, 'balance_amount' => 0, 'paid_at' => now()]);
        $this->activeMethodCode();

        app()->instance('tenant', $tenant);
        $request = $this->proofRequest($invoice, 'easypaisa');
        $response = app(TenantBillingController::class)->uploadPaymentProof($request, $invoice);

        // recordTenantProofPayment throws RuntimeException -> controller returns back() with an error.
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(0, SubscriptionPayment::count(), 'a paid invoice accepts no new proof');
    }

    public function test_proof_is_stored_only_on_the_private_local_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        [$tenant, $invoice] = $this->makeTenant('ee', withInvoice: true);

        $payment = app(SubscriptionBillingService::class)->recordTenantProofPayment(
            $invoice,
            $tenant,
            ['amount' => 100, 'currency_code' => 'PKR', 'payment_method_code' => 'easypaisa', 'payment_date' => now()->toDateString()],
            UploadedFile::fake()->image('proof.jpg', 20, 20)
        );

        $this->assertNotNull($payment->proof_path);
        $this->assertStringStartsWith('billing-proofs/'.$tenant->id.'/'.$invoice->id, $payment->proof_path);
        Storage::disk('local')->assertExists($payment->proof_path);
        // Nothing leaks to a public disk.
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private function activeMethodCode(): string
    {
        BillingPaymentMethod::firstOrCreate(
            ['code' => 'easypaisa'],
            ['display_name' => 'EasyPaisa', 'is_active' => true, 'account_title' => 'Holder', 'account_number' => '0000000000']
        );

        return 'easypaisa';
    }

    private function proofRequest(SubscriptionInvoice $invoice, string $methodCode): Request
    {
        $request = Request::create("/billing/invoices/{$invoice->id}/payments", 'POST', [
            'amount' => 100, 'currency_code' => 'PKR', 'payment_method_code' => $methodCode,
            'payment_date' => now()->toDateString(),
        ]);
        $request->files->set('proof', UploadedFile::fake()->image('proof.jpg', 20, 20));
        $request->setLaravelSession($this->app['session.store']);
        app()->instance('request', $request);

        return $request;
    }

    /** @return array{0: Tenant, 1: ?SubscriptionInvoice} */
    private function makeTenant(string $prefix, bool $withInvoice = false): array
    {
        $tenant = Tenant::create([
            'tenant_code' => $prefix.substr(uniqid(), -6),
            'business_name' => strtoupper($prefix).' Co',
            'owner_name' => 'Owner',
            'owner_email' => $prefix.'_'.uniqid().'@test.local',
            'currency_code' => 'PKR',
            'status' => 'active',
        ]);

        $invoice = null;
        if ($withInvoice) {
            $invoice = SubscriptionInvoice::create([
                'invoice_no' => 'INV-'.strtoupper($prefix).'-'.substr(uniqid(), -6),
                'tenant_id' => $tenant->id,
                'invoice_type' => 'subscription',
                'status' => 'issued',
                'currency_code' => 'PKR',
                'subtotal' => 100, 'total_amount' => 100, 'balance_amount' => 100,
            ]);
        }

        return [$tenant, $invoice];
    }
}
