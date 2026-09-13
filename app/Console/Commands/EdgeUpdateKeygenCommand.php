<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeEnrollmentCrypto;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;

/**
 * P5 KEY CUSTODY — mint the Ed25519 update-signing keypair on the BUILD HOST (never on an appliance).
 *
 *   php artisan edge:update:keygen --private-out=D:\secure\edge-update-signing.key
 *
 * The PRIVATE key is written to the given file only (0600; the operator moves it into the release-signing custody:
 * an offline/HSM-backed store or the CI secret store — never the repository, never an appliance, never a chat). The
 * PUBLIC key is printed: it goes into every appliance's appliance.env as EDGE_UPDATE_PUBLIC_KEY (the installer's
 * -UpdatePublicKey). Rotation = mint a new pair, ship the new public key to appliances through a signed update whose
 * payload the OLD key still signs, then retire the old private key. Refuses to run on a Branch Server.
 */
class EdgeUpdateKeygenCommand extends Command
{
    protected $signature = 'edge:update:keygen
        {--private-out= : File to receive the base64 PRIVATE signing key (created 0600; refuses to overwrite)}
        {--json : Emit the public key as JSON}';

    protected $description = 'Mint the Ed25519 update-signing keypair on the build host (private key to a file, public key printed).';

    public function handle(): int
    {
        if (EdgeRuntime::isBranchServer()) {
            $this->error('Refusing: signing keys are never minted on a Branch Server.');

            return self::FAILURE;
        }
        if (! EdgeEnrollmentCrypto::available()) {
            $this->error('libsodium is required to mint an Ed25519 keypair.');

            return self::FAILURE;
        }
        $out = (string) ($this->option('private-out') ?? '');
        if ($out === '') {
            $this->error('--private-out is required: the private key is written to a file, never printed.');

            return self::FAILURE;
        }
        if (file_exists($out)) {
            $this->error('Refusing to overwrite an existing key file: ' . $out);

            return self::FAILURE;
        }
        $dir = dirname($out);
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            $this->error('Cannot create ' . $dir);

            return self::FAILURE;
        }
        $kp = EdgeEnrollmentCrypto::generateKeypair();
        if (file_put_contents($out, $kp['secret'] . PHP_EOL, LOCK_EX) === false) {
            $this->error('Cannot write ' . $out);

            return self::FAILURE;
        }
        @chmod($out, 0600);
        $fingerprint = substr(hash('sha256', base64_decode($kp['public'], true) ?: $kp['public']), 0, 16);
        if ($this->option('json')) {
            $this->line(json_encode(['public_key' => $kp['public'], 'fingerprint' => $fingerprint, 'private_key_file' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $this->info('Update-signing keypair minted.');
        $this->line('  private key : ' . $out . '  (move into release-signing custody; never an appliance, never git)');
        $this->line('  public key  : ' . $kp['public']);
        $this->line('  fingerprint : ' . $fingerprint);
        $this->line('  appliances  : EDGE_UPDATE_PUBLIC_KEY=<public key>  (Install-EdgeAppliance.ps1 -UpdatePublicKey)');

        return self::SUCCESS;
    }
}
