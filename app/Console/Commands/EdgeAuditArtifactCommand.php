<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeArtifactBuilder;
use Illuminate\Console\Command;

/**
 * EDGE-RUNTIME-BOUNDARY-1 (O) — audit a directory (a built artifact, or the working tree plan) for
 * forbidden files: secrets, VCS, dumps, dev artifacts, FakePrinter.exe. Reports PATHS only, never
 * contents. Non-zero exit if anything forbidden is found — usable as a CI gate.
 *
 *   php artisan edge:audit-artifact {dir}
 */
class EdgeAuditArtifactCommand extends Command
{
    protected $signature = 'edge:audit-artifact {dir? : Directory to audit (defaults to the app root)}';

    protected $description = 'Fail if a directory would package forbidden/secret files into an Edge artifact.';

    public function handle(): int
    {
        $dir = (string) ($this->argument('dir') ?: base_path());
        $builder = EdgeArtifactBuilder::fromConfig();

        // PHYSICAL audit: walk EVERY file under $dir, independent of the include allowlist, so a stale
        // file that was never in the plan (e.g. a copied .env) is still detected.
        $forbidden = $builder->physicalForbidden($dir);

        if ($forbidden !== []) {
            $this->error(count($forbidden) . ' forbidden/secret file(s) found under [' . $dir . ']:');
            foreach (array_slice($forbidden, 0, 50) as $f) {
                $this->line('  - ' . $f);
            }

            return self::FAILURE;
        }

        $this->info('Physical artifact audit clean — no forbidden/secret paths under [' . $dir . '].');

        return self::SUCCESS;
    }
}
