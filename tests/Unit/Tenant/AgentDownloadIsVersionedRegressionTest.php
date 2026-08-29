<?php

namespace Tests\Unit\Tenant;

use Tests\TestCase;

/**
 * THE AGENT DOWNLOAD MUST NOT SERVE A STALE INSTALLER.
 *
 * The endpoint returned a fixed "BingooPrintAgent-Setup.exe" with no cache headers, so a shop that
 * had downloaded before was handed the OLD installer straight from its browser cache. Khatri
 * Biryani reinstalled to pick up a printing fix and came back still running 2.0.1 — the fix never
 * reached the counter, and the failures continued while we both believed it had shipped.
 *
 * The filename now carries the version, the response forbids caching, and the page states which
 * build it is serving so it can be compared against what the agent logs on start.
 */
class AgentDownloadIsVersionedRegressionTest extends TestCase
{
    private function controller(): string
    {
        return file_get_contents(app_path('Http/Controllers/Tenant/PrintAgentController.php'));
    }

    public function test_the_version_comes_from_the_agent_source(): void
    {
        $code = $this->controller();

        $this->assertStringContainsString('public function agentVersion()', $code);
        $this->assertStringContainsString("AGENT_VERSION\\s*=\\s*'([^']+)'", $code, 'version must be read from the agent itself, never hardcoded twice');
    }

    public function test_the_downloaded_file_is_named_with_its_version(): void
    {
        $code = $this->controller();

        $this->assertStringContainsString(
            "'BingooPrintAgent-Setup-' . \$version . '.exe'",
            $code,
            'a fixed filename lets a cached copy masquerade as the new build'
        );
    }

    /**
     * BUILD THE REAL RESPONSE, do not grep for it.
     *
     * The first version of this test only read the source for "no-store" and passed — while the
     * endpoint returned HTTP 500, because response()->download() yields a Symfony
     * BinaryFileResponse which has no withHeaders(). A source-reading assertion cannot catch a
     * method that does not exist. This one calls the controller and inspects what it produced.
     */
    public function test_the_download_response_is_actually_built_and_uncacheable(): void
    {
        if (! is_file(base_path('tools/print-agent/dist/BingooPrintAgent-Setup.exe'))) {
            $this->markTestSkipped('installer artefact not present in this checkout');
        }

        $response = app(\App\Http\Controllers\Tenant\PrintAgentController::class)->downloadWindows(new \Illuminate\Http\Request());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(
            'BingooPrintAgent-Setup-' . app(\App\Http\Controllers\Tenant\PrintAgentController::class)->agentVersion() . '.exe',
            (string) $response->headers->get('Content-Disposition'),
            'the saved file must carry its version'
        );
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertNotEmpty($response->headers->get('X-Agent-Version'));
    }

    public function test_the_page_shows_which_build_it_serves(): void
    {
        $view = file_get_contents(resource_path('views/tenant/printing/agents/index.blade.php'));

        $this->assertStringContainsString('$agentVersion', $view, 'the shop must see the version before installing');
        $this->assertStringContainsString("v=' . \$agentVersion", $view, 'the link must bust a cached download');
    }

    public function test_the_shipped_agent_version_matches_the_installer_script(): void
    {
        preg_match(
            "/AGENT_VERSION\s*=\s*'([^']+)'/",
            file_get_contents(base_path('tools/print-agent/print-agent.js')),
            $agent
        );
        preg_match(
            '/AppVersion=([0-9.]+)/',
            file_get_contents(base_path('tools/print-agent/installer/windows/BingooPrintAgent.iss')),
            $installer
        );

        $this->assertSame(
            $agent[1] ?? 'agent?',
            $installer[1] ?? 'installer?',
            'the wizard and the agent it bundles must declare the same version, or the log cannot be trusted'
        );
    }
}
