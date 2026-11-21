<?php

namespace App\Services\Edge\Packaging;

/**
 * Edge 0.9 (stream E) — package LAYOUT checks shared by the builder (fail the build) and edge:audit-package (report):
 *
 *   - wizard (K8): app/scripts/edge/wizard/** (stream B's setup wizard) and the root-level Setup-BingooEdge.cmd. Optional in
 *     a dev build (warning); REQUIRED in a 0.9+ release build ("strict").
 *   - Windows 10 Task Scheduler compatibility: no packaged PowerShell script may use [TimeSpan]::MaxValue in code (it
 *     serialises to P99999999DT23H59M59S, which Windows 10 rejects — Khatri 21 Nov 2026), nor a day count above 3650.
 *     Comments are ignored. Always an error.
 *   - Cloud URL: the Edge API lives on the CENTRAL domain; the packaged installer's -CloudUrl default (when it has one)
 *     and every `-CloudUrl <url>` example in the packaged install script / README must be https://bingoopos.com
 *     (`https://<tenant>.bingoopos.com` gave "404 api/edge/pair" at Khatri). Warning in dev, error when strict.
 */
final class PackageLayoutInspector
{
    public const CENTRAL_CLOUD_URL = 'https://bingoopos.com';

    /** First Edge version whose RELEASE packages must carry the wizard + central-domain defaults. */
    public const STRICT_FROM_VERSION = '0.9.0';

    public const MAX_TASK_DAYS = 3650;

    public const SETUP_CMD = 'Setup-BingooEdge.cmd';

    /** 0.9+ release package? ("0.9.0-edge" counts: the suffix is ignored; a dev build is never strict by default). */
    public static function isStrict(?string $buildMode, ?string $version, ?bool $override = null): bool
    {
        if ($override !== null) {
            return $override;
        }
        $numeric = (string) preg_replace('/[^0-9.].*$/', '', (string) $version);

        return $buildMode === 'release' && $numeric !== '' && version_compare($numeric, self::STRICT_FROM_VERSION, '>=');
    }

    /** @return array{present:bool, files:int, setup_cmd_root:bool, setup_cmd_scripts:bool, required:bool, ok:bool, severity:string, message:string} */
    public function wizard(string $pkgDir, bool $required): array
    {
        $dir = rtrim(str_replace('\\', '/', $pkgDir), '/');
        $files = is_dir($dir . '/app/scripts/edge/wizard') ? count($this->files($dir . '/app/scripts/edge/wizard')) : 0;
        $present = $files > 0;
        $root = is_file($dir . '/' . self::SETUP_CMD);
        $scripts = is_file($dir . '/scripts/' . self::SETUP_CMD);
        $complete = $present && $root;
        $message = $complete ? 'setup wizard packaged (' . $files . ' files) + root ' . self::SETUP_CMD
            : 'setup wizard ' . ($present ? 'present' : 'ABSENT') . ' (app/scripts/edge/wizard), root ' . self::SETUP_CMD . ' ' . ($root ? 'present' : 'ABSENT');

        return [
            'present' => $present,
            'files' => $files,
            'setup_cmd_root' => $root,
            'setup_cmd_scripts' => $scripts,
            'required' => $required,
            'ok' => $complete || ! $required,
            'severity' => $complete ? 'ok' : ($required ? 'error' : 'warning'),
            'message' => $message,
        ];
    }

    /**
     * Scan every packaged *.ps1 / *.psm1 (outside app/vendor) for Windows-10-incompatible task durations.
     *
     * @return array{ok:bool, files_scanned:int, timespan_maxvalue:list<string>, long_durations:list<string>}
     */
    public function scriptCompatibility(string $pkgDir): array
    {
        $dir = rtrim(str_replace('\\', '/', $pkgDir), '/');
        $max = [];
        $long = [];
        $scanned = 0;
        foreach ($this->files($dir) as $rel) {
            if (str_starts_with($rel, 'app/vendor/') || preg_match('/\.psm?1$/i', $rel) !== 1) {
                continue;
            }
            $scanned++;
            foreach (self::scanPowerShell((string) file_get_contents($dir . '/' . $rel)) as $hit) {
                if ($hit['kind'] === 'maxvalue') {
                    $max[] = $rel . ':' . $hit['line'];
                } else {
                    $long[] = $rel . ':' . $hit['line'] . ' (' . $hit['detail'] . ')';
                }
            }
        }

        return ['ok' => $max === [] && $long === [], 'files_scanned' => $scanned, 'timespan_maxvalue' => $max, 'long_durations' => $long];
    }

    /**
     * The findings in ONE PowerShell source (code only — `#` line comments and `<# … #>` blocks are ignored).
     *
     * @return list<array{kind:string, line:int, detail:string}>
     */
    public static function scanPowerShell(string $source): array
    {
        $hits = [];
        foreach (self::codeLines($source) as $n => $code) {
            if (preg_match('/\[\s*(System\.)?TimeSpan\s*\]\s*::\s*MaxValue/i', $code) === 1) {
                $hits[] = ['kind' => 'maxvalue', 'line' => $n, 'detail' => '[TimeSpan]::MaxValue'];
            }
            if (preg_match_all('/-Days\s+(\d+)/i', $code, $m)) {
                foreach ($m[1] as $days) {
                    if ((int) $days > self::MAX_TASK_DAYS) {
                        $hits[] = ['kind' => 'long', 'line' => $n, 'detail' => '-Days ' . $days . ' > ' . self::MAX_TASK_DAYS];
                    }
                }
            }
            if (preg_match('/\bP(\d{5,})D/', $code, $m) === 1 && (int) $m[1] > self::MAX_TASK_DAYS) {
                $hits[] = ['kind' => 'long', 'line' => $n, 'detail' => 'ISO duration P' . $m[1] . 'D'];
            }
            if (preg_match('/::\s*FromDays\s*\(\s*(\d+)\s*\)/i', $code, $m) === 1 && (int) $m[1] > self::MAX_TASK_DAYS) {
                $hits[] = ['kind' => 'long', 'line' => $n, 'detail' => 'FromDays(' . $m[1] . ')'];
            }
        }

        return $hits;
    }

