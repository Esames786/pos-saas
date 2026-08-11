<?php

namespace Tests\Unit\Tenant;

use PHPUnit\Framework\TestCase;

/**
 * POS SCRIPT-SCOPE GUARD.
 *
 * The POS page is split across two <script> blocks: the main one (whose body lives inside a
 * DOMContentLoaded callback) and the customer-modal IIFE. A function declared inside the callback
 * is NOT visible to the IIFE, and the failure is completely silent — the click handler throws a
 * ReferenceError on its first line and the button just does nothing. No console message reaches
 * the cashier, no request is sent, no error is shown.
 *
 * This has now bitten twice: toast() (worked around with a local notify()) and then
 * buttonIsBusy()/setButtonBusy(), which silently killed BOTH "Add & Attach" and "Save address"
 * on the delivery counter. The fix was to declare the shared helpers at script top level.
 *
 * This test re-runs that audit: anything the second block CALLS must be defined in the second
 * block itself, or at top level — never only inside the first block's DOMContentLoaded callback.
 */
class PosScriptScopeRegressionTest extends TestCase
{
    private const VIEW = __DIR__ . '/../../../resources/views/tenant/pos/index.blade.php';

    /** Identifiers that are language/DOM/library builtins or method names, not page functions. */
    private const NOT_PAGE_FUNCTIONS = [
        'if', 'for', 'while', 'switch', 'catch', 'function', 'return', 'typeof', 'new', 'delete',
        'fetch', 'parseInt', 'parseFloat', 'String', 'Number', 'Boolean', 'Array', 'Object', 'JSON',
        'setTimeout', 'setInterval', 'clearTimeout', 'encodeURIComponent', 'decodeURIComponent',
        'Math', 'Date', 'FormData', 'Event', 'console', 'Promise', 'RegExp', 'Error',
        'map', 'filter', 'forEach', 'then', 'push', 'slice', 'splice', 'trim', 'toFixed', 'split',
        'join', 'indexOf', 'includes', 'replace', 'toLowerCase', 'toUpperCase', 'test', 'match',
        'querySelector', 'querySelectorAll', 'getElementById', 'addEventListener', 'createElement',
        'removeEventListener', 'appendChild', 'add', 'remove', 'toggle', 'contains', 'focus',
        'preventDefault', 'stopPropagation', 'hide', 'show', 'getInstance', 'dispatchEvent',
        'json', 'sort', 'reverse', 'keys', 'values', 'entries', 'assign', 'stringify', 'parse',
        'isArray', 'from', 'call', 'apply', 'bind', 'finally', 'all', 'resolve', 'reject',
        'startsWith', 'endsWith', 'padStart', 'repeat', 'hasOwnProperty', 'find', 'some', 'every',
        'concat', 'charAt', 'substring', 'setAttribute', 'removeAttribute', 'getAttribute',
        // blade/template helpers and common single-letter callback params
        'url', 'route', 'asset', 'csrf_token', 's', 'r', 'c', 'a', 'b', 'e', 'j', 'q', 'x',
    ];

    public function test_the_customer_modal_block_never_calls_a_helper_it_cannot_see(): void
    {
        $source = file_get_contents(self::VIEW);
        $this->assertNotFalse($source, 'POS view must be readable');

        [$firstBlock, $secondBlock, $topLevel] = $this->splitScripts($source);

        // Comments discuss these helpers by name (the customer block carries a note about toast()
        // being unreachable) — scan code only, or the guard flags its own documentation.
        preg_match_all('/\b([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/', $this->stripComments($secondBlock), $matches);

        $unreachable = [];
        foreach (array_unique($matches[1]) as $name) {
            if (in_array($name, self::NOT_PAGE_FUNCTIONS, true)) {
                continue;
            }
            if ($this->declares($secondBlock, $name) || $this->declares($topLevel, $name)) {
                continue;
            }
            if ($this->declares($firstBlock, $name)) {
                $unreachable[] = $name;
            }
        }

        $this->assertSame([], $unreachable, implode('', [
            'These are called by the customer-modal script block but declared only inside the main ',
            "block's DOMContentLoaded callback, so the call throws a ReferenceError and the button ",
            'silently does nothing: ' . implode(', ', $unreachable) . '. ',
            'Declare shared helpers at script top level instead.',
        ]));
    }

    public function test_the_shared_busy_guards_stay_at_top_level(): void
    {
        $source = file_get_contents(self::VIEW);
        [, , $topLevel] = $this->splitScripts($source);

        foreach (['buttonIsBusy', 'setButtonBusy'] as $helper) {
            $this->assertTrue(
                $this->declares($topLevel, $helper),
                "{$helper}() must stay declared at script top level — both script blocks call it, "
                . 'and moving it inside DOMContentLoaded silently breaks the customer modal buttons.'
            );
        }
    }

    /**
     * @return array{0:string,1:string,2:string} main block body, customer-modal block, top-level code
     */
    private function splitScripts(string $source): array
    {
        preg_match_all('/<script>(.*?)<\/script>/s', $source, $blocks);
        $scripts = $blocks[1];
        $this->assertGreaterThanOrEqual(2, count($scripts), 'POS view is expected to hold at least two script blocks');

        // The main block is the one containing the DOMContentLoaded callback; the customer modal is
        // the last block (an IIFE). Top level = whatever sits before the callback in the main block.
        $mainIndex = null;
        foreach ($scripts as $i => $script) {
            if (str_contains($script, "document.addEventListener('DOMContentLoaded'")) {
                $mainIndex = $i;
                break;
            }
        }
        $this->assertNotNull($mainIndex, 'main POS script block not found');

        $main = $scripts[$mainIndex];
        $split = strpos($main, "document.addEventListener('DOMContentLoaded'");

        return [
            substr($main, $split),          // inside the callback
            end($scripts),                  // customer-modal IIFE
            substr($main, 0, $split),       // true top level
        ];
    }

    private function stripComments(string $code): string
    {
        $code = preg_replace('#/\*.*?\*/#s', '', $code);

        return preg_replace('#(^|\s)//[^\n]*#', '$1', $code);
    }

    private function declares(string $code, string $name): bool
    {
        $quoted = preg_quote($name, '/');

        return (bool) preg_match("/(function\s+{$quoted}\s*\(|(?:const|let|var)\s+{$quoted}\s*=)/", $code);
    }
}
