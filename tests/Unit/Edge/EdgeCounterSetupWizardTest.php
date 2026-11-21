<?php

namespace Tests\Unit\Edge;

use App\Services\Edge\EdgeCanonicalJson;
use App\Services\Edge\EdgeCounterSetupRenderer;
use App\Services\Edge\EdgeEnrollmentCrypto;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Edge 0.9 stream D — the cashier SELF-SERVICE counter wizard (EdgeCounterSetupRenderer::renderWindowsWizard).
 *
 * The generated wizard is NEVER run against this PC. Every PowerShell harness below:
 *  1. parses the generated script (0 errors required),
 *  2. REFUSES to continue if any function other than the 7 system primitives references the cert store, the hosts path,
 *     ProgramData, desktop folders, ipconfig or ie4uinit,
 *  3. loads only the data assignments and the function definitions (never the elevation / window top-level code),
 *  4. replaces the 7 primitives with sandbox versions (temp folders, an in-memory certificate store, no DNS flush),
 * and then exercises the real install / repair / uninstall / origin / PIN / checks logic — including the real WPF window
 * (loaded, never shown) driven by RaiseEvent clicks.
 */
class EdgeCounterSetupWizardTest extends TestCase
{
    private const ONLINE = 'https://khatribiryani.bingoopos.com/pos';

    private ?string $tmp = null;

    /** @var array{sk: string, pk: string}|null */
    private static ?array $keys = null;

    private static ?string $caPem = null;

    protected function tearDown(): void
    {
        if ($this->tmp !== null) {
            File::deleteDirectory($this->tmp);
        }
        parent::tearDown();
    }

    // ---- text-level gates ----------------------------------------------------------------------------------------

    public function test_wizard_text_has_pem_once_hosts_line_three_urls_and_no_bypass_or_secret_patterns(): void
    {
        foreach ([$this->wizard(), $this->wizard($this->fullContext())] as $script) {
            $this->assertSame(1, substr_count($script, '-----BEGIN CERTIFICATE-----'));
            $this->assertDoesNotMatchRegularExpression('/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $script);
            $this->assertStringContainsString("\$HostsLine       = '192.168.100.52  bingoo-edge.local  # Bingoo Edge branch server'", $script);
            $this->assertStringContainsString("\$UrlOnline       = 'https://khatribiryani.bingoopos.com/pos'", $script);
            $this->assertStringContainsString("\$UrlOffline      = 'https://bingoo-edge.local:8443/edge/local/login'", $script);
            $this->assertStringContainsString("\$UrlStatus       = 'https://bingoo-edge.local:8443/edge/local/pos/health'", $script);
            $this->assertStringContainsString("\$EdgeBaseByName  = 'https://bingoo-edge.local:8443'", $script);
            $this->assertStringContainsString("\$EdgeBaseByIp    = 'https://192.168.100.52:8443'", $script);
            $this->assertSame(1, substr_count($script, 'bingoopos.com'), 'the Cloud appears only as the Online shortcut: no Cloud call at runtime');

            foreach (['SkipCertificateCheck', 'ServerCertificateValidationCallback', 'CertificatePolicy', 'TrustAllCerts',
                'Invoke-Expression', 'Add-Type', 'Remove-Item', 'Invoke-WebRequest', 'Invoke-RestMethod', 'Start-Transcript',
                'secret', 'passphrase', 'Set-ExecutionPolicy', 'Import-PfxCertificate'] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase($forbidden, $script, "forbidden: $forbidden");
            }
            // 'password' only as the WPF masked input (PasswordBox / its .Password property), never as data
            $this->assertStringNotContainsStringIgnoringCase('password', preg_replace('/PasswordBox|\.Password\b/', '', $script));
            $this->assertDoesNotMatchRegularExpression('/[^\x09\x0A\x0D\x20-\x7E]/', $script);
            $this->assertStringNotContainsString('{{', $script);
            $this->assertSame(substr_count($script, "\n"), substr_count($script, "\r\n"));
            foreach (['RbInstall', 'RbRepair', 'RbUninstall', 'Invoke-UninstallCore', 'Unblock-File', '-Verb RunAs', '-STA', 'exit $State.ExitCode', 'Offline Counter Ready'] as $needed) {
                $this->assertStringContainsString($needed, $script);
            }
        }
    }

    public function test_unsigned_and_signed_renders_carry_the_right_optional_blocks(): void
    {
        $plain = $this->wizard();
        $this->assertStringContainsString("\$SignedProfileB64       = ''", $plain);
        $this->assertStringContainsString("\$EnrollAssertionB64 = ''", $plain);
        $this->assertStringContainsString('$TerminalId    = 0', $plain);
        $this->assertStringContainsString("\$TenantLabel     = ''", $plain);

        $full = $this->wizard($this->fullContext());
        $this->assertMatchesRegularExpression("/\\\$SignedProfileB64       = '[A-Za-z0-9+\\/=]{40,}'/", $full);
        $this->assertStringContainsString("\$SignedProfileKeySha256 = '" . strtoupper(hash('sha256', self::keys()['pk'])) . "'", $full);
        $this->assertMatchesRegularExpression("/\\\$EnrollAssertionB64 = '[A-Za-z0-9+\\/=]{40,}'/", $full);
        $this->assertStringContainsString("\$EnrollUserLabel    = 'Ali Raza (C102)'", $full);
        $this->assertStringContainsString('$TerminalId    = 4', $full);
        $this->assertStringContainsString("\$TerminalLabel = 'Counter 1'", $full);
        $this->assertStringContainsString("\$TenantLabel     = 'Khatri Biryani (Pvt) Ltd'", $full);
        $this->assertSame('Install-Offline-Counter-Khatri-Biryani.ps1', (new EdgeCounterSetupRenderer)->wizardFileName('Khatri Biryani'));
    }

    public function test_the_embedded_assertion_is_passed_through_byte_for_byte(): void
    {
        $context = $this->fullContext();
        $script = $this->wizard($context);
        preg_match("/\\\$EnrollAssertionB64 = '([^']+)'/", $script, $m);

        $this->assertSame($context['enrollment']['assertion'], json_decode(base64_decode($m[1]), true));
        $this->assertTrue(EdgeEnrollmentCrypto::verifySignature(json_decode(base64_decode($m[1]), true), base64_encode(self::keys()['pk'])));
    }

    // ---- renderer validation of the context ----------------------------------------------------------------------

    public static function badContexts(): array
    {
        return [
            'signature over other bytes' => [fn (self $t) => $t->withSignedProfile(['lan_ip' => '192.168.100.52'], tamper: true), 'does not verify'],
            'signed profile for another ip' => [fn (self $t) => $t->withSignedProfile(['lan_ip' => '192.168.100.77']), 'does not match'],
            'signed profile for another port' => [fn (self $t) => $t->withSignedProfile(['https_port' => 9443]), 'does not match'],
            'signed profile for another CA' => [fn (self $t) => $t->withSignedProfile(['ca_sha256' => str_repeat('AB', 32)]), 'does not match'],
            'signed profile for another branch' => [fn (self $t) => $t->withSignedProfile(['branch_id' => 9]), 'another branch'],
            'signed profile malformed' => [fn (self $t) => ['signed_profile' => ['profile' => ['x' => 1], 'signature' => 'abc', 'public_key' => 'def']], 'signed_profile must be'],
            'assertion with wrong purpose' => [fn (self $t) => ['enrollment' => ['assertion' => $t->assertion(['purpose' => 'something-else'])]], 'own assertion'],
            'assertion for another branch' => [fn (self $t) => ['enrollment' => ['assertion' => $t->assertion(['branch_id' => 9])]], 'another branch'],
            'assertion signed by another key' => [fn (self $t) => $t->withSignedProfile([]) + ['enrollment' => ['assertion' => $t->assertion([], sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))]], 'same Cloud key'],
            'assertion without signature' => [fn (self $t) => ['enrollment' => ['assertion' => ['payload' => ['purpose' => 'edge-user-enrollment', 'user_id' => 5, 'expires_at' => 1]]]], 'own assertion'],
            'terminal id zero' => [fn (self $t) => ['terminal' => ['id' => 0]], 'terminal must be'],
            'terminal id text' => [fn (self $t) => ['terminal' => ['id' => '4; calc']], 'terminal must be'],
        ];
    }

