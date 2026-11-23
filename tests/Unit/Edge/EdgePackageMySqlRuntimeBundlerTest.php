<?php

namespace Tests\Unit\Edge;

use App\Services\Edge\Packaging\MySqlRuntimeBundler;
use PHPUnit\Framework\TestCase;

/**
 * Edge 0.9 (stream E) — `edge:build-package --mysql-runtime`: only the server runtime (bin/share/lib + licence) is
 * bundled; debug symbols/builds, import libraries, headers, docs, test suites, Perl helpers, SQL scripts, my.ini and any
 * data directory never ride in a package.
 */
class EdgePackageMySqlRuntimeBundlerTest extends TestCase
{
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            $this->rrmdir($d);
        }
        parent::tearDown();
    }

    private function tmp(): string
    {
        $d = sys_get_temp_dir() . '/edge-mysql-' . bin2hex(random_bytes(4));
        $this->dirs[] = $d;

        return $d;
    }

    private function fakeRuntime(): string
    {
        $src = $this->tmp();
        foreach ([
            'bin/mysqld.exe', 'bin/mysql.exe', 'bin/mysqladmin.exe', 'bin/mysqldump.exe', 'bin/libssl-1_1-x64.dll', 'bin/jemalloc.dll',
            'bin/mysqld.pdb', 'bin/libprotobuf-debug.dll', 'bin/mysqld-debug.exe', 'bin/libprotobuf.lib', 'bin/mysqld_multi.pl',
            'bin/mysqltest.exe', 'bin/mysql_client_test.exe',
            'share/english/errmsg.sys', 'share/charsets/Index.xml', 'share/install_rewriter.sql', 'share/doc/manual.txt',
            'lib/plugin/auth_socket.dll', 'lib/plugin/debug/auth_socket.dll', 'lib/mysqlclient.lib',
            'include/mysql.h', 'docs/INFO_BIN', 'mysql-test/mtr.pl', 'data/ibdata1', 'my.ini', 'my.ini.bak-20261101', 'LICENSE', 'README',
        ] as $rel) {
            @mkdir(dirname($src . '/' . $rel), 0777, true);
            file_put_contents($src . '/' . $rel, 'x ' . $rel);
        }

        return $src;
    }

    public function test_the_slim_server_profile_keeps_only_the_four_programs_their_dlls_and_share(): void
    {
        $src = $this->fakeRuntime();
        // Import names as the PE stores them: mysqld -> libssl -> libcrypto (transitive), mysql -> jemalloc.
        file_put_contents($src . '/bin/mysqld.exe', "MZ ... LIBSSL-1_1-X64.dll ... KERNEL32.dll");
        file_put_contents($src . '/bin/mysql.exe', 'MZ ... jemalloc.dll');
        file_put_contents($src . '/bin/libssl-1_1-x64.dll', 'MZ ... libcrypto-1_1-x64.dll');
        foreach (['bin/libcrypto-1_1-x64.dll', 'bin/libmecab.dll', 'bin/mysqlpump.exe', 'bin/myisamchk.exe', 'bin/mysqlbinlog.exe', 'lib/private/icudt69l/unames.icu'] as $rel) {
            @mkdir(dirname($src . '/' . $rel), 0777, true);
            file_put_contents($src . '/' . $rel, 'x ' . $rel);
        }
        $dst = $this->tmp();
        $entry = (new MySqlRuntimeBundler())->bundle($src, $dst, ['probe' => false]);
        $this->assertSame('server', $entry['profile']);
        $this->assertSame(['bin/jemalloc.dll', 'bin/libcrypto-1_1-x64.dll', 'bin/libssl-1_1-x64.dll'], $entry['dlls']);
        $bin = array_map('basename', glob($dst . '/bin/*') ?: []);
        sort($bin);
        $this->assertSame(['jemalloc.dll', 'libcrypto-1_1-x64.dll', 'libssl-1_1-x64.dll', 'mysql.exe', 'mysqladmin.exe', 'mysqld.exe', 'mysqldump.exe'], $bin);
        $this->assertFileExists($dst . '/share/english/errmsg.sys');
        $this->assertFileExists($dst . '/share/charsets/Index.xml');
        $this->assertDirectoryDoesNotExist($dst . '/lib/plugin', 'no plugins in the server profile');
        $this->assertFileExists($dst . '/lib/private/icudt69l/unames.icu', 'ICU data for REGEXP is server runtime data (official zip)');
        $this->assertFileExists($dst . '/LICENSE');
        // mysqldump is part of the server profile contract.
        unlink($src . '/bin/mysqldump.exe');
        $this->expectExceptionMessage('PACKAGE_MYSQL_RUNTIME_INVALID: bin/mysqldump.exe');
        (new MySqlRuntimeBundler())->bundle($src, $this->tmp(), ['probe' => false]);
    }

    public function test_the_full_profile_keeps_every_runtime_file_but_never_the_excluded_classes(): void
    {
        $src = $this->fakeRuntime();
        $dst = $this->tmp();
        $entry = (new MySqlRuntimeBundler())->bundle($src, $dst, ['probe' => false, 'profile' => 'full']);

        foreach (['bin/mysqld.exe', 'bin/mysql.exe', 'bin/mysqladmin.exe', 'bin/mysqldump.exe', 'bin/libssl-1_1-x64.dll', 'bin/jemalloc.dll',
            'share/english/errmsg.sys', 'share/charsets/Index.xml', 'lib/plugin/auth_socket.dll', 'LICENSE', 'README'] as $kept) {
            $this->assertFileExists($dst . '/' . $kept, "{$kept} is part of the server runtime");
        }
        foreach (['bin/mysqld.pdb', 'bin/libprotobuf-debug.dll', 'bin/mysqld-debug.exe', 'bin/libprotobuf.lib', 'bin/mysqld_multi.pl',
            'bin/mysqltest.exe', 'bin/mysql_client_test.exe', 'share/install_rewriter.sql', 'share/doc/manual.txt', 'lib/plugin/debug/auth_socket.dll',
            'lib/mysqlclient.lib', 'include/mysql.h', 'docs/INFO_BIN', 'mysql-test/mtr.pl', 'data/ibdata1', 'my.ini', 'my.ini.bak-20261101'] as $dropped) {
            $this->assertFileDoesNotExist($dst . '/' . $dropped, "{$dropped} must never ride in a package");
        }
        $this->assertTrue($entry['bundled']);
        $this->assertSame('mysql/', $entry['root']);
        $this->assertSame(11, $entry['file_count']);
        $this->assertSame(hash_file('sha256', $dst . '/bin/mysqld.exe'), $entry['mysqld_sha256']);
        $this->assertSame(11, $entry['excluded_count'], json_encode($entry['excluded_examples']));
        $this->assertNull($entry['version'], 'no probe on a fake runtime');
    }

    public function test_a_runtime_without_the_server_binary_or_messages_is_refused(): void
    {
        $src = $this->fakeRuntime();
        unlink($src . '/share/english/errmsg.sys');
        $this->expectExceptionMessage('PACKAGE_MYSQL_RUNTIME_INVALID: share/english/errmsg.sys');
        (new MySqlRuntimeBundler())->bundle($src, $this->tmp(), ['probe' => false]);
    }

    public function test_a_fake_binary_fails_the_version_probe(): void
    {
        $this->expectExceptionMessage('PACKAGE_MYSQL_RUNTIME_INVALID: mysqld --version');
        (new MySqlRuntimeBundler())->bundle($this->fakeRuntime(), $this->tmp());
    }

    public function test_the_exclusion_rules(): void
    {
        $this->assertTrue(MySqlRuntimeBundler::isExcluded('bin\\mysqld.pdb'));
        $this->assertTrue(MySqlRuntimeBundler::isExcluded('my.cnf'));
        $this->assertTrue(MySqlRuntimeBundler::isExcluded('share/uninstall_rewriter.sql'));
        $this->assertFalse(MySqlRuntimeBundler::isExcluded('bin/mysqld.exe'));
        $this->assertFalse(MySqlRuntimeBundler::isExcluded('share/charsets/latin1.xml'));
        $this->assertFalse(MySqlRuntimeBundler::isExcluded('bin/libprotobuf-lite.dll'));
    }

    /** The REAL MySQL 8.0.30 runtime of this build host (when present) answers the version probe. */
    public function test_the_real_mysql_runtime_reports_8_0(): void
    {
        $src = 'D:/laragon2/bin/mysql/mysql-8.0.30-winx64';
        if (! is_file($src . '/bin/mysqld.exe')) {
            $this->markTestSkipped('no MySQL 8.0.30 runtime on this machine');
        }
        $probe = new \ReflectionMethod(MySqlRuntimeBundler::class, 'probeVersion');
        $this->assertSame('8.0.30', $probe->invoke(new MySqlRuntimeBundler(), $src . '/bin/mysqld.exe'));
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
