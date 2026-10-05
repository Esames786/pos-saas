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
 *   - the OLD Edge page's own ids (resources/views/edge/pos/**) never appear in the shared view sources, and the frozen list
 *     the render gate carries matches the folder while it still exists;
 *   - the shared view carries NO runtime branch (no isEdge() / app.role / mode === 'edge' — one markup, runtime via PosRuntime);
 *   - (d) static "no old Edge page" scan: Phase 2 = only EdgeLocalPosController::screen on the fallback route renders
 *     `edge.pos.index`; STRICT (automatic once the fallback is gone, or forced with EDGE_POS_CUTOVER_STRICT=1) = nothing
 *     under app/ or routes/ renders, includes or extends a Blade under resources/views/edge/pos/**.
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
        foreach ($this->sharedViewSources() as $file => $src) {
            foreach (['isEdge(', "config('app.role')", 'isBranchServer(', "mode === 'edge'", "mode==='edge'", 'MODE_EDGE'] as $needle) {
                $this->assertStringNotContainsString($needle, $src, "{$file}: the shared view must not branch on the runtime ({$needle}) — PosRuntime carries every difference");
            }
            foreach (Gate::OLD_EDGE_ONLY_IDS as $id) {
                $this->assertStringNotContainsString('id="' . $id . '"', $src, "{$file}: the OLD Edge page id #{$id} reappeared in the shared view");
            }
        }
        // While the old folder exists: every id it defines that the shared views do not is in the frozen list (or a new
        // old-page id was added — which must not happen any more), and the frozen list is a subset of that derived set.
        $oldDir = resource_path('views/edge/pos');
        if (is_dir($oldDir)) {
            $oldIds = $this->idsIn(array_merge(glob($oldDir . '/*.blade.php') ?: [], glob($oldDir . '/partials/*.blade.php') ?: []));
            $sharedIds = $this->idsIn(array_keys($this->sharedViewSources()));
            $derived = array_values(array_diff($oldIds, $sharedIds));
            sort($derived);
            $this->assertSame([], array_values(array_diff(Gate::OLD_EDGE_ONLY_IDS, $derived)), 'frozen OLD_EDGE_ONLY_IDS entries that the old folder no longer defines (or the shared view now defines!)');
            $this->assertGreaterThanOrEqual(count(Gate::OLD_EDGE_ONLY_IDS), count($derived));
        } else {
            $this->addToAssertionCount(1); // the folder is gone (post-cutover): the frozen list is the only reference
        }
    }

    /** (d) static: which app code / routes render, include or extend a Blade under resources/views/edge/pos/**. */
    public function test_no_old_edge_page_blade_is_rendered_outside_the_phase_2_fallback_and_none_under_strict_cutover(): void
    {
        $refs = [];
        foreach ($this->phpFiles([app_path(), base_path('routes')]) as $file) {
            foreach (file($file) as $n => $line) {
                if (preg_match("/view\(\s*['\"](edge\.pos\.[a-z0-9_.-]+)['\"]|@include\(\s*['\"](edge\.pos\.[a-z0-9_.-]+)['\"]|@extends\(\s*['\"](edge\.pos\.[a-z0-9_.-]+)['\"]/", $line, $m)) {
                    $refs[] = str_replace('\\', '/', str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file)) . ':' . ($n + 1) . ' ' . ($m[1] ?: ($m[2] ?: $m[3]));
                }
            }
        }
        // Shared views (tenant/pos/**, layouts/pos) never include the old page's fragments.
        foreach ($this->sharedViewSources() as $file => $src) {
            $this->assertDoesNotMatchRegularExpression("/@(?:include|extends|includeIf|each)\(\s*['\"]edge\.pos\./", $src, "{$file} includes an old Edge page fragment");
        }

        $strict = self::strictCutover();
        if ($strict) {
            $this->assertSame([], $refs, "STRICT cutover: nothing under app/ or routes/ may render a Blade under resources/views/edge/pos/**:\n - " . implode("\n - ", $refs));
            $screen = Route::getRoutes()->getByName('edge.local.pos.screen');
            if ($screen) {
                $this->assertStringNotContainsString('@screen', $screen->getActionName(), 'STRICT: GET /edge/local/pos must not route to the old page');
            }
        } else {
            $this->assertCount(1, $refs, "Phase 2: ONLY EdgeLocalPosController::screen may render the old page:\n - " . implode("\n - ", $refs));
            $this->assertStringStartsWith('app/Http/Controllers/Edge/EdgeLocalPosController.php:', $refs[0]);
            $this->assertStringEndsWith(' edge.pos.index', $refs[0]);
        }
    }

    /** The strict switch — identical rule to the render gate (documented in docs/status/edge-phase3-census-replacement.md). */
    public static function strictCutover(): bool
    {
        if (filter_var(getenv('EDGE_POS_CUTOVER_STRICT') ?: '0', FILTER_VALIDATE_BOOL)) {
            return true;
        }
        $routes = file_get_contents(base_path('routes/edge_runtime.php'));
        $fallbackRouted = (bool) preg_match("/\[EdgeLocalPosController::class,\s*'screen'\]\)->name\('screen'\)/", $routes);
        $oldViewExists = is_file(resource_path('views/edge/pos/index.blade.php'));

        return ! ($fallbackRouted && $oldViewExists);
    }

    /** @return string[] unique element ids defined (id="…") in the given Blade files */
    private function idsIn(array $files): array
    {
        $ids = [];
        foreach ($files as $f) {
            preg_match_all('/\sid="([A-Za-z0-9_-]+)"/', file_get_contents($f), $m);
            $ids = array_merge($ids, $m[1]);
        }

        return array_values(array_unique($ids));
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
}
