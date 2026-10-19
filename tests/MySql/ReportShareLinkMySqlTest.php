<?php

namespace Tests\MySql;

use App\Models\Master\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * WHATSAPP-REPORT-CHANNEL-1 — the link the owner taps.
 *
 * Two hops: the central domain looks the token up and redirects; the tenant subdomain renders. The
 * guards here are about who may read what, and for how long — a report link is a window onto a shop's
 * takings, sent over WhatsApp, where it can be forwarded without anyone meaning to.
 */
class ReportShareLinkMySqlTest extends MySqlTenantTestCase
{
    private string $token = 'abcdefghij0123456789abcdefghij01';

    private function seedLink(int $hoursAhead = 48): Tenant
    {
        $tenant = Tenant::on('master')->first();

        if (! $tenant) {
            $this->markTestSkipped('no tenant on the master test database');
        }

        DB::connection('master')->table('report_share_links')->where('token', $this->token)->delete();
        DB::connection('master')->table('report_share_links')->insert([
            'token' => $this->token,
            'tenant_id' => $tenant->id,
            'filters' => json_encode(['date_from' => '2026-10-01', 'date_to' => '2026-10-01']),
            'sections' => json_encode(['overview']),
            'label' => '01 Oct 2026',
            'expires_at' => now()->addHours($hoursAhead),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenant;
    }

    private function central(): string
    {
        return 'http://'.config('tenancy.central_domain');
    }

    public function test_the_link_meta_actually_sends_today_still_redirects(): void
    {
        $tenant = $this->seedLink();

        // This is the shape live messages carry: the template's button base was typed as '.../r/{{1}}'
        // and Meta appends the parameter instead of substituting it.
        $this->get($this->central().'/r/%7B%7B1%7D%7D'.$this->token)
            ->assertRedirectContains($tenant->tenant_code)
            ->assertRedirectContains($this->token);
    }

    public function test_a_clean_link_redirects_too_so_fixing_the_template_breaks_nothing(): void
    {
        // Links already sitting in people's chats must keep working after the template is corrected.
        $tenant = $this->seedLink();

        $this->get($this->central().'/r/'.$this->token)
            ->assertRedirectContains($tenant->tenant_code);
    }

    public function test_an_expired_link_stops_working(): void
    {
        $this->seedLink(-1);

        $this->get($this->central().'/r/'.$this->token)->assertNotFound();
    }

    public function test_an_unknown_token_says_nothing_at_all(): void
    {
        // 404, not 403 — a wrong token must not confirm that it ever existed.
        $this->get($this->central().'/r/zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz')->assertNotFound();
    }

    public function test_a_link_cannot_be_read_on_another_tenants_subdomain(): void
    {
        // The whole point of the second check in the controller. Without it a reader would simply swap
        // the hostname and read a different shop's takings with the same token.
        $tenant = $this->seedLink();

        $this->get('http://not-'.$tenant->tenant_code.'.'.config('tenancy.tenant_base_domain').'/r/'.$this->token)
            ->assertNotFound();
    }
}