    #[DataProvider('badContexts')]
    public function test_rejects_an_unsafe_wizard_context(\Closure $context, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->wizard($context($this));
    }

    // ---- PowerShell: structure (AST) -------------------------------------------------------------------------------

    public function test_structure_confirmation_gates_primitives_network_guard_and_pin_hygiene(): void
    {
        $r = $this->harness(self::STRUCTURE_HARNESS, $this->wizard($this->fullContext()));

        $this->assertSame(0, $r['errors']);
        $calls = collect($r['commands']);

        // the certificate store is written ONLY by Add-CaToStore, called ONLY from Invoke-CaStep, called with -Confirmed
        $this->assertSame(['Invoke-CaStep'], $calls->where('name', 'Add-CaToStore')->pluck('in')->unique()->values()->all());
        $caCalls = $calls->where('name', 'Invoke-CaStep');
        $this->assertSame(['Enter-CaPage', 'Initialize-CounterWindow'], $caCalls->pluck('in')->unique()->sort()->values()->all());
        foreach ($caCalls as $call) {
            $this->assertMatchesRegularExpression('/-Confirmed \$(false|confirmed)$/', $call['text']);
        }
        // ... and $confirmed is the ticked box AND a Yes on the confirmation dialog, set right before the call
        $this->assertStringContainsString('$confirmed = ($ui.ChkTrustCa.IsChecked -eq $true) -and ($answer -eq [System.Windows.MessageBoxResult]::Yes)', $r['functions']['Initialize-CounterWindow']);
        $this->assertLessThan(strpos($r['functions']['Initialize-CounterWindow'], 'Invoke-CaStep -Confirmed $confirmed'), strpos($r['functions']['Initialize-CounterWindow'], "'Confirm the branch certificate'"));
        $this->assertSame(['Invoke-UninstallCore'], $calls->where('name', 'Remove-CaFromStore')->pluck('in')->unique()->values()->all());
        $this->assertStringContainsString('$removeCa = ($ui.ChkRemoveCa.IsChecked -eq $true) -and ($caAnswer -eq [System.Windows.MessageBoxResult]::Yes)', $r['functions']['Initialize-CounterWindow']);
        foreach (['Add-CaToStore', 'Remove-CaFromStore'] as $primitive) {
            $this->assertMatchesRegularExpression('/^function ' . $primitive . " \{\r?\n\s+param\([^)]*\[bool\]\\\$Confirmed\)\r?\n\s+if \(-not \\\$Confirmed\) \{ throw /", $r['functions'][$primitive], "$primitive refuses without confirmation first");
        }
        // Invoke-CaStep never reaches the store without confirmation when the CA is missing
        $this->assertMatchesRegularExpression('/if \(-not \$Confirmed\) \{ return @\{ Ok = \$false.*\r?\n\s+Add-CaToStore -Certificate \$cert -Confirmed \$Confirmed/', $r['functions']['Invoke-CaStep']);

        // system access only inside the 7 primitives
        foreach ($r['functions'] as $name => $text) {
            if (! in_array($name, ['Get-CounterPaths', 'Get-UserDesktops', 'Test-CaInStore', 'Add-CaToStore', 'Remove-CaFromStore', 'Clear-DnsCache', 'Update-IconCache'], true)) {
                $this->assertDoesNotMatchRegularExpression('/X509Store|Cert:\\\\|SystemRoot|GetFolderPath|ProgramData|ipconfig|ie4uinit/', $text, "$name touches the system");
            }
        }
        // deletion only in Remove-CounterFile (allow-listed paths) + the empty state folder in Invoke-UninstallCore
        $this->assertSame(['Invoke-UninstallCore', 'Remove-CounterFile'], collect($r['functions'])->filter(fn ($t) => preg_match('/\]::Delete\(/', $t))->keys()->sort()->values()->all());

        // network: HttpWebRequest only in Invoke-EdgeHttp, which refuses anything but the two Branch Server bases
        $this->assertSame(['Invoke-EdgeHttp'], collect($r['functions'])->filter(fn ($t) => str_contains($t, 'HttpWebRequest'))->keys()->values()->all());
        $this->assertStringContainsString("throw ('refusing to contact '", $r['functions']['Invoke-EdgeHttp']);

        // PIN hygiene: the PasswordBox values are read only in the Set-PIN handler, and no output/log command sees the PIN
        $this->assertSame(['Initialize-CounterWindow'], collect($r['functions'])->filter(fn ($t) => str_contains($t, '.Password'))->keys()->values()->all());
        foreach ($calls->whereIn('name', ['Write-CounterLog', 'Write-Host', 'Write-Say', 'Add-Content', 'Set-Content', 'Out-File', 'Write-Output', 'Set-Status']) as $call) {
            $this->assertDoesNotMatchRegularExpression('/\$Pin\b|\$body\b|Password|PwPin|\$assertionJson|EnrollAssertionB64/i', $call['text'], 'logs/prints the PIN or the assertion: ' . $call['text']);
        }
        $this->assertStringContainsString('$body = $null', $r['functions']['Invoke-PinEnrollment']);
        $this->assertStringContainsString('[Array]::Clear($bytes', $r['functions']['Invoke-EdgeHttp']);
    }

