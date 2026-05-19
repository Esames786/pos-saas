<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Master\RouteCatalog;
use App\Services\Permissions\PermissionSyncService;
use Illuminate\Http\Request;

class RouteCatalogController extends Controller
{
    public function index(Request $request)
    {
        $query = RouteCatalog::query()->latest('synced_at');

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('route_name', 'like', "%{$search}%")
                    ->orWhere('uri', 'like', "%{$search}%")
                    ->orWhere('module_key', 'like', "%{$search}%");
            });
        }

        if ($request->filled('guard')) {
            $query->where('route_name', 'like', $request->guard . '.%');
        }

        if ($request->filled('published')) {
            $query->where('is_published', (bool) $request->published);
        }

        $routes = $query->paginate(25)->withQueryString();

        return view('central.routes.index', compact('routes'));
    }

    public function sync(PermissionSyncService $syncService)
    {
        $count = $syncService->syncRouteCatalog();

        return back()->with('status', "Routes synced successfully. Total: {$count}");
    }

    public function publish(Request $request, PermissionSyncService $syncService)
    {
        RouteCatalog::query()
            ->whereIn('id', $request->input('route_ids', []))
            ->update(['is_published' => true]);

        $centralCount = $syncService->syncCentralPermissions();

        return back()->with('status', "Selected routes published. Central permissions synced: {$centralCount}");
    }

    public function unpublish(Request $request)
    {
        RouteCatalog::query()
            ->whereIn('id', $request->input('route_ids', []))
            ->update(['is_published' => false]);

        return back()->with('status', 'Selected routes unpublished successfully.');
    }

    public function publishAll(PermissionSyncService $syncService)
    {
        RouteCatalog::query()
            ->where(function ($q) {
                $q->where('route_name', 'like', 'central.%')
                    ->orWhere('route_name', 'like', 'tenant.%');
            })
            ->update(['is_published' => true]);

        $centralCount = $syncService->syncCentralPermissions();

        return back()->with('status', "All central/tenant routes published. Central permissions synced: {$centralCount}");
    }

    public function syncPermissions(PermissionSyncService $syncService)
    {
        $centralCount = $syncService->syncCentralPermissions();

        return back()->with('status', "Central permissions synced: {$centralCount}");
    }
}
