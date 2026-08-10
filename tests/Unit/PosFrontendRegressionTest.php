<?php

namespace Tests\Unit;

use Tests\TestCase;

class PosFrontendRegressionTest extends TestCase
{
    public function test_fresh_product_cart_state_does_not_read_a_recalled_line(): void
    {
        $view = file_get_contents(resource_path('views/tenant/pos/index.blade.php'));

        preg_match(
            '/function addToCart\(.*?\n    }\n\n    \/\/ POS-UX-2/s',
            $view,
            $matches
        );

        $this->assertNotEmpty($matches, 'The addToCart function could not be located.');
        $this->assertStringNotContainsString('line.id', $matches[0]);
        $this->assertStringNotContainsString('line.kot_sent', $matches[0]);
        $this->assertStringContainsString('_dbLineId:          null', $matches[0]);
        $this->assertStringContainsString('kot_sent_quantity:  0', $matches[0]);
    }

    public function test_table_board_is_modal_workspace_and_previews_keep_separate_sources(): void
    {
        $view = file_get_contents(resource_path('views/tenant/pos/index.blade.php'));
        $board = file_get_contents(resource_path('views/tenant/pos/partials/table-board.blade.php'));

        $this->assertStringContainsString('id="tableWorkspaceModal"', $view);
        $this->assertStringContainsString('id="view-tables-btn"', $view);
        $this->assertStringNotContainsString('id="dine-in-board"', $view);
        $this->assertStringContainsString('id="billPreviewModal"', $view);
        $this->assertStringContainsString("function billPreview()", $view);
        $this->assertStringContainsString("function showTableBillPreview(sessionId)", $view);
        $this->assertStringContainsString('function showModalAfterWorkspace(modalElement)', $view);
        $this->assertStringContainsString('id="table-workspace-split"', $view);
        $this->assertStringContainsString('data-management-url', $view);
        $this->assertStringContainsString("headers: { 'Accept': 'application/json' }", $view);
        $this->assertStringContainsString('data-table-bill-preview', $board);
        $this->assertStringNotContainsString('target="_blank"', $board);
        $this->assertStringNotContainsString('data-table-merge', $board);
    }

    public function test_desktop_workspace_uses_internal_product_and_cart_scrollers(): void
    {
        $view = file_get_contents(resource_path('views/tenant/pos/index.blade.php'));

        $this->assertStringContainsString('.cart-items { flex: 1 1 auto; min-height: 0; overflow-y: auto;', $view);
        $this->assertStringContainsString('.pos-products-panel {', $view);
        $this->assertStringContainsString('@media (min-width: 1200px) and (min-height: 720px)', $view);
        $this->assertStringContainsString('height: calc(100vh - 188px);', $view);
        $this->assertStringNotContainsString('body { overflow: hidden', $view);
    }

    public function test_merge_hardening_locks_state_and_preserves_paid_history(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Tenant/RestaurantTableSessionController.php'));

        $this->assertGreaterThanOrEqual(4, substr_count($controller, 'lockForUpdate()'));
        $this->assertStringContainsString("whereIn('status', ['held', 'draft'])", $controller);
        $this->assertStringContainsString('Paid fiscal history intentionally remains attached', $controller);
        $this->assertStringContainsString('The source table session changed.', $controller);
        $this->assertStringContainsString('The destination table session changed.', $controller);
    }

    public function test_move_rechecks_session_and_tables_under_locks_for_ajax_workspace(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Tenant/RestaurantTableSessionController.php'));

        $this->assertStringContainsString("RestaurantTableSession::whereKey(\$restaurantTableSession->id)", $controller);
        $this->assertStringContainsString("collect([\$sessionLocked->restaurant_table_id, \$targetTable->id])->sort()", $controller);
        $this->assertStringContainsString("'The table state changed while moving. Refresh and try again.'", $controller);
    }
    public function test_pos_mutations_share_a_visible_double_submit_guard(): void
    {
        $view = file_get_contents(resource_path('views/tenant/pos/index.blade.php'));

        $this->assertStringContainsString('function setButtonBusy(button, busy, label)', $view);
        $this->assertStringContainsString('spinner-border spinner-border-sm', $view);
        $this->assertStringContainsString("setButtonBusy(submitBtn, true, 'Completing sale')", $view);
        $this->assertStringContainsString("setButtonBusy(holdBtn, true, 'Saving order')", $view);
        $this->assertStringContainsString("setButtonBusy(saveAddrBtn, true, 'Saving address')", $view);
        $this->assertStringContainsString("setButtonBusy(btn, true, 'Saving customer')", $view);
        $this->assertStringContainsString("setButtonBusy(openBtn, true, 'Opening table')", $view);
        $this->assertStringContainsString("setButtonBusy(cancelBtn, true, 'Cancelling order')", $view);
        $this->assertStringContainsString("setButtonBusy(applyBtn, true, 'Applying')", $view);
        $this->assertStringContainsString("setButtonBusy(previewBtn, true, 'Building preview')", $view);
        $this->assertStringContainsString("setButtonBusy(btn, true, 'Sending')", $view);
        $this->assertStringContainsString("setButtonBusy(btn, true, 'Reprinting KOT')", $view);
        $this->assertStringContainsString('b.disabled = empty || buttonIsBusy(b);', $view);
    }

    public function test_completed_order_receipt_action_is_an_explicit_reprint(): void
    {
        $view = file_get_contents(resource_path('views/tenant/pos/index.blade.php'));

        preg_match('/function reprintSale\(saleId, type, btn\).*?\n    }/s', $view, $matches);

        $this->assertNotEmpty($matches, 'The completed-order reprint function could not be located.');
        $this->assertStringContainsString("q   = '?reprint=1'", $matches[0]);
        $this->assertSame(2, substr_count($matches[0], "'?reprint=1'"));
    }

    public function test_pos_adjacent_endpoints_apply_user_data_scope(): void
    {
        $tables = file_get_contents(app_path('Http/Controllers/Tenant/RestaurantTableSessionController.php'));
        $prints = file_get_contents(app_path('Http/Controllers/Tenant/PrintJobController.php'));
        $shifts = file_get_contents(app_path('Http/Controllers/Tenant/ShiftController.php'));

        $this->assertStringContainsString('function assertSessionAccess', $tables);
        $this->assertGreaterThanOrEqual(6, substr_count($tables, '$this->assertSessionAccess('));
        $this->assertStringContainsString('branchesForPos', $tables);
        $this->assertStringContainsString('terminalsForPos', $tables);
        $this->assertStringContainsString('function assertSaleAccess', $prints);
        $this->assertStringContainsString('function assertPrintJobAccess', $prints);
        $this->assertStringContainsString("->whereIn('terminal_id', \$terminalIds)", $prints);
        $this->assertStringContainsString("->whereIn('sales_orders.order_type', \$orderTypes)", $prints);
        $this->assertStringContainsString('assertPosSelection', $shifts);
    }

    public function test_switching_customers_cannot_keep_the_previous_delivery_address(): void
    {
        $view = file_get_contents(resource_path('views/tenant/pos/index.blade.php'));

        $this->assertStringContainsString("'customer_phone', 'delivery_address'", $view);
        $this->assertStringContainsString("if (addrEl) addrEl.value = address || '';", $view);
    }
}
