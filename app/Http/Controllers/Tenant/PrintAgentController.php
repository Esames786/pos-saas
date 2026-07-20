<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\PrintAgent;
use App\Models\Tenant\Printer;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\Terminal;
use App\Services\Printing\PrintAgentPairingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PrintAgentController extends Controller
{
    public function __construct(private readonly PrintAgentPairingService $pairing) {}

    public function index()
    {
        return view('tenant.printing.agents.index', [
            'agents'    => PrintAgent::with(['branch', 'terminal'])->latest()->paginate(20),
            'branches'  => Branch::where('status', 'active')->orderBy('name')->get(),
            'terminals' => Terminal::orderBy('name')->get(),
        ]);
    }

    /**
     * PRINT-AGENT-INSTALLER-1: creating an agent now issues a PAIRING CODE
     * instead of flashing a raw permanent token. The permanent token is only
     * ever delivered to the agent itself during the pair exchange.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:190'],
            'branch_id'   => ['nullable', 'exists:branches,id'],
            'terminal_id' => ['nullable', 'exists:terminals,id'],
            'device_name' => ['nullable', 'string', 'max:190'],
        ]);

        $agent = PrintAgent::create([
            'name'        => $data['name'],
            'agent_code'  => 'AG-' . now()->format('YmdHis') . '-' . random_int(100, 999),
            'branch_id'   => $data['branch_id'] ?? null,
            'terminal_id' => $data['terminal_id'] ?? null,
            'device_name' => $data['device_name'] ?? null,
            // Random placeholder until pairing completes — nothing can auth with it.
            'token_hash'  => Hash::make(Str::random(64)),
            'is_active'   => true,
        ]);

        $this->pairing->audit('print_agent.created', $agent);

        $code = $this->pairing->generatePairingCode($agent);

        return back()->with('pairing', $this->pairingPayload($agent, $code));
    }

    /** Issue a fresh pairing code (first pair or re-pair). Old token keeps working until pairing completes. */
    public function pairingCode(PrintAgent $printAgent)
    {
        $code = $this->pairing->generatePairingCode($printAgent);

        return back()->with('pairing', $this->pairingPayload($printAgent, $code));
    }

    /** Legacy/advanced fallback: raw token flow, unchanged for existing manual setups. */
    public function regenerateToken(PrintAgent $printAgent)
    {
        $plainToken = Str::random(64);

        $printAgent->update([
            'token_hash' => Hash::make($plainToken),
        ]);

        $this->pairing->audit('print_agent.token_regenerated', $printAgent);

        return back()->with('status', 'New token generated. Copy now — it will not be shown again: ' . $plainToken);
    }

    public function deactivate(PrintAgent $printAgent)
    {
        $printAgent->update(['is_active' => false]);

        $this->pairing->audit('print_agent.deactivated', $printAgent);

        return back()->with('status', 'Print agent deactivated.');
    }

    /**
     * Queue a simple test page through the EXISTING print_jobs pipeline so the
     * whole chain (queue → agent poll → TCP 9100 → printed callback) is proven.
     */
    public function testPrint(PrintAgent $printAgent)
    {
        $printer = Printer::where('is_active', true)
            ->where('printer_type', 'network')
            ->whereNotNull('ip_address')
            ->when($printAgent->branch_id, fn ($q) => $q->where(function ($qq) use ($printAgent) {
                $qq->whereNull('branch_id')->orWhere('branch_id', $printAgent->branch_id);
            }))
            ->orderBy('id')
            ->first();

        if (! $printer) {
            return back()->withErrors(['test' => 'No printer is mapped yet. Add a printer first, then send a test print.']);
        }

        $payload = ''
            . str_repeat('=', 42) . "\n"
            . "        BINGOO POS TEST PRINT\n"
            . str_repeat('=', 42) . "\n"
            . 'Agent:   ' . $printAgent->agent_code . "\n"
            . 'Printer: ' . $printer->name . ' (' . $printer->ip_address . ':' . ($printer->port ?: 9100) . ")\n"
            . 'Time:    ' . now()->format('Y-m-d H:i:s') . "\n"
            . str_repeat('-', 42) . "\n"
            . "If you can read this, printing is\n"
            . "connected and working.\n"
            . str_repeat('=', 42) . "\n\n\n";

        PrintJob::create([
            'job_no'             => 'PJ-TEST-' . now()->format('YmdHis') . '-' . random_int(100, 999),
            'branch_id'          => $printAgent->branch_id,
            'terminal_id'        => $printAgent->terminal_id,
            'printer_id'         => $printer->id,
            'document_type'      => 'receipt',
            'print_status'       => 'queued',
            'reference_type'     => 'print_agent_test',
            'reference_id'       => $printAgent->id,
            'reference_no'       => $printAgent->agent_code,
            'payload'            => ['test' => true, 'agent_code' => $printAgent->agent_code],
            'raw_payload'        => $payload,
            'attempts'           => 0,
            'created_by_user_id' => Auth::id(),
        ]);

        $this->pairing->audit('print_agent.test_print_sent', $printAgent, ['printer_id' => $printer->id]);

        return back()->with('status', 'Test print queued for "' . $printer->name . '". It prints within a few seconds once the agent is connected.');
    }

    /**
     * Download the Windows agent.
     *
     * Preferred: the one-click wizard `BingooPrintAgent-Setup.exe` (Node.js is
     * bundled inside — the customer installs nothing else; the wizard asks for
     * the server URL + pairing code and registers an auto-start service).
     *
     * Fallback (if the built .exe is not deployed): a ZIP of the script agent +
     * Windows helper scripts for the manual/Node install path.
     */
    public function downloadWindows()
    {
        $base    = base_path('tools/print-agent');
        $setupExe = $base . '/dist/BingooPrintAgent-Setup.exe';

        if (is_file($setupExe)) {
            return response()->download($setupExe, 'BingooPrintAgent-Setup.exe');
        }

        // Fallback: script bundle.
        $files = [
            'print-agent.js'                          => $base . '/print-agent.js',
            'README.md'                               => $base . '/README.md',
            'installer/windows/README.md'             => $base . '/installer/windows/README.md',
            'installer/windows/install-service.ps1'   => $base . '/installer/windows/install-service.ps1',
            'installer/windows/uninstall-service.ps1' => $base . '/installer/windows/uninstall-service.ps1',
        ];

        $zipPath = tempnam(sys_get_temp_dir(), 'bpa');
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::OVERWRITE);

        foreach ($files as $name => $path) {
            if (is_file($path)) {
                $zip->addFile($path, 'BingooPrintAgent/' . $name);
            }
        }

        $zip->addFromString(
            'BingooPrintAgent/SERVER.txt',
            "Server URL for pairing:\n" . request()->getSchemeAndHttpHost() . "\n"
        );

        $zip->close();

        return response()->download($zipPath, 'BingooPrintAgent-windows.zip')->deleteFileAfterSend(true);
    }

    private function pairingPayload(PrintAgent $agent, string $code): array
    {
        return [
            'agent_id'   => $agent->id,
            'agent_name' => $agent->name,
            'agent_code' => $agent->agent_code,
            'code'       => substr($code, 0, 3) . '-' . substr($code, 3),
            'expires_at' => now()->addMinutes(PrintAgentPairingService::CODE_TTL_MINUTES)->toIso8601String(),
            'server_url' => request()->getSchemeAndHttpHost(),
        ];
    }
}
