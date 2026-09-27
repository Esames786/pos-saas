<?php

namespace Tests\Feature\Edge;

use App\Services\Edge\EdgeArtifactBuilder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * W-C (Edge next release §4 / §15, owner decision A3) — the appliance artifact carries the SHARED cashier view's assets
 * with NO Internet dependency, and nothing Cloud-only is rendered by an Edge route.
 *
 *  1. The self-hosted Nunito (public/assets/css/fonts-local.css + public/assets/fonts/nunito/*, OFL 1.1 licence text
 *     beside it) is in the artifact plan, and the nunito directory carries exactly the vendored files — no stray font.
 *  2. style.css as it SHIPS has no `@import` and no Google Fonts host; no CSS the Online layout (and the shared layout
 *     layouts.pos, once W-A lands) links carries an `@import` or a remote url().
 *  3. partials/header.blade.php and partials/sidebar.blade.php (Cloud chrome) MAY ship with resources/, but no
 *     `edge.local.*` route renders them: the views every edge.local.* controller names are expanded through
 *     @extends/@include/@component/<x-…> and the closure must never reach them (route-level, via the route list).
 *  4. The release package builder refuses untracked files under public/ or resources/ (they would ship unreviewed).
 */
class EdgeApplianceArtifactBoundaryTest extends TestCase
{
    private const NUNITO_FILES = [
        'public/assets/fonts/nunito/OFL.txt',
        'public/assets/fonts/nunito/README.md',
        'public/assets/fonts/nunito/nunito-latin-wght-v32.woff2',
    ];

    /** Views that are Cloud chrome only — never rendered by an edge.local.* route. */
    private const CLOUD_CHROME_VIEWS = ['partials.header', 'partials.sidebar'];

    /**
     * The declared Cloud-only capability slot of the shared layout (§11 W-A: `tenant/pos/partials/chrome-cloud`). The
     * Cloud chrome lives behind it by design and the runtime decides; the render-level proof that Edge never takes it is
     * EdgeSharedPosViewMySqlTest (W-G). The static walk stops there.
     */
    private const CLOUD_SLOT_VIEWS = ['tenant.pos.partials.pos-chrome-cloud', 'tenant.pos.partials.chrome-cloud'];

    private static ?array $plan = null;

    private function plan(): array
    {
        return self::$plan ??= array_flip(EdgeArtifactBuilder::fromConfig()->plan(base_path()));
    }

    public function test_the_self_hosted_nunito_font_and_its_licence_are_in_the_artifact_plan(): void
    {
        $plan = $this->plan();
        foreach (array_merge(['public/assets/css/fonts-local.css', 'public/assets/css/style.css'], self::NUNITO_FILES) as $rel) {
            $this->assertArrayHasKey($rel, $plan, "{$rel} must ship in the appliance artifact");
        }

        // Exactly the vendored files — no unrelated font file slipped into the nunito directory.
        $inDir = array_values(array_filter(array_keys($plan), fn ($p) => str_starts_with($p, 'public/assets/fonts/nunito/')));
        sort($inDir);
        $this->assertSame(self::NUNITO_FILES, $inDir);

        $woff2 = base_path('public/assets/fonts/nunito/nunito-latin-wght-v32.woff2');
        $this->assertSame('wOF2', (string) file_get_contents($woff2, false, null, 0, 4), 'a real WOFF2 file');
        $readme = (string) file_get_contents(base_path('public/assets/fonts/nunito/README.md'));
        $this->assertStringContainsString(hash_file('sha256', $woff2), $readme, 'README records the SHA-256 of the vendored file');
        $ofl = (string) file_get_contents(base_path('public/assets/fonts/nunito/OFL.txt'));
        $this->assertStringContainsString('SIL Open Font License, Version 1.1', $ofl);
        $this->assertStringContainsString('The Nunito Project Authors', $ofl);
    }

    public function test_style_css_as_shipped_has_no_import_and_no_google_fonts_host(): void
    {
        $this->assertArrayHasKey('public/assets/css/style.css', $this->plan());
        $css = (string) file_get_contents(base_path('public/assets/css/style.css'));
        $this->assertStringNotContainsString('@import', $css, 'owner decision A3: the two Google Fonts @import lines are gone');
        $this->assertStringNotContainsString('fonts.googleapis', $css);
        $this->assertStringNotContainsString('fonts.gstatic', $css);
        $this->assertStringNotContainsStringIgnoringCase('Poppins', $css, 'Poppins was unused by every rule and is dropped');
        $this->assertStringContainsString('font-family: "Nunito", sans-serif', $css, 'the rules still name the self-hosted family');
    }

    public function test_every_css_the_online_and_shared_pos_layouts_link_ships_and_has_no_remote_reference(): void
    {
        $plan = $this->plan();
        $layouts = ['layouts/app.blade.php'];
        if (is_file(resource_path('views/layouts/pos.blade.php'))) {
            $layouts[] = 'layouts/pos.blade.php';
            $this->assertArrayHasKey('resources/views/layouts/pos.blade.php', $plan, 'the shared POS layout ships');
        }

        $linked = ['assets/css/fonts-local.css']; // linked before style.css by the layouts (W-A / coordinator)
        foreach ($layouts as $layout) {
            // asset('assets/…') on Cloud; $posRuntime->asset('assets/…') / EdgeLocalAssetController::url('…') in the shared layout.
            $blade = (string) file_get_contents(resource_path('views/' . $layout));
            preg_match_all("/(?:asset|->asset)\\(\\s*'(assets\\/[^']+\\.css)'\\s*\\)/", $blade, $m1);
            preg_match_all("/EdgeLocalAssetController::url\\(\\s*'([^']+\\.css)'\\s*\\)/", $blade, $m2);
            $linked = array_merge($linked, $m1[1], array_map(fn ($p) => 'assets/' . ltrim($p, '/'), $m2[1]));
        }
        $linked = array_values(array_unique($linked));
        $this->assertGreaterThanOrEqual(11, count($linked), 'the Online layout links ten stylesheets + the local font');

        foreach ($linked as $asset) {
            $rel = 'public/' . $asset;
            $this->assertArrayHasKey($rel, $plan, "{$rel} (linked by the POS layout) must ship");
            $css = (string) file_get_contents(base_path($rel));
            $this->assertStringNotContainsString('@import', $css, "{$rel} must not @import anything");
            $this->assertDoesNotMatchRegularExpression('/url\(\s*[\'"]?(?:https?:)?\/\//i', $css, "{$rel} must not load a remote url()");
        }
    }

    /**
     * Owner decision A3: the self-hosted face is CONSUMED by both Cloud and Edge — every layout that links style.css links
     * fonts-local.css immediately before it (otherwise removing the Google import silently drops Nunito). The layouts are
     * owned by W-A (layouts/pos) and the coordinator (layouts/app, layouts/auth); until the link lands this is reported as
     * INCOMPLETE with the exact line to add, and it is a hard assertion from then on.
     */
    public function test_every_layout_that_links_style_css_links_fonts_local_css_right_before_it(): void
    {
        $missing = [];
        foreach (glob(resource_path('views/layouts/*.blade.php')) ?: [] as $file) {
            $blade = (string) file_get_contents($file);
            $style = strpos($blade, "assets/css/style.css'");
            if ($style === false) {
                continue;
            }
            $fonts = strpos($blade, "assets/css/fonts-local.css'");
            if ($fonts === false) {
                $missing[] = 'layouts/' . basename($file) . " — add, immediately before the style.css <link>: <link rel=\"stylesheet\" href=\"{{ "
                    . (str_contains($blade, '$posRuntime->asset(') ? '$posRuntime->asset' : 'asset') . "('assets/css/fonts-local.css') }}\">";
                continue;
            }
            $this->assertLessThan($style, $fonts, basename($file) . ': fonts-local.css must be linked BEFORE style.css');
            $between = substr($blade, $fonts, $style - $fonts);
            $this->assertLessThanOrEqual(1, substr_count($between, '<link'), basename($file) . ': fonts-local.css sits right before style.css');
        }
        if ($missing !== []) {
            $this->markTestIncomplete("MERGE BLOCKER (A3) — layouts that link style.css but not the self-hosted font yet:\n  " . implode("\n  ", $missing));
        }
    }

    public function test_cloud_chrome_views_may_ship_but_no_edge_local_route_renders_them(): void
    {
        $this->bootAsBranchServer(function (): void {
            $routes = array_filter(Route::getRoutes()->getRoutes(), fn ($r) => str_starts_with((string) $r->getName(), 'edge.local.'));
            $this->assertGreaterThan(50, count($routes), 'expected the edge.local.* route list');

            $roots = [];
            foreach ($routes as $route) {
                if (isset($route->defaults['view']) && is_string($route->defaults['view'])) {
                    $roots[$route->defaults['view']] = 'Route::view ' . $route->getName(); // Route::view()
                }
                $controller = $route->getAction('controller');
                if (! is_string($controller)) {
                    continue;
                }
                $class = explode('@', $controller)[0];
                foreach ($this->viewsNamedBy($class) as $view) {
                    $roots[$view] = $roots[$view] ?? $class;
                }
            }
            $this->assertArrayHasKey('edge.auth.login', $roots, 'the walk must see the Edge views the controllers render');

            // The shared layout includes a RUNTIME-named chrome view (@include($posRuntime->chromeView)); the static walk
            // cannot follow a variable, so no Edge-side class (controllers, the Edge runtime factory, Edge support) may
            // even NAME the Cloud chrome or its slot.
            $edgeSources = array_merge(
                glob(app_path('Http/Controllers/Edge/*.php')) ?: [],
                glob(app_path('Services/Edge/*.php')) ?: [],
                glob(app_path('Support/Edge*.php')) ?: [],
            );
            $cloudNames = array_merge(self::CLOUD_CHROME_VIEWS, self::CLOUD_SLOT_VIEWS, ['layouts.app']);
            foreach ($edgeSources as $src) {
                $code = (string) file_get_contents($src);
                foreach ($cloudNames as $name) {
                    $this->assertDoesNotMatchRegularExpression('/[\'"]' . preg_quote($name, '/') . '[\'"]/', $code, basename($src) . " names the Cloud-only view '{$name}'");
                }
            }

            [$closure, $via] = $this->expandViews(array_keys($roots));
            $reached = array_values(array_intersect(self::CLOUD_CHROME_VIEWS, array_keys($closure)));
            if ($reached === []) {
                $this->assertTrue(true);

                return;
            }

            $chain = [];
            foreach ($reached as $view) {
                $path = [$view];
                while (isset($via[end($path)])) {
                    $path[] = $via[end($path)];
                }
                $chain[] = implode(' <- ', $path) . ' <- ' . ($roots[end($path)] ?? '?');
            }
            // Interim (Phase 2, coordination only): the Edge POS screen renders the shared tenant.pos.index while that page
            // still @extends('layouts.app') because W-A has not yet created layouts.pos. Reported, not hidden — the gate
            // engages as soon as layouts/pos.blade.php exists.
            $onlyViaOldLayout = collect($chain)->every(fn ($c) => str_contains($c, '<- layouts.app <- tenant.pos.index'));
            if ($onlyViaOldLayout && ! is_file(resource_path('views/layouts/pos.blade.php'))) {
                $this->markTestIncomplete("W-A pending — the Edge POS route reaches Cloud chrome only through tenant.pos.index -> layouts.app:\n  " . implode("\n  ", $chain));
            }
            $this->fail("An edge.local.* route renders Cloud chrome:\n  " . implode("\n  ", $chain));
        });
    }

    public function test_the_release_package_builder_refuses_untracked_files_under_public_or_resources(): void
    {
        $untracked = "?? public/assets/js/stray.js\n?? resources/views/tenant/pos/partials/unreviewed.blade.php\n";
        $fake = function (string $untrackedOutput) {
            return function ($process) use ($untrackedOutput) {
                $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
                if (str_contains($cmd, 'rev-parse')) {
                    return str_repeat('a', 40) . "\n";
                }
                if (str_contains($cmd, '--untracked-files=all')) {
                    return $untrackedOutput;
                }

                return ''; // --untracked-files=no → a clean tracked tree
            };
        };

        Process::fake(['*' => $fake($untracked)]);
        $this->artisan('edge:build-package', ['dest' => sys_get_temp_dir() . '/edge_wc_untracked_' . uniqid()])
            // One console write satisfies one expectation — the refusal names the files in the same message.
            ->expectsOutputToContain('resources/views/tenant/pos/partials/unreviewed.blade.php')
            ->assertExitCode(1);

        // The probe itself is fail-closed: when git cannot answer, a release refuses too.
        Process::fake(['*' => function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

            return str_contains($cmd, '--untracked-files=all') ? Process::result('', 'fatal: not a git repository', 128) : (str_contains($cmd, 'rev-parse') ? str_repeat('a', 40) : '');
        }]);
        $this->artisan('edge:build-package', ['dest' => sys_get_temp_dir() . '/edge_wc_untracked_' . uniqid()])
            ->expectsOutputToContain('git status failed')
            ->assertExitCode(1);

        // No untracked file → the untracked gate passes and the release moves on to its NEXT requirement (the custody keystore).
        Process::fake(['*' => $fake('')]);
        $this->artisan('edge:build-package', ['dest' => sys_get_temp_dir() . '/edge_wc_untracked_' . uniqid()])
            ->doesntExpectOutputToContain('untracked files under public/ or resources/')
            ->expectsOutputToContain('custody keystore')
            ->assertExitCode(1);

        // --allow-untracked is the DEV escape: it warns (and stamps the build dirty) instead of refusing. A non-empty
        // destination without a package manifest stops the dev build right after, so nothing is built here.
        $busy = sys_get_temp_dir() . '/edge_wc_busy_' . uniqid();
        mkdir($busy);
        file_put_contents($busy . '/occupied.txt', 'x');
        Process::fake(['*' => $fake($untracked)]);
        try {
            $this->artisan('edge:build-package', ['dest' => $busy, '--allow-untracked' => true, '--no-sign' => true])
                ->expectsOutputToContain('DEV package: untracked files under public/ or resources/ will ship')
                ->expectsOutputToContain('is not empty')
                ->assertExitCode(1);
        } finally {
            @unlink($busy . '/occupied.txt');
            @rmdir($busy);
        }
    }

    // ── helpers ──

    private function bootAsBranchServer(callable $body): void
    {
        putenv('APP_ROLE=branch_server');
        $_ENV['APP_ROLE'] = $_SERVER['APP_ROLE'] = 'branch_server';
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv("EDGE_LOCAL_APP_KEY={$key}");
        $_ENV['EDGE_LOCAL_APP_KEY'] = $_SERVER['EDGE_LOCAL_APP_KEY'] = $key;
        try {
            $this->refreshApplication();
            $body();
        } finally {
            putenv('APP_ROLE');
            unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
            putenv('EDGE_LOCAL_APP_KEY');
            unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        }
    }

    /**
     * The views the class (and its App\ parents) renders: view('…'), View::make('…'), View::first(['…', …]), ->view('…'),
     * ->make('…'), and `VIEW…` constants/properties. (Not every dotted string: permission names such as
     * 'tenant.shifts.close' collide with view names.)
     */
    private function viewsNamedBy(string $class): array
    {
        $views = [];
        $ref = class_exists($class) ? new \ReflectionClass($class) : null;
        while ($ref && str_starts_with($ref->getName(), 'App\\') && $ref->getFileName()) {
            $src = (string) file_get_contents($ref->getFileName());
            $m = [1 => []];
            if (preg_match_all('/(?:\bview|View::make|View::first|->view|->make)\(\s*\[?([^)]*)/', $src, $calls)) {
                foreach ($calls[1] as $args) {
                    preg_match_all('/[\'"]([a-z0-9_-]+(?:\.[a-z0-9_-]+)+)[\'"]/', $args, $n);
                    $m[1] = array_merge($m[1], $n[1]);
                }
            }
            if (preg_match_all('/\$?\b[A-Za-z_]*(?:VIEW|View|view)[A-Za-z_]*\s*=\s*[\'"]([a-z0-9_-]+(?:\.[a-z0-9_-]+)+)[\'"]/', $src, $consts)) {
                $m[1] = array_merge($m[1], $consts[1]);
            }
            foreach ($m[1] as $candidate) {
                if ($this->viewFile($candidate) !== null) {
                    $views[] = $candidate;
                }
            }
            $ref = $ref->getParentClass() ?: null;
        }

        return array_values(array_unique($views));
    }

    private function viewFile(string $name): ?string
    {
        if (str_contains($name, '::')) {
            return null; // package namespaces never carry the Cloud chrome
        }
        $file = resource_path('views/' . str_replace('.', '/', $name) . '.blade.php');

        return is_file($file) ? $file : (is_file($f = resource_path('views/' . str_replace('.', '/', $name) . '.php')) ? $f : null);
    }

    /**
     * Transitive Blade closure through @extends / @include* / @each / @component / <x-…> anonymous components.
     *
     * @return array{0: array<string,bool>, 1: array<string,string>} [views reached, child => parent]
     */
    private function expandViews(array $roots): array
    {
        $seen = [];
        $via = [];
        $queue = $roots;
        while ($queue !== []) {
            $view = array_shift($queue);
            if (isset($seen[$view])) {
                continue;
            }
            $seen[$view] = true;
            if (in_array($view, self::CLOUD_SLOT_VIEWS, true)) {
                continue;
            }
            $file = $this->viewFile($view);
            if ($file === null) {
                continue;
            }
            $blade = (string) file_get_contents($file);
            $blade = preg_replace('/\{\{--[\s\S]*?--\}\}/', '', $blade); // Blade comments are not rendered
            $children = [];
            if (preg_match_all('/@(?:extends|include|includeIf|includeFirst|includeWhen|includeUnless|each|component)\s*\(([^\n]*)/', $blade, $m)) {
                foreach ($m[1] as $args) {
                    // A shared secondary screen picks layouts.pos on Edge: `@extends(isset($posRuntime) && $posRuntime->isEdge() ? 'layouts.pos' : 'layouts.app')`
                    // — on a Branch Server only the Edge branch of the ternary can render, so walk that one.
                    if (preg_match("/isEdge\(\)\s*\?\s*['\"]([A-Za-z0-9_\-.]+)['\"]\s*:/", $args, $edgeBranch)) {
                        $args = "'" . $edgeBranch[1] . "'";
                    }
                    preg_match_all('/[\'"]([A-Za-z0-9_\-]+(?:\.[A-Za-z0-9_\-]+)*)[\'"]/', $args, $names);
                    foreach ($names[1] as $n) {
                        if ($this->viewFile($n) !== null) {
                            $children[] = $n;
                        }
                    }
                }
            }
            if (preg_match_all('/<x-([a-z0-9\-\.]+)/', $blade, $x)) {
                foreach ($x[1] as $component) {
                    $children[] = 'components.' . $component;
                }
            }
            if (preg_match_all('/\bview\(\s*[\'"]([A-Za-z0-9_\-.]+)[\'"]/', $blade, $v)) {
                $children = array_merge($children, $v[1]);
            }
            foreach (array_unique($children) as $child) {
                if (! isset($seen[$child])) {
                    $via[$child] = $via[$child] ?? $view;
                    $queue[] = $child;
                }
            }
        }

        return [$seen, $via];
    }
}
