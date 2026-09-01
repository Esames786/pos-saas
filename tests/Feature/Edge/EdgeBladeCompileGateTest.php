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
