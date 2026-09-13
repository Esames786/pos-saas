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

        $seen = [];
        $queue = $entry;
        $missing = [];
        $edges = [];
        while ($queue !== []) {
            $class = array_shift($queue);
            if (isset($seen[$class]) || ! class_exists($class)) {
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
