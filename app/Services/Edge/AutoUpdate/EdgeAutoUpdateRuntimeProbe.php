<?php

namespace App\Services\Edge\AutoUpdate;

use App\Models\Edge\EdgeSyncOutbox;
use App\Services\Edge\EdgeAuthorityService;
use App\Services\Edge\EdgeBranchContext;
use Illuminate\Support\Facades\DB;

/**
 * EDGE 0.9 (stream B) — reads (never writes) the facts the update gate needs: binding, authority state, outbox counts.
 * Read through the existing engine (EdgeAuthorityService::state) and the outbox table; any read failure is reported as the
 * UNSAFE value (unknown authority / non-empty outbox), so a broken database can never green-light an update.
 */
class EdgeAutoUpdateRuntimeProbe
{
    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly EdgeAuthorityService $authority,
    ) {
    }

    /** @return array{bound:bool,authority_state:string,outbox_pending:int,outbox_in_flight:int} */
    public function facts(): array
    {
        $bound = false;
        $state = 'unknown';
        try {
            $bound = $this->context->isBound();
            $state = $bound ? $this->authority->state() : 'unknown';
        } catch (\Throwable) {
            $bound = false;
        }
        $pending = 1;
        $inFlight = 0;
        try {
            $counts = DB::connection('tenant')->table('edge_sync_outbox')
                ->whereIn('state', [EdgeSyncOutbox::STATE_PENDING, EdgeSyncOutbox::STATE_LEASED])
                ->selectRaw('state, COUNT(*) as n')->groupBy('state')->pluck('n', 'state');
            $pending = (int) ($counts[EdgeSyncOutbox::STATE_PENDING] ?? 0);
            $inFlight = (int) ($counts[EdgeSyncOutbox::STATE_LEASED] ?? 0);
        } catch (\Throwable) {
            $pending = 1; // unknown = not empty (fail closed)
        }

        return ['bound' => $bound, 'authority_state' => $state, 'outbox_pending' => $pending, 'outbox_in_flight' => $inFlight];
    }
}
