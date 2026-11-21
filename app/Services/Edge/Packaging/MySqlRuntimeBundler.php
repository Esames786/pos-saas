<?php

namespace App\Services\Edge\Packaging;

use RuntimeException;

/**
 * Edge 0.9 (stream E) — optionally bundle the MySQL 8.0 server runtime into a package (mysql/), so the setup wizard can
 * register the local `BingooEdgeMySQL` service without a separate download (Khatri ran MySQL 8.0.30 from a USB kit).
 *
 * Profile "server" (default, Edge 0.9): bin/ holds ONLY mysqld/mysql/mysqladmin/mysqldump + the DLLs they load; no lib/.
 * Profile "full": all of bin/, share/, lib/ and the licence/readme files are taken from the runtime directory. Never: a data directory,
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

    /**
     * Profile "server" (the default, Edge 0.9 slimming): ONLY the programs the installer, the wizard and the appliance run
     * (the service, the client for initialisation SQL, the admin ping/shutdown tool, the dump tool) + the DLLs they load
     * (resolved transitively from their import names) + share/ (error messages, charsets) + the licence. Everything else
     * (mysqlpump, mysqlbinlog, myisam*, ibd2sdi, innochecksum, lz4/zlib tools, keyring/ssl/tz helpers, lib/ plugins…) stays out.
     */
    public const SERVER_PROGRAMS = ['bin/mysqld.exe', 'bin/mysql.exe', 'bin/mysqladmin.exe', 'bin/mysqldump.exe'];

    /** @return list<string> the bin/*.dll files the given programs reference, transitively (an import name is an ASCII string in the PE). */
    public static function dllClosure(string $root, array $programs): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $available = [];
        foreach (glob($root . '/bin/*.dll') ?: [] as $dll) {
            $available[strtolower(basename($dll))] = 'bin/' . basename($dll);
        }
        $seen = [];
        $queue = $programs;
        while ($queue !== []) {
            $rel = array_shift($queue);
            $bytes = (string) @file_get_contents($root . '/' . $rel);
            preg_match_all('/[A-Za-z0-9_.+-]{1,64}\.dll/i', $bytes, $m);
            foreach (array_unique(array_map('strtolower', $m[0] ?? [])) as $name) {
                if (isset($available[$name]) && ! isset($seen[$name])) {
                    $seen[$name] = $available[$name];
                    $queue[] = $available[$name];
                }
            }
        }
        $out = array_values($seen);
        sort($out);

        return $out;
    }

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
     * @param  array{probe?:bool, expect_major?:string, profile?:string}  $opts  probe: run `mysqld --version` (default true);
     *                                                          expect_major: required version prefix (default "8.0.");
     *                                                          profile: "server" (default, slim) or "full"
     * @return array the components.mysql manifest entry
     */
    public function bundle(string $runtimeDir, string $destDir, array $opts = []): array
    {
        $src = rtrim(str_replace('\\', '/', $runtimeDir), '/');
        $dst = rtrim(str_replace('\\', '/', $destDir), '/');
        $profile = (string) ($opts['profile'] ?? 'server');
        if (! in_array($profile, ['server', 'full'], true)) {
            throw new RuntimeException('PACKAGE_MYSQL_PROFILE_INVALID: ' . $profile . ' (server|full)');
        }
        $required = $profile === 'server' ? array_values(array_unique(array_merge(self::REQUIRED, self::SERVER_PROGRAMS))) : self::REQUIRED;
        foreach ($required as $req) {
            if (! is_file($src . '/' . $req)) {
                throw new RuntimeException('PACKAGE_MYSQL_RUNTIME_INVALID: ' . $req . ' missing under ' . $runtimeDir);
            }
        }
        $keepBin = $profile === 'server' ? array_merge(self::SERVER_PROGRAMS, self::dllClosure($src, self::SERVER_PROGRAMS)) : null;
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
                $slimmedOut = $keepBin !== null && (str_starts_with($rel, 'bin/') && ! in_array($rel, $keepBin, true) || str_starts_with($rel, 'lib/'));
                if ($slimmedOut || self::isExcluded($rel)) {
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
            'profile' => $profile,
            'programs' => $profile === 'server' ? self::SERVER_PROGRAMS : null,
            'dlls' => $profile === 'server' ? array_values(array_diff($keepBin, self::SERVER_PROGRAMS)) : null,
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