    // ---- PowerShell: sandboxed behaviour --------------------------------------------------------------------------

    public static function roundTrips(): array
    {
        return ['fresh PC' => [false], 'CA already trusted before the setup' => [true]];
    }

    #[DataProvider('roundTrips')]
    public function test_uninstall_removes_exactly_what_install_added(bool $preexisting): void
    {
        $r = $this->harness(self::ROUNDTRIP_SCENARIO, $this->wizard($this->fullContext()), ['preexisting' => $preexisting]);

        $this->assertTrue($r['ca']['Ok']);
        $this->assertSame(! $preexisting, $r['ca']['Added']);
        $this->assertTrue($r['net']['Ok'], implode(' | ', $r['net']['Lines']));
        $this->assertTrue($r['un']['Ok'], implode(' | ', $r['un']['Lines']));

        // installed: CA, our hosts line (foreign lines kept), 3 shortcuts, icon, state, hosts backup
        $this->assertContains($this->caThumbprint(), $r['installed']['store']);
        $this->assertStringContainsString("192.168.100.52  bingoo-edge.local  # Bingoo Edge branch server", $r['installed']['hosts']);
        $this->assertStringContainsString('10.0.0.9 printer.local', $r['installed']['hosts']);
        foreach (['BINGOO Online', 'BINGOO Offline', 'Branch Server Status'] as $name) {
            $this->assertContains('\\Public\\Desktop\\' . $name . '.url', $r['installed']['files']);
        }
        $this->assertContains('\\ProgramData\\BingooPOS\\bingoo.ico', $r['installed']['files']);
        $this->assertContains('\\ProgramData\\BingooPOS\\counter\\state.json', $r['installed']['files']);
        $this->assertSame(! $preexisting, $r['state']['ca_added_by_setup']);
        $this->assertSame('bingoo-edge.local', $r['state']['edge_host']);

        // after uninstall: byte-identical hosts, the same files, the same certificate store as before the install
        $this->assertSame($r['before']['hosts'], $r['after']['hosts']);
        $this->assertSame($r['before']['files'], $r['after']['files']);
        $this->assertSame($r['before']['store'], $r['after']['store']);
        $this->assertSame($preexisting, in_array($this->caThumbprint(), $r['after']['store'], true));
        $this->assertContains('FOREIGNCA0000000000000000000000000000000', $r['after']['store']);
        $this->assertContains($preexisting ? 'Branch certificate: kept - it was already trusted before this setup' : 'Branch certificate: removed', $r['un']['Lines']);
    }

    public function test_the_certificate_step_never_touches_the_store_without_confirmation(): void
    {
        $r = $this->harness(self::NOCONFIRM_SCENARIO, $this->wizard());

        $this->assertFalse($r['result']['Ok']);
        $this->assertSame('waiting for your confirmation', $r['result']['Detail']);
        $this->assertSame([], $r['store']);
        $this->assertSame([], $r['calls']);
    }

    public function test_repair_moves_the_pc_to_a_new_branch_server_name_and_keeps_install_history(): void
    {
        $r = $this->harness(self::REPAIR_SCENARIO, $this->wizard());

        $this->assertTrue($r['net']['Ok']);
        $this->assertStringNotContainsString('old-edge.local', $r['hosts']);
        $this->assertStringContainsString('127.0.0.1 localhost', $r['hosts']);
        $this->assertSame(1, substr_count($r['hosts'], 'bingoo-edge.local'));
        $this->assertSame('bingoo-edge.local', $r['state']['edge_host']);
        $this->assertSame('192.168.100.52', $r['state']['edge_ip']);
        $this->assertTrue($r['state']['ca_added_by_setup'], 'a repair never forgets that the first install added the CA');
        $this->assertStringContainsString('Old Branch Server name old-edge.local: removed', implode("\n", $r['net']['Lines']));
    }

    public function test_origin_check_verifies_the_cloud_signature_in_powershell(): void
    {
        $signed = $this->harness(self::ORIGIN_SCENARIO, $this->wizard($this->fullContext()));
        $this->assertSame(['asRendered' => 'signed', 'tamperedProfile' => 'invalid', 'otherIp' => 'invalid', 'otherKey' => 'invalid'], $signed);

        $unsigned = $this->harness(self::ORIGIN_SCENARIO, $this->wizard());
        $this->assertSame(['asRendered' => 'unsigned'], $unsigned);
    }

    public function test_powershell_ed25519_agrees_with_sodium(): void
    {
        $vectors = [];
        for ($i = 0; $i < 4; $i++) {
            $kp = sodium_crypto_sign_keypair();
            $msg = $i === 0 ? '' : random_bytes(17 * $i);
            $sig = sodium_crypto_sign_detached($msg, sodium_crypto_sign_secretkey($kp));
            $vectors[] = [$msg, $sig, sodium_crypto_sign_publickey($kp), true];
            $bad = $sig;
            $bad[40] = chr(ord($bad[40]) ^ 4);
            $vectors[] = [$msg, $bad, sodium_crypto_sign_publickey($kp), false];
            $vectors[] = [$msg . 'x', $sig, sodium_crypto_sign_publickey($kp), false];
        }
        // RFC 8032 section 7.1, TEST 1
        $vectors[] = ['', hex2bin('e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e065224901555fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b'),
            hex2bin('d75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a'), true];

        $r = $this->harness(self::ED25519_SCENARIO, $this->wizard(), ['vectors' => array_map(fn ($v) => [base64_encode($v[0]), base64_encode($v[1]), base64_encode($v[2])], $vectors)]);

        $this->assertSame(array_column($vectors, 3), $r);
    }

