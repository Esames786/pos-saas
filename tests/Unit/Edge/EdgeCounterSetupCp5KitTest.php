<?php

namespace Tests\Unit\Edge;

use App\Services\Edge\EdgeCanonicalJson;
use App\Services\Edge\EdgeCounterSetupRenderer;
use App\Services\Edge\EdgeEnrollmentCrypto;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Edge 0.9 stream D — the CP5 Windows Sandbox kit for scenario s (cashier self-service). The kit runs ONLY inside the sandbox;
 * here it is gated statically (parse, no TLS bypass anywhere, sandbox-only guards) and its wizard-window driver is run in its
 * -Simulate mode (temp folder, in-memory store, stubbed Branch Server) against a freshly rendered, signed wizard.
 */
class EdgeCounterSetupCp5KitTest extends TestCase
{
    private const KIT = 'tests/Fixtures/EdgeWizardCp5/sandbox';

    private const OWN = ['Invoke-Cp5CounterSelfService.ps1', 'Invoke-Cp5CounterWizardUi.ps1', 'Cp5Cdp.ps1', 'cp5-selfservice.php', 'cp5-selfservice-web.php', 'cp5-selfservice-router.php'];

    private ?string $tmp = null;

    protected function tearDown(): void
    {
        if ($this->tmp !== null) {
            File::deleteDirectory($this->tmp);
        }
        parent::tearDown();
    }

    public function test_the_kit_never_weakens_tls_and_always_verifies_peer_and_host(): void
    {
        foreach (self::OWN as $file) {
            $text = (string) file_get_contents(base_path(self::KIT . '/' . $file));
            foreach (['SkipCertificateCheck', 'ServerCertificateValidationCallback', 'CertificatePolicy', 'TrustAllCerts', 'ignore-certificate-errors',
                'allow-insecure', 'CURLOPT_SSL_VERIFYPEER => false', 'CURLOPT_SSL_VERIFYHOST => 0', "'verify_peer' => false", 'Invoke-Expression'] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase($forbidden, $text, "$file: $forbidden");
            }
        }
        $web = (string) file_get_contents(base_path(self::KIT . '/cp5-selfservice-web.php'));
        $this->assertStringContainsString('CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CAINFO => $cfg[\'cafile\']', $web);
        $this->assertSame(1, substr_count($web, 'curl_init('), 'one HTTP function, always verifying');
    }

    public function test_the_real_runs_refuse_to_start_outside_the_sandbox(): void
    {
        $orchestrator = (string) file_get_contents(base_path(self::KIT . '/Invoke-Cp5CounterSelfService.ps1'));
        $ui = (string) file_get_contents(base_path(self::KIT . '/Invoke-Cp5CounterWizardUi.ps1'));

        $this->assertMatchesRegularExpression("/if \\(-not \\(\\\$isAdmin -and \\\$env:USERNAME -eq 'WDAGUtilityAccount'\\)\\) \\{\r?\n\s+throw 'refusing/", $orchestrator);
        $this->assertLessThan(strpos($orchestrator, 'New-Item'), strpos($orchestrator, "throw 'refusing"), 'the guard comes before any change');
        $this->assertMatchesRegularExpression("/if \\(-not \\\$Simulate -and -not \\(\\\$InSandbox -and \\\$isAdmin -and \\\$env:USERNAME -eq 'WDAGUtilityAccount'\\)\\) \\{\r?\n\s+throw 'refusing/", $ui);
        // the scenario stops ONLY recorded pids and removes ONLY its own test CA, by thumbprint
        $this->assertStringContainsString('Stop-RecordedTree', $orchestrator);
        $this->assertStringContainsString('$_.Thumbprint -eq $script:TestCaThumb', $orchestrator);
        $this->assertStringNotContainsString('Stop-Process', $orchestrator);
    }

    public function test_the_kit_parses_and_its_wizard_driver_passes_a_simulated_install_and_repair(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows PowerShell + WPF only.');
        }
        $dir = $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bingoo-cp5kit-test-' . bin2hex(random_bytes(6));
        File::ensureDirectoryExists($dir);

