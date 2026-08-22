<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Models\Tenant\CateringInstruction;
use App\Services\Tenancy\TenancyManager;
use Database\Seeders\Tenant\CateringInstructionVocabularySeeder;
use Illuminate\Console\Command;

/**
 * KASHIF-CATERING-INSTRUCTIONS-2 — load the client's authoritative kitchen
 * vocabulary into ONE named tenant, on purpose.
 *
 * Deliberately its own narrow command rather than a flag on catering:seed-uat:
 * Kashif already carries live-ish UAT documents and must never be wholesale
 * reseeded just to receive 55 vocabulary rows. This touches exactly one table,
 * idempotently — see the seeder for the full safety contract.
 *
 * Guarded the same way catering:seed-uat is: an exact tenant allowlist plus an
 * explicit confirmation. Khatri Biryani is live and is NOT on the list; no
 * loop over tenants exists here, so no tenant can receive this by accident.
 */
class CateringSeedInstructionsCommand extends Command
{
    protected $signature = 'catering:seed-instructions {tenant_code} {--yes}';

    protected $description = 'Load the authoritative 55-entry kitchen-instruction vocabulary into ONE allowed tenant (idempotent, additive).';

    /** Production tenants explicitly permitted to receive this vocabulary. */
    private const ALLOWED_TENANTS = ['kashifkitchen'];

    public function handle(TenancyManager $tenancy): int
    {
        $code = (string) $this->argument('tenant_code');

        if (! in_array($code, self::ALLOWED_TENANTS, true)) {
            $this->error("Tenant [{$code}] is not on the instruction-seed allowlist (".implode(', ', self::ALLOWED_TENANTS).').');

            return self::FAILURE;
        }

        $tenant = Tenant::where('tenant_code', $code)->first();
        if (! $tenant) {
            $this->error("Tenant [{$code}] not found.");

            return self::FAILURE;
        }

        if (! $this->option('yes') && ! $this->confirm("Load the 55-entry kitchen vocabulary into [{$code}]? (idempotent; nothing is deleted)")) {
            $this->info('Aborted.');

            return self::FAILURE;
        }

        $tenancy->activate($tenant);

        $before = CateringInstruction::count();
        (new CateringInstructionVocabularySeeder)->run();
        $after = CateringInstruction::count();

        $this->info(sprintf(
            '[%s] instruction vocabulary loaded: %d rows before, %d after (%d expected legacy labels; existing rows updated in place, nothing deleted).',
            $code,
            $before,
            $after,
            count(CateringInstructionVocabularySeeder::VOCABULARY),
        ));

        return self::SUCCESS;
    }
}
