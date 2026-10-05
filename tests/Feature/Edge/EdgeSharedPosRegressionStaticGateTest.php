<?php

namespace Tests\Feature\Edge;

use App\Services\Edge\EdgePosRuntimeFactory;
use App\Support\Pos\PosRuntime;
use Illuminate\Support\Facades\Route;
use Tests\MySql\EdgeSharedPosRegressionGateMySqlTest as Gate;
use Tests\TestCase;

/**
 * PHASE 3 — the STATIC half of the shared-view regression gate (no database; runs in the fast Feature suite). The render
 * half (both runtimes rendered in one run, skeleton / controls / capability state / leaks / modals) is
 * tests/MySql/EdgeSharedPosRegressionGateMySqlTest. Mapping: docs/status/edge-phase3-census-replacement.md.
 *
 *   - the capability → control map the render gate asserts is COMPLETE against the view source (every `$posRuntime->can()` /
 *     `POS.can()` site is covered; every capability the Edge factory turns OFF has a gate in the view);
 *   - the OLD Edge page's own ids (the deleted resources/views/edge/pos/**, frozen in Gate::OLD_EDGE_ONLY_IDS) never appear
 *     in the shared view sources;
 *   - the shared view carries NO runtime branch (no isEdge() / app.role / mode === 'edge' — one markup, runtime via PosRuntime);
 *   - (d) static "no old Edge page" scan — STAGE B (6 Oct 2026): STRICT is the ONLY mode. The old folder is deleted and
 *     nothing under app/ or routes/ may render, include or extend a Blade under resources/views/edge/pos/**; the folder must
 *     not come back. EDGE_POS_CUTOVER_STRICT (the Phase 2 forcing switch) is accepted and ignored — a no-op.
 */
class EdgeSharedPosRegressionStaticGateTest extends TestCase
{
    /** @return string[] the shared view + its partials + layout sources, concatenated */
    private function sharedViewSources(): array
    {
        $files = array_merge(
            [resource_path('views/tenant/pos/index.blade.php'), resource_path('views/layouts/pos.blade.php')],
            glob(resource_path('views/tenant/pos/partials/*.blade.php')) ?: [],
            glob(resource_path('views/tenant/pos/js/*.blade.php')) ?: [],
        );
        $out = [];
        foreach ($files as $f) {
            $out[str_replace('\\', '/', $f)] = file_get_contents($f);
        }

        return $out;
    }

    public function test_the_capability_control_map_is_complete_against_the_view_source(): void
    {
        $src = implode("\n", $this->sharedViewSources());
        preg_match_all("/->can\('([A-Za-z]+)'\)/", $src, $blade);
        preg_match_all("/POS\.can\('([A-Za-z]+)'\)/", $src, $js);
        $bladeGated = array_values(array_unique($blade[1]));
        $jsGated = array_values(array_unique($js[1]));
        sort($bladeGated);
        sort($jsGated);

        $mapped = array_keys(Gate::CAPABILITY_CONTROLS);
        sort($mapped);
        $this->assertSame($bladeGated, $mapped, 'every $posRuntime->can() site of the view must be in the render gate\'s CAPABILITY_CONTROLS map (and nothing stale)');
        $jsMapped = array_keys(Gate::JS_GATED_CAPABILITIES);
        sort($jsMapped);
        $this->assertSame($jsGated, $jsMapped, 'every POS.can() site of the page JS must be in JS_GATED_CAPABILITIES');

        // Every @disabled site in the view is a capability gate (never a hard-coded runtime check).
        preg_match_all('/@disabled\(([^\n]*?)\)\s/', $src, $dis);
        $this->assertGreaterThan(10, count($dis[1]));
        foreach ($dis[1] as $expr) {
            $this->assertStringContainsString('$posRuntime->can(', $expr, "@disabled site must be a capability gate: {$expr}");
        }
        // Every capability the Edge factory turns OFF has a gate in the view (Blade or JS) — an off capability can never
        // leave an enabled control on the Branch Server.
        foreach (EdgePosRuntimeFactory::CAPABILITIES as $key => $on) {
            $this->assertContains($key, PosRuntime::CAPABILITY_KEYS);
            if (! $on) {
                $this->assertTrue(in_array($key, $bladeGated, true) || in_array($key, $jsGated, true), "capability {$key} is OFF on Edge but nothing in the view gates on it");
                $this->assertArrayHasKey($key, EdgePosRuntimeFactory::LABELS, "capability {$key} is OFF on Edge and needs a hint label");
            }
        }
        $this->assertEqualsCanonicalizing(PosRuntime::CAPABILITY_KEYS, array_keys(EdgePosRuntimeFactory::CAPABILITIES));
    }

