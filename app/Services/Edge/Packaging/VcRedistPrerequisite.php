<?php

namespace App\Services\Edge\Packaging;

use RuntimeException;

/**
 * Edge 0.9 (stream E) — the Microsoft Visual C++ 2015-2022 x64 runtime as a packaged PREREQUISITE (prereqs/VC_redist.x64.exe).
 *
 * PHP 8.3 (vs16) and MySQL 8.0 do not start without it, and a clean Windows (B's Sandbox run, 22 Nov 2026) does not have it.
 * The builder bundles the OFFICIAL redistributable only after proving:
 *   - Authenticode status Valid and a Microsoft Corporation signer (Get-AuthenticodeSignature, Windows build host);
 *   - a COMPLETE WiX Burn bundle: the ".wixburn" header's stub + UX + attached-container sizes fit inside the file. The
 *     copy Windows keeps under C:\ProgramData\Package Cache\{…}\VC_redist.x64.exe is STRIPPED of its 18 MB payload
 *     container (630 KB) — it cannot install on an offline PC and is refused here;
 *   - FileVersion 14.x (the 2015-2022 family; the registry minimum Install-EdgePrereqs.ps1 enforces is this version).
 */
final class VcRedistPrerequisite
{
    public const FILE = 'prereqs/VC_redist.x64.exe';

    /** @var callable(string):array{status:string, signer:?string, issuer:?string, version:?string} */
    private $probe;

    /** @param callable|null $probe Authenticode + version probe (tests inject a fake; default: Windows PowerShell) */
    public function __construct(?callable $probe = null)
    {
        $this->probe = $probe ?? [self::class, 'powershellProbe'];
    }

    /**
     * @return array{file:string, bytes:int, sha256:string, burn:array, authenticode:array, version:?string, ok:bool, problems:list<string>}
     */
    public function inspect(string $exe): array
    {
        if (! is_file($exe)) {
            return ['file' => $exe, 'bytes' => 0, 'sha256' => '', 'burn' => [], 'authenticode' => [], 'version' => null, 'ok' => false, 'problems' => ['VCREDIST_MISSING: ' . $exe]];
        }
        $problems = [];
        $burn = self::burnLayout($exe);
        if (! $burn['is_bundle']) {
            $problems[] = 'VCREDIST_NOT_A_BUNDLE: no WiX Burn header (not the official VC_redist.x64.exe)';
        } elseif (! $burn['complete']) {
            $problems[] = 'VCREDIST_INCOMPLETE: the payload container is missing (' . $burn['file_bytes'] . ' of ' . $burn['expected_bytes']
                . ' bytes) - a Package Cache copy cannot install offline; use the full redistributable from Microsoft';
        }
        $sig = ($this->probe)($exe);
        if (($sig['status'] ?? '') !== 'Valid') {
            $problems[] = 'VCREDIST_SIGNATURE_INVALID: Authenticode status ' . ($sig['status'] ?? 'unknown');
        }
        if (! preg_match('/(^|,\s*)O=Microsoft Corporation(,|$)/', (string) ($sig['signer'] ?? '')) || ! str_contains((string) ($sig['signer'] ?? ''), 'CN=Microsoft Corporation')) {
            $problems[] = 'VCREDIST_SIGNER_NOT_MICROSOFT: ' . ($sig['signer'] ?? '(none)');
        }
        $version = $sig['version'] ?? null;
        if (! is_string($version) || ! str_starts_with($version, '14.')) {
            $problems[] = 'VCREDIST_VERSION_UNEXPECTED: ' . var_export($version, true) . ' (the 2015-2022 runtime is 14.x)';
        }

        return [
            'file' => $exe,
            'bytes' => (int) filesize($exe),
            'sha256' => (string) hash_file('sha256', $exe),
            'burn' => $burn,
            'authenticode' => ['status' => $sig['status'] ?? null, 'signer' => $sig['signer'] ?? null, 'issuer' => $sig['issuer'] ?? null],
            'version' => $version,
            'ok' => $problems === [],
            'problems' => $problems,
        ];
    }

    /** Verify and copy into <package>/prereqs/. @return array the components.prereqs.vc_redist manifest entry */
    public function bundle(string $exe, string $pkgDir): array
    {
        $info = $this->inspect($exe);
        if (! $info['ok']) {
            throw new RuntimeException('PACKAGE_PREREQ_REFUSED: ' . implode(' | ', $info['problems']));
        }
        $to = rtrim(str_replace('\\', '/', $pkgDir), '/') . '/' . self::FILE;
        if (! is_dir(dirname($to))) {
            mkdir(dirname($to), 0755, true);
        }
        copy($exe, $to);

        return [
            'file' => self::FILE,
            'product' => 'Microsoft Visual C++ 2015-2022 Redistributable (x64)',
            'version' => $info['version'],
            'sha256' => $info['sha256'],
            'bytes' => $info['bytes'],
            'authenticode_status' => $info['authenticode']['status'],
            'signer' => $info['authenticode']['signer'],
            'burn_complete' => $info['burn']['complete'],
            'install' => 'scripts\\Install-EdgePrereqs.ps1 (VC_redist.x64.exe /install /quiet /norestart when HKLM VC\\Runtimes\\x64 is missing or older)',
        ];
    }

