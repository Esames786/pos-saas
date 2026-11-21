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
     * Each Edge operator page is ONE inline script. `php -l` proves the PHP the Blade compiles to, never the JavaScript
     * the browser runs — a stray brace there breaks every workflow on the till with no server error at all. When a
     * Node runtime is available (Laragon ships one), the extracted script must pass `node --check`.
     *
     * Phase 3 Stage B: the old cashier page (views/edge/pos/index.blade.php + its `@include('edge.pos.js.*')` fragments) is
     * deleted; the SHARED cashier view's scripts are checked by tests/Feature/Pos/SharedPosViewRenderTest
     * (every inline script of the rendered page). Only the finance operator pages remain on this convention.
     */
    public function test_the_edge_operator_page_scripts_parse_as_javascript(): void
    {
        $candidates = array_filter(array_merge(
            glob('D:/laragon2/bin/nodejs/*/node.exe') ?: [],
            glob('/usr/bin/node') ?: [],
            glob('/usr/local/bin/node') ?: [],
        ));
        rsort($candidates); // the newest packaged runtime first (node-v20 over node-v18)
        $node = getenv('EDGE_NODE_BIN') && is_file(getenv('EDGE_NODE_BIN')) ? getenv('EDGE_NODE_BIN')
            : ($candidates ? reset($candidates) : ((new \Symfony\Component\Process\ExecutableFinder())->find('node') ?: null));
        if (! $node) {
            $this->markTestSkipped('no Node runtime available to syntax-check the cashier script');
        }

        // The Edge operator pages built on the single-inline-script convention (F2: the Suppliers / Supplier Ledger /
        // Record Payment page and the General Journal page; F3: Purchase Returns).
        $pages = ['views/edge/finance/suppliers.blade.php', 'views/edge/finance/journal.blade.php', 'views/edge/finance/purchase-returns.blade.php'];
        $this->assertFileDoesNotExist(resource_path('views/edge/pos/index.blade.php'), 'Stage B: the old Edge cashier page is deleted — the shared view is the only cashier page');
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

        $this->assertViewsCompileAndLint($views);
    }

    /**
     * W-C (Edge next release §4.5) — the SHARED cashier view: the Online POS page (resources/views/tenant/pos/**, every
     * depth) is the page the Branch Server renders once W-A/W-B land, so it takes the same compile + `php -l` gate.
     */
    public function test_the_shared_online_pos_views_compile_and_the_generated_php_lints(): void
    {
        $views = $this->bladeFilesUnder(resource_path('views/tenant/pos'));
        $this->assertContains(str_replace('\\', '/', resource_path('views/tenant/pos/index.blade.php')), $views, 'the shared POS page must exist');

        $this->assertViewsCompileAndLint($views);
    }

    /** W-C — the shared POS layout (layouts/pos.blade.php, W-A). Skipped until W-A creates it; a compile error fails. */
    public function test_the_shared_pos_layout_compiles_and_the_generated_php_lints(): void
    {
        $layout = resource_path('views/layouts/pos.blade.php');
        if (! is_file($layout)) {
            $this->markTestSkipped('resources/views/layouts/pos.blade.php does not exist yet (W-A creates it) — the gate engages as soon as it does.');
        }

        $this->assertViewsCompileAndLint([$layout]);
    }

    /** @return string[] every *.blade.php under $dir at any depth (POSIX slashes, sorted) */
    private function bladeFilesUnder(string $dir): array
    {
        $files = [];
        if (is_dir($dir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php')) {
                    $files[] = str_replace('\\', '/', $f->getPathname());
                }
            }
        }
        sort($files);

        return $files;
    }

    /** @param string[] $views */
    private function assertViewsCompileAndLint(array $views): void
    {
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
