<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\PrintAgent;
use App\Models\Tenant\Terminal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PrintAgentController extends Controller
{
    public function index()
    {
        return view('tenant.printing.agents.index', [
            'agents'    => PrintAgent::with(['branch', 'terminal'])->latest()->paginate(20),
            'branches'  => Branch::where('status', 'active')->orderBy('name')->get(),
            'terminals' => Terminal::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:190'],
            'branch_id'   => ['nullable', 'exists:branches,id'],
            'terminal_id' => ['nullable', 'exists:terminals,id'],
            'device_name' => ['nullable', 'string', 'max:190'],
        ]);

        $plainToken = Str::random(64);

        PrintAgent::create([
            'name'        => $data['name'],
            'agent_code'  => 'AG-' . now()->format('YmdHis') . '-' . random_int(100, 999),
            'branch_id'   => $data['branch_id'] ?? null,
            'terminal_id' => $data['terminal_id'] ?? null,
            'device_name' => $data['device_name'] ?? null,
            'token_hash'  => Hash::make($plainToken),
            'is_active'   => true,
        ]);

        return back()->with('status', 'Print agent created. Copy token now — it will not be shown again: ' . $plainToken);
    }

    public function regenerateToken(PrintAgent $printAgent)
    {
        $plainToken = Str::random(64);

        $printAgent->update([
            'token_hash' => Hash::make($plainToken),
        ]);

        return back()->with('status', 'New token generated. Copy now — it will not be shown again: ' . $plainToken);
    }

    public function deactivate(PrintAgent $printAgent)
    {
        $printAgent->update(['is_active' => false]);

        return back()->with('status', 'Print agent deactivated.');
    }
}
