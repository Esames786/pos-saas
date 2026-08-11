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
            "'BingooPrintAgent-Setup-' . \$this->agentVersion() . '.exe'",
            $code,
            'a fixed filename lets a cached copy masquerade as the new build'
        );
    }

    public function test_the_response_forbids_caching(): void
    {
        $code = $this->controller();

        $this->assertStringContainsString('no-store', $code);
        $this->assertStringContainsString('X-Agent-Version', $code);
    }

    public function test_the_page_shows_which_build_it_serves(): void
    {
        $view = file_get_contents(resource_path('views/tenant/printing/agents/index.blade.php'));

        $this->assertStringContainsString('$agentVersion', $view, 'the shop must see the version before installing');
        $this->assertStringContainsString("'?v=' . \$agentVersion", $view, 'the link must bust a cached download');
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
