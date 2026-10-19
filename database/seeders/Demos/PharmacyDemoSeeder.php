<?php

namespace Database\Seeders\Demos;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\Customer;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\GoodsReceiptLine;
use App\Models\Tenant\PaymentMethod;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductBarcode;
use App\Models\Tenant\ProductVariant;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\PurchaseBillLine;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\Unit;
use App\Models\Tenant\User;
use App\Services\Inventory\InventoryService;
use App\Services\Purchasing\PurchasingService;
use App\Services\Sales\SalesReturnService;
use App\Services\Sales\SalesService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PHARMACY-DEMO-1 — a Pakistani medical store for the pharmacydemo tenant (pharmacy_demo plan).
 *
 * Built on TODAY's software, so it shows what a pharmacy can already do:
 *   - medicines kept in their smallest unit (tablet / capsule) so a loose sale works:
 *     a strip is quantity 10, a box of Panadol is 200;
 *   - batch + expiry on every medicine, received through real GRNs from distributors,
 *     two batches on the fast movers so the counter sells the older one first (FEFO);
 *   - a few batches close to expiry (never an expired one with stock — the counter would sell it);
 *   - 25 days of counter sales and two returns, all through the same services the POS uses,
 *     so stock, supplier balances, journals and reports agree with each other.
 *
 * Prices are examples close to the 2026 Pakistani market, not anyone's price list. Every
 * person and distributor name is invented. MUST run inside an activated tenant DB
 * (demo:seed activates it). Re-running is safe: each block skips when its rows exist.
 */
class PharmacyDemoSeeder
{
    private InventoryService $inv;
    private PurchasingService $purchasing;
    private SalesService $sales;
    private array $counts = [];
    private ?Branch $branch = null;
    private ?User $owner = null;

    public function run(): array
    {
        $this->inv        = app(InventoryService::class);
        $this->purchasing = app(PurchasingService::class);
        $this->sales      = app(SalesService::class);

        $this->branch = Branch::query()->orderBy('id')->first();
        $this->owner  = User::where('email', 'owner@pharmacydemo.com')->first() ?? User::query()->orderBy('id')->first();

        $this->seedStore();
        $this->seedUnits();
        $this->seedCategories();
        $this->seedSuppliers();
        $this->seedProducts();
        $this->seedCustomers();
        $this->seedPurchases();
        $this->seedSales();
        $this->seedReturns();

        return $this->counts;
    }

    /** [code, name, unit_type] — pharmacy counting units, all whole-number units. */
    private function units(): array
    {
        return [
            ['TAB',  'Tablet'],
            ['CAP',  'Capsule'],
            ['BTL',  'Bottle'],
            ['SACH', 'Sachet'],
            ['TUBE', 'Tube'],
            ['PCS',  'Piece'],
            ['PACK', 'Pack'],
            ['TIN',  'Tin'],
        ];
    }

    private function categories(): array
    {
        return [
            ['PAIN', 'Pain & Fever'],
            ['COLD', 'Cough, Cold & Allergy'],
            ['ANTB', 'Antibiotics (Rx)'],
            ['GAST', 'Stomach & Digestion'],
            ['CHRN', 'Diabetes, BP & Heart'],
            ['VITA', 'Vitamins & Supplements'],
            ['SYRP', 'Syrups & Suspensions'],
            ['SKIN', 'Creams & Ointments'],
            ['SURG', 'Surgical & Devices'],
            ['BABY', 'Baby & Personal Care'],
        ];
    }

