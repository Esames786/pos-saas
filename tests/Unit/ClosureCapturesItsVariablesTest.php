<?php

namespace Tests\Unit;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A closure must capture every outer variable it reads.
 *
 * PHP only discovers an uncaptured variable when the line actually runs, so this class of mistake
 * ships green: a `use ($a, $b)` that forgot `$request`, inside a branch that only executes when a
 * cashier voids a line while completing a sale, is invisible to unit tests of the services beneath
 * it. It cost a live restaurant its checkout on 2026-08-30 — every "Complete Sale" died with
 * "Undefined variable $request" until the release was rolled back.
 *
 * So the check is static, over the whole codebase, and needs no fixtures: parse app/, walk every
 * `function () use (...)`, and confirm each variable it reads is either captured, a parameter, or
 * assigned inside the closure. `$this` and superglobals are always available.
 *
 * If this test fails, do not weaken it — add the variable to the closure's `use` list, or resolve
 * the value before the closure and pass it in.
 */
class ClosureCapturesItsVariablesTest extends TestCase
{
    /** Always in scope inside a closure. */
    private const ALWAYS_AVAILABLE = [
        'this', 'GLOBALS', '_SERVER', '_GET', '_POST', '_FILES',
        '_COOKIE', '_SESSION', '_REQUEST', '_ENV', 'http_response_header',
    ];

    public function test_no_closure_reads_a_variable_it_did_not_capture(): void
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $finder = new NodeFinder;
        $problems = [];

        foreach ($this->phpFiles(dirname(__DIR__, 2) . '/app') as $file) {
            $ast = $parser->parse(file_get_contents($file));
            if ($ast === null) {
                continue;
            }

            /** @var Node\Expr\Closure $closure */
            foreach ($finder->findInstanceOf($ast, Node\Expr\Closure::class) as $closure) {
                $missing = $this->uncapturedVariables($closure, $finder);
                foreach ($missing as $name) {
                    $problems[] = sprintf(
                        '%s:%d  closure reads $%s but does not capture it',
                        str_replace(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR, '', $file),
                        $closure->getStartLine(),
                        $name
                    );
                }
            }
        }

        $this->assertSame([], $problems, "Closures reading variables they never captured:\n" . implode("\n", $problems));
    }

    /**
     * Variables a closure reads from the enclosing scope without a `use`.
     *
     * Deliberately conservative: anything the closure could plausibly define itself — a parameter,
     * an assignment, a foreach target, a catch, a list()/ref — counts as available, so this reports
     * only variables that can ONLY have come from outside. A nested closure is skipped here; the
     * outer loop reaches it on its own with its own `use` list.
     *
     * @return array<int,string>
     */
    private function uncapturedVariables(Node\Expr\Closure $closure, NodeFinder $finder): array
    {
        $available = self::ALWAYS_AVAILABLE;

        foreach ($closure->uses as $use) {
            $available[] = (string) $use->var->name;
        }
        foreach ($closure->params as $param) {
            if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $available[] = $param->var->name;
            }
        }

        $body = $closure->stmts;

        // Anything the closure defines for itself.
        foreach ($finder->find($body, fn (Node $n) => $n instanceof Node\Expr\Assign
            || $n instanceof Node\Expr\AssignRef
            || $n instanceof Node\Expr\AssignOp
            || $n instanceof Node\Stmt\Foreach_
            || $n instanceof Node\Stmt\Catch_
            || $n instanceof Node\Stmt\Static_
            || $n instanceof Node\Stmt\Global_) as $node) {
            foreach ($this->definedNames($node, $finder) as $name) {
                $available[] = $name;
            }
        }

        // Reads, minus anything belonging to a NESTED closure/arrow function (checked separately).
        $nested = $finder->find($body, fn (Node $n) => $n instanceof Node\Expr\Closure || $n instanceof Node\Expr\ArrowFunction);
        $nestedRanges = array_map(fn (Node $n) => [$n->getStartFilePos(), $n->getEndFilePos()], $nested);

        $missing = [];
        foreach ($finder->findInstanceOf($body, Node\Expr\Variable::class) as $variable) {
            if (! is_string($variable->name)) {
                continue;   // $$dynamic — not decidable statically
            }
            if (in_array($variable->name, $available, true)) {
                continue;
            }
            foreach ($nestedRanges as [$start, $end]) {
                if ($variable->getStartFilePos() >= $start && $variable->getEndFilePos() <= $end) {
                    continue 2;
                }
            }
            $missing[$variable->name] = true;
        }

        return array_keys($missing);
    }

    /** @return array<int,string> */
    private function definedNames(Node $node, NodeFinder $finder): array
    {
        $target = match (true) {
            $node instanceof Node\Expr\Assign,
            $node instanceof Node\Expr\AssignRef,
            $node instanceof Node\Expr\AssignOp => $node->var,
            $node instanceof Node\Stmt\Foreach_ => $node,
            default => $node,
        };

        $names = [];
        foreach ($finder->findInstanceOf([$target], Node\Expr\Variable::class) as $variable) {
            if (is_string($variable->name)) {
                $names[] = $variable->name;
            }
        }
        // A foreach also defines its key/value; findInstanceOf above already caught them, but the
        // iterated expression is a READ — harmless to over-allow, this list only widens "available".
        return $names;
    }

    /** @return \Generator<string> */
    private function phpFiles(string $dir): \Generator
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }
}
