<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\Product;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesOrderLine;
use App\Services\Printing\KotTerminalRoutingRewriter;
use App\Services\Printing\PrintRoutingService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * PHASE 4 — flip a tenant's KOT routing from order-type-keyed to TERMINAL-keyed (see
 * KotTerminalRoutingRewriter). Prints a before/after routing check so an operator can SEE that
 * Delivery and Dine-In still resolve to the exact same printers before trusting it.
 *
 *   php artisan khatri:kot-terminal-routing --dry     # preview + before/after, changes nothing
 *   php artisan khatri:kot-terminal-routing           # apply (take a DB backup first)
 */
class RewriteKhatriKotTerminalRoutingCommand extends Command
{
    protected $signature = 'khatri:kot-terminal-routing {--tenant=khatribiryani} {--branch= : Branch id (defaults to the only/first active branch)} {--dry : Preview only}';

    protected $description = 'Convert a tenant KOT routing to terminal-keyed, verifying Delivery/Dine-In printers do not move.';

    public function handle(KotTerminalRoutingRewriter $rewriter, PrintRoutingService $routing): int
    {
        $tenant = Tenant::where('tenant_code', $this->option('tenant'))->first();
        if (! $tenant) {
            $this->error("Tenant '{$this->option('tenant')}' not found.");

            return self::FAILURE;
        }
        app(TenancyManager::class)->activate($tenant);

        $branchId = $this->option('branch')
            ? (int) $this->option('branch')
            : (int) (Branch::orderBy('id')->value('id') ?? 0);
        if (! $branchId) {
            $this->error('No branch found for this tenant.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry');
        $this->info(($dry ? '[DRY RUN] ' : '') . "KOT terminal routing for tenant '{$tenant->tenant_code}', branch {$branchId}");

        // Capture the CURRENT routing for a food + an overflow category on every order type, so we can
        // prove it does not move.
        $before = $this->probe($routing, $branchId);

        try {
            $result = $rewriter->rewrite($branchId, $dry);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Terminal map (order type → terminal id):');
        foreach ($result['terminal_map'] as $ot => $tid) {
            $this->line(sprintf('   %-11s → terminal #%d', $ot, $tid));
        }
        $this->info(sprintf('Rules %s: %d', $dry ? 'that WOULD convert' : 'converted', $dry ? $result['pending'] : $result['converted']));

        $after = $this->probe($routing, $branchId);

        // Verify: Delivery + Dine-In printers must be identical before and after.
        $this->newLine();
        $this->line('Routing check (category → printer ids):');
        $ok = true;
        foreach ($before as $label => $printers) {
            $now = $after[$label] ?? [];
            $isCritical = str_starts_with($label, 'delivery ') || str_starts_with($label, 'dine_in ');
            $moved = $isCritical && ($printers !== $now);
            if ($moved) {
                $ok = false;
            }
            $this->line(sprintf('   %-22s  before=%s  after=%s%s',
                $label,
                json_encode($printers),
                json_encode($now),
                ($printers === $now ? '' : ($moved ? '  <-- CHANGED (!)' : '  (changed — non-critical order type)')),
            ));
        }

        if (! $ok) {
            $this->error('Delivery or Dine-In routing MOVED — not safe. Restore the backup and investigate.');

            return self::FAILURE;
        }

        $this->info($dry
            ? 'DRY RUN ok — Delivery/Dine-In unchanged. Re-run without --dry to apply.'
            : 'Done — Delivery/Dine-In printers unchanged.');

        return self::SUCCESS;
    }

    /**
     * Resolve the KOT printer ids for a food + an overflow (Desserts/Beverages/Extras) category on
     * every order type, on its serving terminal — the numbers we insist must not move for Delivery/
     * Dine-In. Returns ["<order_type> <category>" => sorted printer ids].
     *
     * @return array<string, array<int,int>>
     */
    private function probe(PrintRoutingService $routing, int $branchId): array
    {
        $terminals = \App\Models\Tenant\Terminal::where('branch_id', $branchId)->get()
            ->keyBy(fn ($t) => mb_strtolower(trim($t->name)));

        // one "food" category and one "overflow" category, if present
        $food = Category::where('is_active', true)
            ->whereNotIn('name', ['Desserts', 'Beverages', 'Extras'])
            ->orderBy('id')->first();
        $overflow = Category::whereIn('name', ['Desserts', 'Beverages', 'Extras'])->orderBy('id')->first();

        $out = [];
        foreach (KotTerminalRoutingRewriter::ORDER_TYPE_TERMINAL as $orderType => $terminalName) {
            $terminalId = (int) ($terminals->get(mb_strtolower($terminalName))?->id ?? 0);
            foreach (['food' => $food, 'overflow' => $overflow] as $kind => $category) {
                if (! $category) {
                    continue;
                }
                $out["{$orderType} {$kind}"] = $this->resolve($routing, $branchId, $terminalId, $orderType, $category);
            }
        }

        return $out;
    }

    /** @return array<int,int> */
    private function resolve(PrintRoutingService $routing, int $branchId, int $terminalId, string $orderType, Category $category): array
    {
        $category->setRelation('parent', null);
        $product = new Product(['category_id' => $category->id]);
        $product->id = 900000 + $category->id;
        $product->setRelation('category', $category);

        $line = new SalesOrderLine(['quantity' => 1, 'kot_sent_quantity' => 0]);
        $line->id = 1;
        $line->setRelation('product', $product);

        $sale = new SalesOrder(['branch_id' => $branchId, 'order_type' => $orderType]);
        $sale->terminal_id = $terminalId ?: null;
        $sale->setRelation('lines', new Collection([$line]));

        return collect($routing->kotRoutesForSale($sale))
            ->pluck('printer.id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->sort()->values()->all();
    }
}
