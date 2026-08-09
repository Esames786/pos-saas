<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Ajax\CustomerLookupController;
use App\Http\Controllers\Tenant\CustomerController;
use App\Http\Controllers\Tenant\POSController;
use App\Models\Tenant\Customer;
use App\Services\Sales\SalesTotalsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * CUSTOMER-UX-1 — POS customer search endpoint (name/phone), the address book
 * (first address = default, explicit default flips), and the branch delivery-charge
 * LOCK (a locked branch quotes its configured default, never the client's number).
 */
class CustomerUxMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['customer_addresses', 'customers', 'products', 'categories', 'branches', 'users']);
    }

    public function test_lookup_searches_by_name_or_phone_and_carries_the_address_book(): void
    {
        $ali = Customer::create(['name' => 'Ali Raza', 'phone' => '0300-1112233', 'status' => 'active']);
        Customer::create(['name' => 'Bilal Khan', 'phone' => '0345-9998877', 'status' => 'active']);
        Customer::create(['name' => 'Inactive Person', 'phone' => '0300-0000000', 'status' => 'inactive']);
        $ali->addresses()->create(['label' => 'Home', 'address' => 'House 12, Street 4', 'is_default' => true]);

        $byName = app(CustomerLookupController::class)(Request::create('/ajax/customers', 'GET', ['q' => 'ali']))->getData(true);
        $this->assertCount(1, $byName['customers']);
        $this->assertSame('Ali Raza', $byName['customers'][0]['name']);
        $this->assertSame('House 12, Street 4', $byName['customers'][0]['addresses'][0]['address']);
        $this->assertTrue($byName['customers'][0]['addresses'][0]['is_default']);

        $byPhone = app(CustomerLookupController::class)(Request::create('/ajax/customers', 'GET', ['q' => '9998877']))->getData(true);
        $this->assertCount(1, $byPhone['customers']);
        $this->assertSame('Bilal Khan', $byPhone['customers'][0]['name']);
    }

    public function test_address_book_defaults_first_address_and_flips_on_explicit_default(): void
    {
        $customer = Customer::create(['name' => 'Address Tester', 'status' => 'active']);

        $first = app(CustomerController::class)->storeAddress(
            Request::create('/', 'POST', ['address' => 'First Street']), $customer
        )->getData(true);
        $this->assertTrue((bool) $first['address']['is_default'], 'the first address becomes the default automatically');

        $second = app(CustomerController::class)->storeAddress(
            Request::create('/', 'POST', ['address' => 'Second Avenue', 'label' => 'Office']), $customer
        )->getData(true);
        $this->assertFalse((bool) $second['address']['is_default'], 'later addresses are not default unless asked');

        app(CustomerController::class)->storeAddress(
            Request::create('/', 'POST', ['address' => 'Third Boulevard', 'is_default' => 1]), $customer
        );
        $this->assertSame(
            ['Third Boulevard'],
            $customer->addresses()->where('is_default', 1)->pluck('address')->all(),
            'an explicit default unsets every other default'
        );
    }

    public function test_locked_branch_quotes_its_default_delivery_charge_never_client_input(): void
    {
        $user = \App\Models\Tenant\User::on('tenant')->find($this->makeUser(['employee_code' => 'CU' . \Illuminate\Support\Str::random(4)]));
        $this->actingAs($user, 'tenant');
        \Illuminate\Support\Facades\Auth::shouldUse('tenant');

        $lockedBranch = $this->makeBranch(['default_delivery_charge' => 150, 'delivery_charge_locked' => 1]);
        $freeBranch = $this->makeBranch(['code' => 'BR-FREE', 'default_delivery_charge' => 90, 'delivery_charge_locked' => 0]);
        $productId = $this->makeProduct($this->makeCategory());

        $quote = function (int $branchId, $clientCharge) use ($productId) {
            $request = Request::create('/api/pos/totals/quote', 'POST', [
                'branch_id' => $branchId, 'order_type' => 'delivery', 'discount_type' => 'none',
                'delivery_charge_amount' => $clientCharge,
                'lines' => [['product_id' => $productId, 'category_id' => 0, 'quantity' => 1, 'unit_price' => 500]],
            ]);

            return app(POSController::class)->quoteTotals($request, app(SalesTotalsService::class))->getData(true);
        };

        $locked = $quote($lockedBranch, 5);
        $this->assertSame(150.0, (float) $locked['delivery_charge_amount'], 'locked branch: client value ignored, default applied');
        $this->assertSame(650.0, (float) $locked['grand_total']);

        $free = $quote($freeBranch, 40);
        $this->assertSame(40.0, (float) $free['delivery_charge_amount'], 'unlocked branch: cashier value honoured');
    }
}
