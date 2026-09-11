<?php

namespace Tests\Feature\Edge;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * EDGE-CASHIER-UI-1 — release gate: every Edge Blade view must COMPILE, and the PHP the Blade compiler
 * generates from it must pass `php -l`.
 *
 * The real-HTTP render test proves the ONE path a happy-case GET takes through the cashier Blade; this gate
 * proves every Edge view compiles to valid PHP at all — catching a broken directive or an unbalanced
 * @if/@foreach on a branch the render test does not execute, before it ships to an appliance with no
 * developer present. It complements, and does not replace, the render guard.
 */
class EdgeBladeCompileGateTest extends TestCase
{
    /**
     * The cashier page is ONE inline script. `php -l` proves the PHP the Blade compiles to, never the JavaScript
     * the browser runs — a stray brace there breaks every workflow on the till with no server error at all. When a
     * Node runtime is available (Laragon ships one), the extracted script must pass `node --check`.
     */
    public function test_the_cashier_page_script_parses_as_javascript(): void
    {
        $candidates = array_filter(array_merge(
            glob('D:/laragon2/bin/nodejs/*/node.exe') ?: [],
            glob('/usr/bin/node') ?: [],
            glob('/usr/local/bin/node') ?: [],
        ));
        $node = $candidates ? reset($candidates) : ((new \Symfony\Component\Process\ExecutableFinder())->find('node') ?: null);
        if (! $node) {
            $this->markTestSkipped('no Node runtime available to syntax-check the cashier script');
        }

        // The cashier page plus every other Edge operator page built on the same single-inline-script convention
        // (F2: the Suppliers / Supplier Ledger / Record Payment page and the General Journal page).
        $pages = ['views/edge/pos/index.blade.php', 'views/edge/finance/suppliers.blade.php', 'views/edge/finance/journal.blade.php', 'views/edge/finance/purchase-returns.blade.php'];
        foreach ($pages as $page) {
            $html = file_get_contents(resource_path($page));
            $start = strpos($html, "<script>\n    (function");
            $end = strrpos($html, '</script>');
            $this->assertNotFalse($start, "{$page} must carry its inline script");
            $js = substr($html, $start + 8, $end - $start - 8);
            $js = preg_replace('/@json\(.*\);/', 'null;', $js);            // server-injected JSON literal → a JS literal
            $js = preg_replace('/\{\{[\s\S]*?\}\}/', 'X', $js);

            $tmp = tempnam(sys_get_temp_dir(), 'edge_pos_js_') . '.js';
            file_put_contents($tmp, $js);
            $out = [];
            $code = 0;
            exec(escapeshellarg($node) . ' --check ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
            @unlink($tmp);
            $this->assertSame(0, $code, "{$page} script does not parse as JavaScript:\n" . implode("\n", $out));
        }
    }

    public function test_every_edge_blade_view_compiles_and_the_generated_php_lints(): void
    {
        $views = glob(resource_path('views/edge') . '/**/*.blade.php');
        $views = array_merge($views, glob(resource_path('views/edge') . '/*.blade.php'));
        $this->assertNotEmpty($views, 'expected Edge Blade views to exist');

        $php = (new \Symfony\Component\Process\PhpExecutableFinder())->find() ?: PHP_BINARY;

        foreach ($views as $path) {
            // 1. It must compile without throwing.
            $compiled = Blade::compileString(file_get_contents($path));
            $this->assertNotSame('', trim($compiled), "compiled output empty for {$path}");

            // 2. The generated PHP must be syntactically valid (php -l), exactly what ships.
            $tmp = tempnam(sys_get_temp_dir(), 'edge_blade_') . '.php';
            file_put_contents($tmp, "<?php ?>" . $compiled);
            $out = [];
            $code = 0;
            exec(escapeshellarg($php) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
            @unlink($tmp);
            $this->assertSame(0, $code, "generated PHP failed php -l for {$path}:\n" . implode("\n", $out));
        }
    }
}