        $paths = array_map(fn ($f) => base_path(self::KIT . '/' . $f), ['Invoke-Cp5CounterSelfService.ps1', 'Invoke-Cp5CounterWizardUi.ps1', 'Cp5Cdp.ps1']);
        file_put_contents($dir . '/list.txt', implode("\n", $paths));
        file_put_contents($dir . '/parse.ps1', "param([string]\$L)\n\$o = @()\nforeach (\$p in @(Get-Content -LiteralPath \$L)) { \$t = \$null; \$e = \$null; [void][System.Management.Automation.Language.Parser]::ParseFile(\$p, [ref]\$t, [ref]\$e); \$o += @(\$e).Count }\nConvertTo-Json -InputObject @(\$o) -Compress\n");
        $parse = new Process(['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $dir . '/parse.ps1', '-L', $dir . '/list.txt']);
        $parse->setTimeout(120);
        $parse->run();
        $this->assertSame([0, 0, 0], json_decode(trim($parse->getOutput()), true), $parse->getErrorOutput());

        // a signed wizard with the cashier's own assertion and a counter hint, as the Cloud builds it
        $kp = sodium_crypto_sign_keypair();
        $sk = sodium_crypto_sign_secretkey($kp);
        $pk = sodium_crypto_sign_publickey($kp);
        preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', (string) file_get_contents(base_path('docs/operator-khatri/Setup-KhatriCounter.ps1')), $m);
        $der = base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', $m[0]));
        $profile = ['purpose' => 'edge-counter-profile', 'lan_hostname' => 'bingoo-edge.local', 'lan_ip' => '192.168.100.52', 'https_port' => 8443, 'ca_sha256' => strtoupper(hash('sha256', $der)), 'branch_id' => 1, 'user_id' => 2];
        $assertion = EdgeEnrollmentCrypto::sign(['version' => EdgeEnrollmentCrypto::ASSERTION_VERSION, 'purpose' => EdgeEnrollmentCrypto::PURPOSE, 'tenant_id' => 1, 'branch_id' => 1,
            'user_id' => 2, 'issuer_user_id' => 2, 'jti' => 'kit-test', 'issued_at' => time(), 'expires_at' => time() + 900], base64_encode($sk));
        $script = (new EdgeCounterSetupRenderer)->renderWindowsWizard(
            ['lan_hostname' => 'bingoo-edge.local', 'lan_ip' => '192.168.100.52', 'lan_https_port' => 8443, 'lan_ca_pem' => $m[0], 'branch_id' => 1],
            'https://cp5edgewiz.cp5.test:9443/pos', 'CP5 Rehearsal', [
                'tenant_label' => 'CP5 Rehearsal Biryani',
                'signed_profile' => ['profile' => $profile, 'signature' => base64_encode(sodium_crypto_sign_detached(EdgeCanonicalJson::encode($profile), $sk)), 'public_key' => base64_encode($pk)],
                'enrollment' => ['assertion' => $assertion, 'user_label' => 'Counter One (CP5C1)'],
                'terminal' => ['id' => 1, 'label' => 'Counter 1 (CP5-T1)'],
            ]);
        file_put_contents($dir . '/Install-Offline-Counter-CP5.ps1', $script);
        file_put_contents($dir . '/pin.keep', '583920');

        $run = new Process(['powershell.exe', '-NoProfile', '-NonInteractive', '-STA', '-ExecutionPolicy', 'Bypass', '-File', $paths[1],
            '-WizardFile', $dir . '/Install-Offline-Counter-CP5.ps1', '-Evidence', $dir . '/ev', '-PinFile', $dir . '/pin.keep', '-Simulate', $dir . '/sim']);
        $run->setTimeout(300);
        $run->run();
        $this->assertSame(0, $run->getExitCode(), $run->getErrorOutput() . $run->getOutput());
        $doc = json_decode((string) file_get_contents($dir . '/ev/s-wizard-ui.json'), true);

        $this->assertTrue($doc['verdict']['all']);
        foreach (['origin_verified', 'ca_only_after_confirmation', 'one_hosts_line', 'shortcuts_with_icon', 'checks_by_name_and_ip', 'own_pin_set', 'offline_counter_ready', 'repair_idempotent', 'non_bingoo_roots_untouched'] as $k) {
            $this->assertTrue($doc['verdict'][$k], $k);
        }
        $this->assertSame(['No', 'Yes'], array_column($doc['install']['certificate']['dialogs'], 'answer'));
        $this->assertSame(6, $doc['install']['pin']['typed_dots']);
        $this->assertCount(11, glob($dir . '/ev/s-wizard-*.png'));
        $this->assertStringNotContainsString('583920', (string) file_get_contents($dir . '/ev/s-wizard-ui.json'));
    }
}
