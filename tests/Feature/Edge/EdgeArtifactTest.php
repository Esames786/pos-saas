<?php

namespace Tests\Feature\Edge;

use App\Services\Edge\EdgeArtifactBuilder;
use RuntimeException;
use Tests\TestCase;

/**
 * EDGE-RUNTIME-BOUNDARY-1 (R + O) — the restricted artifact builder / secret audit.
 *
 * A synthetic tree proves the allowlist/exclude/forbidden engine + integrity manifest; the REAL repo
 * config proves an actual Edge plan carries no .env / .git / tests / docs / FakePrinter and no
 * forbidden/secret paths.
 */
class EdgeArtifactTest extends TestCase
{
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->rrmdir($dir);
        }
        parent::tearDown();
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/edge_artifact_' . uniqid();
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function writeFile(string $root, string $rel, string $contents = 'x'): void
    {
        $abs = $root . '/' . $rel;
        if (! is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0755, true);
        }
        file_put_contents($abs, $contents);
    }

    private function builder(): EdgeArtifactBuilder
    {
        return new EdgeArtifactBuilder([
            'include'   => ['app', 'composer.json'],
            'exclude'   => ['app/skip/*'],
            'forbidden' => ['#(^|/)\.env$#', '#FakePrinter\.exe$#i', '#\.pem$#i'],
        ]);
    }

    public function test_plan_applies_include_and_exclude(): void
    {
        $root = $this->tempDir();
        $this->writeFile($root, 'app/Good.php');
        $this->writeFile($root, 'app/nested/Ok.php');
        $this->writeFile($root, 'app/skip/Excluded.php');
        $this->writeFile($root, 'composer.json');
        $this->writeFile($root, 'ignored/Outside.php'); // not in include

        $plan = $this->builder()->plan($root);

        $this->assertContains('app/Good.php', $plan);
        $this->assertContains('app/nested/Ok.php', $plan);
        $this->assertContains('composer.json', $plan);
        $this->assertNotContains('app/skip/Excluded.php', $plan, 'exclude glob must prune');
        $this->assertNotContains('ignored/Outside.php', $plan, 'only allowlisted top-level paths are included');
    }

    public function test_build_refuses_when_forbidden_files_present(): void
    {
        $root = $this->tempDir();
        $this->writeFile($root, 'app/Good.php');
        $this->writeFile($root, 'app/.env', 'SECRET=1');            // survives exclude, caught by forbidden
        $this->writeFile($root, 'app/tools/FakePrinter.exe', 'MZ'); // forbidden

        $forbidden = $this->builder()->forbidden($this->builder()->plan($root));
        $this->assertContains('app/.env', $forbidden);
        $this->assertContains('app/tools/FakePrinter.exe', $forbidden);

        $this->expectException(RuntimeException::class);
        $this->builder()->build($root, $this->tempDir());
    }

    public function test_build_produces_manifest_and_valid_hashes_when_clean(): void
    {
        $root = $this->tempDir();
        $this->writeFile($root, 'app/Good.php', '<?php // good');
        $this->writeFile($root, 'app/nested/Ok.php', '<?php // ok');
        $this->writeFile($root, 'composer.json', '{}');
        $dest = $this->tempDir();

        $summary = $this->builder()->build($root, $dest, ['git_commit' => 'deadbeef']);

        // Manifest present + no forbidden survived.
        $this->assertFileExists($dest . '/edge-build-manifest.json');
        $manifest = json_decode(file_get_contents($dest . '/edge-build-manifest.json'), true);
        $this->assertSame('deadbeef', $manifest['git_commit']);
        $this->assertSame('branch_server', $manifest['runtime_mode_supported']);
        $this->assertSame(3, $manifest['file_count']);
        $this->assertSame($summary['manifest_hash'], $manifest['manifest_hash']);

        // Required runtime files copied; excluded/forbidden absent.
        $this->assertFileExists($dest . '/app/Good.php');
        $this->assertFileExists($dest . '/composer.json');

        // Per-file hashes validate against the copied bytes.
        foreach ($manifest['files'] as $rel => $hash) {
            $this->assertSame(hash_file('sha256', $dest . '/' . $rel), $hash, "hash mismatch for $rel");
        }
    }

    public function test_build_creates_empty_runtime_dirs_and_fail_closed_marker(): void
    {
        $builder = new EdgeArtifactBuilder([
            'include' => ['app', 'composer.json'], 'exclude' => [], 'forbidden' => [],
            'runtime_dirs' => ['storage/framework/cache/data', 'storage/logs', 'bootstrap/cache'],
        ]);
        $root = $this->tempDir();
        $this->writeFile($root, 'app/A.php', '<?php');
        $this->writeFile($root, 'composer.json', '{}');
        $dest = $this->tempDir();

        $builder->build($root, $dest, []);

        // Empty writable runtime dirs created (a copy would have omitted them).
        $this->assertDirectoryExists($dest . '/storage/framework/cache/data');
        $this->assertDirectoryExists($dest . '/storage/logs');
        $this->assertDirectoryExists($dest . '/bootstrap/cache');

        // The manifest carries the branch_server marker used by EdgeRuntime to fail closed.
        $manifest = json_decode(file_get_contents($dest . '/edge-build-manifest.json'), true);
        $this->assertSame('branch_server', $manifest['runtime_mode_supported']);
    }

    public function test_physical_audit_catches_stale_files_outside_the_include_allowlist(): void
    {
        $builder = new EdgeArtifactBuilder([
            'include' => ['app'], 'exclude' => [],
            'forbidden' => ['#(^|/)\.env$#', '#\.pem$#i', '#(^|/)\.git(/|$)#'],
        ]);
        $dir = $this->tempDir();
        $this->writeFile($dir, 'app/Good.php', '<?php');
        $this->writeFile($dir, '.env', 'DB_PASSWORD=secret');            // NOT under include -> plan misses it
        $this->writeFile($dir, 'vendor/acme/pkg/private.pem', 'KEY');    // NOT under include
        $this->writeFile($dir, '.git/config', '[core]');                 // NOT under include

        // The plan-based scan is BLIND to these (they are outside the include allowlist)...
        $plan = $builder->plan($dir);
        $this->assertNotContains('.env', $plan);

        // ...but the PHYSICAL audit walks the whole tree and catches every one.
        $hits = $builder->physicalForbidden($dir);
        $this->assertContains('.env', $hits, 'physical audit must catch a stale .env');
        $this->assertContains('vendor/acme/pkg/private.pem', $hits, 'physical audit must catch a stray private key');
        $this->assertTrue((bool) collect($hits)->first(fn ($p) => str_starts_with($p, '.git/')), 'physical audit must catch .git');
    }

    public function test_real_repo_plan_is_secret_free(): void
    {
        $builder = EdgeArtifactBuilder::fromConfig();
        $plan = $builder->plan(base_path());

        // Sanity: real runtime files are present.
        $this->assertContains('composer.json', $plan);
        $this->assertContains('artisan', $plan);
        $this->assertTrue((bool) collect($plan)->first(fn ($p) => str_starts_with($p, 'app/')), 'app/ files present');

        // Secrets / VCS / dev / dumps / FakePrinter are NOT in the plan.
        $this->assertNotContains('.env', $plan);
        $this->assertNotContains('tools/print-agent/dist/FakePrinter.exe', $plan);
        foreach ($plan as $p) {
            $this->assertStringStartsNotWith('.git/', $p);
            $this->assertStringStartsNotWith('tests/', $p);
            $this->assertStringStartsNotWith('docs/', $p);
        }

        // And the forbidden scan is clean on the real allowlisted plan.
        $this->assertSame([], $builder->forbidden($plan), 'the real Edge plan must contain no forbidden/secret files');
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