    /**
     * [sku, name, salt / what it is, category, unit, cost per unit, price per unit, pack on the shelf,
     *  tracks batch+expiry, distributor, first receipt qty, restock qty, sale weight, sale quantities]
     * Tablets and capsules are priced per tablet: MRP of the box divided by the tablets in it.
     */
    private function products(): array
    {
        return [
            ['PH-PNL500', 'Panadol 500mg Tablet',            'Paracetamol 500mg',                         'PAIN', 'TAB',  4.46,   5.25,  'Box 200 = 20 strips x 10',  true,  'S1', 1000, 600, 10, [2, 4, 10, 10, 20]],
            ['PH-PNLEX',  'Panadol Extra Tablet',            'Paracetamol 500mg + Caffeine 65mg',         'PAIN', 'TAB',  6.04,   7.10,  'Box 100 = 10 strips x 10',  true,  'S1', 500,  0,   5,  [2, 10, 10]],
            ['PH-CAL500', 'Calpol 500mg Tablet',             'Paracetamol 500mg',                         'PAIN', 'TAB',  4.08,   4.80,  'Box 100 = 10 strips x 10',  true,  'S1', 200,  0,   3,  [10, 20]],
            ['PH-BRF400', 'Brufen 400mg Tablet',             'Ibuprofen 400mg',                           'PAIN', 'TAB',  7.65,   9.00,  'Box 300 = 30 strips x 10',  true,  'S1', 300,  0,   4,  [10, 20]],
            ['PH-PONF',   'Ponstan Forte 500mg Tablet',      'Mefenamic acid 500mg',                      'PAIN', 'TAB',  10.20,  12.00, 'Box 200 = 20 strips x 10',  true,  'S1', 200,  0,   3,  [10]],
            ['PH-DISP',   'Disprin 300mg Tablet',            'Aspirin 300mg',                             'PAIN', 'TAB',  2.55,   3.00,  'Box 300 = 30 strips x 10',  true,  'S1', 300,  0,   2,  [10, 20]],
            ['PH-ARIN',   'Arinac Tablet',                   'Ibuprofen 200mg + Pseudoephedrine 30mg',    'COLD', 'TAB',  9.35,   11.00, 'Box 200 = 20 strips x 10',  true,  'S1', 300,  0,   4,  [10]],
            ['PH-RIGX',   'Rigix 10mg Tablet',               'Cetirizine 10mg',                           'COLD', 'TAB',  11.90,  14.00, 'Box 100 = 10 strips x 10',  true,  'S1', 200,  0,   3,  [10]],
            ['PH-SOFT',   'Softin 10mg Tablet',              'Loratadine 10mg',                           'COLD', 'TAB',  13.60,  16.00, 'Box 100 = 10 strips x 10',  true,  'S1', 200,  0,   2,  [10]],
            ['PH-AUG625', 'Augmentin 625mg Tablet',          'Amoxicillin 500mg + Clavulanic acid 125mg', 'ANTB', 'TAB',  80.75,  95.00, 'Box 6 = 1 strip x 6',       true,  'S1', 120,  60,  4,  [6, 12]],
            ['PH-AMX500', 'Amoxil 500mg Capsule',            'Amoxicillin 500mg',                         'ANTB', 'CAP',  18.70,  22.00, 'Box 100 = 10 strips x 10',  true,  'S1', 200,  0,   2,  [10, 20]],
            ['PH-VEL500', 'Velosef 500mg Capsule',           'Cephradine 500mg',                          'ANTB', 'CAP',  38.25,  45.00, 'Box 12 = 2 strips x 6',     true,  'S1', 120,  0,   2,  [12]],
            ['PH-FLG400', 'Flagyl 400mg Tablet',             'Metronidazole 400mg',                       'ANTB', 'TAB',  5.10,   6.00,  'Box 200 = 20 strips x 10',  true,  'S1', 400,  0,   3,  [10, 20]],
            ['PH-AZM500', 'Azomax 500mg Tablet',             'Azithromycin 500mg',                        'ANTB', 'TAB',  102.00, 120.00,'Box 6 = 1 strip x 6',       true,  'S1', 60,   0,   2,  [3, 6]],
            ['PH-NOV500', 'Novidat 500mg Tablet',            'Ciprofloxacin 500mg',                       'ANTB', 'TAB',  32.30,  38.00, 'Box 10 = 1 strip x 10',     true,  'S1', 100,  0,   1,  [10]],
            ['PH-RSK20',  'Risek 20mg Capsule',              'Omeprazole 20mg',                           'GAST', 'CAP',  28.90,  34.00, 'Box 14 = 2 strips x 7',     true,  'S1', 280,  140, 6,  [7, 14, 14]],
            ['PH-MOTL',   'Motilium 10mg Tablet',            'Domperidone 10mg',                          'GAST', 'TAB',  8.08,   9.50,  'Box 100 = 10 strips x 10',  true,  'S1', 200,  0,   2,  [10]],
            ['PH-GRAV',   'Gravinate 50mg Tablet',           'Dimenhydrinate 50mg',                       'GAST', 'TAB',  5.95,   7.00,  'Box 100 = 10 strips x 10',  true,  'S1', 200,  0,   2,  [10]],
            ['PH-BUSC',   'Buscopan 10mg Tablet',            'Hyoscine butylbromide 10mg',                'GAST', 'TAB',  11.05,  13.00, 'Box 100 = 10 strips x 10',  true,  'S1', 100,  0,   1,  [10]],
            ['PH-ORS',    'ORS Sachet (Orange)',             'Oral Rehydration Salts',                    'GAST', 'SACH', 25.50,  30.00, 'Carton 50 sachets',         true,  'S2', 150,  100, 3,  [2, 5]],
            ['PH-GLU500', 'Glucophage 500mg Tablet',         'Metformin 500mg',                           'CHRN', 'TAB',  5.53,   6.50,  'Box 50 = 5 strips x 10',    true,  'S2', 600,  0,   3,  [30, 60]],
            ['PH-GET2',   'Getryl 2mg Tablet',               'Glimepiride 2mg',                           'CHRN', 'TAB',  13.60,  16.00, 'Box 30 = 3 strips x 10',    true,  'S2', 400,  0,   2,  [30]],
            ['PH-CONC5',  'Concor 5mg Tablet',               'Bisoprolol 5mg',                            'CHRN', 'TAB',  17.00,  20.00, 'Box 30 = 3 strips x 10',    true,  'S2', 180,  0,   1,  [30]],
            ['PH-NORV5',  'Norvasc 5mg Tablet',              'Amlodipine 5mg',                            'CHRN', 'TAB',  27.20,  32.00, 'Box 30 = 3 strips x 10',    true,  'S2', 300,  0,   2,  [30]],
            ['PH-LIP10',  'Lipiget 10mg Tablet',             'Atorvastatin 10mg',                         'CHRN', 'TAB',  18.70,  22.00, 'Box 30 = 3 strips x 10',    true,  'S2', 360,  0,   2,  [30]],
            ['PH-SURZ',   'Surbex-Z Tablet',                 'Multivitamin + Zinc',                       'VITA', 'TAB',  12.75,  15.00, 'Bottle 30 tablets',         true,  'S2', 300,  0,   2,  [30]],
            ['PH-NEUR',   'Neurobion Tablet',                'Vitamin B1 + B6 + B12',                     'VITA', 'TAB',  10.20,  12.00, 'Box 30 = 3 strips x 10',    true,  'S2', 300,  0,   2,  [10, 30]],
            ['PH-CAC1K',  'CAC-1000 Plus Effervescent (10)', 'Calcium + Vitamin C',                       'VITA', 'TUBE', 552.50, 650.00,'Tube of 10 tablets',        true,  'S2', 20,   0,   2,  [1]],
            ['PH-CALSY',  'Calpol Syrup 120ml',              'Paracetamol 120mg/5ml',                     'SYRP', 'BTL',  161.50, 190.00,'Bottle',                    true,  'S2', 30,   24,  4,  [1, 1, 2]],
            ['PH-BRFSY',  'Brufen Syrup 90ml',               'Ibuprofen 100mg/5ml',                       'SYRP', 'BTL',  148.75, 175.00,'Bottle',                    true,  'S2', 24,   24,  3,  [1]],
            ['PH-HYDR',   'Hydryllin Syrup 120ml',           'Diphenhydramine + Ammonium chloride',       'SYRP', 'BTL',  136.00, 160.00,'Bottle',                    true,  'S2', 30,   0,   3,  [1]],
            ['PH-MUCN',   'Mucaine Suspension 120ml',        'Antacid + Oxethazaine',                     'SYRP', 'BTL',  272.00, 320.00,'Bottle',                    true,  'S2', 20,   0,   1,  [1]],
            ['PH-VENT',   'Ventolin Syrup 60ml',             'Salbutamol 2mg/5ml',                        'SYRP', 'BTL',  80.75,  95.00, 'Bottle',                    true,  'S2', 20,   0,   1,  [1]],
            ['PH-FLGSY',  'Flagyl Suspension 60ml',          'Metronidazole 200mg/5ml',                   'SYRP', 'BTL',  119.00, 140.00,'Bottle',                    true,  'S2', 20,   0,   1,  [1]],
            ['PH-POLY',   'Polyfax Ointment 20g',            'Polymyxin B + Bacitracin',                  'SKIN', 'TUBE', 280.50, 330.00,'Tube',                      true,  'S2', 15,   0,   1,  [1]],
            ['PH-BETN',   'Betnovate-N Cream 15g',           'Betamethasone + Neomycin',                  'SKIN', 'TUBE', 123.25, 145.00,'Tube',                      true,  'S2', 20,   0,   1,  [1]],
            ['PH-VICK',   'Vicks VapoRub 25ml',              'Menthol + Camphor + Eucalyptus oil',        'SKIN', 'PCS',  221.00, 260.00,'Jar',                       true,  'S3', 20,   0,   1,  [1]],
            ['PH-DETL',   'Dettol Antiseptic Liquid 120ml',  'Chloroxylenol',                             'BABY', 'BTL',  238.00, 280.00,'Bottle',                    true,  'S3', 20,   0,   1,  [1]],
            ['PH-JBL',    'Baby Lotion 100ml',               'Moisturising lotion',                       'BABY', 'BTL',  357.00, 420.00,'Bottle',                    true,  'S3', 12,   0,   1,  [1]],
            ['PH-NAN1',   'Infant Formula Stage 1 (400g)',   'Infant milk 0-6 months',                    'BABY', 'TIN',  3015.00,3350.00,'Tin',                      true,  'S3', 10,   0,   1,  [1]],
            ['PH-SYR5',   'Disposable Syringe 5cc',          'Single-use syringe',                        'SURG', 'PCS',  18.00,  25.00, 'Box 100',                   false, 'S3', 200,  0,   2,  [1, 2, 5]],
            ['PH-GLOV',   'Surgical Gloves (pair)',          'Latex examination gloves',                  'SURG', 'PCS',  30.00,  40.00, 'Box 50 pairs',              false, 'S3', 100,  0,   1,  [1, 2]],
            ['PH-COTN',   'Cotton Roll 100g',                'Absorbent cotton',                          'SURG', 'PCS',  115.00, 150.00,'Roll',                      false, 'S3', 30,   0,   1,  [1]],
            ['PH-CREP',   'Crepe Bandage 4 inch',            'Elastic bandage',                           'SURG', 'PCS',  140.00, 180.00,'Piece',                     false, 'S3', 20,   0,   1,  [1]],
            ['PH-MASK',   'Face Mask (box of 50)',           '3-ply surgical mask',                       'SURG', 'PCS',  360.00, 450.00,'Box',                       false, 'S3', 15,   0,   1,  [1]],
            ['PH-BPM',    'Digital BP Monitor',              'Upper-arm blood pressure monitor',          'SURG', 'PCS',  5200.00,6500.00,'Piece',                    false, 'S3', 3,    0,   0,  [1]],
            ['PH-GSTR',   'Glucometer Test Strips (50)',     'Blood glucose test strips',                 'SURG', 'PACK', 2000.00,2400.00,'Pack of 50 strips',        true,  'S3', 8,    0,   1,  [1]],
        ];
    }