    /**
     * The WiX Burn ".wixburn" section: stub (engine) size + UX container + attached payload container vs the file size.
     *
     * @return array{is_bundle:bool, complete:bool, file_bytes:int, stub_bytes:int, ux_bytes:int, attached_bytes:int, expected_bytes:int}
     */
    public static function burnLayout(string $exe): array
    {
        $out = ['is_bundle' => false, 'complete' => false, 'file_bytes' => (int) @filesize($exe), 'stub_bytes' => 0, 'ux_bytes' => 0, 'attached_bytes' => 0, 'expected_bytes' => 0];
        $h = @fopen($exe, 'rb');
        if ($h === false) {
            return $out;
        }
        try {
            $read = function (int $off, int $len) use ($h): string {
                fseek($h, $off);

                return (string) fread($h, $len);
            };
            $lfanew = $read(0x3C, 4);
            if ($read(0, 2) !== 'MZ' || strlen($lfanew) !== 4) {
                return $out;
            }
            $pe = unpack('V', $lfanew)[1];
            $coff = $read($pe, 24);
            if (strlen($coff) !== 24 || substr($coff, 0, 4) !== "PE\0\0") {
                return $out;
            }
            $sections = unpack('v', substr($coff, 6, 2))[1];
            $optSize = unpack('v', substr($coff, 20, 2))[1];
            $table = $pe + 24 + $optSize;
            for ($i = 0; $i < min($sections, 96); $i++) {
                $hdr = $read($table + $i * 40, 40);
                if (strlen($hdr) !== 40 || rtrim(substr($hdr, 0, 8), "\0") !== '.wixburn') {
                    continue;
                }
                $raw = unpack('V', substr($hdr, 20, 4))[1];
                $bytes = $read($raw, 56);
                if (strlen($bytes) !== 56) {
                    return $out;
                }
                $d = unpack('Vmagic/Vversion/a16guid/Vstub/Vchecksum/VsigOffset/VsigSize/Vformat/Vcount/Vux/Vattached', $bytes);
                if (($d['magic'] ?? 0) !== 0x00F14300) {
                    return $out;
                }
                $expected = (int) $d['stub'] + (int) $d['ux'] + ((int) $d['count'] > 1 ? (int) $d['attached'] : 0);

                return array_merge($out, ['is_bundle' => true, 'stub_bytes' => (int) $d['stub'], 'ux_bytes' => (int) $d['ux'],
                    'attached_bytes' => (int) $d['attached'], 'expected_bytes' => $expected, 'complete' => $out['file_bytes'] >= $expected]);
            }

            return $out;
        } finally {
            fclose($h);
        }
    }

    /** @return array{status:string, signer:?string, issuer:?string, version:?string} */
    public static function powershellProbe(string $exe): array
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return ['status' => 'UnsupportedPlatform', 'signer' => null, 'issuer' => null, 'version' => null];
        }
        $script = '$f=$env:BINGOO_PREREQ_FILE; $s=Get-AuthenticodeSignature -LiteralPath $f; $v=(Get-Item -LiteralPath $f).VersionInfo; '
            . '[pscustomobject]@{status=[string]$s.Status; signer=$(if($s.SignerCertificate){$s.SignerCertificate.Subject}else{$null}); '
            . 'issuer=$(if($s.SignerCertificate){$s.SignerCertificate.Issuer}else{$null}); version=[string]$v.FileVersion} | ConvertTo-Json -Compress';
        $env = getenv();
        $env['BINGOO_PREREQ_FILE'] = $exe; // the path travels in the environment, never interpolated into the script
        $p = @proc_open(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (! is_resource($p)) {
            return ['status' => 'ProbeFailed', 'signer' => null, 'issuer' => null, 'version' => null];
        }
        $json = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
        $d = json_decode(trim($json), true);

        return is_array($d) ? ['status' => (string) ($d['status'] ?? ''), 'signer' => $d['signer'] ?? null, 'issuer' => $d['issuer'] ?? null, 'version' => ($d['version'] ?? '') !== '' ? $d['version'] : null]
            : ['status' => 'ProbeFailed', 'signer' => null, 'issuer' => null, 'version' => null];
    }
}
