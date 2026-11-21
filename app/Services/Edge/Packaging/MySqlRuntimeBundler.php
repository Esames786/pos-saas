<?php

namespace App\Services\Edge\Packaging;

use RuntimeException;

/**
 * Edge 0.9 (stream E) — optionally bundle the MySQL 8.0 server runtime into a package (mysql/), so the setup wizard can
 * register the local `BingooEdgeMySQL` service without a separate download (Khatri ran MySQL 8.0.30 from a USB kit).
 *
 * Only bin/, share/, lib/ and the licence/readme files are taken from the runtime directory. Never: a data directory,
 * my.ini / my.cnf (machine-specific, written by the installer), debug symbols (*.pdb) or debug builds (*-debug.*),
 * import libraries (*.lib), headers (include/), documentation (docs/, man/, share/doc), test suites (mysql-test/,
 * mysqltest*.exe, mysql_client_test*.exe), Perl helpers (*.pl), logs, or SQL scripts (the boundary forbids *.sql).
 */
final class MySqlRuntimeBundler
{
    /** Top-level entries taken from the runtime directory. */
    public const TOP_LEVEL = ['bin', 'share', 'lib', 'LICENSE', 'README', 'LICENSE.router', 'README.router'];

    /** Never bundled (relative to the runtime root, forward slashes). */
    public const EXCLUDE = [
        '#\.pdb$#i',
        '#-debug\.(dll|exe)$#i',
        '#(^|/)(debug)(/|$)#i',                // lib/plugin/debug
        '#\.lib$#i',
        '#\.pl$#i',
        '#\.sql$#i',
        '#\.(log|err|pid)$#i',
        '#(^|/)(mysql-test|include|docs|man|data)(/|$)#i',
        '#(^|/)share/doc(/|$)#i',
        '#(^|/)bin/(mysqltest|mysql_client_test|mysql_embedded|mysqltest_embedded|mysql_keyring_encryption_test)[^/]*$#i',
        '#(^|/)my(-[^/]*)?\.(ini|cnf)([.-][^/]*)?$#i',
    ];

    /** Files a usable server bundle must carry. */
    public const REQUIRED = ['bin/mysqld.exe', 'bin/mysql.exe', 'bin/mysqladmin.exe', 'share/english/errmsg.sys'];

    public static function isExcluded(string $rel): bool
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        foreach (self::EXCLUDE as $p) {
            if (preg_match($p, $rel) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{probe?:bool, expect_major?:string}  $opts  probe: run `mysqld --version` (default true);
     *                                                          expect_major: required version prefix (default "8.0.")
     * @return array the components.mysql manifest entry
     */
    public function bundle(string $runtimeDir, string $destDir, array $opts = []): array
    {
        $src = rtrim(str_replace('\\', '/', $runtimeDir), '/');
        $dst = rtrim(str_replace('\\', '/', $destDir), '/');
        foreach (self::REQUIRED as $req) {
            if (! is_file($src . '/' . $req)) {
                throw new RuntimeException('PACKAGE_MYSQL_RUNTIME_INVALID: ' . $req . ' missing under ' . $runtimeDir);
            }
        }
        $version = null;
        if ($opts['probe'] ?? true) {
            $version = $this->probeVersion($src . '/bin/mysqld.exe');
            $expect = (string) ($opts['expect_major'] ?? '8.0.');
            if ($version === null || ! str_starts_with($version, $expect)) {
                throw new RuntimeException('PACKAGE_MYSQL_RUNTIME_INVALID: mysqld --version reports ' . var_export($version, true) . ', expected ' . $expect . 'x');
            }
        }
        $copied = 0;
        $bytes = 0;
        $excluded = [];
        foreach (self::TOP_LEVEL as $top) {
            $path = $src . '/' . $top;
            if (is_file($path)) {
                $rels = [$top];
            } elseif (is_dir($path)) {
                $rels = $this->files($src, $top);
            } else {
                continue;
            }
            foreach ($rels as $rel) {
                if (self::isExcluded($rel)) {
                    $excluded[] = $rel;

                    continue;
                }
                $to = $dst . '/' . $rel;
                if (! is_dir(dirname($to))) {
                    mkdir(dirname($to), 0755, true);
                }
                copy($src . '/' . $rel, $to);
                $copied++;
                $bytes += (int) filesize($to);
            }
        }

        return [
            'bundled' => true,
            'kind' => 'mysql',
            'version' => $version,
            'source' => $runtimeDir,
            'root' => 'mysql/',
            'file_count' => $copied,
            'size_bytes' => $bytes,
            'mysqld_sha256' => hash_file('sha256', $dst . '/bin/mysqld.exe'),
            'excluded_count' => count($excluded),
            'excluded_examples' => array_slice($excluded, 0, 15),
            'note' => 'server binaries only: the installer writes my.ini and initialises the data directory under the data root',
        ];
    }

    private function probeVersion(string $mysqld): ?string
    {
        $proc = @proc_open([str_replace('/', DIRECTORY_SEPARATOR, $mysqld), '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, sys_get_temp_dir());
        if (! is_resource($proc)) {
            return null;
        }
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return preg_match('/Ver\s+(\d+\.\d+\.\d+)/', $out, $m) === 1 ? $m[1] : null;
    }

    /** @return list<string> */
    private function files(string $root, string $top): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $top, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $out[] = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/');
            }
        }
        sort($out);

        return $out;
    }
}
