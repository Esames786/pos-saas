<?php

namespace Tests\Feature\Printing;

use App\Models\Tenant\Category;
use App\Models\Tenant\CategoryPrinterMapping;
use App\Models\Tenant\Printer;
use App\Models\Tenant\Product;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesOrderLine;
use App\Services\Printing\PrintRoutingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PrintRoutingFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for the isolated routing database.');
        }

        config()->set('database.connections.tenant', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('tenant');

        Schema::connection('tenant')->create('printers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('printer_type')->default('network');
            $table->string('print_role')->default('kot');
            $table->boolean('supports_reminder')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::connection('tenant')->create('category_printer_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('printer_id');
            $table->string('print_role')->default('kot');
            $table->string('order_type')->default('all');
            $table->boolean('reminder_confirm_on_addition')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::connection('tenant')->create('terminal_printer_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('terminal_id')->unique();
            $table->unsignedBigInteger('kot_printer_id')->nullable();
            $table->unsignedBigInteger('receipt_printer_id')->nullable();
            $table->timestamps();
        });
        // MYSQL-TEST-FOUNDATION-1: reminderRoutesForSale loadMissing('lines.product.category')
        // resolves the category relation, so the mini-schema must expose `categories`
        // (routing itself only uses product.category_id). Without this table the SQLite
        // isolated tests errored "no such table: categories". The AUTHORITATIVE coverage of
        // this routing on the real schema is tests/MySql/PrintRoutingMySqlTest.
        Schema::connection('tenant')->create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->timestamps();
        });
    }

    public function test_reminder_routes_are_order_aware_deduplicated_and_ask_wins(): void
    {
        $printer = $this->printer('reminder');
        $printer->update(['supports_reminder' => true]);
        CategoryPrinterMapping::create([
            'branch_id' => 10, 'category_id' => 20, 'printer_id' => $printer->id,
            'print_role' => 'reminder', 'order_type' => 'dine_in',
            'reminder_confirm_on_addition' => false, 'is_active' => true,
        ]);
        CategoryPrinterMapping::create([
            'branch_id' => null, 'category_id' => 20, 'printer_id' => $printer->id,
            'print_role' => 'reminder', 'order_type' => 'dine_in',
            'reminder_confirm_on_addition' => true, 'is_active' => true,
        ]);

        $routes = app(PrintRoutingService::class)->reminderRoutesForSale($this->sale('dine_in'));

        $this->assertCount(1, $routes);
        $this->assertSame($printer->id, $routes[0]['printer']->id);
        $this->assertTrue($routes[0]['ask_on_addition']);
        $this->assertSame([], app(PrintRoutingService::class)->reminderRoutesForSale($this->sale('delivery')));
    }

    public function test_same_category_routes_by_each_order_type_and_legacy_all(): void
    {
        $printers = collect(['dine_in', 'takeaway', 'quick_sale', 'delivery'])
            ->mapWithKeys(fn ($orderType) => [$orderType => $this->printer($orderType)]);

        foreach ($printers as $orderType => $printer) {
            $this->mapping($printer, $orderType);
        }

        foreach ($printers as $orderType => $printer) {
            $routes = app(PrintRoutingService::class)->kotRoutesForSale($this->sale($orderType));

            $this->assertSame([$printer->id], collect($routes)->pluck('printer.id')->all());
        }

        CategoryPrinterMapping::query()->delete();
        $legacy = $this->printer('legacy');
        $this->mapping($legacy, 'all');

        foreach ($printers->keys() as $orderType) {
            $routes = app(PrintRoutingService::class)->kotRoutesForSale($this->sale($orderType));
            $this->assertSame([$legacy->id], collect($routes)->pluck('printer.id')->all());
        }
    }

    public function test_same_category_and_order_type_routes_once_to_each_physical_printer(): void
    {
        $printerA = $this->printer('printer-a');
        $printerB = $this->printer('printer-b');
        $this->mapping($printerA, 'dine_in');
        $this->mapping($printerB, 'dine_in');

        $routes = app(PrintRoutingService::class)->kotRoutesForSale($this->sale('dine_in'));
        $printerIds = collect($routes)->pluck('printer.id')->sort()->values()->all();

        $this->assertSame([$printerA->id, $printerB->id], $printerIds);
        $this->assertCount(2, $routes);
        $this->assertSame([1], collect($routes)->pluck('line_ids.0')->unique()->values()->all());
    }

    public function test_browser_fallback_keeps_each_category_on_its_own_ticket(): void
    {
        $food = new Category(['name' => 'Food']);
        $food->id = 20;
        $drinks = new Category(['name' => 'Drinks']);
        $drinks->id = 21;

        $lines = collect([[$food, 1], [$drinks, 2]])->map(function ($entry) {
            [$category, $lineId] = $entry;
            $product = new Product(['category_id' => $category->id]);
            $product->id = 30 + $lineId;
            $product->setRelation('category', $category);
            $line = new SalesOrderLine(['quantity' => 1, 'kot_sent_quantity' => 0]);
            $line->id = $lineId;
            $line->setRelation('product', $product);

            return $line;
        });

        $sale = new SalesOrder(['branch_id' => 10, 'order_type' => 'dine_in']);
        $sale->setRelation('lines', $lines);
        $routes = app(PrintRoutingService::class)->kotRoutesForSale($sale);

        $this->assertCount(2, $routes);
        $this->assertSame(['Drinks', 'Food'], collect($routes)->pluck('category_name')->sort()->values()->all());
        $this->assertSame([[1], [2]], collect($routes)->pluck('line_ids')->sortBy(fn ($ids) => $ids[0])->values()->all());
    }

    private function printer(string $suffix): Printer
    {
        return Printer::create([
            'name' => 'Printer ' . $suffix,
            'code' => strtoupper(str_replace('_', '-', $suffix)),
            'printer_type' => 'network',
            'print_role' => 'kot',
            'is_active' => true,
        ]);
    }

    private function mapping(Printer $printer, string $orderType): void
    {
        CategoryPrinterMapping::create([
            'branch_id' => 10,
            'category_id' => 20,
            'printer_id' => $printer->id,
            'print_role' => 'kot',
            'order_type' => $orderType,
            'is_active' => true,
        ]);
    }

    private function sale(string $orderType): SalesOrder
    {
        $product = new Product(['category_id' => 20]);
        $product->id = 30;

        $line = new SalesOrderLine([
            'quantity' => 2,
            'kot_sent_quantity' => 1,
        ]);
        $line->id = 1;
        $line->setRelation('product', $product);

        $sale = new SalesOrder([
            'branch_id' => 10,
            'order_type' => $orderType,
        ]);
        $sale->setRelation('lines', new Collection([$line]));

        return $sale;
    }
}
