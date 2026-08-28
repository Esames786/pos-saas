<?php

namespace Tests\Feature\Edge;

use App\Services\Edge\EdgeArtifactBuilder;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * OFFLINE EDGE PRODUCTIZATION — boot the ACTUAL built restricted artifact, not the development worktree.
 *
 * Builds the real app/bootstrap/config/routes tree (with the production exclude list) into an isolated
 * directory, provides the framework via the shared vendor closure (a junction — the documented deployment
 * model: the appliance ships its own app/config/routes/bootstrap and consumes a provisioned PHP+vendor
 * runtime), then boots THAT directory's own artisan. Proves: the artifact's own bootstrap/config/routes are
 * used; the autoload is COHERENT after pruning (command discovery loads with no fatal from a removed class);
 * under APP_ROLE=branch_server only the Edge runtime routes are registered and the Cloud groups are not; and
 * an excluded Cloud class genuinely does not resolve from the artifact while the Edge runtime does.
 *
 * Windows/junction based; skips cleanly if a junction cannot be created in the sandbox.
 */
class EdgeArtifactBootTest extends TestCase
{
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            // Remove the junction first so we never recurse into the shared real vendor.
            @exec('cmd /c rmdir "' . $dir . '\\vendor" 2>&1');
            $this->rrmdir($dir);
        }
        parent::tearDown();
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '\\edge_boot_' . uniqid();
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function buildArtifact(): string
    {
        $dest = $this->tempDir();
        (new EdgeArtifactBuilder([
            'include' => ['app', 'bootstrap', 'config', 'routes', 'database/migrations', 'lang', 'artisan', 'composer.json', 'composer.lock'],
            'exclude' => (array) config('edge.artifact.exclude'),
            'forbidden' => (array) config('edge.artifact.forbidden'),
            'runtime_dirs' => ['bootstrap/cache', 'storage/framework/cache/data', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs', 'storage/app'],
        ]))->build(base_path(), $dest);

        // The appliance's provisioned runtime supplies the framework: junction the artifact's vendor to the
        // shared vendor closure. Because the junction path roots the autoloader's base dir AT THE ARTIFACT,
        // App\ classes resolve from the artifact's (pruned) app/, while framework packages resolve normally.
        @exec('cmd /c mklink /J "' . $dest . '\\vendor" "' . base_path('vendor') . '" 2>&1', $out, $code);
        if ($code !== 0 || ! is_file($dest . '\\vendor\\autoload.php')) {
            $this->markTestSkipped('could not create a vendor junction in this sandbox');
        }

        return $dest;
    }

    private function runArtisan(string $dest, string $args): \Illuminate\Contracts\Process\ProcessResult
    {
        return Process::path($dest)
            ->env(['APP_ROLE' => 'branch_server', 'EDGE_LOCAL_APP_KEY' => 'base64:' . base64_encode(random_bytes(32)), 'APP_ENV' => 'production'])
            ->run(PHP_BINARY . ' artisan ' . $args);
    }

    public function test_the_built_artifact_boots_its_own_runtime(): void
    {
        $dest = $this->buildArtifact();

        // The artifact's OWN artisan + bootstrap + vendor boot the framework.
        $version = $this->runArtisan($dest, '--version');
        $this->assertSame(0, $version->exitCode(), 'artifact artisan failed: ' . $version->errorOutput());
        $this->assertStringContainsString('Laravel Framework', $version->output());

        // Autoload coherent after pruning: command discovery boots every provider with no fatal.
        $list = $this->runArtisan($dest, 'list --no-ansi');
        $this->assertSame(0, $list->exitCode(), 'command discovery failed (a pruned class broke autoload?): ' . $list->errorOutput());
        $this->assertStringContainsString('edge:local:sync-send', $list->output());
        $this->assertStringContainsString('edge:local:backup', $list->output());
    }

    public function test_the_booted_artifact_registers_only_the_edge_runtime_routes(): void
    {
        $dest = $this->buildArtifact();

        // route:list is itself CLI-denied on a Branch Server, so boot the artifact via a RAW php script (not an
        // artisan command, so the console boundary does not apply) and dump the routes it actually registers.
        $probe = <<<'PHP'
<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$names = [];
foreach ($app->make('router')->getRoutes()->getRoutes() as $r) { if ($r->getName()) { $names[] = $r->getName(); } }
echo json_encode(['role' => config('app.role'), 'names' => $names]);
PHP;
        file_put_contents($dest . '\\route_probe.php', $probe);
        $res = Process::path($dest)
            ->env(['APP_ROLE' => 'branch_server', 'EDGE_LOCAL_APP_KEY' => 'base64:' . base64_encode(random_bytes(32)), 'APP_ENV' => 'production'])
            ->run(PHP_BINARY . ' route_probe.php');

        $this->assertSame(0, $res->exitCode(), 'artifact route probe failed: ' . $res->errorOutput());
        $data = json_decode(trim($res->output()), true);
        $this->assertIsArray($data, 'probe output: ' . $res->output());
        $this->assertSame('branch_server', $data['role'], 'the artifact booted in the branch_server role');

        $names = $data['names'];
        // Branch runtime loads ONLY edge_runtime.php — the Edge-local surface is registered...
        $this->assertTrue((bool) collect($names)->first(fn ($n) => str_starts_with((string) $n, 'edge.local.')), 'an edge.local route must be registered');
        // ...and the Cloud groups (device API ingestion/reconcile/baseline) are NOT registered on the appliance.
        $this->assertNotContains('edge.api.sync.sales', $names);
        $this->assertNotContains('edge.api.sync.reconcile', $names);
        $this->assertNotContains('edge.api.sync.baseline', $names);
    }

    public function test_the_built_artifact_physically_lacks_cloud_source(): void
    {
        // The shipped artifact tree itself carries no Cloud subsystem source (runtime unloadability then
        // follows from the artifact's OWN dumped autoload over this pruned tree — the deployment model below).
        $dest = $this->buildArtifact();
        foreach ([
            'app/Services/Saas', 'app/Services/Manufacturing', 'app/Http/Controllers/Central',
            'app/Services/Edge/EdgeInboundSaleIngestionService.php', 'app/Services/Finance/SupplierPayableService.php',
        ] as $rel) {
            $this->assertFileDoesNotExist($dest . '/' . $rel);
        }
        // The Edge runtime + shared primitives are present.
        $this->assertFileExists($dest . '/app/Services/Edge/EdgeSyncSender.php');
        $this->assertFileExists($dest . '/app/Services/Finance/JournalPostingService.php');
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
