<?php

namespace Tests\Unit\Edge;

use App\Services\Edge\EdgeWorkerSlotPolicy;
use App\Support\EdgeProcessLiveness;
use Tests\TestCase;

/**
 * EDGE 0.8.3 (F-G10) — the singleton worker slot decision matrix, with the OS liveness injected (no DB, no OS).
 */
class EdgeWorkerSlotPolicyTest extends TestCase
{
    private const STALE = 90;

    private function row(array $over = []): array
    {
        return array_merge([
            'state' => 'running', 'worker_uuid' => 'previous-worker', 'heartbeat_at' => now()->subSeconds(10)->toDateTimeString(),
            'worker_pid' => 4242, 'worker_process_token' => 'unix:1700000000', 'worker_host' => EdgeProcessLiveness::host(),
        ], $over);
    }

    private function liveness(string $status): callable
    {
        return fn () => ['status' => $status, 'reason' => "test says {$status}", 'observed_token' => null, 'method' => 'fake'];
    }

    public function test_a_stopped_or_unowned_row_is_a_free_slot_without_consulting_the_os(): void
    {
        $called = false;
        $spy = function () use (&$called) { $called = true; return ['status' => EdgeProcessLiveness::ALIVE, 'reason' => 'x']; };
        foreach ([['state' => 'stopped'], ['worker_uuid' => null], ['worker_uuid' => 'me'], ['heartbeat_at' => null]] as $over) {
            $d = EdgeWorkerSlotPolicy::decide($this->row($over), 'me', self::STALE, $spy);
            $this->assertTrue($d['takeover'], json_encode($over));
            $this->assertSame(EdgeWorkerSlotPolicy::TAKEOVER_SLOT_FREE, $d['reason']);
        }
        $this->assertFalse($called, 'a free slot never needs an OS probe');
    }

    public function test_fresh_heartbeat_with_a_dead_process_is_taken_over_the_g10_trap(): void
    {
        $d = EdgeWorkerSlotPolicy::decide($this->row(['heartbeat_at' => now()->subSeconds(5)]), 'me', self::STALE, $this->liveness(EdgeProcessLiveness::DEAD));
        $this->assertTrue($d['takeover']);
        $this->assertSame(EdgeWorkerSlotPolicy::TAKEOVER_PROCESS_GONE, $d['reason']);
        $this->assertSame(EdgeProcessLiveness::DEAD, $d['liveness']['status']);
        $this->assertLessThan(self::STALE, $d['heartbeat_age_seconds']);
    }

    public function test_fresh_heartbeat_with_a_live_process_is_a_genuine_duplicate_and_is_refused(): void
    {
        $d = EdgeWorkerSlotPolicy::decide($this->row(), 'me', self::STALE, $this->liveness(EdgeProcessLiveness::ALIVE));
        $this->assertFalse($d['takeover']);
        $this->assertSame(EdgeWorkerSlotPolicy::REFUSE_LIVE_DUPLICATE, $d['reason']);
    }

    public function test_stale_heartbeat_is_taken_over_whether_the_process_is_alive_wedged_or_gone(): void
    {
        $stale = $this->row(['heartbeat_at' => now()->subSeconds(self::STALE + 5)]);
        $alive = EdgeWorkerSlotPolicy::decide($stale, 'me', self::STALE, $this->liveness(EdgeProcessLiveness::ALIVE));
        $this->assertTrue($alive['takeover']);
        $this->assertSame(EdgeWorkerSlotPolicy::TAKEOVER_STALE_PROCESS_ALIVE, $alive['reason']);
        $gone = EdgeWorkerSlotPolicy::decide($stale, 'me', self::STALE, $this->liveness(EdgeProcessLiveness::DEAD));
        $this->assertTrue($gone['takeover']);
        $this->assertSame(EdgeWorkerSlotPolicy::TAKEOVER_PROCESS_GONE, $gone['reason']);
    }

    public function test_unknown_liveness_keeps_the_pre_083_heartbeat_rule_exactly(): void
    {
        $fresh = EdgeWorkerSlotPolicy::decide($this->row(['worker_pid' => null]), 'me', self::STALE, $this->liveness(EdgeProcessLiveness::UNKNOWN));
        $this->assertFalse($fresh['takeover']);
        $this->assertSame(EdgeWorkerSlotPolicy::REFUSE_FRESH_HEARTBEAT_UNVERIFIED, $fresh['reason']);
        $stale = EdgeWorkerSlotPolicy::decide($this->row(['worker_pid' => null, 'heartbeat_at' => now()->subSeconds(self::STALE + 1)]), 'me', self::STALE, $this->liveness(EdgeProcessLiveness::UNKNOWN));
        $this->assertTrue($stale['takeover']);
        $this->assertSame(EdgeWorkerSlotPolicy::TAKEOVER_STALE_HEARTBEAT, $stale['reason']);
    }

    public function test_the_real_liveness_check_reports_unknown_for_a_row_without_identity_or_from_another_host(): void
    {
        $noPid = EdgeWorkerSlotPolicy::decide($this->row(['worker_pid' => null, 'worker_process_token' => null, 'worker_host' => null]), 'me', self::STALE);
        $this->assertFalse($noPid['takeover'], 'pre-0.8.3 row + fresh heartbeat = refuse, as before');
        $this->assertSame(EdgeProcessLiveness::UNKNOWN, $noPid['liveness']['status']);
        $otherHost = EdgeWorkerSlotPolicy::decide($this->row(['worker_host' => 'some-other-box']), 'me', self::STALE);
        $this->assertSame(EdgeProcessLiveness::UNKNOWN, $otherHost['liveness']['status']);
        $this->assertStringContainsString('another host', $otherHost['liveness']['reason']);
    }

    public function test_describe_and_identity_columns_are_operator_readable_and_bounded(): void
    {
        $d = EdgeWorkerSlotPolicy::decide($this->row(), 'me', self::STALE, $this->liveness(EdgeProcessLiveness::DEAD));
        $line = EdgeWorkerSlotPolicy::describe($d, 'previous-worker');
        $this->assertStringContainsString('taking over the worker slot [previous_process_gone]', $line);
        $this->assertStringContainsString('previous worker previous-worker', $line);
        $cols = EdgeWorkerSlotPolicy::identityColumns(['pid' => 77, 'token' => 'unix:1', 'host' => str_repeat('h', 100)]);
        $this->assertSame(77, $cols['worker_pid']);
        $this->assertSame(64, mb_strlen($cols['worker_host']));
    }
}
