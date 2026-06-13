<?php

namespace App\Http\Controllers;

use App\Http\Requests\Public\StartTrialRequest;
use App\Models\Master\Plan;
use App\Services\Saas\SelfSignupService;
use Illuminate\Http\Request;
use Throwable;

class PublicSiteController extends Controller
{
    public function home()
    {
        return view('public.home', [
            'plans' => $this->publicPlans(),
        ]);
    }

    public function pricing()
    {
        return view('public.pricing', [
            'plans' => $this->publicPlans(),
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
        $plans = $this->publicPlans();
        $selectedPlan = $plans->firstWhere('id', (int) $request->query('plan_id')) ?: $plans->first();

        return view('public.start-trial', compact('plans', 'selectedPlan'));
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
            ->orderBy('price')
            ->get();
    }
}
