<?php

namespace App\Http\Controllers\Edge\Concerns;

use App\Models\Tenant\Terminal;
use App\Services\Security\UserDataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * W0c — the authority helpers every Edge cashier controller shares (the selected terminal, the Online route-permission
 * gate, terminal authority, the Complete Sale gate). Extracted from the former single EdgeLocalPosController so the
 * parity teams own disjoint controllers (POS / shift / restaurant / held sales / approvals / returns / print jobs)
 * while the rules stay in ONE place. The using class must expose `EdgeBranchContext $context`.
 */
trait ResolvesEdgePosContext
{
    /** The per-session selected terminal (re-validated on every use). */
    public const TERMINAL_SESSION_KEY = 'edge_pos_terminal_id';

    /**
     * ONLINE ROUTE-PERMISSION parity (W0b): the permission that gates the Online route (EnsureRoutePermission by
     * route name) gates the Edge endpoint that performs the same action. Resolved from the synced per-user
     * effective set; the refusal names the permission so the page can explain it the way Online does.
     */
    protected function denyUnlessCan(string $permission, string $message): ?JsonResponse
    {
        if (auth('tenant')->user()?->can($permission)) {
            return null;
        }

        return response()->json(['message' => $message, 'permission' => $permission], 403);
    }

    /**
     * COMPLETE SALE PERMISSION parity (canonical f12f1fc): taking payment is gated on `tenant.pos.store`,
     * separately from discount/approval permissions. The page hides the button; the SERVER refuses regardless.
     * `User::can()` resolves from the synced per-user effective permission set (EDGE_OFFLINE_PERMISSION_AUTHORITY).
     */
    protected function denyUnlessMayCompleteSale(): ?JsonResponse
    {
        if (auth('tenant')->user()?->can('tenant.pos.store')) {
            return null;
        }

        return response()->json(['message' => 'Taking payment needs the Complete Sale permission — apply any discount, then Hold; a counter will close the bill.'], 403);
    }

    /**
     * TERMINAL AUTHORITY parity (Online UserDataScope): a pinned operator (no `tenant.pos.change-terminal`, default
     * terminal set) works on that terminal only; a terminal-assigned operator only on an assigned terminal.
     */
    protected function denyUnlessMayOperateTerminal(Terminal $terminal): ?JsonResponse
    {
        $user = auth('tenant')->user();
        if ($user && ! $user->can(UserDataScope::CHANGE_TERMINAL_PERMISSION)) {
            $pinned = (int) ($user->default_terminal_id ?? 0);
            if ($pinned > 0 && $pinned !== (int) $terminal->id) {
                return response()->json(['message' => 'You can only work on your own terminal.', 'permission' => UserDataScope::CHANGE_TERMINAL_PERMISSION], 403);
            }
        }
        if (! app(UserDataScope::class)->canOperateTerminal($user, (int) $terminal->id)) {
            return response()->json(['message' => 'You can only work on a terminal assigned to you.'], 403);
        }

        return null;
    }

    /** The session-selected terminal, re-validated against the bound branch AND the operator's authority on EVERY use. */
    protected function selectedTerminal(Request $request): Terminal|JsonResponse
    {
        $terminalId = (int) $request->session()->get(self::TERMINAL_SESSION_KEY, 0);
        if ($terminalId <= 0) {
            return response()->json(['message' => 'Select a terminal first.'], 422);
        }
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $terminal = Terminal::on('tenant')->where('id', $terminalId)->where('branch_id', $branchId)->where('status', 'active')->first();
        if (! $terminal) {
            $request->session()->forget(self::TERMINAL_SESSION_KEY);

            return response()->json(['message' => 'The selected terminal is no longer available — select a terminal.'], 422);
        }
        // re-validated on EVERY use: an assignment or pin that changed after selection takes effect at once.
        if ($denied = $this->denyUnlessMayOperateTerminal($terminal)) {
            return $denied;
        }

        return $terminal;
    }
}
