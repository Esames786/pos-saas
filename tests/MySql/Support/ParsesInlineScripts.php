<?php

namespace Tests\MySql\Support;

/**
 * Every inline <script> on a rendered page must parse.
 *
 * The PHPUnit harness cannot run a page's JavaScript, and asserting on its source text passes
 * happily while the whole block is dead: from 29 Sep to 7 Oct the product form's script failed to
 * parse in every browser and every test was green. `node --check` parses without running.
 */
trait ParsesInlineScripts
{
    /** @param string $mustContain text the page's own script carries — proves the check saw it */
    protected function assertInlineScriptsParse(string $html, string $page, string $mustContain): void
    {
        $node = $this->nodeBinary();

        preg_match_all('#<script([^>]*)>(.*?)</script>#s', $html, $m, PREG_SET_ORDER);
        // Inline JavaScript only: no src, and no data type such as application/json.
        $scripts = array_filter($m, fn ($s) => ! str_contains($s[1], 'src=')
            && (! preg_match('/type="([^"]+)"/', $s[1], $t) || in_array($t[1], ['text/javascript', 'module'], true)));
        $this->assertTrue(collect($scripts)->contains(fn ($s) => str_contains($s[2], $mustContain)),
            "{$page}: the page's own script was not found — this check would prove nothing");

        foreach ($scripts as $i => $script) {
            $tmp = tempnam(sys_get_temp_dir(), 'pscript');
            file_put_contents($tmp . '.js', $script[2]);
            $out = [];
            exec(escapeshellarg($node) . ' --check ' . escapeshellarg($tmp . '.js') . ' 2>&1', $out, $code);
            @unlink($tmp . '.js');
            @unlink($tmp);
            $this->assertSame(0, $code, "{$page}: inline script #{$i} does not parse — the whole block is dead in the browser:\n" . implode("\n", $out));
        }
    }

    private function nodeBinary(): string
    {
        $candidates = array_filter([
            getenv('NODE_BINARY') ?: null,
            trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null')) ?: null,
            ...glob('D:/laragon2/bin/nodejs/*/node.exe') ?: [],
        ]);
        foreach ($candidates as $candidate) {
            $candidate = strtok($candidate, "\r\n");
            if ($candidate && is_file($candidate)) {
                return $candidate;
            }
        }
        $this->markTestSkipped('node not found (set NODE_BINARY) — inline scripts cannot be parse-checked here.');
    }
}
