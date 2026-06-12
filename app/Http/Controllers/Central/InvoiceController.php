<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Master\PaymentGateway;
use App\Models\Master\Plan;
use App\Models\Master\SubscriptionInvoice;
use App\Models\Master\SubscriptionPayment;
use App\Models\Master\Tenant;
use App\Services\Saas\SubscriptionBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;

class InvoiceController extends Controller
{
    public function __construct(private readonly SubscriptionBillingService $billing) {}

    public function index(Request $request)
    {
        $query = SubscriptionInvoice::with(['tenant', 'plan', 'payments'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', (int) $request->tenant_id);
        }

        return view('central.invoices.index', [
            'invoices' => $query->paginate(20)->withQueryString(),
            'tenants'  => Tenant::orderBy('business_name')->get(),
        ]);
    }

    public function create(Tenant $tenant)
    {
        $tenant->load('subscription.plan');

        return view('central.invoices.create', [
            'tenant' => $tenant,
            'plans'  => Plan::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'plan_id'         => ['nullable', 'exists:plans,id'],
            'invoice_type'    => ['required', Rule::in(['subscription', 'upgrade', 'addon', 'manual'])],
            'currency_code'   => ['required', 'string', 'size:3'],
            'subtotal'        => ['required', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'tax_amount'      => ['nullable', 'numeric', 'min:0'],
            'period_start'    => ['nullable', 'date'],
            'period_end'      => ['nullable', 'date', 'after_or_equal:period_start'],
            'due_date'        => ['nullable', 'date'],
            'notes'           => ['nullable', 'string'],
        ]);

        $invoice = $this->billing->createInvoice($tenant, $data);

        return redirect(url('/invoices/' . $invoice->id))
            ->with('status', 'Invoice ' . $invoice->invoice_no . ' created.');
    }

    public function show(SubscriptionInvoice $invoice)
    {
        $invoice->load([
            'tenant',
            'subscription',
            'plan',
            'payments.gateway',
            'payments.verifiedBy',
        ]);

        return view('central.invoices.show', [
            'invoice'  => $invoice,
            'gateways' => PaymentGateway::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function storePayment(Request $request, SubscriptionInvoice $invoice)
    {
        $data = $request->validate([
            'payment_gateway_id'  => ['nullable', 'exists:payment_gateways,id'],
            'payment_method_code' => ['nullable', 'string', 'max:100'],
            'amount'              => ['required', 'numeric', 'min:0.01'],
            'currency_code'       => ['required', 'string', 'size:3'],
            'payment_date'        => ['required', 'date'],
            'reference_no'        => ['nullable', 'string', 'max:255'],
            'status'              => ['required', Rule::in(['pending', 'verified', 'rejected'])],
            'notes'               => ['nullable', 'string'],
        ]);

        try {
            $this->billing->recordPayment($invoice, $data);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['payment' => $e->getMessage()]);
        }

        return redirect(url('/invoices/' . $invoice->id))
            ->with('status', 'Payment recorded.');
    }

    public function void(SubscriptionInvoice $invoice)
    {
        try {
            $this->billing->voidInvoice($invoice);
        } catch (RuntimeException $e) {
            return back()->withErrors(['void' => $e->getMessage()]);
        }

        return redirect(url('/invoices/' . $invoice->id))
            ->with('status', 'Invoice voided.');
    }

    public function verifyPayment(SubscriptionInvoice $invoice, SubscriptionPayment $payment)
    {
        abort_unless((int) $payment->subscription_invoice_id === (int) $invoice->id, 404);

        $this->billing->verifyPayment($payment);

        return back()->with('status', 'Payment verified.');
    }

    public function rejectPayment(Request $request, SubscriptionInvoice $invoice, SubscriptionPayment $payment)
    {
        abort_unless((int) $payment->subscription_invoice_id === (int) $invoice->id, 404);

        $data = $request->validate([
            'notes' => ['nullable', 'string'],
        ]);

        $this->billing->rejectPayment($payment, $data['notes'] ?? null);

        return back()->with('status', 'Payment rejected.');
    }

    public function downloadPaymentProof(SubscriptionInvoice $invoice, SubscriptionPayment $payment)
    {
        abort_unless((int) $payment->subscription_invoice_id === (int) $invoice->id, 404);
        abort_unless($payment->proof_path, 404);

        return Storage::disk('local')->download(
            $payment->proof_path,
            $payment->proof_original_name ?: basename($payment->proof_path)
        );
    }
}
