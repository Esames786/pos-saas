<?php

namespace App\Http\Controllers;

use App\Http\Requests\Public\StartTrialRequest;
use App\Jobs\Saas\ProvisionTrialWorkspaceJob;
use App\Mail\TrialWorkspacePreparingMail;
use App\Models\Master\Plan;
use App\Models\Master\Tenant;
use App\Services\Saas\BillingPeriodResolver;
use App\Services\Saas\PlanPricingService;
use App\Services\Saas\SelfSignupService;
use App\Support\PublicLocale;
use App\Support\PublicMoney;
use App\Services\Saas\TrialHttpsProbe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Throwable;

class PublicSiteController extends Controller
{
    private function comingSoonMode(): bool
    {
        return config('saas.public_site_mode') === 'coming_soon';
    }

    private function comingSoon(): \Illuminate\View\View
    {
        return view('public.coming-soon');
    }

    public function home(Request $request)
    {
        if ($this->comingSoonMode()) return $this->comingSoon();

        // WEBSITE-I18N-GEO-1 P2: the home page's price preview speaks the visitor's market too.
        $pricing = app(PlanPricingService::class);
        $market = $this->rememberMarket($request, $pricing);
        $selfService = $this->selfServicePlans()->load('prices');

        return view('public.home', [
            'plans'            => $this->publicPlans(),
            'selfServicePlans' => $selfService,
            'customPlans'      => $this->customPlans(),
            'previewPrices'    => $selfService->mapWithKeys(fn ($plan) => [$plan->code => $pricing->quote($plan, $market)])->filter()->all(),
        ]);
    }

    public function pricing(Request $request)
    {
        if ($this->comingSoonMode()) return $this->comingSoon();

        // WEBSITE-I18N-GEO-1 P2: the market picks the currency and the model. Pakistan (bundle) sees
        // exactly the page it always had; a per-branch market sees per-branch cards and the builder.
        $pricing = app(PlanPricingService::class);
        $market = $this->rememberMarket($request, $pricing);
        $selfService = $this->selfServicePlans()->load('prices');

        return view('public.pricing', [
            'plans'            => $this->publicPlans(),
            'selfServicePlans' => $selfService,
            'customPlans'      => $this->customPlans(),
            'market'           => $market,
            'markets'          => $pricing->markets(),
            'perBranch'        => ($pricing->market($market)['pricing'] ?? 'bundle') === 'per_branch'
                ? $this->perBranchPricing($selfService, $market, $pricing)
                : null,
        ]);
    }

    /** The market this visit shows; remembered for a year when the visitor picked it (?market=). */
    private function rememberMarket(Request $request, PlanPricingService $pricing): string
    {
        $market = $pricing->resolveMarket($request);
        if ($pricing->isMarket(strtolower(trim((string) $request->query('market'))))) {
            \Illuminate\Support\Facades\Cookie::queue('bingoo_market', $market, 60 * 24 * 365);
        }

        return $market;
    }

