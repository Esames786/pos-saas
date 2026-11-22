<?php

namespace Tests\Unit\Edge;

use PHPUnit\Framework\TestCase;

/**
 * Edge 0.9 (CP5 run 12) — every namespace-qualified .NET type the PowerShell under scripts/ names (New-Object <Type>,
 * [Type] literals and casts) must resolve on Windows PowerShell 5.1. The wizard's fresh-install MySQL step used
 * "New-Object Text.Encoding.ASCIIEncoding" (no such type); only a real fresh install in Windows Sandbox reached it.
 */
class EdgeScriptsTypeNamesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->markTestSkipped('Windows PowerShell only');
        }
    }

    /** @return array{0:int, 1:string} */
    private function scan(string $root): array
    {
        $script = dirname(__DIR__, 2) . '\\Fixtures\\EdgeScripts\\Find-UnresolvedTypeNames.ps1';
        $cmd = ['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $script, '-Root', $root];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($p), (string) $out];
    }

    public function test_every_type_named_by_the_edge_scripts_resolves(): void
    {
        [$code, $out] = $this->scan(dirname(__DIR__, 3) . '\\scripts');

        $this->assertStringContainsString('unresolved: 0', $out, $out);
        $this->assertSame(0, $code, $out);
    }

    public function test_the_scan_reports_a_type_that_does_not_exist(): void
    {
        $dir = sys_get_temp_dir() . '\\edge-typescan-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '\\bad.ps1', "\$a = New-Object Text.Encoding.ASCIIEncoding\r\n\$b = New-Object Text.ASCIIEncoding\r\n"
            . "\$c = [System.Collections.Generic.List[string]]::new()\r\n\$d = [IO.Fileee]::Exists('x')\r\n");
        try {
            [$code, $out] = $this->scan($dir);
        } finally {
            @unlink($dir . '\\bad.ps1');
            @rmdir($dir);
        }

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('bad.ps1:1 Text.Encoding.ASCIIEncoding', $out);
        $this->assertStringContainsString('bad.ps1:4 IO.Fileee', $out);
        $this->assertStringContainsString('unresolved: 2', $out);
    }
}