    public static function pinResponses(): array
    {
        return [
            'enrolled' => [201, '{"ok":true,"user_id":5}', true, 'Your Edge PIN is set'],
            'old branch server' => [404, '', false, 'cannot set PINs from a counter yet'],
            'throttled' => [429, '{"reason":"too many attempts"}', false, 'Too many tries'],
            'expired' => [422, '{"reason":"assertion expired"}', false, 'has expired'],
            'replayed' => [409, '{"reason":"assertion already used (replay)"}', false, 'already used'],
            'weak' => [422, '{"reason":"An Edge PIN must not be all the same digit."}', false, 'An Edge PIN must not be all the same digit.'],
        ];
    }

    #[DataProvider('pinResponses')]
    public function test_pin_enrollment_posts_the_assertion_and_pin_only_to_the_branch_server_and_never_logs_the_pin(int $status, string $body, bool $ok, string $message): void
    {
        $context = $this->fullContext();
        $r = $this->harness(self::PIN_SCENARIO, $this->wizard($context), ['status' => $status, 'body' => $body]);

        $this->assertSame($ok, $r['result']['Ok']);
        $this->assertStringContainsString($message, $r['result']['Message']);
        $this->assertSame('https://bingoo-edge.local:8443/edge/local/enroll', $r['sent']['Url']);
        $this->assertSame('POST', $r['sent']['Method']);
        $sent = json_decode($r['sent']['Body'], true);
        $this->assertSame(['assertion', 'credential', 'type'], array_keys($sent));
        $this->assertSame($context['enrollment']['assertion'], $sent['assertion']);
        $this->assertSame('482916', $sent['credential']);
        $this->assertSame('pin', $sent['type']);
        $this->assertStringContainsString('edge PIN setup: HTTP ' . $status, $r['log']);
        $this->assertStringNotContainsString('482916', $r['log']);
        $this->assertStringNotContainsString($context['enrollment']['assertion']['signature'], $r['log']);

        $this->assertSame([
            '|' => 'Type your new PIN in both boxes. / Dono khanon mein naya PIN likhein.',
            '482916|482917' => 'The two PINs are different. / Dono PIN alag hain.',
            '12345|12345' => 'The PIN must be 6 to 12 digits (numbers only). / PIN 6 se 12 hindson ka ho (sirf numbers).',
            '4829a6|4829a6' => 'The PIN must be 6 to 12 digits (numbers only). / PIN 6 se 12 hindson ka ho (sirf numbers).',
            '1234567890123|1234567890123' => 'The PIN must be 6 to 12 digits (numbers only). / PIN 6 se 12 hindson ka ho (sirf numbers).',
            '777777|777777' => 'The PIN must not be one digit repeated. / PIN mein ek hi hindsa baar baar na ho.',
            '123456|123456' => 'This PIN is too easy to guess. / Yeh PIN bohat aasaan hai.',
            '482916|482916' => '',
        ], $r['policy']);
    }

    public function test_network_guard_refuses_anything_but_the_branch_server(): void
    {
        $r = $this->harness(self::GUARD_SCENARIO, $this->wizard());

        $this->assertCount(4, $r);
        foreach ($r as $url => $outcome) {
            $this->assertStringStartsWith('refusing to contact ', $outcome, $url);
        }
    }

    public static function checkCases(): array
    {
        $ok = ['Ok' => true, 'Kind' => 'ok', 'Status' => 200, 'Message' => ''];

        return [
            'all good + same cloud key' => [['name' => $ok, 'ip' => $ok, 'login' => $ok, 'key' => 'same'], true, []],
            'name unknown, ip fine' => [['name' => ['Ok' => false, 'Kind' => 'name', 'Status' => 0, 'Message' => 'The remote name could not be resolved'], 'ip' => $ok, 'login' => ['Ok' => false, 'Kind' => 'name', 'Status' => 0, 'Message' => 'x'], 'key' => 'none'], false, ['was not found although the IP works']],
            'certificate not trusted' => [['name' => ['Ok' => false, 'Kind' => 'trust', 'Status' => 0, 'Message' => 'Could not establish trust relationship for the SSL/TLS secure channel.'], 'ip' => ['Ok' => false, 'Kind' => 'trust', 'Status' => 0, 'Message' => 'x'], 'login' => ['Ok' => false, 'Kind' => 'trust', 'Status' => 0, 'Message' => 'x'], 'key' => 'none'], false, ['certificate check FAILED']],
            'server off' => [['name' => ['Ok' => false, 'Kind' => 'network', 'Status' => 0, 'Message' => 'Unable to connect'], 'ip' => ['Ok' => false, 'Kind' => 'network', 'Status' => 0, 'Message' => 'Unable to connect'], 'login' => ['Ok' => false, 'Kind' => 'network', 'Status' => 0, 'Message' => 'x'], 'key' => 'none'], false, ['did not answer']],
            'login page not ready' => [['name' => $ok, 'ip' => $ok, 'login' => ['Ok' => true, 'Kind' => 'ok', 'Status' => 503, 'Message' => ''], 'key' => 'none'], false, ['login page did not']],
            'different cloud key' => [['name' => $ok, 'ip' => $ok, 'login' => $ok, 'key' => 'other'], false, ['certificate check FAILED']],
        ];
    }

    /** @param list<string> $hints */
    #[DataProvider('checkCases')]
    public function test_edge_checks_report_actionable_hints(array $case, bool $ok, array $hints): void
    {
        $r = $this->harness(self::CHECKS_SCENARIO, $this->wizard($this->fullContext()), $case);

        $this->assertSame($ok, $r['Ok'], implode(' | ', $r['Lines']));
        $this->assertCount(count($hints), $r['Hints'], implode(' | ', $r['Hints']));
        foreach ($hints as $i => $hint) {
            $this->assertStringContainsString($hint, $r['Hints'][$i]);
        }
        if ($case['key'] === 'other') {
            $this->assertStringContainsString('DIFFERENT Bingoo Cloud', implode("\n", $r['Lines']));
        }
        if ($hints === ['did not answer']) {
            // the unreachable-server hint names the shop network and explains the Repair path for a new address
            $this->assertStringContainsString('(192.168.100.x)', $r['Hints'][0]);
            $this->assertStringContainsString('download a NEW setup file from the Online POS and choose Repair', $r['Hints'][0]);
        }
    }

