<?php

namespace Tests\Unit\Edge;

use App\Services\Edge\EdgeCounterSetupRenderer;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionParameter;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Edge 0.9 stream D — EdgeCounterSetupRenderer (contract K7).
 *
 * The generated script is NEVER executed here (it changes the cert store and the hosts file). It is checked as text, with
 * the PowerShell parser (0 errors + the commands it uses), and two pure excerpts are exercised in a temp sandbox: the hosts
 * editor with its path rewritten to a temp file (and ipconfig removed), and the CA fingerprint check cut off before the
 * certificate store is opened. The harness refuses to run an excerpt that still references a system path or the store.
 */
class EdgeCounterSetupRendererTest extends TestCase
{
    /** The real Khatri branch CA (public certificate) used on 21 Nov 2026 — docs/operator-khatri/Setup-KhatriCounter.ps1. */
    private const KHATRI_CA_PEM = <<<'PEM'
-----BEGIN CERTIFICATE-----
MIIFWzCCA0OgAwIBAgIQbu/MmHP7vIVH2YwMH0sCETANBgkqhkiG9w0BAQsFADBG
MRgwFgYDVQQKDA9CaW5nb28gUE9TIEVkZ2UxKjAoBgNVBAMMIUJpbmdvbyBFZGdl
IEJyYW5jaCBDQSAoS2hhdHJpLTAxKTAeFw0yNjExMjEwNjQzMDNaFw0zMTExMjEw
NjUzMDJaMEYxGDAWBgNVBAoMD0JpbmdvbyBQT1MgRWRnZTEqMCgGA1UEAwwhQmlu
Z29vIEVkZ2UgQnJhbmNoIENBIChLaGF0cmktMDEpMIICIjANBgkqhkiG9w0BAQEF
AAOCAg8AMIICCgKCAgEAwSJl+pcaoJmwPcYoKz9PuTg2wh4JcB1/uJL79GO9r7jM
4KUJVewgVvgv7UQj30iUZJJF0qzmFYPioYtD76jeJ02/bClBI4HmuJ1qAIvMhGik
yq65tK/rCXu4exZf6uaV+2tmwh9KuuwUhjcHpiX70iR53AQ0fpMig4NymqS2JQU+
ySRanKEoJkw01xy7Kl+xmCJqfCfEDA+GI9lzDCiAi0PVYQLIV7TuOEHszZfYY95f
dG3UxoV9DF/vSFi3mA4tdEoPqOFC3Yq27kyXFowm5TEIECm/pyfsrmZMn04Zv0yD
DJzXyKmL9KYFqZspHva6qXi0NJ4bmTe//Zbe+v3HZ9Eaxu6AmMu4XEqUImBQLi4A
Q+hMS/iGxDUOW4+8popHk961l19fMy7Bwt67ADgIngGuETSYrcZqM7txbR8C0Z2C
1LglXWtabguehP0mF1SDpoAY1TWBdDsFdWpAQN86+zb+kJwIxp1z1eS948kzN7im
O/WsJcHfvTwdpu0WdB26yljGrg+Y0ckE3z1fHZHjdYV0i0G2RjhC39KbJQVCJd/Y
3pf/1vFHo7oxpUan+ll5ndJ0Tm9UdSoA5BocWZmn6E9I99EIe9SGxm8VJApI4vaf
SganjvQ2ZaskK1quDjfhqK7CLsvWqP8Q6XXklMC9AIC4U31OBUxDAbEEgoynUXUC
AwEAAaNFMEMwDgYDVR0PAQH/BAQDAgEGMBIGA1UdEwEB/wQIMAYBAf8CAQAwHQYD
VR0OBBYEFOoyh3yu3KqRZxGqp4rAGAG+yDbIMA0GCSqGSIb3DQEBCwUAA4ICAQBq
IOv2O6O87dokqFpMujjVYK2junafuns11VdUiWUv8bXPxUqJqQpXOaNHApr/SIU6
2JMVoViNjeFbctNKSeCLMGlH1iNVK550OHNCUXVeceSWPVpE1sUBXSzfEEvnbDns
T9gculoi8BhbrwPeO/TWywF9cEGg6cosCEwtTXrfdFCzEsmwO6SF4ED6Unu4L6oc
Hhzi4ZHzcTVphVIr2T0PnrCUEICrbDji41bGXUM/122DTcW0Sw605lSAYzgk6LAt
qPAAY1YYEAjhv63V4tDhfh9Gm+Q/BveCJQwxzUaRHz6rmkfPFGoxWj1yX8lP7gKO
CFVCsO94JhnuPuTWfG8CF/SIePEqcteRL/OwdOoLkWRv0rEa+PdP/lBzH+Va9tWx
h3zvjpb8XEM94FLQNHsyuC6w+lCRyIxApYEIpZt4JdtQgJz42+KDEVVLARPPjXUY
CdIUxPbLUGk3JlCEotH4LBr9V28VM2A1Hxc7LOmEXviS83ju2pGlIvyTvSwVMmTV
8WvxsBDZT9wRLofOf4/38ezpeHX79+eWXyNoH+7vLytAC9CPkSvS5Op83G0o95SR
VsOCRQH71miWXhy8UKq4MaKZayGNZiJI3ZVQjUiY+nYIOUknIg4dYTqEmjfx/lav
NryJ4883lMzeNeP6xi4P/dwDqVz//pKiI4qEq6l2OA==
-----END CERTIFICATE-----
PEM;

