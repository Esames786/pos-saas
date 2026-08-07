<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeEnrollmentConsumer;
use App\Support\EdgeLocalDatabase;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;
use Throwable;

/**
 * EDGE-LOCAL-AUTH-1 (Sections 7/9) — consume a Cloud-signed enrollment assertion and set the user's
 * Edge credential on the Branch Server.
 *
 *   php artisan edge:local:enroll <assertion.json> [--credential=...] [--type=password|pin]
 *
 * Branch Server only, safe Edge DB only. The credential is prompted secretly if not supplied. This
 * NEVER touches the Cloud master DB and NEVER stores/echoes the raw credential.
 */
class EdgeLocalEnrollCommand extends Command
{
    protected $signature = 'edge:local:enroll {assertion : Path to the signed enrollment assertion JSON}
                            {--credential= : The new Edge credential (prompted secretly if omitted)}
                            {--type=password : password|pin}';

    protected $description = 'Consume a Cloud enrollment assertion and set a local Edge credential (config only, no selling).';

    public function handle(EdgeEnrollmentConsumer $consumer): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:enroll only runs on a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }
        if (($reason = EdgeLocalDatabase::unsafeReason()) !== null) {
            $this->error("Refusing to enroll: {$reason}.");

            return self::FAILURE;
        }

        $path = (string) $this->argument('assertion');
        if (! is_file($path)) {
            $this->error("Assertion file not found: {$path}");

            return self::FAILURE;
        }
        $assertion = json_decode((string) file_get_contents($path), true);
        if (! is_array($assertion) || ! isset($assertion['payload'], $assertion['signature'])) {
            $this->error('Invalid assertion: expected a JSON object with "payload" and "signature".');

            return self::FAILURE;
        }

        EdgeLocalDatabase::useAsTenantConnection();

        $credential = (string) ($this->option('credential') ?? '');
        if ($credential === '') {
            $credential = (string) $this->secret('Choose the Edge credential for this user');
        }

        try {
            $cred = $consumer->consume($assertion, $credential, (string) $this->option('type'));
        } catch (Throwable $e) {
            $this->error('Enrollment failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Edge credential set. User #' . $cred->user_id . ' may now log in locally (version ' . $cred->credential_version . ').');

        return self::SUCCESS;
    }
}