    public function test_the_real_window_drives_install_with_pin_then_remove_back_to_the_original_pc(): void
    {
        $r = $this->harness(self::UI_SCENARIO, $this->wizard($this->fullContext()));

        // the XAML loads and every control the code uses exists
        $this->assertSame([], $r['missingControls']);
        // CA page: Next disabled until trusted; the button does nothing without the tick or with a "No"
        $this->assertFalse($r['caNextBefore']);
        $this->assertSame(0, $r['storeAfterNoTick']);
        $this->assertSame(0, $r['storeAfterNo']);
        $this->assertSame(1, $r['storeAfterYes']);
        $ask = array_search('ask:Confirm the branch certificate', $r['calls'], true);
        $add = array_search('store-add:True', $r['calls'], true);
        $this->assertNotFalse($ask);
        $this->assertNotFalse($add);
        $this->assertLessThan($add, $ask, 'the confirmation dialog comes before the store is written');
        $this->assertSame(1, count(array_keys($r['calls'], 'store-add:True', true)));

        $this->assertSame(['PageWelcome', 'PageCa', 'PageNetwork', 'PageCheck', 'PagePin', 'PageTerminal', 'PageFinish'], $r['installPages']);
        $this->assertSame('Offline Counter Ready', $r['installTitle']);
        $this->assertSame(0, $r['installExit']);
        $this->assertSame('set', $r['pinStatus']);
        $this->assertSame('', $r['pinBoxAfter'], 'the PIN boxes are cleared after sending');
        $this->assertStringContainsString('knows this counter', $r['terminalText']);
        $this->assertSame('482916', json_decode($r['sentBody'], true)['credential']);
        $this->assertStringNotContainsString('482916', $r['log']);

        // a second run detects the setup, offers Repair, and Remove returns the PC to exactly where it was
        $this->assertTrue($r['repairPreselected']);
        $this->assertSame(['PageWelcome', 'PageRemove', 'PageFinish'], $r['removePages']);
        $this->assertSame('Bingoo Offline was removed from this PC', $r['removeTitle']);
        $this->assertSame(0, $r['removeExit']);
        $this->assertSame($r['before'], $r['after']);
    }

    // ---- helpers -------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $context */
    private function wizard(array $context = []): string
    {
        return (new EdgeCounterSetupRenderer)->renderWindowsWizard($this->lan(), self::ONLINE, 'Khatri Biryani', $context);
    }

    private function lan(): array
    {
        return [
            'lan_hostname' => 'bingoo-edge.local', 'lan_ip' => '192.168.100.52', 'lan_https_port' => 8443,
            'lan_ca_pem' => self::caPem(), 'branch_id' => 1,
        ];
    }

    /** @return array<string, mixed> */
    private function fullContext(): array
    {
        return $this->withSignedProfile([]) + [
            'tenant_label' => 'Khatri Biryani (Pvt) Ltd',
            'enrollment' => ['assertion' => $this->assertion(), 'user_label' => 'Ali Raza (C102)'],
            'terminal' => ['id' => 4, 'label' => 'Counter 1'],
        ];
    }

    /** @param array<string, mixed> $override */
    public function withSignedProfile(array $override, bool $tamper = false): array
    {
        $profile = array_merge([
            'lan_hostname' => 'bingoo-edge.local', 'lan_ip' => '192.168.100.52', 'https_port' => 8443,
            'ca_sha256' => $this->caSha256(), 'branch_id' => 1, 'tenant_code' => 'khatri', 'issued_at' => 1795000000,
        ], $override);
        $signature = sodium_crypto_sign_detached(EdgeCanonicalJson::encode($tamper ? $profile + ['extra' => 1] : $profile), self::keys()['sk']);

        return ['signed_profile' => ['profile' => $profile, 'signature' => base64_encode($signature), 'public_key' => base64_encode(self::keys()['pk'])]];
    }

    /** @param array<string, mixed> $override */
    public function assertion(array $override = [], ?string $secretKey = null): array
    {
        $payload = array_merge([
            'version' => EdgeEnrollmentCrypto::ASSERTION_VERSION, 'purpose' => EdgeEnrollmentCrypto::PURPOSE,
            'tenant_id' => 3, 'tenant_code' => 'khatri', 'branch_id' => 1, 'device_public_uuid' => 'a7d57f56-0000-4000-8000-000000000001',
            'activation_epoch' => 1, 'user_id' => 5, 'jti' => '01JCOUNTERSETUPTEST0000000', 'issuer_user_id' => 5,
            'issued_at' => 1795000000, 'expires_at' => 1795000900,
        ], $override);

        return EdgeEnrollmentCrypto::sign($payload, base64_encode($secretKey ?? self::keys()['sk']));
    }

    /** @return array{sk: string, pk: string} */
    private static function keys(): array
    {
        if (self::$keys === null) {
            $kp = sodium_crypto_sign_keypair();
            self::$keys = ['sk' => sodium_crypto_sign_secretkey($kp), 'pk' => sodium_crypto_sign_publickey($kp)];
        }

        return self::$keys;
    }

    private static function caPem(): string
    {
        if (self::$caPem === null) {
            $src = file_get_contents(base_path('docs/operator-khatri/Setup-KhatriCounter.ps1'));
            preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $src, $m);
            self::$caPem = $m[0];
        }

        return self::$caPem;
    }

    private function caDer(): string
    {
        return base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', self::caPem()));
    }

    private function caSha256(): string
    {
        return strtoupper(hash('sha256', $this->caDer()));
    }

    private function caThumbprint(): string
    {
        return strtoupper(hash('sha1', $this->caDer()));
    }

    private function tempDir(): string
    {
        if ($this->tmp === null) {
            $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bingoo-counter-wizard-test-' . bin2hex(random_bytes(6));
            File::ensureDirectoryExists($this->tmp);
        }

        return $this->tmp;
    }

    /**
     * Runs PRELUDE + $scenario against $script in a fresh sandbox folder; returns the decoded JSON the scenario prints.
     *
     * @param  array<string, mixed>|null  $input
     */
    private function harness(string $scenario, string $script, ?array $input = null): mixed
    {
        $exe = PHP_OS_FAMILY === 'Windows' ? 'powershell.exe' : (new ExecutableFinder)->find('pwsh');
        if ($exe === null) {
            $this->markTestSkipped('PowerShell is not available on this machine.');
        }
        $dir = $this->tempDir();
        $id = bin2hex(random_bytes(4));
        $scriptPath = $dir . DIRECTORY_SEPARATOR . 'Install-Offline-Counter-' . $id . '.ps1';
        $harnessPath = $dir . DIRECTORY_SEPARATOR . 'harness-' . $id . '.ps1';
        $sandbox = $dir . DIRECTORY_SEPARATOR . 'sandbox-' . $id;
        File::ensureDirectoryExists($sandbox);
        file_put_contents($scriptPath, $script);
        file_put_contents($harnessPath, ($scenario === self::STRUCTURE_HARNESS ? '' : self::PRELUDE) . $scenario);
        $args = [$exe, '-NoProfile', '-NonInteractive', '-STA', '-ExecutionPolicy', 'Bypass', '-File', $harnessPath, '-Script', $scriptPath];
        if ($scenario !== self::STRUCTURE_HARNESS) {
            $args = array_merge($args, ['-Sandbox', $sandbox]);
        }
        if ($input !== null) {
            $inputPath = $dir . DIRECTORY_SEPARATOR . 'input-' . $id . '.json';
            file_put_contents($inputPath, json_encode($input));
            $args = array_merge($args, ['-InputFile', $inputPath]);
        }
        $process = new Process($args);
        $process->setTimeout(300);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        return json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
    }

    // ---- PowerShell harnesses ------------------------------------------------------------------------------------

    private const STRUCTURE_HARNESS = <<<'PS1'
