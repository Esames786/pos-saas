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

        $plan = $builder->plan($dir);
        $forbidden = $builder->forbidden($plan);

        if ($forbidden !== []) {
            $this->error(count($forbidden) . ' forbidden file(s) would be packaged:');
            foreach (array_slice($forbidden, 0, 50) as $f) {
                $this->line('  - ' . $f);
            }

            return self::FAILURE;
        }

        $this->info('Artifact audit clean — ' . count($plan) . ' files, no forbidden/secret paths.');

        return self::SUCCESS;
    }
}