    /** Days until the FIRST receipt's batch expires, where it is not the usual ~18 months. */
    private function shortExpiryDays(): array
    {
        return [
            'PH-CALSY' => 20,   // near expiry: on the 30-day report and the dashboard tile, sold first
            'PH-GSTR'  => 45,
            'PH-ORS'   => 75,
            'PH-BETN'  => 180,
            'PH-AZM500'=> 240,
        ];
    }

    private function seedStore(): void
    {
        if (! $this->branch) {
            return;
        }

        $this->branch->update([
            'name'          => 'Bingoo Demo Pharmacy',
            'business_type' => 'store',
            'address'       => 'Main Boulevard, Lahore (demo address)',
            'phone'         => '042-35000000',
        ]);

        Terminal::updateOrCreate(
            ['code' => 'C1'],
            ['branch_id' => $this->branch->id, 'name' => 'Counter 1', 'requires_shift' => true, 'status' => 'active']
        );

        // A medical store sells over the counter: every login opens on Takeaway, with no
        // Dine In / Quick Sale tabs (Quick Sale would ask for a vehicle and a waiter).
        User::query()->update(['allowed_order_types' => json_encode(['takeaway']), 'default_order_type' => 'takeaway']);

        DB::connection('tenant')->table('receipt_layout_settings')
            ->where('branch_id', $this->branch->id)
            ->where('document_type', 'receipt')
            ->update([
                'header_text'         => 'Bingoo Demo Pharmacy',
                'footer_text'         => 'Medicines are returnable within 7 days with this receipt. Fridge items are not returnable.',
                'show_branch_address' => true,
                'show_branch_phone'   => true,
                'updated_at'          => now(),
            ]);

        $this->counts['store'] = 'branch renamed, Counter 1 (needs a shift), takeaway-only users, receipt text';
    }