    /**
     * Everything the per-branch cards, the plan builder and the checkout show — each figure from
     * PlanPricingService::quote(). A plan with no price in this market's currency is left out.
     * Null when no plan can be sold per branch here.
     */
    private function perBranchPricing($plans, string $market, PlanPricingService $pricing): ?array
    {
        $map = (array) config('saas.plan_builder', []);
        $roles = [];
        foreach ($map as $biz => $tiers) {
            foreach ($tiers as $tier => $code) {
                $roles[$code] = [$biz, $tier];
            }
        }
        $moduleLabels = (array) config('saas.module_labels', []);
        $feature = function ($plan, string $key) {
            $value = $plan->features->firstWhere('feature_key', $key)?->feature_value;

            return ($value === null || $value === '') ? null : (int) $value;
        };

        $cards = [];
        foreach ($plans as $plan) {
            $quote = isset($roles[$plan->code]) ? $pricing->quote($plan, $market) : null;
            if (! $quote || $quote['pricing'] !== 'per_branch') {
                continue;
            }
            $cards[$plan->code] = [
                'id'                   => $plan->id,
                'name'                 => $plan->name,
                'description'          => $plan->public_description,
                'biz'                  => $roles[$plan->code][0],
                'tier'                 => $roles[$plan->code][1],
                'quote'                => $quote,
                'terminals_per_branch' => $feature($plan, 'terminals_per_branch') ?? 1,
                'users_per_branch'     => $feature($plan, 'users_per_branch') ?? 1,
                'products'             => $feature($plan, 'product_limit') ?? 0,
                'highlights'           => $plan->enabledModules->pluck('key')->map(fn ($k) => $moduleLabels[$k] ?? null)->filter()->values()->all(),
            ];
        }
        if (! $cards) {
            return null;
        }
        // The builder offers a business only when both of its levels are on sale here.
        foreach ($map as $biz => $tiers) {
            foreach ($tiers as $code) {
                if (! isset($cards[$code])) {
                    unset($map[$biz]);
                    break;
                }
            }
        }

        $first = reset($cards)['quote'];
        $max = $pricing->maxBranches();
        $steps = (array) config('saas.branch_discounts', []);
        ksort($steps);
        $froms = array_keys($steps);
        $discounts = [];
        foreach ($froms as $i => $from) {
            $discounts[] = [(int) $from, isset($froms[$i + 1]) ? (int) $froms[$i + 1] - 1 : $max, (int) $steps[$from]];
        }

        return [
            'market'      => $market,
            'currency'    => $first['currency'],
            'vat_percent' => $first['vat_percent'],
            'plans'       => $cards,
            'discounts'   => $discounts,
            'builder'     => [
                'market'       => $market,
                'label'        => PublicMoney::label($first['currency']),
                'vat'          => $first['vat_percent'],
                'yearlyMonths' => BillingPeriodResolver::YEARLY_PRICE_MONTHS,
                'maxBranches'  => $max,
                'discounts'    => $discounts,
                'map'          => (object) $map,
                'plans'        => collect($cards)->map(fn ($c) => [
                    'id' => $c['id'], 'name' => __($c['name']), 'unit' => $c['quote']['unit'],
                    'extra' => (float) ($c['quote']['extra_terminal_unit'] ?? 0),
                    'tpb' => $c['terminals_per_branch'], 'upb' => $c['users_per_branch'], 'products' => $c['products'],
                ])->all(),
                'checkout'     => PublicLocale::url('/start-trial'),
                'contact'      => PublicLocale::url('/contact?plan=enterprise'),
                // Written out so lang:audit sees every sentence the builder shows.
                'labels'       => [
                    'per branch / month' => __('per branch / month'),
                    'Yearly' => __('Yearly'),
                    'Monthly' => __('Monthly'),
                    'Branches' => __('Branches'),
                    'Extra terminals' => __('Extra terminals'),
                    'Subtotal' => __('Subtotal'),
                    'Multi-branch discount (:percent%)' => __('Multi-branch discount (:percent%)'),
                    'Total per month' => __('Total per month'),
                    'Total per year' => __('Total per year'),
                    'You save :amount a year with yearly billing' => __('You save :amount a year with yearly billing'),
                    '+ :percent% VAT' => __('+ :percent% VAT'),
                    'Branches: :count' => __('Branches: :count'),
                    'Terminals: :count' => __('Terminals: :count'),
                    'Users: :count' => __('Users: :count'),
                    'Up to :count products' => __('Up to :count products'),
                    'Start 30-Day Trial' => __('Start 30-Day Trial'),
                    'No card needed. Nothing is charged during the trial.' => __('No card needed. Nothing is charged during the trial.'),
                    'More than :count branches?' => __('More than :count branches?'),
                    'We will plan a custom rollout and price with you.' => __('We will plan a custom rollout and price with you.'),
                    'Contact Sales' => __('Contact Sales'),
                    'Each branch already includes :count. Add more for busy counters.' => __('Each branch already includes :count. Add more for busy counters.'),
                    'per month' => __('per month'),
                    'per year' => __('per year'),
                ],
            ],
        ];
    }