    public function test_the_shared_view_carries_no_runtime_branch_and_none_of_the_old_edge_page_ids(): void
    {
        $this->assertNotEmpty(Gate::OLD_EDGE_ONLY_IDS, 'the frozen list is the only reference to the old page now that the folder is deleted');
        foreach ($this->sharedViewSources() as $file => $src) {
            foreach (['isEdge(', "config('app.role')", 'isBranchServer(', "mode === 'edge'", "mode==='edge'", 'MODE_EDGE'] as $needle) {
                $this->assertStringNotContainsString($needle, $src, "{$file}: the shared view must not branch on the runtime ({$needle}) — PosRuntime carries every difference");
            }
            foreach (Gate::OLD_EDGE_ONLY_IDS as $id) {
                $this->assertStringNotContainsString('id="' . $id . '"', $src, "{$file}: the OLD Edge page id #{$id} reappeared in the shared view");
            }
        }
    }

    /**
     * (d) static, STRICT (the only mode since Stage B): the old folder stays deleted, and nothing under app/ or routes/ renders,
     * includes or extends a Blade under resources/views/edge/pos/**; GET /edge/local/pos routes to the shared view.
     */
    public function test_no_old_edge_page_blade_exists_or_is_rendered_anywhere(): void
    {
        $this->assertTrue(self::strictCutover(), 'EDGE_POS_CUTOVER_STRICT is a no-op: strict is the only mode');
        $this->assertDirectoryDoesNotExist(resource_path('views/edge/pos'), 'Stage B deleted resources/views/edge/pos/** — it must not come back');

        $refs = [];
        foreach ($this->phpFiles([app_path(), base_path('routes')]) as $file) {
            foreach (file($file) as $n => $line) {
                if (preg_match("/view\(\s*['\"](edge\.pos\.[a-z0-9_.-]+)['\"]|@include\(\s*['\"](edge\.pos\.[a-z0-9_.-]+)['\"]|@extends\(\s*['\"](edge\.pos\.[a-z0-9_.-]+)['\"]/", $line, $m)) {
                    $refs[] = str_replace('\\', '/', str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file)) . ':' . ($n + 1) . ' ' . ($m[1] ?: ($m[2] ?: $m[3]));
                }
            }
        }
        $this->assertSame([], $refs, "STRICT cutover: nothing under app/ or routes/ may render a Blade under resources/views/edge/pos/**:\n - " . implode("\n - ", $refs));
        // Shared views (tenant/pos/**, layouts/pos) never include an old-page fragment either.
        foreach ($this->sharedViewSources() as $file => $src) {
            $this->assertDoesNotMatchRegularExpression("/@(?:include|extends|includeIf|each)\(\s*['\"]edge\.pos\./", $src, "{$file} includes an old Edge page fragment");
        }
        // No Blade anywhere under resources/views references the old namespace.
        foreach ($this->bladeFiles(resource_path('views')) as $file) {
            $this->assertStringNotContainsString("'edge.pos.", file_get_contents($file), "{$file} references the deleted edge.pos.* views");
        }

        $screen = Route::getRoutes()->getByName('edge.local.pos.screen');
        if ($screen) {
            $this->assertStringEndsWith('@sharedScreen', $screen->getActionName(), 'GET /edge/local/pos renders the shared view (EdgeLocalPosController::sharedScreen)');
        }
        $this->assertFalse(method_exists(\App\Http\Controllers\Edge\EdgeLocalPosController::class, 'screen'), 'the old-page delegate EdgeLocalPosController::screen() is deleted (Stage B)');
    }

    /**
     * The strict switch — identical rule to the render gate. Since Stage B strict is the ONLY mode: the Phase 2 fallback
     * (route + old view) no longer exists, and the forcing variable EDGE_POS_CUTOVER_STRICT=1 is a documented no-op.
     */
    public static function strictCutover(): bool
    {
        return true;
    }

    /** @return string[] */
    private function phpFiles(array $dirs): array
    {
        $out = [];
        foreach ($dirs as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                    $out[] = $f->getPathname();
                }
            }
        }
        sort($out);

        return $out;
    }

    /** @return string[] every *.blade.php under $dir */
    private function bladeFiles(string $dir): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php')) {
                $out[] = str_replace('\\', '/', $f->getPathname());
            }
        }
        sort($out);

        return $out;
    }
}