param([string]$Script)
$ErrorActionPreference = 'Stop'
$tokens = $null; $errors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile($Script, [ref]$tokens, [ref]$errors)
$functions = [ordered]@{}
foreach ($f in @($ast.FindAll({ param($n) $n -is [System.Management.Automation.Language.FunctionDefinitionAst] }, $true))) { $functions[$f.Name] = $f.Extent.Text }
$commands = @()
foreach ($c in @($ast.FindAll({ param($n) $n -is [System.Management.Automation.Language.CommandAst] }, $true))) {
    $parent = $c.Parent
    while ($parent -and -not ($parent -is [System.Management.Automation.Language.FunctionDefinitionAst])) { $parent = $parent.Parent }
    $in = '(top)'
    if ($parent) { $in = $parent.Name }
    $commands += [pscustomobject]@{ name = $c.GetCommandName(); in = $in; text = $c.Extent.Text }
}
[pscustomobject]@{ errors = @($errors).Count; functions = $functions; commands = $commands } | ConvertTo-Json -Depth 5 -Compress
PS1;

    private const PRELUDE = <<<'PS1'
param([string]$Script, [string]$Sandbox, [string]$InputFile = '')
$ErrorActionPreference = 'Stop'
$tokens = $null; $errors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile($Script, [ref]$tokens, [ref]$errors)
if (@($errors).Count -gt 0) { throw 'the generated script does not parse' }
$Primitives = @('Get-CounterPaths', 'Get-UserDesktops', 'Test-CaInStore', 'Add-CaToStore', 'Remove-CaFromStore', 'Clear-DnsCache', 'Update-IconCache')
$SystemPattern = 'X509Store|Cert:\\|SystemRoot|SystemDrive|GetFolderPath|ProgramData|env:PUBLIC|ipconfig|ie4uinit'
$functions = @($ast.FindAll({ param($n) $n -is [System.Management.Automation.Language.FunctionDefinitionAst] }, $false))
$firstFunction = ($functions | ForEach-Object { $_.Extent.StartOffset } | Measure-Object -Minimum).Minimum
foreach ($statement in $ast.EndBlock.Statements) {
    if ($statement -is [System.Management.Automation.Language.AssignmentStatementAst] -and $statement.Extent.StartOffset -lt $firstFunction) {
        . ([scriptblock]::Create($statement.Extent.Text))
    }
}
foreach ($f in $functions) {
    if ($Primitives -notcontains $f.Name -and $f.Extent.Text -match $SystemPattern) { throw ('refusing: ' + $f.Name + ' touches the system outside the primitives') }
    . ([scriptblock]::Create($f.Extent.Text))
}
# ---- sandbox primitives (replace the 7 real ones) ----
foreach ($d in @('etc', 'Public\Desktop', 'Users\cashier\Desktop', 'ProgramData')) { New-Item -ItemType Directory -Force -Path (Join-Path $Sandbox $d) | Out-Null }
$global:FakeStore = New-Object System.Collections.ArrayList
$global:Calls = New-Object System.Collections.ArrayList
function Get-CounterPaths { return @{ Hosts = (Join-Path $Sandbox 'etc\hosts'); PublicDesktop = (Join-Path $Sandbox 'Public\Desktop'); IconPath = (Join-Path $Sandbox 'ProgramData\BingooPOS\bingoo.ico'); StateFile = (Join-Path $Sandbox 'ProgramData\BingooPOS\counter\state.json'); LogDir = (Join-Path $Sandbox 'ProgramData\BingooPOS\logs') } }
function Get-UserDesktops { return @(Join-Path $Sandbox 'Users\cashier\Desktop') }
function Test-CaInStore { param([string]$Thumbprint) return $global:FakeStore.Contains($Thumbprint) }
function Add-CaToStore { param($Certificate, [bool]$Confirmed) [void]$global:Calls.Add('store-add:' + $Confirmed); if (-not $Confirmed) { throw 'not confirmed' }; [void]$global:FakeStore.Add($Certificate.Thumbprint) }
function Remove-CaFromStore { param([string]$Thumbprint, [bool]$Confirmed) [void]$global:Calls.Add('store-remove:' + $Confirmed); if (-not $Confirmed) { throw 'not confirmed' }; $global:FakeStore.Remove($Thumbprint) }
function Clear-DnsCache { }
function Update-IconCache { }
function Get-SandboxSnapshot {
    $logs = Join-Path $Sandbox 'ProgramData\BingooPOS\logs'
    $files = @(Get-ChildItem -LiteralPath $Sandbox -Recurse -File | Where-Object { -not $_.FullName.StartsWith($logs) } | ForEach-Object { $_.FullName.Substring($Sandbox.Length) } | Sort-Object)
    $hosts = ''
    if (Test-Path -LiteralPath (Get-CounterPaths).Hosts) { $hosts = [IO.File]::ReadAllText((Get-CounterPaths).Hosts) }
    return [ordered]@{ files = $files; hosts = $hosts; store = @($global:FakeStore | Sort-Object) }
}
function Get-SandboxLog {
    $dir = (Get-CounterPaths).LogDir
    if (-not (Test-Path -LiteralPath $dir)) { return '' }
    return (@(Get-ChildItem -LiteralPath $dir -File) | ForEach-Object { [IO.File]::ReadAllText($_.FullName) }) -join "`n"
}
$Data = $null
if ($InputFile) { $Data = Get-Content -LiteralPath $InputFile -Raw | ConvertFrom-Json }
function Initialize-SandboxPc {
    [IO.File]::WriteAllText((Get-CounterPaths).Hosts, "# hosts`r`n127.0.0.1 localhost`r`n10.0.0.9 printer.local`r`n")
    Set-Content -LiteralPath (Join-Path $Sandbox 'Public\Desktop\Notes.txt') -Value 'mine' -Encoding ASCII
    Set-Content -LiteralPath (Join-Path $Sandbox 'Users\cashier\Desktop\Chrome.url') -Value @('[InternetShortcut]', 'URL=https://example.com/') -Encoding ASCII
    [void]$global:FakeStore.Add('FOREIGNCA0000000000000000000000000000000')
}

