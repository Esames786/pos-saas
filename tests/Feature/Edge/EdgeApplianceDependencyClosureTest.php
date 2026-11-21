<?php

namespace Tests\Feature\Edge;

use App\Services\Edge\EdgeArtifactBuilder;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * P5 RELEASE-SHAPE GATE — every class the APPLIANCE runtime can instantiate must ship in the restricted artifact.
 *
 * The dev vendor junction let Composer resolve `App\` classes from the developer tree, so P4 never saw that an appliance
 * command could pull a Cloud-only class through constructor injection (the real release runtime died on
 * `App\Services\Saas\TenantSubscriptionAccessService` while pulling the bootstrap snapshot). This gate walks the
 * container-resolved dependency closure — constructor parameters and command `handle()` parameters, transitively — from
 * every appliance entry point (edge:local:* commands, the branch-server routes' controllers, the Edge middleware, the
 * supervision/health services) and requires each `App\` class in it to be in the artifact plan. Interfaces are followed
 * through the container binding.
 */
class EdgeApplianceDependencyClosureTest extends TestCase
{
    public function test_every_class_the_appliance_runtime_can_instantiate_ships_in_the_artifact(): void
    {
        $plan = array_flip(EdgeArtifactBuilder::fromConfig()->plan(base_path()));
        $entry = $this->entryPoints();
        $this->assertGreaterThan(30, count($entry), 'expected the appliance entry points to be discovered');

        [$seen, $missing] = $this->walk($entry, $plan);
        $report = '';
        foreach ($missing as $file => $via) {
            $report .= "\n  {$file}  (reached via {$via})";
        }
        $this->assertSame([], array_keys($missing), 'the appliance runtime would instantiate classes that are NOT in the artifact:' . $report);
        $this->assertGreaterThan(60, count($seen), 'the closure should span the Edge runtime (' . count($seen) . ' classes walked)');
        // The closure must never reach a Cloud-only subsystem, even one that happens to ship.
        foreach (array_keys($seen) as $class) {
            $this->assertDoesNotMatchRegularExpression('/^App\\\\Services\\\\(Saas|Catering|Manufacturing|Purchasing|Central)\\\\/', $class, "{$class} is reachable from the appliance runtime");
        }
    }

    /**
     * W-C (Edge next release §4.5) — the SHARED cashier view reaches classes the container walk above cannot see: Blade
     * static calls (`\App\Models\Tenant\VoidReason::where(…)`, `\App\Models\Tenant\User::ORDER_TYPES`, `@can(\App\…::CONST)`)
     * and container lookups (`app(\App\Support\TenantClock::class)`). Every `App\` class referenced from
     * resources/views/tenant/pos/** and layouts/pos.blade.php must be in the artifact plan (not excluded), and so must
     * its constructor closure — otherwise the appliance page dies with "Class not found" on the first render.
     */
    public function test_every_class_the_shared_pos_views_reference_ships_in_the_artifact(): void
    {
        $plan = array_flip(EdgeArtifactBuilder::fromConfig()->plan(base_path()));
        $refs = $this->bladeClassReferences();
        $this->assertNotEmpty($refs, 'expected the shared POS views to reference App classes');

        $unknown = [];
        foreach ($refs as $class => $where) {
            if (! class_exists($class) && ! interface_exists($class) && ! enum_exists($class) && ! trait_exists($class)) {
                $unknown[] = "{$class} ({$where})";
            }
        }
        $this->assertSame([], $unknown, 'the shared POS views reference App classes that do not exist');

        foreach (['App\\Support\\TenantClock', 'App\\Models\\Tenant\\VoidReason', 'App\\Models\\Tenant\\User', 'App\\Services\\Security\\UserDataScope'] as $expected) {
            $this->assertArrayHasKey($expected, $refs, "the scan must find {$expected} (it is referenced by tenant/pos/index.blade.php today)");
        }

        [$seen, $missing] = $this->walk(array_keys($refs), $plan);
        $report = '';
        foreach ($missing as $file => $via) {
            $report .= "\n  {$file}  (reached via {$via})";
        }
        $report .= "\nBlade references: " . json_encode($refs, JSON_UNESCAPED_SLASHES);
        $this->assertSame([], array_keys($missing), 'the shared POS views reference classes that are NOT in the artifact:' . $report);
        foreach (array_keys($seen) as $class) {
            $this->assertDoesNotMatchRegularExpression('/^App\\\\Services\\\\(Saas|Catering|Manufacturing|Purchasing|Central)\\\\/', $class, "{$class} is reachable from the shared POS views");
        }
    }

    /**
     * FQCN => "view:line" of the first reference, for every `App\` class the shared POS views name statically.
     *
     * @return array<string,string>
     */
    private function bladeClassReferences(): array
    {
        $files = [];
        $dir = resource_path('views/tenant/pos');
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php')) {
                $files[] = $f->getPathname();
            }
        }
        if (is_file(resource_path('views/layouts/pos.blade.php'))) {
            $files[] = resource_path('views/layouts/pos.blade.php');
        }
        sort($files);

        $patterns = [
            // \App\X\Y::member / \App\X\Y::class (static calls, constants, app(\App\…::class), @can(\App\…::CONST))
            '/(?<![A-Za-z0-9_\\\\])\\\\?(App(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+)\s*::/',
            // @inject('name', 'App\X\Y') / app('App\X\Y') / resolve('App\X\Y')
            '/(?:@inject\(\s*[\'"][^\'"]+[\'"]\s*,|\b(?:app|resolve)\()\s*[\'"]\\\\?(App(?:\\\\{1,2}[A-Za-z_][A-Za-z0-9_]*)+)[\'"]/',
            // `use App\X\Y;` inside @php
            '/\buse\s+\\\\?(App(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+)\s*(?:as\s+\w+\s*)?;/',
        ];
        $refs = [];
        foreach ($files as $file) {
            $rel = ltrim(str_replace('\\', '/', substr($file, strlen(resource_path('views')))), '/');
            foreach (preg_split('/\R/', (string) file_get_contents($file)) as $i => $line) {
                foreach ($patterns as $pattern) {
                    if (preg_match_all($pattern, $line, $m)) {
                        foreach ($m[1] as $class) {
                            $class = str_replace('\\\\', '\\', $class);
                            $refs[$class] = $refs[$class] ?? ($rel . ':' . ($i + 1));
                        }
                    }
                }
            }
        }
        ksort($refs);

        return $refs;
    }

    /**
     * Transitive constructor / handle() closure from $entry; a class whose file is not in the plan is recorded (file =>
     * the class that reached it) and not walked into.
     *
     * @return array{0: array<string,bool>, 1: array<string,string>}
     */
    private function walk(array $entry, array $plan): array
    {
        $seen = [];
        $queue = $entry;
        $missing = [];
        $edges = [];
        while ($queue !== []) {
            $class = array_shift($queue);
            if (isset($seen[$class]) || (! class_exists($class) && ! interface_exists($class))) {
                continue;
            }
            $seen[$class] = true;
            $file = $this->fileFor($class);
            if ($file !== null && ! isset($plan[$file])) {
                $missing[$file] = $edges[$class] ?? '(entry point)';
                continue; // do not walk into a class that does not ship — the miss itself is the finding
            }
            foreach ($this->dependencies($class) as $dep) {
                if (! isset($seen[$dep])) {
                    $edges[$dep] = $edges[$dep] ?? $class;
                    $queue[] = $dep;
                }
            }
        }

        return [$seen, $missing];
    }

    /** @return string[] */
    private function entryPoints(): array
    {
        $classes = [];
        foreach (glob(app_path('Console/Commands/EdgeLocal*.php')) ?: [] as $f) {
            $classes[] = 'App\\Console\\Commands\\' . basename($f, '.php');
        }
        $routes = (string) file_get_contents(base_path('routes/edge_runtime.php'));
        if (preg_match_all('/([A-Za-z0-9_\\\\]+Controller)::class/', $routes, $m)) {
            foreach (array_unique($m[1]) as $name) {
                $short = ltrim(substr($name, (int) strrpos($name, '\\')), '\\');
                $classes[] = str_starts_with($name, 'App\\') ? $name : 'App\\Http\\Controllers\\Edge\\' . $short;
            }
        }
        foreach (glob(app_path('Http/Middleware/*Edge*.php')) ?: [] as $f) {
            $classes[] = 'App\\Http\\Middleware\\' . basename($f, '.php');
        }
        foreach (['App\\Services\\Edge\\EdgeSupervisionPlan', 'App\\Services\\Edge\\EdgeApplianceHealthService', 'App\\Services\\Edge\\EdgeAuthorityTick',
            'App\\Services\\Edge\\EdgeLocalPosService', 'App\\Services\\Edge\\EdgeLocalReturnService', 'App\\Services\\Edge\\EdgeLocalSupplierFinanceService',
            'App\\Services\\Edge\\EdgeLocalPurchaseReturnService', 'App\\Services\\Edge\\EdgeUpdateInstaller', 'App\\Services\\Edge\\EdgeRestoreService',
            'App\\Services\\Edge\\EdgeBackupService', 'App\\Services\\Edge\\EdgeLocalPrintDeliveryService', 'App\\Services\\Edge\\EdgeHandbackOrchestrator'] as $c) {
            $classes[] = $c;
        }

        return array_values(array_unique(array_filter($classes, 'class_exists')));
    }

    /** Constructor + (for commands) handle() parameter classes, interfaces resolved through the container binding. */
    private function dependencies(string $class): array
    {
        $deps = [];
        $ref = new ReflectionClass($class);
        $methods = [];
        if ($ref->getConstructor()) {
            $methods[] = $ref->getConstructor();
        }
        if ($ref->isSubclassOf(\Illuminate\Console\Command::class) && $ref->hasMethod('handle')) {
            $methods[] = $ref->getMethod('handle');
        }
        foreach ($methods as $method) {
            /** @var ReflectionMethod $method */
            foreach ($method->getParameters() as $param) {
                $type = $param->getType();
                if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }
                $name = $type->getName();
                if (! str_starts_with($name, 'App\\')) {
                    continue;
                }
                if (interface_exists($name) || (class_exists($name) && (new ReflectionClass($name))->isAbstract())) {
                    $name = $this->concreteFor($name) ?? $name;
                }
                $deps[] = $name;
            }
        }

        return array_unique($deps);
    }

    private function concreteFor(string $abstract): ?string
    {
        try {
            $instance = app()->make($abstract);

            return $instance::class;
        } catch (\Throwable) {
            return null;
        }
    }

    private function fileFor(string $class): ?string
    {
        $path = (new ReflectionClass($class))->getFileName();
        if (! $path) {
            return null;
        }
        $rel = ltrim(str_replace('\\', '/', substr($path, strlen(str_replace('\\', '/', base_path())))), '/');

        return str_starts_with($rel, 'app/') ? $rel : null;
    }
}