    public function features()
    {
        if ($this->comingSoonMode()) return $this->comingSoon();

        return view('public.features', [
            'plans' => $this->publicPlans(),
        ]);
    }

    public function trialCreate(Request $request)
    {
        if ($this->comingSoonMode()) return $this->comingSoon();

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

        // CLOUD-BILLING-2: ?billing=yearly (from the pricing toggle, or typed) chooses the cycle the
        // form opens on. It only chooses monthly vs yearly — the amounts come from the plan, through the
        // same resolver that prices the invoice. Before this the page always said "per month".
        $resolver = app(BillingPeriodResolver::class);
        $selectedBilling = $resolver->normalize(strtolower((string) $request->query('billing')));
        $planPrices = $plans->mapWithKeys(fn ($plan) => [$plan->id => [
            'monthly' => $resolver->invoiceAmount($plan, 'monthly') ?? (float) $plan->price,
            'yearly'  => $resolver->invoiceAmount($plan, 'yearly') ?? (float) $plan->price * BillingPeriodResolver::YEARLY_PRICE_MONTHS,
        ]])->all();

        // WEBSITE-I18N-GEO-1 P2: in a per-branch market the checkout carries the branches and extra
        // terminals chosen in the builder (?branches=&terminals=) and shows that quote. It is priced
        // again from the plan when the form is posted — the URL only chooses, it never sets an amount.
        $pricing = app(PlanPricingService::class);
        $market = $this->rememberMarket($request, $pricing);
        $perBranch = ($pricing->market($market)['pricing'] ?? 'bundle') === 'per_branch'
            ? $this->perBranchPricing($plans->load('prices'), $market, $pricing)
            : null;
        $checkout = null;
        if ($perBranch) {
            $code = $selectedPlan && isset($perBranch['plans'][$selectedPlan->code]) ? $selectedPlan->code : array_key_first($perBranch['plans']);
            $selectedPlan = $plans->firstWhere('code', $code);
            $branches = max(1, min((int) $request->query('branches', 1), $pricing->maxBranches()));
            $extra = max(0, min((int) $request->query('terminals', 0), 99));
            $checkout = [
                'market' => $market,
                'branches' => $branches,
                'extra_terminals' => $extra,
                'quote' => $pricing->quote($selectedPlan, $market, $branches, $extra, $selectedBilling),
            ];
        }

        return view('public.start-trial', compact('plans', 'selectedPlan', 'enterpriseRequested', 'selectedBilling', 'planPrices', 'perBranch', 'checkout'));
    }

    public function contact()
    {
        if ($this->comingSoonMode()) return $this->comingSoon();

        return view('public.contact', [
            'customPlans' => $this->customPlans(),
        ]);
    }

