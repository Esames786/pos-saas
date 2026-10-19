<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Master\Module;
use App\Services\Central\ModuleCatalogService;
use Illuminate\Http\Request;

class ModuleController extends Controller
{
    public function index(ModuleCatalogService $catalog)
    {
        // MASTER-UNIVERSE-1: big boxes grouped by category, each with its plain description, how many
        // plans / tenants use it and "N screens · M permissions" from the route catalogue.
        $groups = $catalog->all()->groupBy(fn ($row) => $row['module']->category ?: 'Other');

        return view('central.modules.index', [
            'groups' => $groups,
            'moduleSummaries' => $this->moduleSummaries(),
        ]);
    }

    public function edit(Module $module, ModuleCatalogService $catalog)
    {
        $moduleSummaries = $this->moduleSummaries();
        $stats = $catalog->all()->first(fn ($row) => $row['module']->id === $module->id);

        return view('central.modules.edit', compact('module', 'moduleSummaries', 'stats'));
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
        return ModuleCatalogService::SUMMARIES;
    }
}
