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

    public function test_only_the_server_runtime_is_bundled(): void
    {
        $src = $this->fakeRuntime();
        $dst = $this->tmp();
        $entry = (new MySqlRuntimeBundler())->bundle($src, $dst, ['probe' => false]);

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
