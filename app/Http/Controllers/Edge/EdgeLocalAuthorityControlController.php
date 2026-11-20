<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Services\Edge\EdgeAuthorityService;
use App\Services\Edge\EdgeHandbackOrchestrator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * KHATRI OWNER CONTROL (21 Nov 2026) — the two supervised authority actions as buttons on the EXISTING operator status page
 * (`edge.health`, /edge/local/pos/health), over the EXISTING engine paths and nothing else:
 *
 *   Switch to Offline  → EdgeAuthorityService::takeOver(confirmed: true, by, acceptStale: FALSE, reason: null)
 *   Return to Online   → EdgeHandbackOrchestrator::run(by)   (the same orchestrated handback the CLI uses)
 *
 * Every refusal the engine makes (Cloud lease still valid / gates blocked / standby not fresh / sync not clean / Cloud did not
 * acknowledge) is shown verbatim as the blocker — never bypassed. There is NO stale override on this surface (the CLI keeps
 * `--accept-stale --reason` as the supervised recovery tool), NO automatic failover, and NO Artisan execution over HTTP.
 *
 * Guards: edge.auth + edge.branch (enrolled local session on the bound branch) → explicit action permission
 * (PERMISSION, replicated from the Cloud role grants) → CSRF (web group) → explicit `confirm=yes` field (the page asks in the
 * browser first) → one appliance-wide lock (concurrent clicks: the second is told to wait) → idempotent (already Offline /
 * already Online returns a notice, not a second transition) → audited (user id, employee code, IP, action, outcome).
 */
class EdgeLocalAuthorityControlController extends Controller
{
    /** The explicit action permission: who may manage Offline Edge security on the Cloud may switch the branch here. */
    public const PERMISSION = 'tenant.offline-edge.security';

    public const LOCK = 'edge-local-authority-control';

    public const ACTION_SWITCH_OFFLINE = 'switch_offline';

    public const ACTION_RETURN_ONLINE = 'return_online';

    public function __construct(
        private readonly EdgeAuthorityService $authority,
        private readonly EdgeHandbackOrchestrator $handback,
    ) {
    }

    public function switchOffline(Request $request): RedirectResponse
    {
        return $this->act($request, self::ACTION_SWITCH_OFFLINE);
    }

    public function returnOnline(Request $request): RedirectResponse
    {
        return $this->act($request, self::ACTION_RETURN_ONLINE);
    }

    private function act(Request $request, string $action): RedirectResponse
    {
        $user = auth('tenant')->user();
        abort_unless($user !== null && $user->can(self::PERMISSION), 403,
            'Switching this branch between Online and Offline needs the Offline Edge security permission (' . self::PERMISSION . ').');

        // Identity for the audit trail comes from the authenticated session, never from the form.
        $by = trim((string) $user->name) . ' (' . trim((string) ($user->employee_code ?? '')) . ')';

        if ((string) $request->input('confirm') !== 'yes') {
            return $this->finish($request, $action, $user, false, 'Not confirmed — the action was not performed.', 'not_confirmed');
        }

        $lock = Cache::lock(self::LOCK, 120);
        if (! $lock->get()) {
            return $this->finish($request, $action, $user, false, 'Another authority change is already in progress — wait a moment and refresh.', 'busy');
        }

        try {
            if ($action === self::ACTION_SWITCH_OFFLINE) {
                if ($this->authority->state() === EdgeAuthorityService::LOCAL_ACTIVE) {
                    return $this->finish($request, $action, $user, true, 'This Branch Server is already OFFLINE (Local Mode active) — nothing changed.', 'already');
                }
                // The engine validates everything: Branch Server only, lease mode provisioned, Cloud lease lapsed
                // (AUTHORITY_TAKEOVER_SAFE), every readiness gate, standby freshness. No stale override from here.
                $r = $this->authority->takeOver(true, $by, false, null);
                $msg = ! empty($r['already'])
                    ? 'This Branch Server is already OFFLINE (Local Mode active) — nothing changed.'
                    : 'OFFLINE — Local Mode is active: this Branch Server now sells for the branch. Cashiers use the BINGOO Offline POS.';

                return $this->finish($request, $action, $user, true, $msg, 'ok');
            }

            $state = $this->authority->state();
            if (! in_array($state, [EdgeAuthorityService::LOCAL_ACTIVE, EdgeAuthorityService::HANDING_BACK], true)) {
                return $this->finish($request, $action, $user, true, 'The Cloud already sells for this branch (ONLINE) — nothing to return.', 'already');
            }
            // The orchestrated handback: assesses connectivity, Cloud fence, sync cleanliness, reservations; fences the
            // appliance; asks the Cloud; never discards anything. Blockers come back named.
            $r = $this->handback->run($by);
            $blockers = (array) ($r['blockers'] ?? []);
            if ($blockers !== []) {
                $text = implode('; ', array_map(
                    fn ($b) => is_array($b) ? trim((string) ($b['code'] ?? '') . ' — ' . (string) ($b['detail'] ?? ''), ' —') : (string) $b,
                    $blockers
                ));

                return $this->finish($request, $action, $user, false, 'Return to Online is BLOCKED: ' . $text, 'blocked');
            }
            if (! empty($r['error'])) {
                return $this->finish($request, $action, $user, false, 'Return to Online failed: ' . (string) $r['error'] . ' (authority ' . (string) ($r['authority_state'] ?? '?') . ' — still fenced; fix the cause and press again).', 'error');
            }

            return $this->finish($request, $action, $user, true, 'ONLINE — authority handed back to the Cloud. Cashiers go back to the BINGOO Online POS and open a new shift there.', 'ok');
        } catch (RuntimeException $e) {
            // The engine's own refusal, verbatim (e.g. "Local Mode cannot start — failing gates: AUTHORITY_TAKEOVER_SAFE",
            // "Handback refused — sync is not clean (pending 3, needs attention 0)").
            return $this->finish($request, $action, $user, false, ($action === self::ACTION_SWITCH_OFFLINE ? 'Switch to Offline refused: ' : 'Return to Online refused: ') . $e->getMessage(), 'refused');
        } finally {
            $lock->release();
        }
    }

    private function finish(Request $request, string $action, $user, bool $ok, string $message, string $outcome): RedirectResponse
    {
        Log::info('[edge-authority-control]', [
            'action' => $action,
            'outcome' => $outcome,
            'ok' => $ok,
            'user_id' => $user->id,
            'employee_code' => $user->employee_code ?? null,
            'ip' => $request->ip(),
            'message' => $message,
        ]);

        return redirect()->to('/edge/local/pos/health')->with('authority_control', [
            'ok' => $ok,
            'action' => $action,
            'outcome' => $outcome,
            'message' => $message,
            'at' => now()->toIso8601String(),
        ]);
    }
}