    private function seedUnits(): void
    {
        foreach ($this->units() as [$code, $name]) {
            Unit::updateOrCreate(['code' => $code], ['name' => $name, 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => true, 'is_active' => true]);
        }
        $this->counts['units'] = count($this->units());
    }

    private function seedCategories(): void
    {
        foreach ($this->categories() as $i => [$code, $name]) {
            $cat = Category::updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'slug' => Str::slug($name), 'sort_order' => $i + 1, 'is_active' => true]
            );
            $cat->translations()->updateOrCreate(['language_code' => 'en'], ['name' => $name]);
        }
        $this->counts['categories'] = count($this->categories());
    }

    private function seedSuppliers(): void
    {
        $suppliers = [
            ['code' => 'S1', 'name' => 'Al-Noor Pharma Distributors', 'contact_person' => 'Sales Desk', 'phone' => '042-37110001', 'payment_terms_days' => 30],
            ['code' => 'S2', 'name' => 'City Medicine Agency',        'contact_person' => 'Order Desk', 'phone' => '042-37110002', 'payment_terms_days' => 15],
            ['code' => 'S3', 'name' => 'Punjab Surgical House',       'contact_person' => 'Counter',    'phone' => '042-37110003', 'payment_terms_days' => 7],
        ];
        foreach ($suppliers as $s) {
            Supplier::updateOrCreate(
                ['code' => 'PH-' . $s['code']],
                array_merge($s, ['code' => 'PH-' . $s['code'], 'status' => 'active', 'opening_balance' => 0, 'current_balance' => 0])
            );
        }
        $this->counts['suppliers'] = count($suppliers);
    }

    private function seedProducts(): void
    {
        $count = 0;
        $hasMedicineCols = \Illuminate\Support\Facades\Schema::connection('tenant')->hasColumn('products', 'generic_name');
        foreach ($this->products() as $i => [$sku, $brand, $salt, $catCode, $unitCode, $cost, $price, $pack, $tracksBatch]) {
            [$name, $generic, $strength] = $this->medicineParts($sku, $brand, $salt, $catCode, $hasMedicineCols);
            $product = Product::updateOrCreate(
                ['sku' => $sku],
                [
                    'category_id'                   => Category::where('code', $catCode)->value('id'),
                    'unit_id'                       => Unit::where('code', $unitCode)->value('id'),
                    'name'                          => $name,
                    'slug'                          => Str::slug($name),
                    'description'                   => $salt . ' · ' . $pack,
                    'product_type'                  => 'simple',
                    'item_kind'                     => 'finished_good',
                    'inventory_consumption_method'  => 'stock_item',
                    'product_kind'                  => 'sale_item',
                    'is_sellable'                   => true,
                    'is_pos_visible'                => true,
                    'can_be_bom_component'          => false,
                    'can_be_bom_output'             => false,
                    'is_manufactured_finished_good' => false,
                    'is_purchasable'                => true,
                    'is_stock_tracked'              => true,
                    'has_expiry'                    => $tracksBatch,
                    'requires_batch'                => $tracksBatch,
                    'default_purchase_price'        => $cost,
                    'default_selling_price'         => $price,
                    'is_taxable'                    => false,
                    'status'                        => 'active',
                ] + ($hasMedicineCols ? ['generic_name' => $generic, 'strength' => $strength, 'needs_prescription' => $catCode === 'ANTB'] : [])
            );
            $product->translations()->updateOrCreate(['language_code' => 'en'], ['name' => $name]);

            // Reorder at roughly a week's sale; Concor and the BP monitor start close to it.
            $reorder = match ($sku) {
                'PH-CONC5' => 60,
                'PH-BPM'   => 2,
                default    => in_array($unitCode, ['TAB', 'CAP'], true) ? 50 : 5,
            };
            $variant = ProductVariant::updateOrCreate(
                ['sku' => $sku],
                [
                    'product_id'       => $product->id,
                    'name'             => $name,
                    'purchase_price'   => $cost,
                    'selling_price'    => $price,
                    'reorder_level'    => $reorder,
                    'reorder_quantity' => $reorder * 2,
                    'is_default'       => true,
                    'is_active'        => true,
                ]
            );

            ProductBarcode::updateOrCreate(
                ['barcode' => $this->ean13('2009100' . str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT))],
                ['product_id' => $product->id, 'product_variant_id' => $variant->id, 'barcode_type' => 'system', 'is_primary' => true]
            );
            $count++;
        }
        $this->counts['products'] = $count . ' (each with a scannable EAN-13 barcode)';
    }

    /**
     * A medicine is found by its salt as often as by its brand ("paracetamol" for Panadol or Calpol).
     * With the medicine fields (PRODUCT-SCREEN-V2) the salt and strength have their own columns and the
     * counter searches them; the name stays the brand. Without them (older schema) the salt goes into the
     * name so the demo can still be searched by it.
     *
     * @return array{0:string,1:?string,2:?string}  name, generic, strength
     */
    private function medicineParts(string $sku, string $brand, string $salt, string $catCode, bool $hasColumns): array
    {
        if (in_array($catCode, ['SURG', 'BABY'], true) || in_array($sku, ['PH-ORS', 'PH-VICK'], true)) {
            return [$brand, null, null];
        }
        $generic = trim(preg_replace('/\s*\d[\d.,]*\s*(mg|mcg|ml|g)(\/\s*\d+\s*ml)?/i', '', $salt));
        preg_match_all('/\d[\d.,]*\s*(?:mg|mcg|ml|g)(?:\/\s*\d+\s*ml)?/i', $salt, $m);
        $strength = $m[0] ? implode(' + ', $m[0]) : null;

        if ($hasColumns) {
            return [$brand, $generic !== '' ? $generic : null, $strength];
        }

        return [$generic !== '' ? "{$brand} ({$generic})" : $brand, null, null];
    }

    private function seedCustomers(): void
    {
        $customers = [
            ['code' => 'PH-C001', 'name' => 'Abdul Rasheed (diabetes, monthly)', 'phone' => '0300-1234501'],
            ['code' => 'PH-C002', 'name' => 'Nasreen Akhtar (BP, monthly)',      'phone' => '0301-1234502'],
            ['code' => 'PH-C003', 'name' => 'Kamran Yousaf',                     'phone' => '0302-1234503'],
            ['code' => 'PH-C004', 'name' => 'Ayesha Siddiqui (baby care)',       'phone' => '0303-1234504'],
        ];
        foreach ($customers as $c) {
            Customer::updateOrCreate(['code' => $c['code']], array_merge($c, ['status' => 'active']));
        }
        $this->counts['customers'] = count($customers);
    }

    /**
     * Two rounds from the three distributors: the first stocks every shelf 28 days ago, the second
     * restocks the fast movers 8 days ago with a later expiry — so those items carry two batches.
     * Each GRN gets its purchase bill; the bills stay unpaid, so the distributor balances show.
     */
    private function seedPurchases(): void
    {
        if (! $this->branch || ! $this->owner) {
            $this->counts['purchases'] = 'skipped (no branch/user)';
            return;
        }
        if (GoodsReceipt::count() > 0) {
            $this->counts['purchases'] = 'already exist';
            return;
        }

        $short = $this->shortExpiryDays();
        $rounds = [
            ['days_ago' => 28, 'suffix' => 'A', 'qty_index' => 10],
            ['days_ago' => 8,  'suffix' => 'B', 'qty_index' => 11],
        ];
        $grns = 0;
        $total = 0.0;

        foreach ($rounds as $round) {
            $date = now()->subDays($round['days_ago'])->setTime(11, 30);
            foreach (['S1', 'S2', 'S3'] as $supplierCode) {
                $lines = [];
                foreach ($this->products() as $row) {
                    [$sku, , , , , $cost, , , $tracksBatch, $supplier] = $row;
                    $qty = (int) $row[$round['qty_index']];
                    if ($supplier !== $supplierCode || $qty <= 0) {
                        continue;
                    }
                    $expiry = null;
                    if ($tracksBatch) {
                        $expiry = $round['suffix'] === 'A' && isset($short[$sku])
                            ? now()->addDays($short[$sku])
                            : now()->addMonths($round['suffix'] === 'A' ? 18 : 24)->endOfMonth();
                    }
                    $lines[] = [
                        'sku'    => $sku,
                        'qty'    => $qty,
                        'cost'   => $cost,
                        'batch'  => $tracksBatch ? strtoupper(substr(str_replace('PH-', '', $sku), 0, 3)) . $date->format('ym') . $round['suffix'] : null,
                        'expiry' => $expiry?->toDateString(),
                    ];
                }
                if ($lines === []) {
                    continue;
                }
                $total += $this->receiveAndBill(Supplier::where('code', 'PH-' . $supplierCode)->first(), $lines, $date);
                $grns++;
            }
        }

        $this->counts['purchases'] = "{$grns} GRNs with batch + expiry, {$grns} unpaid bills, Rs " . number_format($total, 2);
    }

    private function receiveAndBill(Supplier $supplier, array $lines, Carbon $date): float
    {
        return DB::connection('tenant')->transaction(function () use ($supplier, $lines, $date) {
            $grn = GoodsReceipt::create([
                'grn_no'            => $this->purchasing->nextGrnNo(),
                'branch_id'         => $this->branch->id,
                'supplier_id'       => $supplier->id,
                'receipt_date'      => $date->toDateString(),
                'status'            => 'posted',
                'notes'             => 'Distributor delivery (demo)',
                'extra_charges'     => 0,
                'posted_by_user_id' => $this->owner->id,
                'posted_at'         => $date,
            ]);

            foreach ($lines as $line) {
                $product = Product::where('sku', $line['sku'])->first();
                GoodsReceiptLine::create([
                    'goods_receipt_id'   => $grn->id,
                    'product_id'         => $product->id,
                    'product_variant_id' => $this->inv->resolveVariant($product, null)?->id,
                    'batch_no'           => $line['batch'],
                    'expiry_date'        => $line['expiry'],
                    'quantity_received'  => $line['qty'],
                    'unit_cost'          => $line['cost'],
                    'discount_amount'    => 0,
                    'tax_amount'         => 0,
                    'affects_stock'      => true,
                ]);
            }

            $grn->load('lines.product', 'lines.variant', 'branch');
            $this->purchasing->postGrn($grn, $this->owner->id);

            $subtotal = round($grn->lines->sum(fn ($l) => (float) $l->quantity_received * (float) $l->unit_cost), 2);
            $bill = PurchaseBill::create([
                'bill_no'             => $this->purchasing->nextBillNo(),
                'supplier_invoice_no' => 'INV-' . $date->format('md') . '-' . substr($supplier->code, -2),
                'supplier_id'         => $supplier->id,
                'branch_id'           => $this->branch->id,
                'goods_receipt_id'    => $grn->id,
                'bill_date'           => $date->toDateString(),
                'due_date'            => $date->copy()->addDays((int) $supplier->payment_terms_days)->toDateString(),
                'status'              => 'posted',
                'subtotal'            => $subtotal,
                'extra_charges'       => 0,
                'discount_total'      => 0,
                'tax_total'           => 0,
                'grand_total'         => $subtotal,
                'amount_paid'         => 0,
                'balance_due'         => $subtotal,
                'posted_by_user_id'   => $this->owner->id,
                'posted_at'           => $date,
            ]);
            foreach ($grn->lines as $line) {
                PurchaseBillLine::create([
                    'purchase_bill_id'   => $bill->id,
                    'product_id'         => $line->product_id,
                    'product_variant_id' => $line->product_variant_id,
                    'quantity'           => $line->quantity_received,
                    'unit_cost'          => $line->unit_cost,
                    'discount_amount'    => 0,
                    'tax_amount'         => 0,
                    'line_total'         => round((float) $line->quantity_received * (float) $line->unit_cost, 2),
                ]);
            }
            $bill->load('supplier');
            $this->purchasing->postBill($bill, $this->owner->id);

            return $subtotal;
        });
    }

    /**
     * 25 days of counter sales (5–8 a day) picked by how often each item really sells, plus the
     * monthly patients. Seeded with a fixed number so every rebuild gives the same history.
     */
    private function seedSales(): void
    {
        if (SalesOrder::count() > 0) {
            $this->counts['sales'] = 'already exist';
            return;
        }

        $cash = PaymentMethod::where('method_type', 'cash')->first();
        $card = PaymentMethod::where('method_type', 'card')->first();
        if (! $this->branch || ! $this->owner || ! $cash) {
            $this->counts['sales'] = 'skipped (missing branch/user/cash)';
            return;
        }

        $made = 0;
        $skipped = [];
        $amount = 0.0;
        foreach ($this->plannedSales() as $i => $p) {
            try {
                $amount += $this->createSale($p, $i + 1, $p['card'] && $card ? $card : $cash);
                $made++;
            } catch (\Throwable $e) {
                $skipped[] = Str::limit($e->getMessage(), 90);   // a sale the shelf could not cover; the rest go on
            }
        }
        $this->counts['sales'] = "{$made} counter sales over 25 days, Rs " . number_format($amount, 2)
            . ($skipped ? ' (' . count($skipped) . ' skipped: ' . implode(' | ', array_unique($skipped)) . ')' : '');
    }

    /** The sales to make, oldest first: [at (Karachi time), lines [[sku, qty]], card, customer code]. */
    public function plannedSales(): array
    {
        mt_srand(20261018);
        $pool = [];
        foreach ($this->products() as $row) {
            for ($w = 0; $w < $row[12]; $w++) {
                $pool[] = $row;
            }
        }

        $planned = [];
        for ($daysAgo = 24; $daysAgo >= 0; $daysAgo--) {
            $perDay = 5 + ($daysAgo % 4);
            for ($n = 0; $n < $perDay; $n++) {
                $picked = [];
                $lineCount = mt_rand(1, 3);
                while (count($picked) < $lineCount) {
                    $row = $pool[mt_rand(0, count($pool) - 1)];
                    $picked[$row[0]] = [$row[0], $row[13][mt_rand(0, count($row[13]) - 1)]];
                }
                $planned[] = [
                    'at'    => $this->shopTime($daysAgo, 10 + intdiv($n * 12, $perDay), mt_rand(0, 59), $perDay - $n),
                    'lines' => array_values($picked),
                    'card'  => mt_rand(1, 100) <= 15,
                    'cust'  => null,
                ];
            }
        }

        // The regulars: monthly medicines, and a baby's formula.
        foreach ([
            [20, 'PH-C001', [['PH-GLU500', 60], ['PH-GET2', 30]]],
            [3,  'PH-C001', [['PH-GLU500', 60], ['PH-GET2', 30]]],
            [18, 'PH-C002', [['PH-CONC5', 30], ['PH-NORV5', 30]]],
            [12, 'PH-C004', [['PH-NAN1', 1], ['PH-CALSY', 1]]],
            [2,  'PH-C004', [['PH-NAN1', 1], ['PH-JBL', 1]]],
            [9,  'PH-C003', [['PH-BPM', 1], ['PH-GSTR', 1]]],
        ] as [$daysAgo, $cust, $lines]) {
            $planned[] = ['at' => $this->shopTime($daysAgo, 18, 15, 1), 'lines' => $lines, 'card' => $daysAgo === 9, 'cust' => $cust];
        }

        usort($planned, fn ($a, $b) => $a['at'] <=> $b['at']);

        return $planned;
    }

    /**
     * A time on the shop's own clock (Karachi), never later than now: today's sales stop a few
     * minutes before the seed runs. Stored in UTC like every POS sale; the business day is Karachi's.
     */
    private function shopTime(int $daysAgo, int $hour, int $minute, int $minutesBack): Carbon
    {
        $local = now('Asia/Karachi')->subDays($daysAgo)->setTime($hour, $minute);
        $latest = now('Asia/Karachi')->subMinutes(10 * $minutesBack);
        if ($local->greaterThan($latest)) {
            $local = $latest;
        }

        return $local;
    }

    private function createSale(array $p, int $seq, PaymentMethod $method): float
    {
        $local = $p['at'];
        $at = $local->copy()->utc();
        $rows = collect($this->products())->keyBy(0);
        $subtotal = 0.0;
        foreach ($p['lines'] as [$sku, $qty]) {
            $subtotal += round($qty * $rows[$sku][6], 2);
        }
        $subtotal = round($subtotal, 2);

        $sale = SalesOrder::create([
            'sale_no'            => 'SO-' . $local->format('Ymd') . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
            'branch_id'          => $this->branch->id,
            'customer_id'        => $p['cust'] ? Customer::where('code', $p['cust'])->value('id') : null,
            'order_source'       => 'pos',
            'order_type'         => 'takeaway',
            'sale_date'          => $at,
            'business_date'      => $local->toDateString(),
            'subtotal'           => $subtotal,
            'discount_type'      => 'none',
            'discount_value'     => 0,
            'discount_amount'    => 0,
            'tax_amount'         => 0,
            'grand_total'        => $subtotal,
            'status'             => 'draft',
            'created_by_user_id' => $this->owner->id,
        ]);

        foreach ($p['lines'] as [$sku, $qty]) {
            $row = $rows[$sku];
            $product = Product::where('sku', $sku)->first();
            $variant = $this->inv->resolveVariant($product, null);
            $sale->lines()->create([
                'product_id'         => $product->id,
                'product_variant_id' => $variant?->id,
                'product_name'       => $product->name,
                'variant_name'       => $variant?->name,
                'unit_code'          => $row[4],
                'quantity'           => $qty,
                'unit_price'         => $row[6],
                'unit_cost'          => 0,
                'cost_total'         => 0,
                'discount_amount'    => 0,
                'tax_amount'         => 0,
                'line_total'         => round($qty * $row[6], 2),
            ]);
        }

        $isCash = $method->method_type === 'cash';
        $sale->payments()->create([
            'payment_method_id' => $method->id,
            'amount'            => $subtotal,
            'tendered_amount'   => $isCash ? (float) (ceil($subtotal / 100) * 100) : null,
            'change_amount'     => $isCash ? round(ceil($subtotal / 100) * 100 - $subtotal, 2) : 0,
            'transaction_ref'   => $isCash ? null : 'CARD-' . str_pad((string) $seq, 6, '0', STR_PAD_LEFT),
        ]);

        $this->sales->finalizePaidSale($sale);

        return $subtotal;
    }

    /** Two everyday returns: an unopened syrup bottle, and tablets after a prescription change. */
    private function seedReturns(): void
    {
        if (SalesReturn::count() > 0) {
            $this->counts['returns'] = 'already exist';
            return;
        }

        $made = [];
        foreach ([
            ['PH-CALSY', 1,  6, 'Unopened bottle, customer bought a second one by mistake'],
            ['PH-PNL500', 10, 2, 'Doctor changed the prescription'],
        ] as [$sku, $qty, $minDaysAgo, $reason]) {
            $productId = Product::where('sku', $sku)->value('id');
            $sale = SalesOrder::where('status', 'paid')
                ->where('sale_date', '<=', now()->subDays($minDaysAgo))
                ->whereHas('lines', fn ($q) => $q->where('product_id', $productId)->where('quantity', '>=', $qty))
                ->orderByDesc('sale_date')
                ->first();
            if (! $sale) {
                continue;
            }
            $line = $sale->lines()->where('product_id', $productId)->first();
            try {
                app(SalesReturnService::class)->processReturn(
                    $sale,
                    [['sales_order_line_id' => $line->id, 'quantity' => $qty]],
                    $reason,
                    'cash',
                    round($qty * (float) $line->unit_price, 2),
                    $this->owner->id,
                );
                $made[] = $sale->sale_no;
            } catch (\Throwable $e) {
                // keep seeding; reported below
            }
        }
        $this->counts['returns'] = count($made) . ' (' . implode(', ', $made) . ')';
    }

    private function ean13(string $twelve): string
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $twelve[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return $twelve . ((10 - $sum % 10) % 10);
    }
}
