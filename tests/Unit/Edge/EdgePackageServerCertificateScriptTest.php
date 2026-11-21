<?php

namespace Tests\Unit\Edge;

use PHPUnit\Framework\TestCase;

/**
 * Edge 0.9 (stream E) — scripts/edge/New-EdgeServerCertificate.ps1 caps the server certificate validity at 825 days
 * (iOS / macOS refuse a longer TLS server certificate even from a trusted CA): -ValidDays default 730, max 825;
 * -ValidYears is a deprecated alias (1..2). The serverAuth type and the DNS+IPAddress SAN TextExtension are unchanged.
 * Every refusal happens BEFORE the certificate store is touched, so the behavioural cases run with -WhatIf safely.
 */
class EdgePackageServerCertificateScriptTest extends TestCase
{
    private function script(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'edge' . DIRECTORY_SEPARATOR . 'New-EdgeServerCertificate.ps1';
    }

    /** @return array{0:int, 1:string} */
    private function powershell(array $args): array
    {
        $p = proc_open(array_merge(['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($p), (string) $out];
    }

    private function runScript(array $extra): array
    {
        return $this->powershell(array_merge(['-File', $this->script(), '-Hostname', 'bingoo-edge.local', '-ReservedIp', '192.168.100.52',
            '-CaThumbprint', 'NOSUCHTHUMBPRINT0000', '-OutDir', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-cert-never-created', '-WhatIf'], $extra));
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->markTestSkipped('Windows PowerShell only');
        }
    }

    public function test_the_script_parses_and_keeps_the_server_auth_type_and_the_ip_san(): void
    {
        [$code, $out] = $this->powershell(['-Command', '$e=$null; $t=$null; [void][System.Management.Automation.Language.Parser]::ParseFile(\'' . $this->script() . '\',[ref]$t,[ref]$e); "PARSE_ERRORS=" + $e.Count']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('PARSE_ERRORS=0', $out);
        $src = (string) file_get_contents($this->script());
        $this->assertStringContainsString('-Type SSLServerAuthentication', $src);
        $this->assertStringContainsString('-TextExtension @("2.5.29.17={text}DNS=$Hostname&IPAddress=$ReservedIp")', $src);
        $this->assertStringContainsString('[int] $ValidDays = 730', $src);
        $this->assertStringContainsString('$MaxValidDays = 825', $src);
        $this->assertStringNotContainsString('AddYears(', $src, 'validity is computed in days only');
        $this->assertDoesNotMatchRegularExpression('/[^\x00-\x7F]/', substr($src, (int) strpos($src, 'param(')), 'PS 5.1: keep the code ASCII');
    }

    public function test_validity_above_825_days_is_refused_before_the_store_is_touched(): void
    {
        [$code, $out] = $this->runScript(['-ValidDays', '826']);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('ValidDays must be between 1 and 825', $out);

        [$code, $out] = $this->runScript(['-ValidYears', '3']);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('ValidYears (deprecated) must be 1 or 2', $out);

        [$code, $out] = $this->runScript(['-ValidYears', '2', '-ValidDays', '700']);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('Use -ValidDays only', $out);
    }

    public function test_an_allowed_validity_passes_validation_and_reaches_the_ca_lookup(): void
    {
        foreach ([['-ValidDays', '825'], [], ['-ValidYears', '2']] as $extra) {
            [$code, $out] = $this->runScript($extra);
            $this->assertNotSame(0, $code, 'the fake CA thumbprint stops it');
            $this->assertStringContainsString('Branch CA with thumbprint NOSUCHTHUMBPRINT0000 not found', $out, implode(' ', $extra) . ': ' . $out);
            $this->assertStringNotContainsString('must be between', $out);
        }
        $this->assertFileDoesNotExist(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-cert-never-created');
    }
}
