<?php

namespace App\Services\Edge\AutoUpdate;

use App\Services\Edge\EdgeUpdateInstaller;

/**
 * EDGE 0.9 (stream B) — the EXISTING signed installer (EdgeUpdateInstaller: verify → pre-update backup → stage into a new
 * version dir → D7 plan check against the approved hash → atomic pointer switch → schema upgrade in the NEW runtime →
 * pointer revert on a schema failure), with its two designated seams filled for an unattended appliance:
 *
 *   stopWorkers()  — called after the verified pre-update backup, before staging: stop every task but the updater;
 *   startWorkers() — called after a successful schema upgrade: re-render the plan with the NEW runtime and start the tasks.
 *
 * Nothing in the base class is changed or bypassed; the callbacks are set by EdgeAutoUpdateApplier per run.
 */
class EdgeAutoUpdateInstaller extends EdgeUpdateInstaller
{
    /** @var null|callable():void */
    private $onStop = null;

    /** @var null|callable():void */
    private $onStart = null;

    public bool $workersStopped = false;

    public function withServiceHooks(?callable $onStop, ?callable $onStart): static
    {
        $this->onStop = $onStop;
        $this->onStart = $onStart;
        $this->workersStopped = false;

        return $this;
    }

    protected function stopWorkers(): void
    {
        if ($this->onStop !== null) {
            ($this->onStop)();
        }
        $this->workersStopped = true;
    }

    protected function startWorkers(): void
    {
        if ($this->onStart !== null) {
            ($this->onStart)();
        }
        $this->workersStopped = false;
    }
}
