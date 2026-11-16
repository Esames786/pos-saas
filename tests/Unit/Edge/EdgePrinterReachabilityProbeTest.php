<?php

namespace Tests\Unit\Edge;

use App\Services\Edge\EdgeNetworkPrinterTransport;
use App\Services\Edge\EdgePrinterReachabilityProbe;
use App\Services\Edge\EdgePrinterTransportException;
use Tests\TestCase;

/**
 * EDGE 0.8.3 (F-3) — printer reachability classification against REAL sockets on loopback (a listening socket, the
 * discard port 9 that nothing serves → refused) plus the errno matrix, and the transport's classified exception.
 * Nothing here ever touches port 9100.
 */
class EdgePrinterReachabilityProbeTest extends TestCase
{
    public function test_a_listening_loopback_socket_is_reachable_and_nothing_is_written_to_it(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, $errstr);
        $port = (int) substr(stream_socket_get_name($server, false), strrpos(stream_socket_get_name($server, false), ':') + 1);
        try {
            $r = app(EdgePrinterReachabilityProbe::class)->probe('127.0.0.1', $port, 1.0);
            $this->assertSame(EdgePrinterReachabilityProbe::REACHABLE, $r['status'], $r['detail']);
            $this->assertNull($r['errno']);
            stream_set_blocking($server, false);
            $client = @stream_socket_accept($server, 1);
            if (is_resource($client)) {
                stream_set_blocking($client, false);
                usleep(100_000);
                $this->assertSame('', (string) fread($client, 1024), 'a probe never writes a byte to a printer');
                fclose($client);
            }
        } finally {
            fclose($server);
        }
    }

    public function test_a_closed_loopback_port_is_refused_within_the_default_bound(): void
    {
        // Windows reports a refused connect only after its SYN retransmit (measured 2.0 s on the dev host) — the reason the
        // default bound is 3 s, and the reason a caller-supplied 0.5 s is raised to MIN_TIMEOUT_SECONDS instead of misreporting "timeout".
        $t0 = microtime(true);
        $r = app(EdgePrinterReachabilityProbe::class)->probe('127.0.0.1', 9, 0.5); // discard port: nothing listens on this host
        $this->assertSame(EdgePrinterReachabilityProbe::REFUSED, $r['status'], $r['detail']);
        $this->assertLessThan(EdgePrinterReachabilityProbe::DEFAULT_TIMEOUT_SECONDS + 0.5, microtime(true) - $t0, 'a refusal must not wait for the full timeout');
        $this->assertStringContainsString('refused', $r['detail']);
    }

    public function test_the_errno_matrix_classifies_windows_and_posix_codes_and_falls_back_to_wording_and_elapsed_time(): void
    {
        $c = fn (int $errno, string $msg = '', int $ms = 0, float $t = 1.0) => EdgePrinterReachabilityProbe::classify($errno, $msg, $ms, $t);
        $this->assertSame('refused', $c(10061));
        $this->assertSame('refused', $c(111));
        $this->assertSame('timeout', $c(10060));
        $this->assertSame('timeout', $c(110));
        $this->assertSame('unreachable', $c(10065));
        $this->assertSame('unreachable', $c(113));
        $this->assertSame('unresolved', $c(11001));
        $this->assertSame('unresolved', $c(-2, 'getaddrinfo failed'));
        $this->assertSame('timeout', $c(0, 'Connection timed out'));
        $this->assertSame('timeout', $c(0, 'A connection attempt failed because the connected party did not properly respond after a period of time'));
        $this->assertSame('refused', $c(0, 'No connection could be made because the target machine actively refused it'));
        $this->assertSame('timeout', $c(0, 'odd message', 1000, 1.0), 'errno 0 + elapsed >= timeout = timeout');
        $this->assertSame('error', $c(0, 'odd message', 10, 1.0));
    }

    public function test_the_transport_throws_a_classified_exception_whose_message_starts_with_the_category(): void
    {
        try {
            (new EdgeNetworkPrinterTransport)->send('127.0.0.1', 9, "X\n");
            $this->fail('port 9 must refuse');
        } catch (EdgePrinterTransportException $e) {
            $this->assertSame(EdgePrinterReachabilityProbe::REFUSED, $e->category());
            $this->assertTrue($e->isConnectFailure());
            $this->assertStringStartsWith('[refused] printer connect failed [127.0.0.1:9]', $e->getMessage());
            // G4 guard: the Edge error text must never match the Cloud agent's auto-requeue needles.
            foreach (['connection timed out', 'econnrefused', 'ehostunreach', 'enetunreach', 'etimedout', 'econnreset', 'socket hang up'] as $needle) {
                $this->assertStringNotContainsString($needle, strtolower($e->getMessage()), "needle [$needle] would auto-requeue a terminal job");
            }
        }
    }

    public function test_a_timeout_is_classified_as_timeout_or_unreachable_never_refused(): void
    {
        // 192.0.2.0/24 is TEST-NET-1 (RFC 5737): never routed; a SYN there gets no answer or an ICMP unreachable.
        $r = app(EdgePrinterReachabilityProbe::class)->probe('192.0.2.1', 9100, 0.5);
        $this->assertContains($r['status'], [EdgePrinterReachabilityProbe::TIMEOUT, EdgePrinterReachabilityProbe::UNREACHABLE], json_encode($r));
    }

    public function test_the_fake_seam_replaces_the_socket_probe(): void
    {
        EdgePrinterReachabilityProbe::fake(fn ($ip, $port) => ['status' => 'timeout', 'latency_ms' => 1000, 'errno' => 10060, 'detail' => 'fake', 'ip' => $ip, 'port' => $port, 'probed_at' => 'x']);
        try {
            $this->assertSame('timeout', app(EdgePrinterReachabilityProbe::class)->probe('10.9.9.9', 9100)['status']);
        } finally {
            EdgePrinterReachabilityProbe::fake(null);
        }
    }
}
