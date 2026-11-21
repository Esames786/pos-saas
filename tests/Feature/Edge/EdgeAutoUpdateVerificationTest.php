<?php

namespace Tests\Feature\Edge;

use App\Services\Edge\AutoUpdate\EdgeAutoUpdatePackageVerifier;
use App\Services\Edge\AutoUpdate\EdgeAutoUpdateStager;
use App\Services\Edge\AutoUpdate\EdgeDeviceCloudClient;
use App\Services\Edge\EdgeArtifactBuilder;
use App\Services\Edge\EdgeEnrollmentCrypto;
use App\Services\Edge\EdgeSigningKeyStore;
use App\Services\Edge\EdgeUpdatePackageService;
use App\Services\Edge\EdgeUpdateVerifier;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EDGE 0.9 (stream B) — the REAL verification chain of a downloaded release (no fakes in the crypto): the advertisement must
 * match the bytes (manifest sha256, package hash, version), every file must match the manifest, the signing key id must be
 * the one this appliance trusts, the Ed25519 signature must verify with the appliance's public key, the artifact must match
 * the signed manifest hash, and the release minimum must admit the installed version. Plus the zip-slip guard of the stager.
 */
class EdgeAutoUpdateVerificationTest extends TestCase
{
    private string $dir;
    private array $keys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-auv-' . Str::lower(Str::random(8));
        mkdir($this->dir, 0775, true);
        $this->keys = EdgeEnrollmentCrypto::generateKeypair();
        config(['edge.update.public_key' => $this->keys['public'], 'edge.app_version' => '0.9.0-edge', 'edge.update.allow_downgrade' => false]);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
        parent::tearDown();
    }

    public function test_a_genuine_release_verifies(): void
    {
        [$root, $release] = $this->package();
        $r = $this->verifier()->verify($root, $release, '0.9.0-edge', (string) config('edge.config_schema'));
        $this->assertSame('0.9.1-edge', $r['version']);
        $this->assertSame(EdgeSigningKeyStore::keyId($this->keys['public']), $r['signing_key_id']);
        $this->assertSame('0.9.1-edge', $r['package']['payload']['edge_app_version']);
    }

    public function test_every_tampering_or_mismatch_is_refused_with_a_stable_code(): void
    {
        $cases = [
            'advertised manifest sha differs' => [fn (&$root, &$rel) => $rel['manifest_sha256'] = str_repeat('0', 64), 'AUTO_UPDATE_MANIFEST_MISMATCH'],
            'package manifest names another artifact hash' => [function (&$root, &$rel) {
                $m = json_decode(file_get_contents($root . '/package-manifest.json'), true);
                $m['artifact_manifest_hash'] = str_repeat('1', 64);
                file_put_contents($root . '/package-manifest.json', json_encode($m));
            }, 'AUTO_UPDATE_MANIFEST_MISMATCH'],
            'advertised package hash differs' => [fn (&$root, &$rel) => $rel['package_hash'] = str_repeat('0', 64), 'AUTO_UPDATE_PACKAGE_HASH_MISMATCH'],
            'advertised version differs' => [fn (&$root, &$rel) => $rel['version'] = '0.9.2-edge', 'AUTO_UPDATE_VERSION_MISMATCH'],
            'a file was changed after the build' => [fn (&$root, &$rel) => file_put_contents($root . '/app/app/A.php', '<?php // evil'), 'AUTO_UPDATE_PACKAGE_TAMPERED'],
            'signed by another key id' => [fn (&$root, &$rel) => $rel['signing_key_id'] = 'ffffffffffffffff', 'AUTO_UPDATE_SIGNING_KEY_MISMATCH'],
            'release minimum above the installed version' => [fn (&$root, &$rel) => $rel['min_from_version'] = '0.9.1-edge', 'UPDATE_PREVIOUS_TOO_OLD'],
            'incomplete advertisement' => [fn (&$root, &$rel) => $rel['package_hash'] = '', 'AUTO_UPDATE_RELEASE_INCOMPLETE'],
        ];
        foreach ($cases as $label => [$mutate, $code]) {
            [$root, $release] = $this->package();
            $mutate($root, $release);
            $this->assertRefused($root, $release, $code, $label);
        }
    }

    public function test_a_signature_by_a_key_the_appliance_does_not_trust_is_refused(): void
    {
        [$root, $release] = $this->package();
        $other = EdgeEnrollmentCrypto::generateKeypair();
        config(['edge.update.public_key' => $other['public']]);
        $release['signing_key_id'] = ''; // even when the Cloud does not name the key, the signature itself must verify
        $this->assertRefused($root, $release, 'AUTO_UPDATE_SIGNING_KEY_MISMATCH', 'manifest names the real key id');

        // Without the manifest key id hint the Ed25519 check itself refuses.
        [$root, $release] = $this->package(manifestKeyId: false);
        $release['signing_key_id'] = '';
        config(['edge.update.public_key' => $other['public']]);
        $this->assertRefused($root, $release, 'UPDATE_SIGNATURE_INVALID', 'signature with an untrusted key');
    }

    public function test_a_downgrade_is_refused(): void
    {
        [$root, $release] = $this->package('0.8.9-edge');
        $this->assertRefused($root, $release, 'UPDATE_DOWNGRADE_REFUSED', 'downgrade');
    }

    /**
     * CP4 regression: the REAL whole-tree and SHA256SUMS checks (no seam) run on the appliance — the container must be able to
     * build them and they must fail CLOSED with stable codes on a package that is not a full signed 0.9 package (earlier:
     * app(EdgePackageBuilder::class) was unresolvable and PackageChecksums::verify was called statically, so EVERY real
     * release was refused before CP4 caught it).
     */
    public function test_the_real_tree_and_checksum_checks_run_and_fail_closed(): void
    {
        [$root, $release] = $this->package();
        $real = app(EdgeAutoUpdatePackageVerifier::class);
        $tree = (new \ReflectionMethod($real, 'verifyTree'))->invoke($real, $root);
        $this->assertIsArray($tree);
        $this->assertFalse($tree['ok'] ?? null, 'a mini package is not a full Edge package');
        try {
            (new \ReflectionMethod($real, 'verifyChecksums'))->invoke($real, $root);
            $this->fail('a package without SHA256SUMS must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('AUTO_UPDATE_CHECKSUMS_INVALID', $e->getMessage());
            $this->assertStringContainsString('SHA256SUMS_MISSING', $e->getMessage());
        }
        // A signed SHA256SUMS over the package (stream E writer, the appliance's own key) passes the checksum step.
        $kp = sodium_crypto_sign_seed_keypair(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES));
        config(['edge.update.public_key' => base64_encode(sodium_crypto_sign_publickey($kp))]);
        (new \App\Services\Edge\Packaging\PackageChecksums())->write($root, base64_encode(sodium_crypto_sign_secretkey($kp)), null);
        (new \ReflectionMethod($real, 'verifyChecksums'))->invoke($real, $root);
        $this->addToAssertionCount(1);
    }

    public function test_the_stager_refuses_zip_slip_entries(): void
    {
        foreach (['../evil.php', 'a/../../evil', '/abs/path', 'C:/Windows/x', "a\0b"] as $bad) {
            $this->assertFalse(EdgeAutoUpdateStager::safeEntry($bad), $bad);
        }
        foreach (['BingooEdge-0.9.1-edge/package-manifest.json', 'app/vendor/x.php', 'a/..b/c'] as $ok) {
            $this->assertTrue(EdgeAutoUpdateStager::safeEntry($ok), $ok);
        }
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available in this PHP build');
        }
        $zipFile = $this->dir . '/slip.zip';
        $zip = new \ZipArchive();
        $zip->open($zipFile, \ZipArchive::CREATE);
        $zip->addFromString('pkg/package-manifest.json', '{}');
        $zip->addFromString('../escape.txt', 'x');
        $zip->close();
        $stager = new EdgeAutoUpdateStager(new EdgeDeviceCloudClient());
        try {
            $stager->extract($zipFile, $this->dir . '/out');
            $this->fail('zip-slip must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('AUTO_UPDATE_BAD_ZIP', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->dir . '/escape.txt');
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function assertRefused(string $root, array $release, string $code, string $label): void
    {
        try {
            $this->verifier()->verify($root, $release, '0.9.0-edge', (string) config('edge.config_schema'));
            $this->fail("{$label}: must be refused with {$code}");
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith($code, $e->getMessage(), $label);
        }
    }

    /** The real verifier; only the whole-tree check uses a mini-package equivalent (no full Edge runtime sentinels here). */
    private function verifier(): EdgeAutoUpdatePackageVerifier
    {
        return new class(app(EdgeUpdateVerifier::class)) extends EdgeAutoUpdatePackageVerifier {
            /** stream E SHA256SUMS(.sig) is proven by its own suite; mini packages here carry none. */
            protected function verifyChecksums(string $root): void {}

            protected function verifyTree(string $root): array
            {
                $manifest = json_decode((string) file_get_contents($root . '/package-manifest.json'), true);
                $listed = (array) $manifest['files'];
                $tampered = [];
                $missing = [];
                foreach ($listed as $rel => $hash) {
                    $abs = $root . '/' . $rel;
                    if (! is_file($abs)) {
                        $missing[] = $rel;
                    } elseif (! hash_equals($hash, hash_file('sha256', $abs))) {
                        $tampered[] = $rel;
                    }
                }
                ksort($listed);
                $hashOk = hash_equals((string) $manifest['package_hash'], hash('sha256', json_encode($listed)));

                return ['ok' => $missing === [] && $tampered === [] && $hashOk, 'missing' => $missing, 'tampered' => $tampered, 'unlisted' => [], 'package_hash_ok' => $hashOk];
            }
        };
    }

    /** @return array{0:string,1:array} a mini signed package + its K6 advertisement */
    private function package(string $version = '0.9.1-edge', bool $manifestKeyId = true): array
    {
        $src = $this->dir . '/src-' . Str::random(4);
        mkdir($src . '/app', 0775, true);
        file_put_contents($src . '/app/A.php', '<?php // app class');
        file_put_contents($src . '/composer.json', '{}');
        $root = $this->dir . '/pkg-' . Str::random(4);
        mkdir($root, 0775, true);
        (new EdgeArtifactBuilder(['include' => ['app', 'composer.json'], 'exclude' => [], 'forbidden' => []]))->build($src, $root . '/app', ['git_commit' => 'r1']);
        $signed = app(EdgeUpdatePackageService::class)->build($root . '/app', $this->keys['secret'], ['edge_app_version' => $version]);
        mkdir($root . '/update', 0775, true);
        file_put_contents($root . '/update/edge-update-' . $version . '.json', json_encode($signed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
            $files[$rel] = hash_file('sha256', $f->getPathname());
        }
        ksort($files);
        $keyId = EdgeSigningKeyStore::keyId($this->keys['public']);
        $manifest = [
            'package_format_version' => 'edge-package-v1', 'runtime_mode_supported' => 'branch_server', 'edge_app_version' => $version,
            'components' => ['update' => array_filter(['signed' => true, 'signing_key_id' => $manifestKeyId ? $keyId : null])],
            'artifact_manifest_hash' => (string) $signed['payload']['artifact_manifest_hash'], // as EdgePackageBuilder writes it
            'package_hash' => hash('sha256', json_encode($files)), 'files' => $files,
        ];
        file_put_contents($root . '/package-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return [$root, [
            'version' => $version, 'manifest_sha256' => (string) $signed['payload']['artifact_manifest_hash'], 'package_hash' => $manifest['package_hash'],
            'signing_key_id' => $keyId, 'min_from_version' => '0.9.0-edge', 'download_url' => 'https://bingoopos.com/x',
        ]];
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