    /**
     * WEBSITE-I18N-GEO-1 — every public page in every enabled language, each listing its siblings
     * (xhtml:link hreflang), so a search engine indexes the Arabic pages as Arabic and not as copies.
     */
    public function sitemap()
    {
        $paths = ['/', '/pricing', '/features', '/demos', '/start-trial', '/contact', '/terms', '/privacy', '/refund-policy', '/support-policy'];
        $locales = array_keys(PublicLocale::all());

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "
"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "
";
        foreach ($paths as $path) {
            foreach ($locales as $locale) {
                $xml .= '  <url><loc>' . e(PublicLocale::url($path, $locale)) . '</loc>';
                foreach ($locales as $alt) {
                    $xml .= '<xhtml:link rel="alternate" hreflang="' . $alt . '" href="' . e(PublicLocale::url($path, $alt)) . '"/>';
                }
                $xml .= '<xhtml:link rel="alternate" hreflang="x-default" href="' . e(PublicLocale::url($path, PublicLocale::default())) . '"/></url>' . "
";
            }
        }

        return response($xml . '</urlset>' . "
", 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
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
        if ($this->comingSoonMode()) return $this->comingSoon();

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

    public function trialSuccess()
    {
        if ($this->comingSoonMode()) return $this->comingSoon();

        $signup = session('trial_signup');
        if (! $signup) {
            return redirect(PublicLocale::url('/pricing'));
        }

        return view('public.trial-success', ['signup' => $signup]);
    }

    /**
     * TRIAL-SIGNUP-QUEUE-1 — what the success page polls while the workspace is built.
     *
     * Only ever about the signup in THIS session; there is no id in the URL to guess.
     * preparing → securing (built, HTTPS not on its address yet) → ready, or failed.
     */
    public function trialStatus(TrialHttpsProbe $probe)
    {
        $signup = session('trial_signup');
        if (! $signup) {
            return response()->json(['state' => 'unknown'], 404);
        }

        $tenant = Tenant::find($signup['tenant_id']);
        if (! $tenant) {
            return response()->json(['state' => 'failed']);   // a failed build removes the tenant
        }
        if ($tenant->status !== 'active') {
            return response()->json(['state' => 'preparing']);
        }

        $loginUrl = $signup['login_url'];
        if (str_starts_with($loginUrl, 'https://')) {
            $secure = Cache::remember('trial-https:' . $tenant->id, 15,
                fn () => $probe->serves((string) parse_url($loginUrl, PHP_URL_HOST)));
            if (! $secure) {
                return response()->json(['state' => 'securing']);
            }
        }

        return response()->json(['state' => 'ready', 'login_url' => $loginUrl]);
    }

    public function trialStore(StartTrialRequest $request, SelfSignupService $signup)
    {
        if ($this->comingSoonMode()) return $this->comingSoon();

        $data = $request->signupData();
        $couldNotStart = fn () => back()
            ->withInput($request->except(['password', 'password_confirmation']))
            ->withErrors([
                'signup' => __('We could not create your trial right now. Please try again or contact support.'),
            ]);

        // TRIAL-SIGNUP-QUEUE-1: only the master rows here — about a second. The database, the
        // migrations and the owner are built by ProvisionTrialWorkspaceJob on the queue worker.
        // (Measured 7 Oct: doing it all in this request held the browser for 70 seconds.)
        try {
            $tenant = $signup->createPendingTrial($data);
        } catch (Throwable $e) {
            report($e);

            return $couldNotStart();
        }

        $domain = $tenant->domains()->where('is_primary', true)->value('domain');
        $scheme = $request->secure() ? 'https' : 'http';
        $loginUrl = $scheme . '://' . $domain . '/login';
        $brand = config('saas.brand_name', 'Bingoo');
        $supportEmail = config('saas.contact.support_email', 'support@bingoopos.com');

        // The "we are setting it up" email goes on the queue FIRST: one worker serves the queue,
        // so behind a minute-long build it would arrive a minute late. Never blocks the signup.
        try {
            Mail::to($tenant->owner_email)->queue(new TrialWorkspacePreparingMail(
                brand: $brand,
                businessName: $tenant->business_name,
                workspaceAddress: $domain,
                ownerEmail: $tenant->owner_email,
                supportEmail: $supportEmail,
            ));
        } catch (Throwable $e) {
            report($e);
        }

        // Only the password HASH goes on the queue — the payload is a row in the `jobs` table.
        try {
            ProvisionTrialWorkspaceJob::dispatch(
                $tenant->id, Hash::make($data['password']), $loginUrl, $tenant->business_name, $tenant->owner_email,
            );
        } catch (Throwable $e) {
            report($e);
            $signup->discardFailedTrial($tenant);   // nothing will ever build it — free the address

            return $couldNotStart();
        }

        session()->put('trial_signup', [
            'tenant_id'     => $tenant->id,
            'login_url'     => $loginUrl,
            'address'       => $domain,
            'owner_email'   => $tenant->owner_email,
            'business_name' => $tenant->business_name,
        ]);

        // WEBSITE-I18N-GEO-1: back to the success page in the language the form was filled in.
        return redirect(PublicLocale::url('/trial/success'));
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
