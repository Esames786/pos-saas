<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Master\Module;
use Illuminate\Http\Request;

class ModuleController extends Controller
{
    public function index()
    {
        $modules = Module::orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->groupBy('category');

        $moduleSummaries = $this->moduleSummaries();

        return view('central.modules.index', compact('modules', 'moduleSummaries'));
    }

    public function edit(Module $module)
    {
        $moduleSummaries = $this->moduleSummaries();

        return view('central.modules.edit', compact('module', 'moduleSummaries'));
    }

    public function update(Request $request, Module $module)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string'],
            'route_module_keys_text' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_core' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $routeKeys = collect(preg_split('/[\r\n,]+/', (string) ($data['route_module_keys_text'] ?? '')))
            ->map(fn ($key) => trim($key))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $module->update([
            'name' => $data['name'],
            'category' => $data['category'] ?? null,
            'description' => $data['description'] ?? null,
            'route_module_keys' => $routeKeys,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_core' => $request->boolean('is_core'),
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect('/modules')->with('status', 'Module updated successfully.');
    }

    private function moduleSummaries(): array
    {
        return [
            'pos' => [
                'description' => 'Checkout, sales orders, payments, returns, customers, and POS screen.',
                'sees' => 'POS checkout, sales orders, held sales, returns, customers, payment methods, and sales ledger.',
            ],
            'catalog' => [
                'description' => 'Products, categories, units, modifiers, barcodes, and branch pricing.',
                'sees' => 'Product catalog, units, categories, modifiers, and barcode tools.',
            ],
            'inventory' => [
                'description' => 'Stock on hand, adjustments, transfers, and inventory movement control.',
                'sees' => 'Inventory dashboard, stock adjustments, and stock transfers.',
            ],
            'stock_count' => [
                'description' => 'Physical stock count sessions and variance review.',
                'sees' => 'Stock count sessions and count review screens.',
            ],
            'purchasing' => [
                'description' => 'Suppliers, purchase orders, receipts, bills, and supplier payments.',
                'sees' => 'Purchasing documents, suppliers, receipts, and payables.',
            ],
            'restaurant' => [
                'description' => 'Floors, tables, waiters, dine-in sessions, and restaurant operations.',
                'sees' => 'Restaurant floors, tables, waiters, and table sessions.',
            ],
            'kitchen_display' => [
                'description' => 'Live kitchen preparation queues.',
                'sees' => 'Kitchen display and order preparation screens.',
            ],
            'kitchen_inventory' => [
                'description' => 'Kitchen recipes, ingredient usage, and consumption reports.',
                'sees' => 'Kitchen recipes, ingredients, production, and wastage tools.',
            ],
            'printing' => [
                'description' => 'Print agents, KOT, receipts, and print job monitoring.',
                'sees' => 'Printing setup, print agents, and print job status.',
            ],
            'reports' => [
                'description' => 'Operational dashboards and report menus.',
                'sees' => 'Analytics dashboards and report menus.',
            ],
            'sales_controls' => [
                'description' => 'Promotions, combos/deals, void reasons, and service charges.',
                'sees' => 'Promotions, deals, void controls, and service-charge setup.',
            ],
            'multi_branch' => [
                'description' => 'Branches, terminals, shifts, daily closings, and currencies.',
                'sees' => 'Branch, terminal, shift, daily closing, and currency setup.',
            ],
            'users_roles' => [
                'description' => 'Tenant users, roles, and permission administration.',
                'sees' => 'User, role, and permission administration.',
            ],
            'finance' => [
                'description' => 'Chart of accounts, cash/bank, expenses, journals, and financial statements.',
                'sees' => 'Finance ledgers, cash/bank, journals, expenses, and statements.',
            ],
            'manufacturing' => [
                'description' => 'BOM, production orders, WIP, finished goods, consumption, and reports.',
                'sees' => 'BOM, production orders, WIP, receipts, requisitions, and manufacturing reports.',
            ],
        ];
    }
}