    /**
     * @return array{ok:bool, severity:string, central:string, install_script:?string, default:?string, default_ok:?bool,
     *               doc_urls:list<string>, bad:list<string>, message:string}
     */
    public function cloudUrl(string $pkgDir, bool $strict): array
    {
        $dir = rtrim(str_replace('\\', '/', $pkgDir), '/');
        $script = null;
        foreach (['scripts/Install-EdgeAppliance.ps1', 'app/scripts/edge/Install-EdgeAppliance.ps1'] as $rel) {
            if (is_file($dir . '/' . $rel)) {
                $script = $rel;
                break;
            }
        }
        $default = null;
        $docs = [];
        $bad = [];
        if ($script !== null) {
            $src = (string) file_get_contents($dir . '/' . $script);
            if (preg_match('/\[string\]\s*\$CloudUrl\s*=\s*[\'"]([^\'"]*)[\'"]/i', $src, $m) === 1) {
                $default = $m[1];
                if (! self::isCentral($default)) {
                    $bad[] = $script . ': -CloudUrl default ' . $default;
                }
            }
            foreach (self::docUrls($src) as $u) {
                $docs[] = $script . ': ' . $u;
                if (! self::isCentral($u)) {
                    $bad[] = $script . ': example -CloudUrl ' . $u;
                }
            }
        }
        foreach (['templates/README-INSTALL.md', 'app/scripts/edge/appliance/README-INSTALL.md'] as $rel) {
            if (is_file($dir . '/' . $rel)) {
                foreach (self::docUrls((string) file_get_contents($dir . '/' . $rel)) as $u) {
                    $docs[] = $rel . ': ' . $u;
                    if (! self::isCentral($u)) {
                        $bad[] = $rel . ': example -CloudUrl ' . $u;
                    }
                }
                break;
            }
        }
        $bad = array_values(array_unique($bad));
        $ok = $bad === [];

        return [
            'ok' => $ok || ! $strict,
            'severity' => $ok ? 'ok' : ($strict ? 'error' : 'warning'),
            'central' => self::CENTRAL_CLOUD_URL,
            'install_script' => $script,
            'default' => $default,
            'default_ok' => $default === null ? null : self::isCentral($default),
            'doc_urls' => array_values(array_unique($docs)),
            'bad' => $bad,
            'message' => $ok ? 'install script / README name the central domain ' . self::CENTRAL_CLOUD_URL . ($default === null ? ' (the installer has no -CloudUrl default)' : '')
                : 'non-central Cloud URL(s): ' . implode('; ', $bad),
        ];
    }

    public static function isCentral(string $url): bool
    {
        return strcasecmp(rtrim(trim($url), '/'), self::CENTRAL_CLOUD_URL) === 0;
    }

    /** @return list<string> every URL written after a `-CloudUrl` (examples / usage text) */
    private static function docUrls(string $text): array
    {
        preg_match_all('/-CloudUrl[\s=]+[\'"]?(https?:\/\/[^\s\'"`)]+)/i', $text, $m);

        return array_values(array_unique($m[1] ?? []));
    }

    /** @return array<int,string> line number => the CODE part of that line (comments removed) */
    private static function codeLines(string $source): array
    {
        $out = [];
        $inBlock = false;
        foreach (preg_split('/\r\n|\n|\r/', $source) ?: [] as $i => $line) {
            $code = '';
            $rest = $line;
            while ($rest !== '') {
                if ($inBlock) {
                    $end = strpos($rest, '#>');
                    if ($end === false) {
                        $rest = '';
                        break;
                    }
                    $rest = substr($rest, $end + 2);
                    $inBlock = false;

                    continue;
                }
                $open = strpos($rest, '<#');
                $hash = self::lineCommentAt($rest);
                if ($open !== false && ($hash === null || $open <= $hash)) {
                    $code .= substr($rest, 0, $open);
                    $rest = substr($rest, $open + 2);
                    $inBlock = true;

                    continue;
                }
                $code .= $hash === null ? $rest : substr($rest, 0, $hash);
                $rest = '';
            }
            $out[$i + 1] = $code;
        }

        return $out;
    }

    /** Position of a `#` that starts a line comment (at the start, or after whitespace / `;` / `{` / `(`), outside quotes. */
    private static function lineCommentAt(string $s): ?int
    {
        $quote = null;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;

                continue;
            }
            if ($c === '#' && ($i === 0 || preg_match('/[\s;{(]/', $s[$i - 1]) === 1) && ($s[$i + 1] ?? '') !== '>') {
                return $i;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function files(string $root): array
    {
        $out = [];
        if (! is_dir($root)) {
            return $out;
        }
        $skip = $root . '/app/vendor';
        $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            fn (\SplFileInfo $f) => ! ($f->isDir() && rtrim(str_replace('\\', '/', $f->getPathname()), '/') === $skip)
        ));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $out[] = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/');
            }
        }
        sort($out);

        return $out;
    }
}
