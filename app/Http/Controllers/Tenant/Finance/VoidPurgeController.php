<?php

namespace App\Http\Controllers\Tenant\Finance;

use App\Http\Controllers\Controller;
use App\Models\Tenant\PurgedEntry;
use App\Services\Finance\VoidPurgeNeedsConfirmation;
use App\Services\Finance\VoidPurgeService;
use App\Support\TenantFeatures;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * VOID-PURGE-1 — the Owner's "Delete permanently" on a voided row, and the Owner-only "Deleted entries" log.
 * Both are behind the per-tenant Master switch (off everywhere by default) and their own permissions, which deploy
 * grants to the Owner role only. The service decides what may go; this only asks who, why, and the password again.
 */
class VoidPurgeController extends Controller
{
    public function __construct(private readonly VoidPurgeService $purge)
    {
    }

    public function purge(Request $request, string $type, int $id)
    {
        abort_unless(TenantFeatures::on(TenantFeatures::VOID_PURGE), 404);
        abort_unless(isset(VoidPurgeService::TYPES[$type]), 404);

        $data = $request->validate([
            'purge_reason'   => ['required', 'string', 'min:5', 'max:500'],
            'purge_password' => ['required', 'string'],
        ], [
            'purge_reason.required' => 'Write why this entry is being deleted.',
            'purge_reason.min'      => 'Write why this entry is being deleted (at least 5 characters).',
            'purge_password.required' => 'Type your password to confirm.',
        ]);

        $user = Auth::guard('tenant')->user();
        if (! Hash::check($data['purge_password'], (string) $user->password)) {
            return $this->refused($request, 'The password did not match. Nothing was deleted.', retry: true);
        }

        try {
            $entry = $this->purge->purge($type, $id, $data['purge_reason'], (int) $user->id, $request->ip(), $request->boolean('across_days'));
        } catch (VoidPurgeNeedsConfirmation $e) {
            // A void dated another day: the screen asks "Delete anyway?" with the days that change, then sends across_days.
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'confirm' => true, 'message' => $e->getMessage()], 409);
            }

            return back()->withErrors(['purge' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            return $this->refused($request, $e->getMessage());
        }

        $message = VoidPurgeService::TYPES[$type] . ' ' . $entry->document_no
            . ' and its reversal were deleted permanently. A copy is in Finance → Deleted entries.';
        $to = \App\Support\CashBookReturn::from($request) ?? url('/finance/voided-entries');
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $message, 'redirect' => $to]);
        }

        return redirect($to)->with('status', $message);
    }

    /** Nothing was deleted: the popup says why (and lets the person try again for a password or reason), or the page does. */
    private function refused(Request $request, string $why, bool $retry = false)
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'retry' => $retry, 'message' => $why, 'errors' => ['purge' => [$why]]], 422);
        }

        return back()->withErrors(['purge' => $why]);
    }

    public function index()
    {
        abort_unless(TenantFeatures::on(TenantFeatures::VOID_PURGE), 404);

        $entries = VoidPurgeService::ready()
            ? PurgedEntry::with('purgedBy')->orderByDesc('purged_at')->orderByDesc('id')->paginate(30)
            : null;

        return view('tenant.finance.voided-entries.index', ['entries' => $entries, 'types' => VoidPurgeService::TYPES]);
    }
}
