<?php

namespace App\Http\Controllers;

use App\Http\Requests\Public\StartTrialRequest;
use App\Models\Master\Plan;
use App\Models\Master\Tenant;
use App\Services\Saas\SelfSignupService;
use Illuminate\Http\Request;
use Throwable;

class PublicSiteController extends Controller
{
    public function home()
    {
        return view('public.home', [
            'plans'            => $this->publicPlans(),
            'selfServicePlans' => $this->selfServicePlans(),
            'customPlans'      => $this->customPlans(),
        ]);
    }

    public function pricing()
    {
        return view('public.pricing', [
            'plans'            => $this->publicPlans(),
            'selfServicePlans' => $this->selfServicePlans(),
            'customPlans'      => $this->customPlans(),
        ]);
    }

    public function features()
    {
        return view('public.features', [
            'plans' => $this->publicPlans(),
        ]);
    }

    public function trialCreate(Request $request)
    {
        $plans = $this->selfServicePlans();

        $selectedPlan = null;

        if ($request->filled('plan')) {
            $selectedPlan = $plans->firstWhere('code', $request->query('plan'));
        }

        if (! $selectedPlan && $request->filled('plan_id')) {
            $selectedPlan = $plans->firstWhere('id', (int) $request->query('plan_id'));
        }

        $selectedPlan = $selectedPlan ?: $plans->first();

        $enterpriseRequested = $request->query('plan') === 'enterprise';

        return view('public.start-trial', compact('plans', 'selectedPlan', 'enterpriseRequested'));
    }

    public function contact()
    {
        return view('public.contact', [
            'customPlans' => $this->customPlans(),
        ]);
    }

    // ── Legal / policy pages (PRD-4) ────────────────────────────────────────

    public function terms()
    {
        return view('public.terms');
    }

    public function privacy()
    {
        return view('public.privacy');
    }

    public function refundPolicy()
    {
        return view('public.refund-policy');
    }

    public function supportPolicy()
    {
        return view('public.support-policy');
    }

    public function demos()
    {
        $cards = config('saas.demos.cards', []);
        $password = config('saas.demos.default_password', 'demo1234');
        $baseDomain = config('tenancy.tenant_base_domain', request()->getHost());
        $scheme = request()->getScheme();

        // Load the demo tenants we care about in one master-DB query.
        $codes = collect($cards)->pluck('tenant_code')->filter()->all();

        $tenants = Tenant::with('subscription')
            ->whereIn('tenant_code', $codes)
            ->get()
            ->keyBy('tenant_code');

        $demos = [];

        foreach ($cards as $key => $card) {
            $tenantCode = $card['tenant_code'] ?? null;
            $tenant = $tenantCode ? $tenants->get($tenantCode) : null;

            $available = $tenant
                && $tenant->isDemo()
                && $tenant->status === 'active'
                && optional($tenant->subscription)->status === 'active';

            $demos[] = array_merge($card, [
                'key'       => $key,
                'available' => $available,
                'login_url' => $tenantCode ? "{$scheme}://{$tenantCode}.{$baseDomain}/login" : null,
                'password'  => $password,
            ]);
        }

        return view('public.demos', [
            'demos'       => $demos,
            'selfServicePlans' => $this->selfServicePlans(),
        ]);
    }

    public function trialStore(StartTrialRequest $request, SelfSignupService $signup)
    {
        $data = $request->signupData();

        try {
            $tenant = $signup->registerTrial($data);
        } catch (Throwable $e) {
            report($e);

            return back()
                ->withInput($request->except(['password', 'password_confirmation']))
                ->withErrors([
                    'signup' => 'We could not create your trial right now. Please try again or contact support.',
                ]);
        }

        $domain = $tenant->domains->first()?->domain;
        $loginUrl = 'http://' . $domain . '/login';

        return redirect(url('/trial/success'))->with([
            'trial_login_url'     => $loginUrl,
            'trial_owner_email'   => $tenant->owner_email,
            'trial_business_name' => $tenant->business_name,
        ]);
    }

    public function trialSuccess()
    {
        if (! session('trial_login_url')) {
            return redirect(url('/pricing'));
        }

        return view('public.trial-success');
    }

    private function publicPlans()
    {
        return Plan::with(['features', 'enabledModules'])
            ->where('is_active', true)
            ->where('is_public', true)
            ->orderBy('display_order')
            ->orderBy('price')
            ->get();
    }

    private function selfServicePlans()
    {
        return Plan::with(['features', 'enabledModules'])
            ->where('is_active', true)
            ->where('is_public', true)
            ->where('is_custom', false)
            ->orderBy('display_order')
            ->orderBy('price')
            ->get();
    }

    private function customPlans()
    {
        return Plan::with(['features', 'enabledModules'])
            ->where('is_active', true)
            ->where('is_public', true)
            ->where('is_custom', true)
            ->orderBy('display_order')
            ->orderBy('price')
            ->get();
    }
}
