<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

/**
 * TENANT-RESET-1 owner UI (Khatri #5): the "demo done, start real business" reset.
 * Wipes transactions, keeps every piece of master data. The heavy lifting + guards live in
 * tenant:reset-transactions; this page adds the Owner-facing flow: typed tenant code,
 * password re-entry and an explicit backup acknowledgement.
 */
class SystemResetController extends Controller
{
    public function index()
    {
        return view('tenant.system-reset.index', [
            'tenantCode' => app('tenant')->tenant_code,
        ]);
    }

    public function execute(Request $request)
    {
        $tenantCode = (string) app('tenant')->tenant_code;

        $data = $request->validate([
            'confirm_code' => ['required', 'string'],
            'password' => ['required', 'string'],
            'backup_ack' => ['accepted'],
        ], [
            'backup_ack.accepted' => 'You must confirm that a backup exists before resetting.',
        ]);

        if ($data['confirm_code'] !== $tenantCode) {
            return back()->withErrors(['confirm_code' => "Type the business code exactly: {$tenantCode}"]);
        }
        if (! Hash::check($data['password'], auth('tenant')->user()->password)) {
            return back()->withErrors(['password' => 'Your password did not match.']);
        }

        $exit = Artisan::call('tenant:reset-transactions', [
            'tenant_code' => $tenantCode, '--yes' => true, '--confirm' => $tenantCode,
        ]);
        $output = trim(Artisan::output());

        if ($exit !== 0) {
            return back()->withErrors(['confirm_code' => 'Reset failed: ' . $output]);
        }

        return redirect(url('/system-reset'))->with('status', "System reset complete.\n" . $output);
    }
}