    /** Windows thumbprint (SHA-1) of the Khatri CA as hand-written in the proven Khatri script. */
    private const KHATRI_CA_SHA1 = '125DFE0A8EF21BDB6CA844EF987856E29E0D5EE4';

    private const KHATRI_CA_SHA256 = 'B78FA7EE65D9D4EBCC6000327326B839321D90C1BFE6245E80E08F189612B612';

    private const ONLINE = 'https://khatribiryani.bingoopos.com/pos';

    /** @var array<string, array{pem: string, key: mixed, cert: mixed}> */
    private static array $certs = [];

    private ?string $tmp = null;

    protected function tearDown(): void
    {
        if ($this->tmp !== null) {
            File::deleteDirectory($this->tmp);
        }
        parent::tearDown();
    }

    // ---- rendering -----------------------------------------------------------------------------------------------

    public function test_renders_the_khatri_profile_with_the_proven_thumbprint(): void
    {
        $script = $this->render();

        $this->assertStringContainsString("\$CaThumbprint = '" . self::KHATRI_CA_SHA1 . "'", $script);
        $this->assertStringContainsString("\$CaSha256     = '" . self::KHATRI_CA_SHA256 . "'", $script);
        $this->assertStringContainsString("\$CaName       = 'Bingoo Edge Branch CA (Khatri-01)'", $script);
        $this->assertStringContainsString("\$EdgeIp       = '192.168.100.52'", $script);
        $this->assertStringContainsString("\$EdgeHost     = 'bingoo-edge.local'", $script);
        $this->assertStringContainsString('$EdgePort     = 8443', $script);
        $this->assertStringContainsString("\$LanHint      = '192.168.100.x'", $script);
    }

