<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Master\SubscriptionInvoice;
use App\Models\Master\SubscriptionPayment;
use App\Services\Saas\SubscriptionBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class TenantBillingController extends Controller
{
    public function __construct(private readonly SubscriptionBillingService $billing) {}

    public function index()
    {
        $tenant = app('tenant');
        $tenant->loadMissing('subscription.plan');

        $invoices = SubscriptionInvoice::with('plan')
            ->where('tenant_id', $tenant->id)
            ->latest()
            ->paginate(20);

        return view('tenant.billing.index', [
            'tenant'   => $tenant,
            'invoices' => $invoices,
        ]);
    }

    public function show(SubscriptionInvoice $invoice)
    {
        $this->ensureTenantInvoice($invoice);

        $invoice->load(['plan', 'payments.gateway']);

        return view('tenant.billing.show', [
            'invoice' => $invoice,
        ]);
    }

    public function uploadPaymentProof(Request $request, SubscriptionInvoice $invoice)
    {
        $this->ensureTenantInvoice($invoice);

        $data = $request->validate([
            'amount'              => ['required', 'numeric', 'min:0.01'],
            'currency_code'       => ['required', 'string', 'size:3'],
            'payment_method_code' => ['required', 'string', 'max:100'],
            'payment_date'        => ['required', 'date'],
            'reference_no'        => ['nullable', 'string', 'max:255'],
            'notes'               => ['nullable', 'string'],
            // PROD-READINESS-1: extension + real-mimetype double check (a renamed
            // .exe/.php passes a naive extension check but not mimetypes), 5MB cap.
            'proof'               => [
                'required', 'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'mimetypes:image/jpeg,image/png,image/webp,application/pdf',
                'max:5120',
            ],
        ], [
            'proof.mimes'     => 'Proof must be a JPG, PNG, WEBP image or a PDF.',
            'proof.mimetypes' => 'The uploaded file content is not a valid image or PDF.',
            'proof.max'       => 'Proof file may not be larger than 5 MB.',
        ]);

        try {
            $this->billing->recordTenantProofPayment(
                $invoice,
                app('tenant'),
                $data,
                $request->file('proof')
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['proof' => $e->getMessage()]);
        }

        return redirect(url('/billing/invoices/' . $invoice->id))
            ->with('status', 'Payment proof uploaded. It will be verified by the provider.');
    }

    public function downloadProof(SubscriptionInvoice $invoice, SubscriptionPayment $payment)
    {
        $this->ensureTenantInvoice($invoice);

        abort_unless(
            (int) $payment->subscription_invoice_id === (int) $invoice->id
            && (int) $payment->tenant_id === (int) app('tenant')->id,
            404
        );

        abort_unless($payment->proof_path, 404);

        return Storage::disk('local')->download(
            $payment->proof_path,
            $payment->proof_original_name ?: basename($payment->proof_path)
        );
    }

    private function ensureTenantInvoice(SubscriptionInvoice $invoice): void
    {
        abort_unless((int) $invoice->tenant_id === (int) app('tenant')->id, 404);
    }
}