PS1;

    private const ROUNDTRIP_SCENARIO = <<<'PS1'
Initialize-SandboxPc
if ($Data.preexisting) { [void]$global:FakeStore.Add($CaThumbprint) }
$before = Get-SandboxSnapshot
$ca = Invoke-CaStep -Confirmed $true
$net = Invoke-NetworkStep
$installed = Get-SandboxSnapshot
$state = Get-Content -LiteralPath (Get-CounterPaths).StateFile -Raw | ConvertFrom-Json
$un = Invoke-UninstallCore -RemoveCa $true
$after = Get-SandboxSnapshot
[pscustomobject]@{ before = $before; installed = $installed; after = $after; ca = $ca; net = $net; un = $un; state = $state } | ConvertTo-Json -Depth 6 -Compress
PS1;

    private const NOCONFIRM_SCENARIO = <<<'PS1'
$r = Invoke-CaStep -Confirmed $false
[pscustomobject]@{ result = $r; store = @($global:FakeStore); calls = @($global:Calls) } | ConvertTo-Json -Depth 4 -Compress
PS1;

    private const REPAIR_SCENARIO = <<<'PS1'
New-Item -ItemType Directory -Force -Path (Split-Path -Parent (Get-CounterPaths).StateFile) | Out-Null
Set-Content -LiteralPath (Get-CounterPaths).StateFile -Value '{"edge_host":"old-edge.local","edge_ip":"192.168.1.9","ca_added_by_setup":true,"shortcuts":[],"installed_at":"2026-11-01T10:00:00"}' -Encoding ASCII
[IO.File]::WriteAllText((Get-CounterPaths).Hosts, "127.0.0.1 localhost`r`n192.168.1.9  old-edge.local  # Bingoo Edge branch server`r`n")
$net = Invoke-NetworkStep
[pscustomobject]@{ net = $net; hosts = [IO.File]::ReadAllText((Get-CounterPaths).Hosts); state = (Get-CounterState) } | ConvertTo-Json -Depth 5 -Compress
PS1;

    private const ORIGIN_SCENARIO = <<<'PS1'
$out = [ordered]@{ asRendered = (Get-OriginCheck).Status }
if ($SignedProfileB64) {
    $saved = $SignedProfileB64
    $bytes = [Convert]::FromBase64String($SignedProfileB64); $bytes[10] = $bytes[10] -bxor 1
    $SignedProfileB64 = [Convert]::ToBase64String($bytes)
    $out.tamperedProfile = (Get-OriginCheck).Status
    $SignedProfileB64 = $saved
    $savedIp = $EdgeIp; $EdgeIp = '192.168.100.99'
    $out.otherIp = (Get-OriginCheck).Status
    $EdgeIp = $savedIp
    $savedKey = $SignedProfileKey
    $key = [Convert]::FromBase64String($SignedProfileKey); $key[3] = $key[3] -bxor 1
    $SignedProfileKey = [Convert]::ToBase64String($key)
    $out.otherKey = (Get-OriginCheck).Status
    $SignedProfileKey = $savedKey
}
$out | ConvertTo-Json -Compress
PS1;

    private const ED25519_SCENARIO = <<<'PS1'
$results = @()
foreach ($v in @($Data.vectors)) {
    $message = New-Object byte[] 0
    if ($v[0]) { $message = [Convert]::FromBase64String($v[0]) }
    $results += [bool](Test-Ed25519Signature -Message $message -Signature ([Convert]::FromBase64String($v[1])) -PublicKey ([Convert]::FromBase64String($v[2])))
}
ConvertTo-Json -InputObject @($results) -Compress
PS1;

    private const PIN_SCENARIO = <<<'PS1'
$global:Sent = $null
function Invoke-EdgeHttp { param([string]$Url, [string]$Method = 'GET', [string]$JsonBody = '', [int]$TimeoutMs = 10000) $global:Sent = [ordered]@{ Url = $Url; Method = $Method; Body = $JsonBody }; return @{ Ok = $true; Kind = 'ok'; Status = [int]$Data.status; Body = [string]$Data.body; Message = '' } }
$policy = [ordered]@{}
foreach ($case in @(@('', ''), @('482916', '482917'), @('12345', '12345'), @('4829a6', '4829a6'), @('1234567890123', '1234567890123'), @('777777', '777777'), @('123456', '123456'), @('482916', '482916'))) {
    $policy[$case[0] + '|' + $case[1]] = Test-PinPolicy -Pin $case[0] -Repeat $case[1]
}
$r = Invoke-PinEnrollment -Pin '482916'
[pscustomobject]@{ policy = $policy; result = $r; sent = $global:Sent; log = (Get-SandboxLog) } | ConvertTo-Json -Depth 5 -Compress
PS1;

    private const GUARD_SCENARIO = <<<'PS1'
$out = [ordered]@{}
foreach ($u in @('https://bingoopos.com/api/edge/pair', 'https://bingoo-edge.local:8443.evil.example/edge/local/health', 'http://bingoo-edge.local:8443/edge/local/health', 'https://192.168.100.52:8443@evil.example/edge/local/enroll')) {
    try { [void](Invoke-EdgeHttp -Url $u); $out[$u] = 'ALLOWED' } catch { $out[$u] = $_.Exception.Message }
}
$out | ConvertTo-Json -Compress
PS1;

    private const CHECKS_SCENARIO = <<<'PS1'
