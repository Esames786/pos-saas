<?php

namespace Tests\Unit\Edge;

use App\Support\EdgeProcessLiveness;
use PHPUnit\Framework\TestCase;

/**
 * EDGE 0.8.3 (F-G10) — the OS process liveness primitive, against REAL processes on this host: this process is alive
 * with its own start token, a process that has exited is dead, a recorded start token that differs = pid reuse = dead.
 */
class EdgeProcessLivenessTest extends TestCase
{
    protected function tearDown(): void
    {
        EdgeProcessLiveness::fakeProbe(null);
        parent::tearDown();
    }

    public function test_this_process_is_alive_and_carries_a_unix_start_token(): void
    {
        $self = EdgeProcessLiveness::self();
        $this->assertSame((int) getmypid(), $self['pid']);
        $this->assertNotSame('', $self['host']);
        $this->assertNotNull($self['token'], 'the OS probe must yield a start token on this host');
        $seconds = EdgeProcessLiveness::tokenSeconds($self['token']);
        $this->assertNotNull($seconds);
        $this->assertLessThanOrEqual(time(), $seconds);
        $this->assertGreaterThan(time() - 6 * 3600, $seconds, 'this test process started within the last hours');

        $check = EdgeProcessLiveness::check($self['pid'], $self['token'], $self['host']);
        $this->assertSame(EdgeProcessLiveness::ALIVE, $check['status'], $check['reason']);
    }

    public function test_an_exited_process_is_dead_even_with_a_fresh_token(): void
    {
        $proc = proc_open([PHP_BINARY, '-r', 'echo getmypid();'], [1 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        $pid = (int) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($proc); // the child has exited and been reaped
        $this->assertGreaterThan(0, $pid);

        $check = EdgeProcessLiveness::check($pid, 'unix:' . time(), EdgeProcessLiveness::host());
        $this->assertSame(EdgeProcessLiveness::DEAD, $check['status'], $check['reason']);
    }

    public function test_a_running_process_with_a_different_start_token_counts_as_pid_reuse_and_is_dead(): void
    {
        $self = EdgeProcessLiveness::self();
        $check = EdgeProcessLiveness::check(999_999_999, null, $self['host']); // cannot exist: Windows/Linux pids are far smaller
        $this->assertSame(EdgeProcessLiveness::DEAD, $check['status']);

        // a long-lived sibling: a php child sleeping; its real token differs from a token 1 h in the past → reuse → dead
        $proc = proc_open([PHP_BINARY, '-r', 'echo getmypid(); fflush(STDOUT); sleep(20);'], [1 => ['pipe', 'w']], $pipes);
        $pid = (int) fread($pipes[1], 32);
        try {
            $real = EdgeProcessLiveness::check($pid, null, $self['host']);
            $this->assertSame(EdgeProcessLiveness::ALIVE, $real['status'], $real['reason']);
            $reused = EdgeProcessLiveness::check($pid, 'unix:' . (time() - 3600), $self['host']);
            $this->assertSame(EdgeProcessLiveness::DEAD, $reused['status'], $reused['reason']);
            $this->assertStringContainsString('reused', $reused['reason']);
            $sameToken = EdgeProcessLiveness::check($pid, $real['observed_token'], $self['host']);
            $this->assertSame(EdgeProcessLiveness::ALIVE, $sameToken['status']);
        } finally {
            proc_terminate($proc);
            fclose($pipes[1]);
            proc_close($proc);
        }
    }

    public function test_unknown_when_nothing_is_recorded_or_the_probe_cannot_tell(): void
    {
        $this->assertSame(EdgeProcessLiveness::UNKNOWN, EdgeProcessLiveness::check(null, null, null)['status']);
        $this->assertSame(EdgeProcessLiveness::UNKNOWN, EdgeProcessLiveness::check(123, 'unix:1', 'another-host-name')['status']);
        EdgeProcessLiveness::fakeProbe(fn () => ['exists' => null, 'token' => null, 'method' => 'fake-broken']);
        $c = EdgeProcessLiveness::check(123, 'unix:1', EdgeProcessLiveness::host());
        $this->assertSame(EdgeProcessLiveness::UNKNOWN, $c['status']);
        $this->assertStringContainsString('fake-broken', $c['reason']);
    }

    public function test_tokens_match_within_the_rounding_tolerance_only(): void
    {
        $this->assertTrue(EdgeProcessLiveness::tokensMatch('unix:1000', 'unix:1002'));
        $this->assertTrue(EdgeProcessLiveness::tokensMatch('unix:1000@some-worker-uuid', 'unix:1001'), 'the uuid binding suffix is ignored when comparing start times');
        $this->assertSame(1000, EdgeProcessLiveness::tokenSeconds('unix:1000@some-worker-uuid'));
        $this->assertSame('', EdgeProcessLiveness::bareToken('@some-worker-uuid'));
        $this->assertFalse(EdgeProcessLiveness::tokensMatch('unix:1000', 'unix:1003'));
        $this->assertTrue(EdgeProcessLiveness::tokensMatch('opaque-a', 'opaque-a'));
        $this->assertFalse(EdgeProcessLiveness::tokensMatch('opaque-a', 'opaque-b'));
    }
}