    public function test_embeds_the_ca_pem_exactly_once(): void
    {
        $script = $this->render();
        $body = preg_replace('/-----[A-Z ]+-----|\s+/', '', self::KHATRI_CA_PEM);
        $scriptBody = preg_replace('/\s+/', '', $script);

        $this->assertSame(1, substr_count($script, '-----BEGIN CERTIFICATE-----'));
        $this->assertSame(1, substr_count($script, '-----END CERTIFICATE-----'));
        $this->assertSame(1, substr_count($scriptBody, $body));
        $this->assertDoesNotMatchRegularExpression('/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $script);
        // inside a single-quoted here-string, so PowerShell takes it verbatim
        $this->assertMatchesRegularExpression("/\\\$CaPem = @'\r\n-----BEGIN CERTIFICATE-----\r\n[A-Za-z0-9+\\/=\r\n]+-----END CERTIFICATE-----\r\n'@\r\n/", $script);
    }

    public function test_contains_the_hosts_line_the_three_shortcut_urls_and_the_tls_checks(): void
    {
        $script = $this->render();

        $this->assertStringContainsString("\$HostsLine    = '192.168.100.52  bingoo-edge.local  # Bingoo Edge branch server'", $script);
        $this->assertStringContainsString("\$UrlOnline    = 'https://khatribiryani.bingoopos.com/pos'", $script);
        $this->assertStringContainsString("\$UrlOffline   = 'https://bingoo-edge.local:8443/edge/local/login'", $script);
        $this->assertStringContainsString("\$UrlStatus    = 'https://bingoo-edge.local:8443/edge/local/pos/health'", $script);
        $this->assertStringContainsString("\$CheckByName  = 'https://bingoo-edge.local:8443/edge/local/health'", $script);
        $this->assertStringContainsString("\$CheckByIp    = 'https://192.168.100.52:8443/edge/local/health'", $script);
        foreach (["'BINGOO Online'        = \$UrlOnline", "'BINGOO Offline'       = \$UrlOffline", "'Branch Server Status' = \$UrlStatus"] as $link) {
            $this->assertStringContainsString($link, $script);
        }
        $this->assertStringContainsString("GetFolderPath('CommonDesktopDirectory')", $script);
        $this->assertStringContainsString("'IconIndex=0'", $script);
        $this->assertStringContainsString('Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 15', $script);
    }

    public function test_has_no_certificate_bypass_and_no_secret_material(): void
    {
        $script = $this->render(label: 'Main');

        foreach (['SkipCertificateCheck', 'ServerCertificateValidationCallback', 'CertificatePolicy', 'TrustAllCerts',
            'CheckCertificateRevocationList', 'Invoke-Expression', 'Add-Type', 'password', 'secret', 'passphrase', 'Set-ExecutionPolicy'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $script, "forbidden pattern: $forbidden");
        }
        $this->assertDoesNotMatchRegularExpression('/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $script);
        $this->assertDoesNotMatchRegularExpression('/\biex\b/i', $script);
    }

    public function test_output_is_ascii_with_crlf_and_no_leftover_placeholders(): void
    {
        $script = $this->render(label: "Khatri Biryani \u{2014} Caf\u{E9} \u{2018}Main\u{2019}");

        $this->assertDoesNotMatchRegularExpression('/[^\x09\x0A\x0D\x20-\x7E]/', $script);
        $this->assertStringNotContainsString('{{', $script);
        $this->assertSame(substr_count($script, "\n"), substr_count($script, "\r\n"));
        $this->assertStringContainsString("\$BranchLabel  = 'Khatri Biryani - Cafe ''Main'''", $script);
    }

    public function test_self_unblock_elevation_guidance_open_window_and_exit_codes(): void
    {
        $script = $this->render();

        $this->assertStringContainsString('Unblock-File -LiteralPath $PSCommandPath', $script);
        $this->assertStringContainsString("Start-Process -FilePath 'powershell.exe' -Verb RunAs", $script);
        $this->assertStringContainsString('-Elevated', $script);
        $this->assertStringContainsString('ADMINISTRATOR account', $script);              // non-admin users are told what to do
        $this->assertStringContainsString('ADMINISTRATOR account se sign in karwayein', $script); // ... in Roman Urdu too
        $this->assertStringContainsString('[void](Read-Host)', $script);                    // window stays open
        $this->assertStringContainsString('Wait-BeforeClose', $script);
        $this->assertStringContainsString('exit $exitCode', $script);
        $this->assertStringContainsString('exit 1', $script);
        $this->assertStringContainsString('$exitCode = 0', $script);
        $this->assertStringContainsString("[Net.SecurityProtocolType]::Tls12", $script);
        $this->assertStringContainsString("StoreLocation]::LocalMachine", $script);
        $this->assertStringContainsString("StoreName]::Root", $script);
        $this->assertStringContainsString("'.before-bingoo'", $script);                     // hosts backup before the first write
        $this->assertStringContainsString('older per-user copies refreshed', $script);
    }

    public function test_embedded_icon_is_the_bingoo_logo_as_a_128px_png_ico(): void
    {
        $script = $this->render();
        $this->assertSame(1, preg_match("/\\\$IconB64 = '([A-Za-z0-9+\\/=]+)'/", $script, $m));
        $ico = base64_decode($m[1], true);

        $this->assertNotFalse($ico);
        $this->assertSame([0, 1, 1], array_values(unpack('v3', substr($ico, 0, 6))));
        $entry = unpack('Cw/Ch/Ccolors/Creserved/vplanes/vbpp/Vsize/Voffset', substr($ico, 6, 16));
        $this->assertSame([128, 128, 1, 32, 22], [$entry['w'], $entry['h'], $entry['planes'], $entry['bpp'], $entry['offset']]);
        $png = substr($ico, 22);
        $this->assertSame($entry['size'], strlen($png));
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
        $info = getimagesizefromstring($png);
        $this->assertSame([128, 128], [$info[0], $info[1]]);
    }

    public function test_port_443_is_left_out_of_the_urls(): void
    {
        $script = $this->render(['lan_https_port' => '443']);

        $this->assertStringContainsString("\$UrlOffline   = 'https://bingoo-edge.local/edge/local/login'", $script);
        $this->assertStringContainsString("\$CheckByIp    = 'https://192.168.100.52/edge/local/health'", $script);
        $this->assertStringNotContainsString(':443', $script);
    }

    public function test_accepts_k5_wire_names_and_a_colon_formatted_lowercase_fingerprint(): void
    {
        $renderer = new EdgeCounterSetupRenderer;
        $script = $renderer->renderWindows([
            'lan_hostname' => 'Bingoo-Edge.Local',
            'lan_ip' => '10.20.30.40',
            'https_port' => 8443,
            'ca_certificate_pem' => self::KHATRI_CA_PEM,
            'ca_sha256' => strtolower(implode(':', str_split(self::KHATRI_CA_SHA256, 2))),
        ], self::ONLINE, 'Gulshan');

        $this->assertStringContainsString("\$HostsLine    = '10.20.30.40  bingoo-edge.local  # Bingoo Edge branch server'", $script);
        $this->assertStringContainsString("\$LanHint      = '10.20.30.x'", $script);
    }

    public function test_a_freshly_generated_ca_is_accepted_and_its_fingerprints_are_computed(): void
    {
        $ca = self::cert('ca');
        $der = base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', $ca['pem']));

        $script = $this->render(['lan_ca_pem' => $ca['pem'], 'lan_ca_sha256' => hash('sha256', $der)]);

        $this->assertStringContainsString("\$CaThumbprint = '" . strtoupper(hash('sha1', $der)) . "'", $script);
        $this->assertStringContainsString("\$CaName       = 'Bingoo Edge Branch CA (Test-01)'", $script);
    }

    public function test_the_sha256_is_optional_but_must_match_when_given(): void
    {
        $this->assertStringContainsString(self::KHATRI_CA_SHA1, $this->render(['lan_ca_sha256' => null]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not match');
        $this->render(['lan_ca_sha256' => str_repeat('AB', 32)]);
    }

    public function test_windows_file_name(): void
    {
        $r = new EdgeCounterSetupRenderer;

        $this->assertSame('Setup-Khatri-Biryani-Counter.ps1', $r->windowsFileName('Khatri Biryani'));
        $this->assertSame('Setup-Gulshan-E-Iqbal-2-Counter.ps1', $r->windowsFileName("gulshan-e-iqbal #2"));
        $this->assertMatchesRegularExpression('/^Setup-[A-Za-z0-9-]+-Counter\.ps1$/', $r->windowsFileName("\u{0627}\u{0631}\u{062F}\u{0648}")); // transliterated, ASCII only
        $this->assertSame('Setup-Branch-Counter.ps1', $r->windowsFileName('!!!'));
        $this->assertSame('Setup-Branch-Counter.ps1', $r->windowsFileName('../..\\'));
    }

    // ---- validation ----------------------------------------------------------------------------------------------

    public static function badProfiles(): array
    {
        return [
            'ip out of range' => [['lan_ip' => '999.1.1.1'], 'lan_ip'],
            'ip too short' => [['lan_ip' => '192.168.1'], 'lan_ip'],
            'ip with injection' => [['lan_ip' => "192.168.1.5'; calc"], 'lan_ip'],
            'ip empty' => [['lan_ip' => ''], 'lan_ip'],
            'ipv6' => [['lan_ip' => 'fe80::1'], 'lan_ip'],
            'public ip' => [['lan_ip' => '8.8.8.8'], 'private'],
            'loopback ip' => [['lan_ip' => '127.0.0.1'], 'private'],
            'link-local ip' => [['lan_ip' => '169.254.10.10'], 'private'],
            'hostname with space' => [['lan_hostname' => 'bingoo edge.local'], 'lan_hostname'],
            'hostname with quote' => [['lan_hostname' => "bingoo'edge.local"], 'lan_hostname'],
            'hostname with semicolon' => [['lan_hostname' => 'a.local;calc'], 'lan_hostname'],
            'hostname empty' => [['lan_hostname' => ''], 'lan_hostname'],
            'hostname localhost' => [['lan_hostname' => 'localhost'], 'lan_hostname'],
            'hostname is an ip' => [['lan_hostname' => '192.168.1.5'], 'lan_hostname'],
            'hostname label too long' => [['lan_hostname' => str_repeat('a', 64) . '.local'], 'lan_hostname'],
            'port zero' => [['lan_https_port' => 0], 'lan_https_port'],
            'port too big' => [['lan_https_port' => 70000], 'lan_https_port'],
            'port text' => [['lan_https_port' => 'abc'], 'lan_https_port'],
            'port missing' => [['lan_https_port' => null], 'lan_https_port'],
            'pem missing' => [['lan_ca_pem' => ''], 'exactly one PEM certificate'],
            'pem garbage' => [['lan_ca_pem' => "-----BEGIN CERTIFICATE-----\nnot base64 !!\n-----END CERTIFICATE-----"], 'exactly one PEM certificate'],
            'pem not x509' => [['lan_ca_pem' => "-----BEGIN CERTIFICATE-----\n" . base64_encode('hello world') . "\n-----END CERTIFICATE-----"], 'X.509'],
            'two certificates' => [['lan_ca_pem' => self::KHATRI_CA_PEM . "\n" . self::KHATRI_CA_PEM], 'exactly one PEM certificate'],
            'private key alongside' => [['lan_ca_pem' => self::KHATRI_CA_PEM . "\n-----BEGIN PRIVATE KEY-----\nMIIB\n-----END PRIVATE KEY-----"], 'private key'],
            'sha256 not hex' => [['lan_ca_sha256' => 'xyz'], '64 hex digits'],
            'branch id injection' => [['branch_id' => "1'; drop"], 'branch_id'],
        ];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('badProfiles')]
    public function test_rejects_a_bad_lan_profile(array $override, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->render($override);
    }

    public static function badCertificates(): array
    {
        return [
            'server (non-CA) certificate' => ['leaf', 'not a CA certificate'],
            'intermediate CA (not self-signed)' => ['intermediate', 'self-signed branch ROOT CA'],
            'CA without certificate signing' => ['nosign', 'Certificate Sign'],
            'expired CA' => ['expired', 'expired'],
        ];
    }

    #[DataProvider('badCertificates')]
    public function test_rejects_a_certificate_that_is_not_a_valid_branch_root_ca(string $kind, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->render(['lan_ca_pem' => self::cert($kind)['pem'], 'lan_ca_sha256' => null]);
    }

    public static function badOnlineUrls(): array
    {
        return [
            'http' => ['http://khatribiryani.bingoopos.com/pos'],
            'quote' => ["https://khatribiryani.bingoopos.com/pos'+(calc)+'"],
            'space' => ['https://khatribiryani.bingoopos.com/p os'],
            'credentials' => ['https://user:pw@khatribiryani.bingoopos.com/pos'],
            'newline' => ["https://khatribiryani.bingoopos.com/pos\r\nURL=https://evil.example"],
            'backtick' => ['https://khatribiryani.bingoopos.com/`pos'],
            'not a url' => ['khatribiryani'],
        ];
    }

    #[DataProvider('badOnlineUrls')]
    public function test_rejects_an_unsafe_online_pos_url(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new EdgeCounterSetupRenderer)->renderWindows($this->profile(), $url, 'Main');
    }

    // ---- phone onboarding ----------------------------------------------------------------------------------------

    public function test_phone_onboarding_shape(): void
    {
        $data = (new EdgeCounterSetupRenderer)->phoneOnboarding($this->profile());

        $this->assertSame('https://192.168.100.52:8443/edge/local/login', $data['login_url']);
        $this->assertSame($data['login_url'], $data['qr_text']);
        $this->assertSame('https://192.168.100.52:8443/edge/local/pos/health', $data['status_url']);
        $this->assertSame(['ip' => '192.168.100.52', 'hostname' => 'bingoo-edge.local', 'https_port' => 8443], $data['branch_server']);

        $this->assertSame('tenant.offline-edge.branch.ca', $data['ca']['download_route']);
        $this->assertSame('branch', $data['ca']['download_route_parameter']);
        $this->assertSame([], $data['ca']['download_route_parameters']);
        $this->assertSame('application/x-x509-ca-cert', $data['ca']['mime_type']);
        $this->assertSame('bingoo-branch-ca.crt', $data['ca']['file_name']);
        $this->assertSame('Bingoo Edge Branch CA (Khatri-01)', $data['ca']['name']);
        $this->assertSame(implode(':', str_split(self::KHATRI_CA_SHA256, 2)), $data['ca']['sha256']);
        $this->assertSame('2031-11-21', $data['ca']['valid_until']);

        $this->assertSame(['android', 'ios'], array_keys($data['platforms']));
        foreach ($data['platforms'] as $platform) {
            $this->assertNotSame('', $platform['name']);
            $this->assertGreaterThanOrEqual(4, count($platform['steps']));
            foreach ($platform['steps'] as $step) {
                $this->assertSame(['en', 'ur'], array_keys($step));
                $this->assertNotSame('', trim($step['en']));
                $this->assertNotSame('', trim($step['ur']));
            }
        }
        $android = implode(' ', array_column($data['platforms']['android']['steps'], 'en'));
        $ios = implode(' ', array_column($data['platforms']['ios']['steps'], 'en'));
        $this->assertStringContainsString('CA certificate', $android);
        $this->assertStringContainsString('Install anyway', $android);
        $this->assertStringContainsString('SAFARI', $ios);
        $this->assertStringContainsString('Certificate Trust Settings', $ios);
        $this->assertStringContainsString('Bingoo Edge Branch CA (Khatri-01)', $ios);
        $this->assertNotEmpty($data['warnings']);
        $this->assertStringNotContainsStringIgnoringCase('password', json_encode($data));
    }

    public function test_phone_onboarding_carries_the_branch_route_parameter_when_known(): void
    {
        $data = (new EdgeCounterSetupRenderer)->phoneOnboarding($this->profile(['branch_id' => 7, 'lan_https_port' => 443]));

        $this->assertSame(['branch' => 7], $data['ca']['download_route_parameters']);
        $this->assertSame('https://192.168.100.52/edge/local/login', $data['login_url']);
    }

    public function test_phone_onboarding_validates_the_profile_too(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new EdgeCounterSetupRenderer)->phoneOnboarding($this->profile(['lan_ip' => '8.8.8.8']));
    }

    // ---- contract K7 ---------------------------------------------------------------------------------------------

    public function test_satisfies_the_counter_setup_renderer_contract(): void
    {
        if (! interface_exists(\App\Contracts\Edge\CounterSetupRenderer::class)) {
            require_once base_path('tests/Support/Edge/CounterSetupRendererContract.php');
        }
        $contract = new ReflectionClass(\App\Contracts\Edge\CounterSetupRenderer::class);
        $class = new ReflectionClass(EdgeCounterSetupRenderer::class);
        $this->assertFalse($class->isFinal(), 'the lead adds `implements` at integration; tests extend it below');

        $sig = fn (ReflectionParameter $p) => [$p->getName(), (string) $p->getType(), $p->isOptional()];
        foreach ($contract->getMethods() as $method) {
            $impl = $class->getMethod($method->getName());
            $this->assertTrue($impl->isPublic(), $method->getName());
            $this->assertSame((string) $method->getReturnType(), (string) $impl->getReturnType(), $method->getName());
            $this->assertSame(array_map($sig, $method->getParameters()), array_map($sig, $impl->getParameters()), $method->getName());
        }

        $adapter = new class extends EdgeCounterSetupRenderer implements \App\Contracts\Edge\CounterSetupRenderer {};
        $this->assertInstanceOf(\App\Contracts\Edge\CounterSetupRenderer::class, $adapter);
        $this->assertStringContainsString(self::KHATRI_CA_SHA1, $adapter->renderWindows($this->profile(), self::ONLINE, 'Khatri'));
    }

    // ---- PowerShell gates ----------------------------------------------------------------------------------------

    public function test_rendered_scripts_parse_with_zero_errors_and_use_only_expected_commands(): void
    {
        $dir = $this->tempDir();
        $variants = [
            'khatri' => $this->render(),
            'hostile label' => $this->render(label: "Ali's Caf\u{E9} \n'; Remove-Item C:\\ -Recurse # \$(calc) `\"@'"),
            'port 443 + 10/8' => $this->render(['lan_ip' => '10.1.2.3', 'lan_https_port' => 443], 'Gulshan'),
            'generated ca' => $this->render(['lan_ca_pem' => self::cert('ca')['pem'], 'lan_ca_sha256' => null], 'Test'),
        ];
        $paths = [];
        foreach ($variants as $name => $text) {
            $paths[$name] = $dir . DIRECTORY_SEPARATOR . 'Setup-' . substr(md5($name), 0, 8) . '-Counter.ps1';
            file_put_contents($paths[$name], $text);
        }
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'list.txt', implode("\n", $paths));

        $results = json_decode($this->powershell(self::PARSE_HARNESS, ['-ListFile', $dir . DIRECTORY_SEPARATOR . 'list.txt']), true);
        $this->assertCount(count($variants), $results);

        foreach ($results as $i => $result) {
            $name = array_keys($variants)[$i];
            $this->assertSame(0, $result['errors'], $name . ': ' . implode('; ', (array) $result['messages']));
            $commands = array_map('strtolower', (array) $result['commands']);
            foreach (['unblock-file', 'start-process', 'read-host', 'invoke-webrequest', 'write-host', 'start-transcript'] as $needed) {
                $this->assertContains($needed, $commands, "$name uses $needed");
            }
            foreach (['invoke-expression', 'iex', 'add-type', 'remove-item', 'set-executionpolicy', 'invoke-restmethod', 'new-selfsignedcertificate'] as $never) {
                $this->assertNotContains($never, $commands, "$name must not use $never");
            }
        }
        $this->assertStringContainsString("\$BranchLabel  = 'Ali''s Cafe ''; Remove-Item C:\\ -Recurse # \$(calc) `\"@'''", $variants['hostile label']);
    }

    public static function hostsFiles(): array
    {
        $line = '192.168.100.52  bingoo-edge.local  # Bingoo Edge branch server';
        $default = "# Copyright (c) 1993-2009 Microsoft Corp.\r\n#\r\n#\t127.0.0.1       localhost\r\n#\t::1             localhost\r\n";

        return [
            'windows default' => [$default, 'written', [$line], ['# Copyright (c) 1993-2009 Microsoft Corp.', "#\t127.0.0.1       localhost"], [], true],
            'stale ip + alias line' => ["127.0.0.1 localhost\r\n192.168.1.9  bingoo-edge.local\r\n10.0.0.1 other.local BINGOO-EDGE.LOCAL # lab\r\n", 'written',
                [$line, '127.0.0.1 localhost', "10.0.0.1\tother.local"], [], ['192.168.1.9', 'BINGOO-EDGE.LOCAL'], true],
            'khatri line already there' => ["127.0.0.1 localhost\r\n192.168.100.52  bingoo-edge.local\r\n", 'already present',
                ['192.168.100.52  bingoo-edge.local'], [], ['# Bingoo Edge branch server'], false],
            'duplicates collapse' => ["192.168.100.52 bingoo-edge.local\r\n192.168.100.52 bingoo-edge.local\r\n", 'written',
                ['192.168.100.52 bingoo-edge.local'], [], [], true],
        ];
    }

    /**
     * @param  list<string>  $mustHaveOnce
     * @param  list<string>  $mustKeep
     * @param  list<string>  $mustDrop
     */
    #[DataProvider('hostsFiles')]
    public function test_hosts_editor_excerpt_is_idempotent_and_keeps_foreign_lines(string $before, string $firstResult, array $mustHaveOnce, array $mustKeep, array $mustDrop, bool $backup): void
    {
        $dir = $this->tempDir();
        $script = $dir . DIRECTORY_SEPARATOR . 'Setup-Hosts-Counter.ps1';
        file_put_contents($script, $this->render());
        $hosts = $dir . DIRECTORY_SEPARATOR . 'hosts';
        file_put_contents($hosts, $before);

        $r = json_decode($this->powershell(self::HOSTS_HARNESS, ['-Script', $script, '-HostsPath', $hosts]), true);

        $this->assertStringStartsWith($firstResult, $r['first']);
        $this->assertSame('already present', $r['second'], 'a second run changes nothing');
        $this->assertSame($r['afterFirst'], $r['afterSecond']);
        $lines = preg_split('/\r?\n/', $r['afterSecond']);
        foreach ($mustHaveOnce as $l) {
            $this->assertSame(1, count(array_keys($lines, $l, true)), "exactly once: $l");
        }
        foreach ($mustKeep as $l) {
            $this->assertContains($l, $lines);
        }
        foreach ($mustDrop as $s) {
            $this->assertStringNotContainsString($s, $r['afterSecond']);
        }
        $this->assertSame(1, preg_match_all('/^\S+\s+bingoo-edge\.local\b/mi', $r['afterSecond']), 'exactly one mapping for the branch server');
        $this->assertSame($backup, $r['backup']);
        if ($backup) {
            $this->assertSame($before, file_get_contents($hosts . '.before-bingoo'));
        }
    }

    public function test_ca_fingerprint_excerpt_agrees_with_php_and_refuses_a_mismatch(): void
    {
        $dir = $this->tempDir();
        $script = $dir . DIRECTORY_SEPARATOR . 'Setup-Ca-Counter.ps1';
        file_put_contents($script, $this->render());

        $ok = json_decode($this->powershell(self::CA_HARNESS, ['-Script', $script]), true);
        $this->assertSame('HASHES-OK', $ok['result'], (string) $ok['error']);

        $bad = json_decode($this->powershell(self::CA_HARNESS, ['-Script', $script, '-Thumbprint', str_repeat('0', 40)]), true);
        $this->assertNull($bad['result']);
        $this->assertStringContainsString('is not the expected one', (string) $bad['error']);
    }

    // ---- helpers -------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $override */
    private function profile(array $override = []): array
    {
        return array_merge([
            'lan_hostname' => 'bingoo-edge.local',
            'lan_ip' => '192.168.100.52',
            'lan_https_port' => 8443,
            'lan_ca_pem' => self::KHATRI_CA_PEM,
            'lan_ca_sha256' => self::KHATRI_CA_SHA256,
        ], $override);
    }

    /** @param array<string, mixed> $override */
    private function render(array $override = [], string $label = 'Khatri Biryani'): string
    {
        return (new EdgeCounterSetupRenderer)->renderWindows($this->profile($override), self::ONLINE, $label);
    }

    private function tempDir(): string
    {
        if ($this->tmp === null) {
            $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bingoo-counter-setup-test-' . bin2hex(random_bytes(6));
            File::ensureDirectoryExists($this->tmp);
        }

        return $this->tmp;
    }

    /** Runs a harness (never the generated script) with Windows PowerShell; skips where no PowerShell exists. */
    private function powershell(string $harness, array $args): string
    {
        $exe = PHP_OS_FAMILY === 'Windows' ? 'powershell.exe' : (new ExecutableFinder)->find('pwsh');
        if ($exe === null) {
            $this->markTestSkipped('PowerShell is not available on this machine.');
        }
        $file = $this->tempDir() . DIRECTORY_SEPARATOR . 'harness-' . bin2hex(random_bytes(4)) . '.ps1';
        file_put_contents($file, $harness);
        $process = new Process(array_merge([$exe, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $file], $args));
        $process->setTimeout(180);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

        return trim($process->getOutput());
    }

    /** @return array{pem: string, key: mixed, cert: mixed} */
    private static function cert(string $kind): array
    {
        if (isset(self::$certs[$kind])) {
            return self::$certs[$kind];
        }
        $signer = $kind === 'intermediate' ? self::cert('ca') : null;
        $cnf = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bingoo-counter-setup-openssl-' . getmypid() . '-' . $kind . '.cnf';
        file_put_contents($cnf, "[req]\ndistinguished_name=dn\n[dn]\n"
            . "[v3_ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n"
            . "[v3_leaf]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n"
            . "[v3_nosign]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature\n");
        // days = 0 makes notAfter == the issuance second, i.e. already expired for the renderer (validTo <= now).
        [$ext, $cn, $days] = match ($kind) {
            'ca' => ['v3_ca', 'Bingoo Edge Branch CA (Test-01)', 365],
            'leaf' => ['v3_leaf', 'bingoo-edge.local', 365],
            'intermediate' => ['v3_ca', 'Bingoo Edge Intermediate (Test-01)', 365],
            'nosign' => ['v3_nosign', 'Bingoo Edge No-Sign CA', 365],
            'expired' => ['v3_ca', 'Bingoo Edge Expired CA', 0],
        };
        $opts = ['config' => $cnf, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048, 'digest_alg' => 'sha256', 'x509_extensions' => $ext];
        $key = openssl_pkey_new($opts);
        $csr = openssl_csr_new(['commonName' => $cn, 'organizationName' => 'Bingoo POS Edge'], $key, $opts);
        $cert = openssl_csr_sign($csr, $signer['cert'] ?? null, $signer['key'] ?? $key, $days, $opts, random_int(1, PHP_INT_MAX));
        openssl_x509_export($cert, $pem);
        @unlink($cnf);

        return self::$certs[$kind] = ['pem' => $pem, 'key' => $key, 'cert' => $cert];
    }

    private const PARSE_HARNESS = <<<'PS1'
param([string]$ListFile)
$ErrorActionPreference = 'Stop'
$out = @()
foreach ($p in @(Get-Content -LiteralPath $ListFile)) {
    if (-not $p) { continue }
    $tokens = $null; $errors = $null
    $ast = [System.Management.Automation.Language.Parser]::ParseFile($p, [ref]$tokens, [ref]$errors)
    $commands = @($ast.FindAll({ param($n) $n -is [System.Management.Automation.Language.CommandAst] }, $true) | ForEach-Object { $_.GetCommandName() } | Where-Object { $_ } | Sort-Object -Unique)
    $messages = @($errors | ForEach-Object { 'line ' + $_.Extent.StartLineNumber + ': ' + $_.Message })
    $out += [pscustomobject]@{ errors = @($errors).Count; messages = $messages; commands = $commands }
}
ConvertTo-Json -InputObject @($out) -Depth 4 -Compress
PS1;

    /** Loads ONLY Set-HostsEntry from the generated script, pointed at a temp file, without ipconfig. */
    private const HOSTS_HARNESS = <<<'PS1'
param([string]$Script, [string]$HostsPath)
$ErrorActionPreference = 'Stop'
$tokens = $null; $errors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile($Script, [ref]$tokens, [ref]$errors)
$fn = @($ast.FindAll({ param($n) $n -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq 'Set-HostsEntry' }, $true))[0]
$text = $fn.Extent.Text
$pathExpr = "Join-Path `$env:SystemRoot 'System32\drivers\etc\hosts'"
$flush = 'try { & ipconfig.exe /flushdns | Out-Null } catch { }'
if (-not $text.Contains($pathExpr) -or -not $text.Contains($flush)) { throw 'unexpected Set-HostsEntry shape' }
$text = $text.Replace($pathExpr, "'" + $HostsPath.Replace("'", "''") + "'").Replace($flush, '')
if ($text -match 'SystemRoot|drivers|ipconfig|Cert:|X509Store') { throw 'refusing to run an excerpt that touches the system' }
foreach ($name in @('EdgeIp', 'EdgeHost', 'HostsLine')) {
    $assign = @($ast.FindAll({ param($n) $n -is [System.Management.Automation.Language.AssignmentStatementAst] -and $n.Left.Extent.Text -eq ('$' + $name) }, $false))[0]
    . ([scriptblock]::Create($assign.Extent.Text))
}
. ([scriptblock]::Create($text))
$first = Set-HostsEntry
$afterFirst = [IO.File]::ReadAllText($HostsPath)
$second = Set-HostsEntry
$afterSecond = [IO.File]::ReadAllText($HostsPath)
[pscustomobject]@{ first = [string]$first; second = [string]$second; afterFirst = $afterFirst; afterSecond = $afterSecond; backup = (Test-Path -LiteralPath ($HostsPath + '.before-bingoo')) } | ConvertTo-Json -Compress
PS1;

    /** Loads Install-BranchCa from the generated script CUT OFF before the certificate store is opened. */
    private const CA_HARNESS = <<<'PS1'
param([string]$Script, [string]$Thumbprint = '')
$ErrorActionPreference = 'Stop'
$tokens = $null; $errors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile($Script, [ref]$tokens, [ref]$errors)
$fn = @($ast.FindAll({ param($n) $n -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq 'Install-BranchCa' }, $true))[0]
$text = $fn.Extent.Text
$cut = $text.IndexOf('$store = New-Object')
if ($cut -lt 0) { throw 'unexpected Install-BranchCa shape' }
$text = $text.Substring(0, $cut) + "return 'HASHES-OK'`n}"
if ($text -match 'X509Store|Cert:|LocalMachine|\.Add\(') { throw 'refusing to run an excerpt that touches the certificate store' }
foreach ($name in @('CaPem', 'CaThumbprint', 'CaSha256')) {
    $assign = @($ast.FindAll({ param($n) $n -is [System.Management.Automation.Language.AssignmentStatementAst] -and $n.Left.Extent.Text -eq ('$' + $name) }, $false))[0]
    . ([scriptblock]::Create($assign.Extent.Text))
}
if ($Thumbprint) { $CaThumbprint = $Thumbprint }
. ([scriptblock]::Create($text))
$result = $null; $err = $null
try { $result = Install-BranchCa } catch { $err = $_.Exception.Message }
[pscustomobject]@{ result = $result; error = $err } | ConvertTo-Json -Compress
PS1;
}
