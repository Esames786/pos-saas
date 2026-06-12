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

        return view('central.modules.index', compact('modules'));
    }

    public function edit(Module $module)
    {
        return view('central.modules.edit', compact('module'));
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
}
