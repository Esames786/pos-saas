<?php

namespace Tests\Feature\Edge;

use App\Services\Edge\EdgeArtifactBuilder;
use App\Services\Edge\EdgeEnrollmentCrypto;
use App\Services\Edge\EdgeSigningKeyStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * P5B §2 — release-signing key custody (shape B: an offline encrypted keystore).
 *
 * The private key is never at rest in plaintext, never printed, never inside a package or an artifact; the keystore
 * opens only with its passphrase; a signature made from the opened key verifies with the keystore public key and is
 * refused by any other key.
 */
class EdgeSigningKeyStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (! EdgeEnrollmentCrypto::available() || ! function_exists('sodium_crypto_pwhash')) {
            $this->markTestSkipped('libsodium with Argon2id is required');
        }
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-keystore-' . Str::lower(Str::random(8));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_the_keystore_seals_the_private_key_and_only_the_passphrase_opens_it(): void
    {
        $path = $this->dir . '/release.keystore.json';
        $pass = 'correct horse battery staple 2026';
        $facts = EdgeSigningKeyStore::create($path, $pass, ['label' => 'test']);

        $this->assertFileExists($path);
        $this->assertSame(EdgeSigningKeyStore::keyId($facts['public_key']), $facts['key_id']);
        $this->assertArrayNotHasKey('secret', $facts, 'the mint result never carries the secret');
        $raw = (string) file_get_contents($path);
        $store = json_decode($raw, true);
        $this->assertSame(['format' => EdgeSigningKeyStore::FORMAT, 'version' => 1, 'purpose' => EdgeSigningKeyStore::PURPOSE], ['format' => $store['format'], 'version' => $store['version'], 'purpose' => $store['purpose']]);
        $this->assertSame('argon2id13', $store['kdf']['alg']);
        $this->assertSame('xsalsa20poly1305', $store['cipher']);

        $secret = EdgeSigningKeyStore::open($path, $pass);
        $this->assertSame(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, strlen((string) base64_decode($secret, true)));
        $this->assertStringNotContainsString($secret, $raw, 'the keystore file never holds the plaintext secret');
        $this->assertStringNotContainsString(substr($secret, 0, 24), $raw);

        // Signed with the opened key → verifies with the keystore public key; a different key refuses (wrong key refuses).
        $signed = EdgeEnrollmentCrypto::sign(['edge_app_version' => '0.6.0-edge', 'artifact_manifest_hash' => str_repeat('a', 64)], $secret);
        $this->assertTrue(EdgeEnrollmentCrypto::verifySignature($signed, $facts['public_key']));
        $other = EdgeEnrollmentCrypto::generateKeypair();
        $this->assertFalse(EdgeEnrollmentCrypto::verifySignature($signed, $other['public']), 'a package signed by another key must be refused');
        $tampered = $signed;
        $tampered['payload']['edge_app_version'] = '9.9.9-edge';
        $this->assertFalse(EdgeEnrollmentCrypto::verifySignature($tampered, $facts['public_key']));

        try {
            EdgeSigningKeyStore::open($path, 'wrong passphrase but long enough');
            $this->fail('a wrong passphrase must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('SIGNING_KEYSTORE_PASSPHRASE_INVALID', $e->getMessage());
        }
        $this->assertSame($facts['key_id'], EdgeSigningKeyStore::describe($path)['key_id']);
        $this->assertArrayNotHasKey('ciphertext', EdgeSigningKeyStore::describe($path));
    }

    public function test_the_keystore_refuses_weak_passphrases_overwrites_and_tampering(): void
    {
        $path = $this->dir . '/k.keystore.json';
        try {
            EdgeSigningKeyStore::create($path, 'short');
            $this->fail('weak passphrase');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('SIGNING_PASSPHRASE_WEAK', $e->getMessage());
        }
        EdgeSigningKeyStore::create($path, 'a long enough passphrase 123456');
        try {
            EdgeSigningKeyStore::create($path, 'a long enough passphrase 123456');
            $this->fail('overwrite');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('SIGNING_KEYSTORE_EXISTS', $e->getMessage());
        }
        // A keystore whose public key was swapped (e.g. to make a foreign key look official) is refused by its key id.
        $store = json_decode((string) file_get_contents($path), true);
        $store['public_key'] = EdgeEnrollmentCrypto::generateKeypair()['public'];
        file_put_contents($path, json_encode($store));
        try {
            EdgeSigningKeyStore::open($path, 'a long enough passphrase 123456');
            $this->fail('tampered label');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('SIGNING_KEYSTORE_INVALID', $e->getMessage());
        }
    }

    public function test_the_keygen_command_mints_into_a_keystore_outside_the_tree_and_never_prints_the_secret(): void
    {
        $store = $this->dir . '/pilot.keystore.json';
        $passFile = $this->dir . '/pilot.passphrase';
        file_put_contents($passFile, "pilot release passphrase 2026-09\n");

        config(['app.role' => 'branch_server']);
        $this->assertSame(1, Artisan::call('edge:update:keygen', ['--keystore' => $store, '--passphrase-file' => $passFile]));
        $this->assertStringContainsString('never minted on a Branch Server', Artisan::output());
        config(['app.role' => null]);

        $inTree = base_path('storage/app/oops.keystore.json');
        $this->assertSame(1, Artisan::call('edge:update:keygen', ['--keystore' => $inTree, '--passphrase-file' => $passFile]));
        $this->assertStringContainsString('OUTSIDE the source tree', Artisan::output());
        $this->assertFileDoesNotExist($inTree);

        $this->assertSame(1, Artisan::call('edge:update:keygen', ['--keystore' => $store]), 'both files are required');

        $code = Artisan::call('edge:update:keygen', ['--keystore' => $store, '--passphrase-file' => $passFile, '--label' => 'pilot', '--json' => true]);
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);
        $facts = json_decode(substr($out, (int) strpos($out, '{')), true);
        $this->assertSame(EdgeSigningKeyStore::describe($store)['public_key'], $facts['public_key']);
        $this->assertSame($facts['key_id'], EdgeSigningKeyStore::describe($store)['key_id']);
        $secret = EdgeSigningKeyStore::open($store, 'pilot release passphrase 2026-09');
        $this->assertStringNotContainsString($secret, $out, 'the command output never carries the secret');
        $this->assertStringNotContainsString('pilot release passphrase', $out, 'nor the passphrase');
        $this->assertSame(1, Artisan::call('edge:update:keygen', ['--keystore' => $store, '--passphrase-file' => $passFile]), 'refuses to overwrite');
    }

    public function test_keystore_and_passphrase_files_are_forbidden_in_every_artifact_and_package(): void
    {
        $forbidden = EdgeArtifactBuilder::fromConfig()->forbidden([
            'release/edge-update-signing-v1.keystore.json', 'tools/pilot.keystore', 'secrets/release.passphrase', 'app/Good.php', 'config/edge.php',
        ]);
        $this->assertContains('release/edge-update-signing-v1.keystore.json', $forbidden);
        $this->assertContains('tools/pilot.keystore', $forbidden);
        $this->assertContains('secrets/release.passphrase', $forbidden);
        $this->assertNotContains('app/Good.php', $forbidden);
        $this->assertNotContains('config/edge.php', $forbidden);
    }
}
