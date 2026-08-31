<?php

namespace Tests\MySql;

use App\Models\Tenant\RestaurantTableSession;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * TABLE-CLOSE-EMPTY-1 — free a table opened by mistake, without ever discarding a live check.
 *
 * A session with nothing on it had no way out of the POS board: the card offered Continue and Move
 * only, and the close action lived on the standalone restaurant board. Kashif Food's table 1 sat
 * "Occupied" from 13:18 on 31 Aug for exactly that reason — opened, nothing ordered, walked away.
 *
 * The danger the button introduces is the RACE: it was drawn when the board last rendered, and
 * between the render and the click another counter can punch an order onto that same table. So the
 * decision has to be made under a lock at the moment of the close, never on what the screen knew.
 * That is what most of this file tests.
 */
class TableCloseEmptyMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $userId;
    private int $tableId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'sales_order_lines', 'sales_orders', 'restaurant_table_sessions',
            'restaurant_tables', 'restaurant_floors', 'products', 'categories',
            'terminals', 'branches', 'users',
        ]);

        $this->userId = $this->makeUser(['employee_code' => 'TC' . Str::random(4)]);
        $user = User::on('tenant')->find($this->userId);
        $this->actingAs($user, 'tenant');
        Auth::shouldUse('tenant');

        $this->branchId = $this->makeBranch();
        $this->tableId = $this->makeTable($this->branchId, ['table_no' => '1']);
        $this->productId = $this->makeProduct(
            $this->makeCategory(['name' => 'Food', 'slug' => 'f-' . Str::random(4)])
        );
    }

    private function openSession(): RestaurantTableSession
    {
        DB::connection('tenant')->table('restaurant_tables')->where('id', $this->tableId)
            ->update(['status' => 'occupied']);

        $id = DB::connection('tenant')->table('restaurant_table_sessions')->insertGetId([
            'session_no' => 'TS-' . Str::upper(Str::random(10)),
            'branch_id' => $this->branchId, 'restaurant_table_id' => $this->tableId,
            'status' => 'open', 'guest_count' => 1,
            'opened_by_user_id' => $this->userId, 'opened_at' => now(),
            'business_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return RestaurantTableSession::on('tenant')->findOrFail($id);
    }

    private function punchOrder(RestaurantTableSession $session, string $status): int
    {
        $id = $this->makeSale($this->branchId, [
            'status' => $status, 'order_type' => 'dine_in',
            'restaurant_table_session_id' => $session->id,
            'restaurant_table_id' => $this->tableId, 'grand_total' => 750,
        ]);
        $this->makeSaleLine($id, $this->productId, ['quantity' => 1]);

        return $id;
    }

    /** Call the close action the way the POS board does — as JSON. */
    private function close(RestaurantTableSession $session)
    {
        $req = \Illuminate\Http\Request::create(
            '/restaurant/table-sessions/' . $session->id . '/close', 'POST', ['status' => 'closed']
        );
        $req->headers->set('Accept', 'application/json');
        $req->setUserResolver(fn () => User::on('tenant')->find($this->userId));
        app()->instance('request', $req);

        return app(\App\Http\Controllers\Tenant\RestaurantTableSessionController::class)->close($req, $session);
    }

    private function tableStatus(): string
    {
        return (string) DB::connection('tenant')->table('restaurant_tables')
            ->where('id', $this->tableId)->value('status');
    }

    /** The case the button exists for: opened by mistake, nothing on it. */
    public function test_an_empty_session_closes_and_frees_the_table(): void
    {
        $session = $this->openSession();

        $res = $this->close($session);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertTrue((bool) $res->getData()->ok);
        $this->assertSame('closed', (string) $session->refresh()->status);
        $this->assertNotNull($session->closed_at);
        $this->assertSame('available', $this->tableStatus());
    }

    /**
     * THE race the owner named: the board drew the button on an empty table, another counter
     * punched an order, and only then was Close pressed. The server must refuse.
     */
    public function test_an_order_punched_after_the_board_rendered_blocks_the_close(): void
    {
        $session = $this->openSession();
        $this->punchOrder($session, 'held');          // the other counter, after the render

        $res = $this->close($session);

        $this->assertSame(422, $res->getStatusCode(), 'the close must be refused');
        $this->assertStringContainsString('open order', (string) $res->getData()->message);
        $this->assertSame('open', (string) $session->refresh()->status, 'the session survives');
        $this->assertSame('occupied', $this->tableStatus(), 'and so does the table');
    }

    /** A draft check counts too — it is someone's work in progress. */
    public function test_a_draft_order_also_blocks_the_close(): void
    {
        $session = $this->openSession();
        $this->punchOrder($session, 'draft');

        $this->assertSame(422, $this->close($session)->getStatusCode());
        $this->assertSame('occupied', $this->tableStatus());
    }

    /** A paid bill does NOT block: closing after payment is the normal end of a check. */
    public function test_a_paid_bill_does_not_block_the_close(): void
    {
        $session = $this->openSession();
        $this->punchOrder($session, 'paid');

        $res = $this->close($session);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('available', $this->tableStatus());
    }

    /** Two cashiers pressing Close at once must not both "succeed". */
    public function test_closing_an_already_closed_session_is_refused(): void
    {
        $session = $this->openSession();
        $this->close($session);

        $res = $this->close($session->refresh());

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('already closed', (string) $res->getData()->message);
    }

    /**
     * The POS board calls this with fetch(). A redirect would read as success to it and the cashier
     * would watch the table stay occupied with no reason shown — so a refusal must be JSON.
     */
    public function test_a_refusal_comes_back_as_json_for_the_pos_board(): void
    {
        $session = $this->openSession();
        $this->punchOrder($session, 'held');

        $res = $this->close($session);

        $this->assertInstanceOf(\Illuminate\Http\JsonResponse::class, $res,
            'the board cannot show a reason it never receives');
        $this->assertFalse((bool) $res->getData()->ok);
    }

    /**
     * The Table Board's "Cancel" posts to this same action with status=cancelled, and it is NOT
     * disabled the way "Close (Paid)" is. It must be refused just as hard when a check is running —
     * otherwise that button, and not the POS, becomes the way to make a live order's table vanish.
     *
     * Note what this proves about the PIN: the board's Cancel can only ever end a session that holds
     * nothing unpaid, so it destroys no order and no money. The POS cancel is a different act — it
     * kills food already sent to the kitchen — which is why that one asks for a manager and this one
     * does not.
     */
    public function test_the_boards_cancel_is_refused_over_a_live_check_too(): void
    {
        $session = $this->openSession();
        $this->punchOrder($session, 'held');

        $req = \Illuminate\Http\Request::create(
            '/restaurant/table-sessions/' . $session->id . '/close', 'POST', ['status' => 'cancelled']
        );
        $req->headers->set('Accept', 'application/json');
        $req->setUserResolver(fn () => User::on('tenant')->find($this->userId));
        app()->instance('request', $req);

        $res = app(\App\Http\Controllers\Tenant\RestaurantTableSessionController::class)->close($req, $session);

        $this->assertSame(422, $res->getStatusCode(), 'cancelling must not be a back door around Close');
        $this->assertSame('open', (string) $session->refresh()->status);
        $this->assertSame('occupied', $this->tableStatus());
    }

    /** With nothing unpaid on it, the board's Cancel does its job. */
    public function test_the_boards_cancel_ends_an_empty_session(): void
    {
        $session = $this->openSession();

        $req = \Illuminate\Http\Request::create(
            '/restaurant/table-sessions/' . $session->id . '/close', 'POST', ['status' => 'cancelled']
        );
        $req->headers->set('Accept', 'application/json');
        $req->setUserResolver(fn () => User::on('tenant')->find($this->userId));
        app()->instance('request', $req);

        $res = app(\App\Http\Controllers\Tenant\RestaurantTableSessionController::class)->close($req, $session);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('cancelled', (string) $session->refresh()->status);
        $this->assertSame('available', $this->tableStatus());
    }

    /** The card must not offer Close once anything is on the session. */
    public function test_the_board_only_offers_close_on_an_empty_session(): void
    {
        $blade = file_get_contents(resource_path('views/tenant/pos/partials/table-board.blade.php'));

        $this->assertStringContainsString('$hasAnyOrder = $session->salesOrders->isNotEmpty()', $blade);
        $this->assertStringContainsString('@if(! $hasAnyOrder)', $blade,
            'the button is hidden the moment an order exists — the server still re-checks');
        $this->assertStringContainsString("@can('tenant.restaurant.table-sessions.close')", $blade);
    }
}
