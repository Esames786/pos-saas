<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Services\Edge\AutoUpdate\EdgeAutoUpdateRuntimeProbe;
use App\Services\Edge\AutoUpdate\EdgeAutoUpdateService;
use App\Services\Edge\AutoUpdate\EdgeAutoUpdateStateStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * EDGE 0.9 (stream B) — "Update now" on the operator status page (`edge.health`, /edge/local/pos/health).
 *
 * It only RECORDS the request (update-now.request.json in the auto-update work dir); the BingooEdgeUpdater task picks it up on
 * its next run (≤ 15 min) and applies the verified release through the SAME gate as a maintenance-window update — standby
 * only, outbox empty, signed + verified package, health check, rollback rule. "Update now" bypasses ONLY the window. No
 * Artisan runs over HTTP, nothing is installed by the web process.
 *
 * Guards — the same set as the authority buttons (EdgeLocalAuthorityControlController): edge.auth + edge.branch (enrolled local
 * session on the bound branch) → explicit permission tenant.offline-edge.security → CSRF (web group) → explicit confirm=yes →
 * a short lock (concurrent clicks) → refused while the branch is not in standby or has unsynced events → audited.
 */
class EdgeLocalAutoUpdateController extends Controller
{
    public const PERMISSION = EdgeLocalAuthorityControlController::PERMISSION;

    public const LOCK = 'edge-local-auto-update-control';

    public function __construct(
        private readonly EdgeAutoUpdateStateStore $store,
        private readonly EdgeAutoUpdateRuntimeProbe $runtime,
        private readonly EdgeAutoUpdateService $service,
    ) {
    }

    public function updateNow(Request $request): RedirectResponse
    {
        $user = auth('tenant')->user();
        abort_unless($user !== null && $user->can(self::PERMISSION), 403,
            'Updating this Branch Server needs the Offline Edge security permission (' . self::PERMISSION . ').');
        $by = trim((string) $user->name) . ' (' . trim((string) ($user->employee_code ?? '')) . ')';

        if ((string) $request->input('confirm') !== 'yes') {
            return $this->finish($request, $user, false, 'Not confirmed — nothing was requested.', 'not_confirmed');
        }
        $lock = Cache::lock(self::LOCK, 30);
        if (! $lock->get()) {
            return $this->finish($request, $user, false, 'Another update request is being recorded — refresh in a moment.', 'busy');
        }
        try {
            $facts = $this->runtime->facts();
            if (($facts['authority_state'] ?? '') !== 'standby') {
                return $this->finish($request, $user, false, 'Update now is refused: this Branch Server is ' . strtoupper((string) $facts['authority_state']) . '. Updates run only while the Cloud sells (Return to Online first).', 'refused');
            }
            if (($facts['outbox_pending'] + $facts['outbox_in_flight']) > 0) {
                return $this->finish($request, $user, false, 'Update now is refused: ' . ($facts['outbox_pending'] + $facts['outbox_in_flight']) . ' event(s) still waiting to sync to the Cloud.', 'refused');
            }
            $this->store->requestUpdateNow($by, 'status_page');
            $available = $this->service->status()['available']['version'] ?? null;
            $msg = $available
                ? "Update to {$available} requested — it starts within " . (int) config('edge.auto_update.check_interval_minutes', 15) . ' minutes if the branch is still in standby with nothing to sync. This page shows the result.'
                : 'Update check requested — the updater asks the Cloud within ' . (int) config('edge.auto_update.check_interval_minutes', 15) . ' minutes and installs a newer signed version if there is one.';

            return $this->finish($request, $user, true, $msg, 'requested');
        } finally {
            $lock->release();
        }
    }

    private function finish(Request $request, $user, bool $ok, string $message, string $outcome): RedirectResponse
    {
        Log::info('[edge-auto-update-control]', [
            'action' => 'update_now',
            'outcome' => $outcome,
            'ok' => $ok,
            'user_id' => $user->id,
            'employee_code' => $user->employee_code ?? null,
            'ip' => $request->ip(),
            'message' => $message,
        ]);

        return redirect()->to('/edge/local/pos/health')->with('auto_update_control', [
            'ok' => $ok,
            'outcome' => $outcome,
            'message' => $message,
            'at' => now()->toIso8601String(),
        ]);
    }
}
