<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Models\Tenant\Category;
use App\Models\Tenant\WebSetting;
use App\Services\OnlineOrdering\WebsiteBranding;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Console\Command;

/**
 * ONLINE-ORDERING-1 — purani website ka logo aur banner slides aik dafa me (Website Settings → "Website look"
 * wala kaam, files se). Banners isi tarteeb me jaise diye; pehle wale banners rehte hain jab tak --replace na ho.
 */
class OnlineOrderingImportBrandingCommand extends Command
{
    protected $signature = 'online-ordering:import-branding {tenant_code}
        {--logo= : Logo ki file}
        {--banner=* : Banner ki file (kai dafa, tarteeb se)}
        {--upsell= : Cart ke "Popular with your order" ki category ka naam}
        {--replace : Purane banners hata kar}
        {--yes : Likho. Is ke baghair sirf dikhata hai}';

    protected $description = 'Website ka logo, banners aur cart suggestion category (default: dry run).';

    public function handle(TenancyManager $tenancy, WebsiteBranding $branding): int
    {
        $tenant = Tenant::where('tenant_code', $this->argument('tenant_code'))->firstOrFail();
        $tenancy->activate($tenant);
        $settings = WebSetting::tenantDefault();
        $write = (bool) $this->option('yes');

        $files = array_filter(array_merge([$this->option('logo')], (array) $this->option('banner')));
        foreach ($files as $f) {
            if (! is_file($f)) {
                $this->error("File nahi mili: {$f}");

                return self::FAILURE;
            }
        }

        if ($logo = $this->option('logo')) {
            $this->line('  Logo: '.basename($logo));
            $write && $branding->setLogo($settings, $logo, $tenant->tenant_code);
        }
        if ($this->option('replace')) {
            $this->line('  Purane banners hatenge: '.count((array) ($settings->banners ?? [])));
            $write && $branding->removeBanners($settings, array_keys((array) ($settings->banners ?? [])));
        }
        foreach ((array) $this->option('banner') as $b) {
            $this->line('  Banner: '.basename($b).' ('.round(filesize($b) / 1024).' KB → WebP 1920 + 960)');
            $write && $branding->addBanner($settings->fresh(), $b, $tenant->tenant_code);
        }
        if ($name = $this->option('upsell')) {
            $cat = Category::whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($name))])->first();
            if (! $cat) {
                $this->error("Category nahi mili: {$name}");

                return self::FAILURE;
            }
            $this->line("  Cart suggestion: {$cat->name}");
            $write && $settings->fresh()->update(['upsell_category_id' => $cat->id]);
        }

        if (! $write) {
            $this->warn('Ye sirf dikhaya gaya hai. Likhne ke liye --yes lagayein.');
        } else {
            $this->info('Likh diya. Banners ab: '.count((array) ($settings->fresh()->banners ?? [])));
        }

        return self::SUCCESS;
    }
}