function Invoke-EdgeHttp {
    param([string]$Url, [string]$Method = 'GET', [string]$JsonBody = '', [int]$TimeoutMs = 10000)
    $pick = $Data.login
    if ($Url -eq ($EdgeBaseByName + '/edge/local/health')) { $pick = $Data.name }
    if ($Url -eq ($EdgeBaseByIp + '/edge/local/health')) { $pick = $Data.ip }
    $body = '<html></html>'
    if ($Url.EndsWith('/health')) {
        $body = '{"status":"ok"}'
        if ($Data.key -eq 'same') { $body = '{"status":"ok","enrollment_key_sha256":"' + $SignedProfileKeySha256 + '"}' }
        if ($Data.key -eq 'other') { $body = '{"status":"ok","enrollment_key_sha256":"' + ('AB' * 32) + '"}' }
    }
    return @{ Ok = [bool]$pick.Ok; Kind = [string]$pick.Kind; Status = [int]$pick.Status; Body = $body; Message = [string]$pick.Message }
}
$r = Invoke-EdgeChecks
[pscustomobject]@{ Ok = $r.Ok; Lines = @($r.Lines); Hints = @($r.Hints) } | ConvertTo-Json -Depth 4 -Compress
PS1;

    private const UI_SCENARIO = <<<'PS1'
foreach ($assembly in @('PresentationFramework, Version=4.0.0.0, Culture=neutral, PublicKeyToken=31bf3856ad364e35', 'PresentationCore, Version=4.0.0.0, Culture=neutral, PublicKeyToken=31bf3856ad364e35', 'WindowsBase, Version=4.0.0.0, Culture=neutral, PublicKeyToken=31bf3856ad364e35', 'System.Xaml, Version=4.0.0.0, Culture=neutral, PublicKeyToken=b77a5c561934e089')) { [void][System.Reflection.Assembly]::Load($assembly) }
$global:Answers = New-Object System.Collections.Queue
$global:SentBody = ''
function Show-Message { param([string]$Text, [string]$Title, [string]$Buttons = 'OK', [string]$Icon = 'Information') [void]$global:Calls.Add('ask:' + $Title); if ($global:Answers.Count -gt 0) { return $global:Answers.Dequeue() }; return [System.Windows.MessageBoxResult]::No }
function Update-UiNow { }
function Invoke-EdgeHttp {
    param([string]$Url, [string]$Method = 'GET', [string]$JsonBody = '', [int]$TimeoutMs = 10000)
    [void]$global:Calls.Add('http:' + $Method + ' ' + $Url)
    if ($Url.EndsWith('/edge/local/enroll')) { $global:SentBody = $JsonBody; return @{ Ok = $true; Kind = 'ok'; Status = 201; Body = '{"ok":true,"user_id":5}'; Message = '' } }
    if ($Url.EndsWith('/edge/local/health')) { return @{ Ok = $true; Kind = 'ok'; Status = 200; Body = ('{"status":"ok","enrollment_key_sha256":"' + $SignedProfileKeySha256 + '","terminals":[{"id":4,"name":"Counter 1","active":true}]}'); Message = '' } }
    return @{ Ok = $true; Kind = 'ok'; Status = 200; Body = '<html></html>'; Message = '' }
}
function Click { param($Button) $Button.RaiseEvent((New-Object System.Windows.RoutedEventArgs -ArgumentList ([System.Windows.Controls.Primitives.ButtonBase]::ClickEvent))) }
function New-SandboxWindow {
    $doc = [xml]$Xaml
    $script:Window = [Windows.Markup.XamlReader]::Load((New-Object System.Xml.XmlNodeReader -ArgumentList $doc))
    Initialize-CounterWindow -XamlDoc $doc
    $missing = @($doc.SelectNodes('//*') | ForEach-Object { $_.GetAttribute('x:Name') } | Where-Object { $_ -and ($null -eq $ui[$_]) })
    $text = [IO.File]::ReadAllText($Script)
    $missing += @([regex]::Matches($text, '\$ui\.([A-Za-z0-9]+)') | ForEach-Object { $_.Groups[1].Value } | Where-Object { $null -eq $ui[$_] } | Select-Object -Unique)
    Show-Page 'PageWelcome'
    return , $missing
}

Initialize-SandboxPc
$out = [ordered]@{}
$out.before = Get-SandboxSnapshot
$out.missingControls = New-SandboxWindow
$pages = @($State.Page)
Click $ui.BtnNext; $pages += $State.Page
$out.caNextBefore = [bool]$ui.BtnNext.IsEnabled
Click $ui.BtnTrustCa
$out.storeAfterNoTick = $global:FakeStore.Count - 1
$ui.ChkTrustCa.IsChecked = $true
$global:Answers.Enqueue([System.Windows.MessageBoxResult]::No)
Click $ui.BtnTrustCa
$out.storeAfterNo = $global:FakeStore.Count - 1
$global:Answers.Enqueue([System.Windows.MessageBoxResult]::Yes)
Click $ui.BtnTrustCa
$out.storeAfterYes = $global:FakeStore.Count - 1
Click $ui.BtnNext; $pages += $State.Page
Click $ui.BtnApplyNetwork
Click $ui.BtnNext; $pages += $State.Page
Click $ui.BtnNext; $pages += $State.Page
$ui.PwPin1.Password = '482916'
$ui.PwPin2.Password = '482916'
Click $ui.BtnSetPin
$out.pinStatus = $State.PinStatus
$out.pinBoxAfter = $ui.PwPin1.Password
Click $ui.BtnNext; $pages += $State.Page
$out.terminalText = $ui.TxtTerminalStatus.Text
Click $ui.BtnNext; $pages += $State.Page
$out.installPages = $pages
$out.installTitle = $ui.TxtFinishTitle.Text
$out.installExit = $State.ExitCode
$out.sentBody = $global:SentBody

[void](New-SandboxWindow)
$out.repairPreselected = [bool]$ui.RbRepair.IsChecked
$ui.RbUninstall.IsChecked = $true
$pages = @($State.Page)
Click $ui.BtnNext; $pages += $State.Page
$ui.ChkRemoveCa.IsChecked = $true
$global:Answers.Enqueue([System.Windows.MessageBoxResult]::Yes)
$global:Answers.Enqueue([System.Windows.MessageBoxResult]::Yes)
Click $ui.BtnRemove
Click $ui.BtnNext; $pages += $State.Page
$out.removePages = $pages
$out.removeTitle = $ui.TxtFinishTitle.Text
$out.removeExit = $State.ExitCode
$out.after = Get-SandboxSnapshot
$out.calls = @($global:Calls)
$out.log = Get-SandboxLog
$out | ConvertTo-Json -Depth 6 -Compress
PS1;
}
