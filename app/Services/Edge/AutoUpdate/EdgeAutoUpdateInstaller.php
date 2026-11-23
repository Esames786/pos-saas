<?php

namespace App\Services\Edge\AutoUpdate;

use App\Services\Edge\EdgeUpdateInstaller;

/**
 * EDGE 0.9 (stream B) — the EXISTING signed installer (EdgeUpdateInstaller: verify → pre-update backup → stage into a new
 * version dir → D7 plan check against the approved hash → atomic pointer switch → schema upgrade in the NEW runtime →
 * pointer revert on a schema failure), with its two designated seams filled for an unattended appliance:
 *
 *   stopWorkers()  — called after staging, the staged re-verify and the D7 plan check, right before the pointer switch: stop
 *                    every task but the updater (the standby appliance keeps heartbeating through the long copy);
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
        // Set BEFORE the callback: a stop that fails half-way still makes the applier restart the previous runtime.
        $this->workersStopped = true;
        if ($this->onStop !== null) {
            ($this->onStop)();
        }
    }

    protected function startWorkers(): void
    {
        if ($this->onStart !== null) {
            ($this->onStart)();
        }
        $this->workersStopped = false;
    }
}
