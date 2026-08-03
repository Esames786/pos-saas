<?php

namespace Tests\Feature\Printing;

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
