<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Blade;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * PLATFORM-ENTITLEMENT-BOUNDARY-1 — compiled-Blade syntax gate.
 *
 * WHY THIS EXISTS: `php artisan view:cache` compiles Blade to PHP but NEVER
 * validates the PHP it produces. Two Catering views shipped to production whose
 * generated PHP could not parse (a long multi-line array literal inside
 * `@json(...)`: Blade matches directive arguments with a recursive paren regex
 * which hits PCRE limits on long payloads and SILENTLY TRUNCATES the output). Every gate we had reported GREEN: view:cache said "cached
 * successfully", and the MySQL suite only exercised services, never rendering.
 * The screens 500'd for the client.
 *
 * This test compiles EVERY Blade view in the application and runs `php -l` over
 * the generated PHP. It is deliberately platform-wide — never a list of known
 * filenames — so the whole class of defect cannot reach production again.
 */
class CompiledBladeSyntaxTest extends TestCase
{
    /** Views whose compiled output legitimately cannot stand alone, if any. */
    private const EXCLUDE = [];

    public function test_every_blade_view_compiles_to_valid_php(): void
    {
        $viewsRoot = realpath(resource_path('views'));
        $this->assertIsString($viewsRoot, 'resources/views must exist');

        $php = $this->phpBinary();
        $checked = 0;
        $invalid = [];

        /** @var \SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewsRoot)) as $file) {
            if ($file->isDir() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $relative = str_replace($viewsRoot.DIRECTORY_SEPARATOR, '', $file->getPathname());
            if (in_array($relative, self::EXCLUDE, true)) {
                continue;
            }

            $checked++;

            try {
                $compiled = Blade::compileString(file_get_contents($file->getPathname()));
            } catch (\Throwable $e) {
                $invalid[] = $relative.' => COMPILE FAILED: '.$e->getMessage();

                continue;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'blade_lint_').'.php';
            file_put_contents($tmp, $compiled);
            $output = [];
            $status = 0;
            exec($php.' -l '.escapeshellarg($tmp).' 2>&1', $output, $status);
            @unlink($tmp);

            if ($status !== 0) {
                $message = preg_replace('/ in .*$/m', '', implode(' ', $output));
                $invalid[] = $relative.' => '.trim($message);
            }
        }

        $this->assertGreaterThan(100, $checked, 'expected the full view tree to be scanned');
        $this->assertSame(
            [],
            $invalid,
            "Compiled Blade produced invalid PHP in {$checked} scanned views:\n  - ".implode("\n  - ", $invalid)
                ."\n\nFIX: never place a multi-line array literal inside a Blade directive such as"
                .' @json([...]). Build the payload in a @php block and pass the variable.'
        );
    }

    private function phpBinary(): string
    {
        $binary = PHP_BINARY;

        return $binary !== '' ? escapeshellarg($binary) : 'php';
    }
}
