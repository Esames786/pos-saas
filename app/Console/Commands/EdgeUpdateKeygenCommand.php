<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeSigningKeyStore;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;

/**
 * P5B §2 — mint the Ed25519 update-signing keypair INTO AN ENCRYPTED KEYSTORE on the release authority.
 *
 *   php artisan edge:update:keygen --keystore=D:\edge-release\keystore\edge-update-signing-v1.keystore.json \
 *       --passphrase-file=D:\edge-release\passphrase\edge-update-signing-v1.passphrase --label="pilot release key"
 *
 * The private key is never printed and never written in plaintext: the keystore holds it sealed under the passphrase
 * (Argon2id + XSalsa20-Poly1305). Refuses on a Branch Server, refuses a keystore path inside the source tree, refuses to
 * overwrite. Prints the PUBLIC key (for EDGE_UPDATE_PUBLIC_KEY on appliances) and the key id / fingerprint.
 */
class EdgeUpdateKeygenCommand extends Command
{
    protected $signature = 'edge:update:keygen
        {--keystore= : Path of the NEW encrypted keystore file (release authority custody; refuses to overwrite)}
        {--passphrase-file= : File holding the keystore passphrase (>= 16 chars; never argv, never printed)}
        {--label= : Custody label recorded in the keystore (public metadata)}
        {--json : Emit the public facts as JSON}';

    protected $description = 'Mint the Ed25519 update-signing keypair into an encrypted keystore on the release authority (public key printed, private key never).';

    public function handle(): int
    {
        if (EdgeRuntime::isBranchServer()) {
            $this->error('Refusing: signing keys are never minted on a Branch Server.');

            return self::FAILURE;
        }
        $store = (string) ($this->option('keystore') ?? '');
        $passFile = (string) ($this->option('passphrase-file') ?? '');
        if ($store === '' || $passFile === '') {
            $this->error('--keystore=<new file> and --passphrase-file=<file> are both required: the private key lives only sealed in the keystore.');

            return self::FAILURE;
        }
        $storeDir = strtolower(str_replace('\\', '/', rtrim((string) (realpath(dirname($store)) ?: dirname($store)), '\\/'))) . '/';
        $tree = strtolower(str_replace('\\', '/', rtrim(base_path(), '\\/'))) . '/';
        if (str_starts_with($storeDir, $tree)) {
            $this->error('Refusing: the keystore must live OUTSIDE the source tree (never git, never a package).');

            return self::FAILURE;
        }
        $passphrase = null;
        try {
            $passphrase = EdgeSigningKeyStore::readPassphraseFile($passFile);
            $facts = EdgeSigningKeyStore::create($store, $passphrase, ['label' => (string) ($this->option('label') ?? '')]);
        } catch (\Throwable $e) {
            $this->error('Keygen refused: ' . $e->getMessage());

            return self::FAILURE;
        } finally {
            if (is_string($passphrase)) {
                sodium_memzero($passphrase);
            }
        }
        if ($this->option('json')) {
            $this->line(json_encode(['public_key' => $facts['public_key'], 'key_id' => $facts['key_id'], 'keystore' => $facts['path']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $this->info('Update-signing keypair minted into the encrypted keystore.');
        $this->line('  keystore    : ' . $facts['path'] . '  (release authority custody; needs its passphrase to sign; never an appliance, never git)');
        $this->line('  public key  : ' . $facts['public_key']);
        $this->line('  key id      : ' . $facts['key_id']);
        $this->line('  appliances  : EDGE_UPDATE_PUBLIC_KEY=<public key>  (Install-EdgeAppliance.ps1 -UpdatePublicKey)');
        $this->line('  sign with   : edge:build-package --signing-keystore=<keystore> --signing-passphrase-file=<file>');

        return self::SUCCESS;
    }
}
