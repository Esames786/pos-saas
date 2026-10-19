<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\Master\TenantDomain;
use App\Models\Tenant\ReceiptLayoutSetting;
use App\Models\Tenant\WebSetting;
use App\Services\OnlineOrdering\WebsiteSeo;
use App\Support\OnlineOrdering;
use App\Support\SafeHtml;
use Illuminate\Http\Request;

/**
 * ONLINE-ORDERING-1 — website ke safhe aur Google ki files, har restaurant ke apne (malik, 15 Oct: "sab dynamic,
 * tenant ke against"):
 *
 * - Privacy Policy aur FAQs: Website Settings → "SEO & pages" me likhe; khaali ho to safha nahi (404) aur footer
 *   me link bhi nahi.
 * - robots.txt: restaurant ke apne domain par Google ko ijazat + sitemap; UAT jaisa *.bingoopos.com website pata
 *   chhupa; bingoopos.com aur POS subdomain par wohi jo pehle public/robots.txt me tha (lafz ba lafz).
 * - sitemap.xml: apne domain ke safhe.
 */
class WebsitePagesController extends Controller
{
    /** Pehle wali public/robots.txt — central aur POS subdomains par bilkul yehi. */
    public const PLATFORM_ROBOTS = "User-agent: *\nDisallow:\n\n# WEBSITE-I18N-GEO-1: every public page in every language (English + Arabic).\nSitemap: https://bingoopos.com/sitemap.xml\n";

    public function privacy(Request $request)
    {
        abort_unless(OnlineOrdering::enabledForTenant(), 404);
        $s = WebSetting::tenantDefault();
        abort_if(trim((string) $s->privacy_policy) === '', 404);

        return $this->page($request, $s, 'privacy-policy', 'Privacy Policy', ['html' => self::html((string) $s->privacy_policy)]);
    }

    public function faqs(Request $request)
    {
        abort_unless(OnlineOrdering::enabledForTenant(), 404);
        $s = WebSetting::tenantDefault();
        $faqs = $s->faqList();
        abort_if($faqs === [], 404);

        $faqs = array_map(fn ($f) => $f + ['html' => self::html($f['a']), 'text' => SafeHtml::text(self::html($f['a']))], $faqs);

        return $this->page($request, $s, 'faqs', 'Frequently Asked Questions', ['groups' => collect($faqs)->groupBy('category')->all()]);
    }

    public function robots(Request $request)
    {
        $kind = $request->attributes->get('tenant_domain_kind');
        if ($kind !== TenantDomain::KIND_WEBSITE) {
            $body = self::PLATFORM_ROBOTS;
        } elseif (! WebsiteSeo::onOwnDomain($request)) {
            // UAT jaisa *.bingoopos.com website pata: Google se chhupa (asal domain par hi index ho).
            $body = "User-agent: *\nDisallow: /\n";
        } else {
            $body = "User-agent: *\nAllow: /\nDisallow: /order/status/\nDisallow: /order/confirm/\nDisallow: /order/checkout\nDisallow: /order/locate\n\nSitemap: ".url('/sitemap.xml')."\n";
        }

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function sitemap(Request $request)
    {
        abort_unless(OnlineOrdering::enabledForTenant() && WebsiteSeo::onOwnDomain($request), 404);
        $s = WebSetting::tenantDefault();
        $urls = [['loc' => url('/'), 'priority' => '1.0', 'freq' => 'daily']];
        if (trim((string) $s->privacy_policy) !== '') {
            $urls[] = ['loc' => url('/privacy-policy'), 'priority' => '0.3', 'freq' => 'yearly'];
        }
        if ($s->faqList() !== []) {
            $urls[] = ['loc' => url('/faqs'), 'priority' => '0.5', 'freq' => 'monthly'];
        }
        $last = $s->updated_at?->toAtomString();
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $u) {
            $xml .= '  <url><loc>'.e($u['loc']).'</loc>'.($last ? '<lastmod>'.$last.'</lastmod>' : '').'<changefreq>'.$u['freq'].'</changefreq><priority>'.$u['priority'].'</priority></url>'."\n";
        }

        return response($xml.'</urlset>'."\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * Editor ka HTML (malik: "rich text editable") — saaf kar ke. Purana saada text ho to us ke hisson se HTML.
     */
    public static function html(string $content): string
    {
        if (! preg_match('/<[a-z][^>]*>/i', $content)) {
            $content = implode('', array_map(fn ($b) => match ($b['type']) {
                'h' => '<h2>'.e($b['text']).'</h2>',
                'ul' => '<ul>'.implode('', array_map(fn ($i) => '<li>'.e($i).'</li>', $b['items'])).'</ul>',
                default => '<p>'.e($b['text']).'</p>',
            }, self::blocks($content)));
        }

        return SafeHtml::clean($content);
    }

    /**
     * Likhe text ko safhe me: khaali line = naya hissa; akeli chhoti line (bina . : ? !) = sarkhi; "- " ya "• " = list.
     *
     * @return list<array{type: string, text?: string, items?: list<string>}>
     */
    public static function blocks(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R\s*\R/', trim($text)) ?: [] as $chunk) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $chunk) ?: []), fn ($l) => $l !== ''));
            if (count($lines) === 1 && mb_strlen($lines[0]) <= 70 && ! preg_match('/[.:!?]$/u', $lines[0]) && ! preg_match('/^[-•*]\s/u', $lines[0])) {
                $out[] = ['type' => 'h', 'text' => $lines[0]];

                continue;
            }
            $list = [];
            foreach ($lines as $l) {
                if (preg_match('/^[-•*]\s+(.*)$/u', $l, $m)) {
                    $list[] = $m[1];

                    continue;
                }
                if ($list) {
                    $out[] = ['type' => 'ul', 'items' => $list];
                    $list = [];
                }
                $out[] = ['type' => 'p', 'text' => $l];
            }
            if ($list) {
                $out[] = ['type' => 'ul', 'items' => $list];
            }
        }

        return $out;
    }

    private function page(Request $request, WebSetting $s, string $slug, string $title, array $data)
    {
        $tenant = app('tenant');
        $logo = $s->logo_path ? asset('storage/'.$s->logo_path)
            : (($l = ReceiptLayoutSetting::whereNotNull('logo_path')->value('logo_path')) ? asset('storage/'.$l) : null);

        return response()->view('website.page', $data + [
            'slug' => $slug,
            'title' => $title,
            'businessName' => $tenant->business_name,
            'logo' => $logo,
            'settings' => $s,
            'home' => WebsiteSeo::path($request),
            'privacyUrl' => trim((string) $s->privacy_policy) !== '' ? WebsiteSeo::path($request, 'privacy-policy') : null,
            'faqsUrl' => $s->faqList() !== [] ? WebsiteSeo::path($request, 'faqs') : null,
            'canonical' => WebsiteSeo::canonical($request, $slug),
            'indexable' => WebsiteSeo::onOwnDomain($request),
        ]);
    }
}
