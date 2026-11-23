<?php

namespace Tests\Unit\Edge;

use App\Services\Edge\Packaging\PackageLayoutInspector;
use PHPUnit\Framework\TestCase;

/**
 * Edge 0.9 (stream E) — package LAYOUT gates: the setup wizard (K8), Windows 10 task durations (Khatri 21 Nov 2026:
 * [TimeSpan]::MaxValue serialises to P99999999DT23H59M59S which Windows 10 rejects) and the central -CloudUrl
 * (a tenant subdomain gave "404 api/edge/pair" at Khatri). Filesystem-only, on throwaway package-shaped directories.
 */
class EdgePackageLayoutInspectorTest extends TestCase
{
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            $this->rrmdir($d);
        }
        parent::tearDown();
    }

    private function pkg(array $files): string
    {
        $d = sys_get_temp_dir() . '/edge-layout-' . bin2hex(random_bytes(4));
        $this->dirs[] = $d;
        foreach ($files as $rel => $body) {
            @mkdir(dirname($d . '/' . $rel), 0777, true);
            file_put_contents($d . '/' . $rel, $body);
        }
        @mkdir($d, 0777, true);

        return $d;
    }

    public function test_timespan_maxvalue_in_code_is_found_but_comments_are_ignored(): void
    {
        $src = implode("\n", [
            '# [TimeSpan]::MaxValue serialises to P99999999DT23H59M59S, rejected by Windows 10 (comment: ignored)',
            '<# block comment: [TimeSpan]::MaxValue',
            '   still the block #>',
            '$ok = New-TimeSpan -Days 3650  # -Days 99999 in a trailing comment is ignored',
            '$bad = [TimeSpan]::MaxValue',
            '$bad2 = [System.TimeSpan]::MaxValue',
            '$long = New-TimeSpan -Days 36500',
            '$iso = "P99999999DT23H59M59S"',
            '$fd = [TimeSpan]::FromDays(4000)',
            'Write-Host "# not a comment"; $x = [timespan]::maxvalue',
        ]);
        $hits = PackageLayoutInspector::scanPowerShell($src);
        $byLine = [];
        foreach ($hits as $h) {
            $byLine[$h['line']][] = $h['kind'];
        }
        $this->assertSame([5, 6, 7, 8, 9, 10], array_keys($byLine), json_encode($hits));
        $this->assertSame(['maxvalue'], $byLine[5]);
        $this->assertSame(['maxvalue'], $byLine[6]);
        $this->assertSame(['long'], $byLine[7]);
        $this->assertSame(['maxvalue'], $byLine[10], 'a # inside a string does not hide the code after it');
    }

    public function test_the_real_edge_scripts_are_windows_10_safe(): void
    {
        $root = dirname(__DIR__, 3);
        $pkg = $this->pkg([]);
        // Package-shaped copy of the repository's PowerShell scripts (scripts/ = what the builder ships at the top level).
        foreach (glob($root . '/scripts/edge/*.ps1') ?: [] as $f) {
            copy($f, $pkg . '/' . basename($f));
        }
        $result = (new PackageLayoutInspector())->scriptCompatibility($pkg);
        $this->assertGreaterThan(10, $result['files_scanned']);
        $this->assertTrue($result['ok'], json_encode($result));
    }

    public function test_script_compatibility_reports_the_offending_file_and_line_and_skips_vendor(): void
    {
        $pkg = $this->pkg([
            'scripts/Install-EdgeFooTask.ps1' => "param()\n\$t = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration ([TimeSpan]::MaxValue)\n",
            'app/vendor/acme/tool.ps1' => '$x = [TimeSpan]::MaxValue',
        ]);
        $result = (new PackageLayoutInspector())->scriptCompatibility($pkg);
        $this->assertFalse($result['ok']);
        $this->assertSame(['scripts/Install-EdgeFooTask.ps1:2'], $result['timespan_maxvalue']);
        $this->assertSame(1, $result['files_scanned'], 'app/vendor is never scanned');
    }

    public function test_strict_mode_is_a_0_9_plus_release_only(): void
    {
        $this->assertTrue(PackageLayoutInspector::isStrict('release', '0.9.0-edge'));
        $this->assertTrue(PackageLayoutInspector::isStrict('release', '0.10.1-edge'));
        $this->assertFalse(PackageLayoutInspector::isStrict('release', '0.8.5-edge'));
        $this->assertFalse(PackageLayoutInspector::isStrict('dev', '0.9.0-dev'));
        $this->assertTrue(PackageLayoutInspector::isStrict('dev', '0.9.0-dev', true), '--strict-layout forces the gates');
        $this->assertFalse(PackageLayoutInspector::isStrict('release', '0.9.0-edge', false));
    }

    public function test_wizard_presence_is_a_warning_in_dev_and_an_error_when_strict(): void
    {
        $i = new PackageLayoutInspector();
        $empty = $this->pkg(['app/artisan' => '<?php']);
        $dev = $i->wizard($empty, false);
        $this->assertFalse($dev['present']);
        $this->assertTrue($dev['ok']);
        $this->assertSame('warning', $dev['severity']);
        $strict = $i->wizard($empty, true);
        $this->assertFalse($strict['ok']);
        $this->assertSame('error', $strict['severity']);

        $full = $this->pkg([
            'app/scripts/edge/wizard/Start-BingooEdgeWizard.ps1' => '# wizard',
            'app/scripts/edge/wizard/pages/Welcome.ps1' => '# page',
            'Setup-BingooEdge.cmd' => '@echo off',
            'scripts/Setup-BingooEdge.cmd' => '@echo off',
        ]);
        $ok = $i->wizard($full, true);
        $this->assertTrue($ok['ok']);
        $this->assertSame(2, $ok['files']);
        $this->assertTrue($ok['setup_cmd_root']);
        $this->assertSame('ok', $ok['severity']);
        // The wizard without the ROOT launcher is incomplete.
        unlink($full . '/Setup-BingooEdge.cmd');
        $this->assertSame('error', $i->wizard($full, true)['severity']);
    }

    public function test_the_cloud_url_must_be_the_central_domain(): void
    {
        $i = new PackageLayoutInspector();
        $good = $this->pkg([
            'scripts/Install-EdgeAppliance.ps1' => "#   .\\Install-EdgeAppliance.ps1 -PackageRoot D:\\pkg -CloudUrl https://bingoopos.com `\nparam([string]\$CloudUrl = 'https://bingoopos.com')\n",
            'templates/README-INSTALL.md' => "Run Setup-BingooEdge.cmd. Manual: `-CloudUrl https://bingoopos.com/`\n",
        ]);
        $r = $i->cloudUrl($good, true);
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('https://bingoopos.com', $r['default']);
        $this->assertTrue($r['default_ok']);

        $bad = $this->pkg([
            'scripts/Install-EdgeAppliance.ps1' => "#   .\\Install-EdgeAppliance.ps1 -CloudUrl https://pos.example.com `\nparam([string]\$CloudUrl = 'https://khatri.bingoopos.com')\n",
            'templates/README-INSTALL.md' => "-CloudUrl https://<tenant>.bingoopos.com\n",
        ]);
        $strict = $i->cloudUrl($bad, true);
        $this->assertFalse($strict['ok']);
        $this->assertSame('error', $strict['severity']);
        $this->assertFalse($strict['default_ok']);
        $this->assertCount(3, $strict['bad'], json_encode($strict['bad'])); // the default, the pos.example.com example, the tenant-subdomain README line
        $dev = $i->cloudUrl($bad, false);
        $this->assertTrue($dev['ok']);
        $this->assertSame('warning', $dev['severity']);
        // No default at all (today's installer) is acceptable when the examples are central.
        $noDefault = $this->pkg(['scripts/Install-EdgeAppliance.ps1' => "param([string]\$CloudUrl)\n"]);
        $this->assertTrue($i->cloudUrl($noDefault, true)['ok']);
        $this->assertNull($i->cloudUrl($noDefault, true)['default_ok']);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
