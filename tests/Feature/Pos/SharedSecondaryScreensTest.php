<?php

namespace Tests\Feature\Pos;

use App\Support\Pos\CloudPosRuntimeFactory;
use App\Support\Pos\PosRuntime;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * W-A (Phase 2, coordinator addendum) — the SECONDARY shared screens (shift open/close/index/show, sales-return
 * create/index/show, split bill) take every endpoint from the runtime map, fall back to the Cloud runtime when an Online
 * controller passes none, compile to valid PHP, and use the shared manager-approval prompt. Also pins that both runtime
 * factories define every contract route key (the PosRuntime constructor refuses a missing one at request time).
 */
class SharedSecondaryScreensTest extends TestCase
{
    private const VIEWS = [
        'tenant/shifts/open', 'tenant/shifts/close', 'tenant/shifts/index', 'tenant/shifts/show',
        'tenant/sales-returns/create', 'tenant/sales-returns/index', 'tenant/sales-returns/show',
        'tenant/sales-orders/split-bill',
    ];

    /** The one literal kept on purpose: the Online "Held Sales" page has no runtime key yet (Edge branch → posIndex). */
    private const ALLOWED_LITERALS = ["url('/held-sales')"];

    public function test_every_secondary_view_takes_its_endpoints_from_the_runtime_and_falls_back_to_the_cloud(): void
    {
        foreach (self::VIEWS as $view) {
            $src = (string) file_get_contents(resource_path("views/{$view}.blade.php"));
            $this->assertStringStartsWith("@extends(isset(\$posRuntime) && \$posRuntime->isEdge() ? 'layouts.pos' : 'layouts.app')", $src, $view);
            $this->assertStringContainsString('@php $posRuntime = $posRuntime ?? app(\App\Support\Pos\CloudPosRuntimeFactory::class)->make(); @endphp', $src, $view);

            $stripped = str_replace(self::ALLOWED_LITERALS, '', $src);
            $this->assertStringNotContainsString('url(', $stripped, "{$view} still hard-codes a url() endpoint");

            preg_match_all("/->route\('([A-Za-z]+)'/", $src, $m);
            $this->assertNotEmpty($m[1], "{$view} resolves no runtime route");
            foreach (array_unique($m[1]) as $key) {
                $this->assertContains($key, PosRuntime::ROUTE_KEYS, "{$view} asks for unknown route key [{$key}]");
            }
        }
    }

    public function test_every_secondary_view_compiles_and_the_generated_php_lints(): void
    {
        $php = (new \Symfony\Component\Process\PhpExecutableFinder())->find() ?: PHP_BINARY;
        foreach (self::VIEWS as $view) {
            $compiled = Blade::compileString((string) file_get_contents(resource_path("views/{$view}.blade.php")));
            $tmp = tempnam(sys_get_temp_dir(), 'pos_view_') . '.php';
            file_put_contents($tmp, $compiled);
            $out = [];
            $code = 0;
            exec(escapeshellarg($php) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
            @unlink($tmp);
            $this->assertSame(0, $code, "{$view} compiles to invalid PHP:\n" . implode("\n", $out));
        }
    }

    public function test_the_return_page_uses_the_shared_manager_prompt_and_keeps_its_online_field(): void
    {
        $src = (string) file_get_contents(resource_path('views/tenant/sales-returns/create.blade.php'));
        $this->assertStringContainsString("POS.managerCredentialFieldsHtml(RETURN_PIN_FIELD)", $src);
        $this->assertStringContainsString("POS.managerCredentialFromPrompt(RETURN_PIN_FIELD)", $src);
        $this->assertStringContainsString("{ id: 'return-manager-pin', placeholder: 'Manager PIN', attrs: 'inputmode=\"numeric\" autocomplete=\"off\"' }", $src);
        $this->assertStringContainsString("@json(\$posRuntime->route('managerVerify'))", $src);
        $this->assertStringNotContainsString('pin: pin', $src, 'the credential body comes from the runtime transport');
        // Online (layouts.app) gets the transport from the page itself; Edge (layouts.pos) already has it
        $this->assertMatchesRegularExpression("/@unless\(\\\$posRuntime->isEdge\(\)\)\s*\{\{--.*?--\}\}\s*<script>window\.POS_RUNTIME = window\.POS_RUNTIME \|\| @json\(\\\$posRuntime\);<\/script>\s*@include\('tenant\.pos\.js\.pos-runtime'\)/s", $src);
    }

    public function test_cloud_values_of_the_secondary_screen_keys_are_the_online_paths(): void
    {
        $rt = app(CloudPosRuntimeFactory::class)->make();
        $this->assertSame('/shifts', $rt->route('shiftIndexPage'));
        $this->assertSame('/shifts/5', $rt->route('shiftShowPage', ['shift' => 5]));
        $this->assertSame('/shifts/open', $rt->route('shiftOpenStore'));
        $this->assertSame('/shifts/5/close', $rt->route('shiftCloseStore', ['shift' => 5]));
        $this->assertSame('/shifts/5/close', $rt->route('shiftClosePage', ['shift' => 5]));
        $this->assertSame('/shifts-close-branch', $rt->route('shiftCloseBranchPage'));
        $this->assertSame('/sales-returns/9', $rt->route('salesReturnShowPage', ['salesReturn' => 9]));
        $this->assertSame('/ajax/sales', $rt->route('salesReturnSearch'));
        $this->assertSame('/sales-returns', $rt->route('salesReturnStore'));
        $this->assertSame('/sales-returns', $rt->route('salesReturnIndexPage'));
        $this->assertSame('/sales-orders/3/split-bill', $rt->route('splitBillStore', ['sale' => 3]));
        $this->assertSame('/printing/jobs/4/mark-printed', $rt->route('printMarkPrinted', ['job' => 4]));
        $this->assertSame('/printing/jobs/4/dismiss', $rt->route('printDismiss', ['job' => 4]));
        foreach (['terminals', 'terminalSelect', 'syncSummary', 'shiftSummary', 'voidReasons', 'printPreferences', 'heldKot'] as $key) {
            $this->assertNull($rt->route($key), "[{$key}] has no separate Cloud endpoint");
        }
    }

    /** Team B's Edge factory must define every contract key too (static read — building it needs the appliance DB). */
    public function test_the_edge_factory_defines_every_contract_route_key(): void
    {
        $path = app_path('Services/Edge/EdgePosRuntimeFactory.php');
        if (! is_file($path)) {
            $this->markTestSkipped('Edge runtime factory not present');
        }
        $src = (string) file_get_contents($path);
        $start = strpos($src, 'public function routes(): array');
        $this->assertNotFalse($start);
        $body = substr($src, $start, (int) strpos($src, "\n    }\n", $start) - $start);
        preg_match_all("/^\s*'([A-Za-z]+)'\s*=>/m", $body, $m);
        $missing = array_diff(PosRuntime::ROUTE_KEYS, $m[1]);
        $this->assertSame([], array_values($missing), 'EdgePosRuntimeFactory::routes() misses contract keys');
    }
}
