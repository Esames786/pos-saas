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

    public function editPermissions(Role $role, PermissionSyncService $syncService)
    {
        abort_unless($role->guard_name === 'tenant', 404);

        $role->load('permissions');

        return view('tenant.roles.permissions', [
            'role'                => $role,
            'permissionGroups'    => $syncService->tenantPermissionGroups(),
            'assignedPermissions' => $role->permissions->pluck('name')->toArray(),
        ]);
    }

    public function updatePermissions(UpdateRolePermissionsRequest $request, Role $role)
    {
        abort_unless($role->guard_name === 'tenant', 404);

        $role->syncPermissions($request->input('permissions', []));

        return redirect('/roles')->with('status', 'Role permissions updated successfully.');
    }

    public function syncPermissions(PermissionSyncService $syncService)
    {
        $count = $syncService->syncTenantPermissions();

        return back()->with('status', "Tenant permissions synced. Total: {$count}");
    }
}
