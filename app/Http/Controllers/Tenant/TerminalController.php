<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Terminal;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TerminalController extends Controller
{
    public function index(Request $request)
    {
        $query = Terminal::with(['branch', 'openShift'])->latest();

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        return view('tenant.terminals.index', [
            'terminals' => $query->paginate(15)->withQueryString(),
            'branches'  => Branch::where('status', 'active')->orderBy('name')->get(),
            'usage'     => app(\App\Services\Saas\TenantSubscriptionAccessService::class)
                ->checkLimit(app('tenant'), 'terminals'),
        ]);
    }

    public function create()
    {
        return view('tenant.terminals.create', [
            'branches' => Branch::where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateTerminal($request);

        $limit = app(\App\Services\Saas\TenantSubscriptionAccessService::class)
            ->checkLimit(app('tenant'), 'terminals');

        if (!$limit['allowed']) {
            return back()->withInput()->withErrors(['limit' => $limit['message']]);
        }

        Terminal::create($data);

        return redirect('/terminals')->with('status', 'Terminal created successfully.');
    }

    public function edit(Terminal $terminal)
    {
        return view('tenant.terminals.edit', [
            'terminal' => $terminal,
            'branches' => Branch::where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Terminal $terminal)
    {
        $terminal->update($this->validateTerminal($request, $terminal));

        return redirect('/terminals')->with('status', 'Terminal updated successfully.');
    }

    public function destroy(Terminal $terminal)
    {
        if ($terminal->shifts()->exists()) {
            return back()->withErrors(['terminal' => 'Terminal has shift history and cannot be deleted.']);
        }

        $terminal->delete();

        return back()->with('status', 'Terminal deleted successfully.');
    }

    private function validateTerminal(Request $request, ?Terminal $terminal = null): array
    {
        return $request->validate([
            'branch_id'         => ['required', 'exists:branches,id'],
            'code'              => ['required', 'string', 'max:50', Rule::unique('terminals', 'code')->ignore($terminal?->id)],
            'name'              => ['required', 'string', 'max:190'],
            'device_identifier' => ['nullable', 'string', 'max:190'],
            'requires_shift'    => ['nullable', 'boolean'],
            'status'            => ['required', Rule::in(['active', 'inactive'])],
        ]);
    }
}
