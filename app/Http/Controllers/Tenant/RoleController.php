<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreRoleRequest;
use App\Http\Requests\Tenant\UpdateRolePermissionsRequest;
use App\Http\Requests\Tenant\UpdateRoleRequest;
use App\Services\Permissions\PermissionSyncService;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(Request $request)
    {
        $query = Role::query()
            ->where('guard_name', 'tenant')
            ->withCount('permissions')
            ->latest();

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . trim($request->search) . '%');
        }

        $roles = $query->paginate(15)->withQueryString();

        return view('tenant.roles.index', compact('roles'));
    }

    public function create()
    {
        return view('tenant.roles.create');
    }

    public function store(StoreRoleRequest $request)
    {
        Role::create([
            'name'       => $request->name,
            'guard_name' => 'tenant',
        ]);

        return redirect('/roles')->with('status', 'Role created successfully.');
    }

    public function edit(Role $role)
    {
        abort_unless($role->guard_name === 'tenant', 404);

        return view('tenant.roles.edit', compact('role'));
    }

    public function update(UpdateRoleRequest $request, Role $role)
    {
        abort_unless($role->guard_name === 'tenant', 404);

        if ($role->name === 'Owner' && $request->name !== 'Owner') {
            return back()->withErrors(['name' => 'Owner role name cannot be changed.']);
        }

        $role->update(['name' => $request->name]);

        return redirect('/roles')->with('status', 'Role updated successfully.');
    }

    public function destroy(Role $role)
    {
        abort_unless($role->guard_name === 'tenant', 404);

        if ($role->name === 'Owner') {
            return back()->withErrors(['role' => 'Owner role cannot be deleted.']);
        }

        $role->delete();

        return back()->with('status', 'Role deleted successfully.');
    }

    public function editPermissions(Role $role, \App\Services\Permissions\PermissionCatalogService $catalog)
    {
        abort_unless($role->guard_name === 'tenant', 404);

        $role->load('permissions');

        // Entitlement-aware editor: only the tenant's ENABLED modules are rendered/managed. Grants
        // outside the managed set (non-entitled modules, unmapped keys) are PRESERVED untouched by
        // updatePermissions — the editor can neither wipe nor grant what it does not display.
        $entitled = null;
        if (app()->bound('tenant') && app('tenant')?->subscription?->plan) {
            $entitled = app('tenant')->subscription->plan->enabledModules()->pluck('key')->all();
        }
        $built = $catalog->build(
            \Spatie\Permission\Models\Permission::where('guard_name', 'tenant')->get(),
            $entitled
        );

        return view('tenant.roles.permissions', [
            'role'                => $role,
            'catalog'             => $built['modules'],
            'managed'             => $built['managed'],
            'unavailableModules'  => $built['unavailable_modules'],
            'assignedPermissions' => $role->permissions->pluck('name')->toArray(),
        ]);
    }

    public function updatePermissions(UpdateRolePermissionsRequest $request, Role $role, \App\Services\Permissions\PermissionCatalogService $catalog)
    {
        abort_unless($role->guard_name === 'tenant', 404);

        // Rebuild the SERVER-side managed set (never trust a posted list of managed names): only
        // permissions the entitled editor actually displays may be granted or revoked here.
        $entitled = null;
        if (app()->bound('tenant') && app('tenant')?->subscription?->plan) {
            $entitled = app('tenant')->subscription->plan->enabledModules()->pluck('key')->all();
        }
        $managed = $catalog->build(
            \Spatie\Permission\Models\Permission::where('guard_name', 'tenant')->get(),
            $entitled
        )['managed'];

        $submitted = array_values(array_intersect($request->input('permissions', []), $managed));
        // preserve every grant OUTSIDE the managed scope (non-entitled modules, unmapped keys) —
        // the friendly editor is a management UX, never a stealth revoker (safety test F).
        $preserved = $role->permissions->pluck('name')->reject(fn ($n) => in_array($n, $managed, true))->values()->all();

        // BASELINE (Khatri #6): every role keeps its landing page — a role saved without the
        // Reports group was 403'd off /dashboard right after login.
        $baseline = ['tenant.dashboard'];
        foreach ($baseline as $name) {
            \Spatie\Permission\Models\Permission::findOrCreate($name, 'tenant');
        }

        $role->syncPermissions(array_values(array_unique(array_merge($preserved, $submitted, $baseline))));

        return redirect('/roles')->with('status', 'Role permissions updated successfully.');
    }

    public function syncPermissions(PermissionSyncService $syncService)
    {
        $count = $syncService->syncTenantPermissions();

        return back()->with('status', "Tenant permissions synced. Total: {$count}");
    }
}
