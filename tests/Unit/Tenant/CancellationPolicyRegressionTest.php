<?php

namespace Tests\Unit\Tenant;

use App\Models\Tenant\Branch;
use App\Services\Printing\PrintJobService;
use App\Services\Sales\KotCancellationService;
use App\Services\Sales\ManagerApprovalService;
use ReflectionMethod;
use Tests\TestCase;

class CancellationPolicyRegressionTest extends TestCase
{
    public function test_whole_order_and_line_reduction_use_separate_branch_modes(): void
    {
        $branch = new Branch([
            'held_kot_cancellation_approval_mode' => Branch::KOT_CANCELLATION_MANAGER_REQUIRED,
            'held_kot_line_cancellation_approval_mode' => Branch::KOT_CANCELLATION_AUTO_APPROVE,
        ]);
        $service = new KotCancellationService(
            $this->createMock(ManagerApprovalService::class),
            $this->createMock(PrintJobService::class),
        );
        $method = new ReflectionMethod($service, 'cancellationMode');

        $this->assertSame(Branch::KOT_CANCELLATION_MANAGER_REQUIRED, $method->invoke($service, $branch, 'order'));
        $this->assertSame(Branch::KOT_CANCELLATION_AUTO_APPROVE, $method->invoke($service, $branch, 'line'));
    }

    public function test_pos_uses_line_mode_and_accepts_the_seeded_manager_code(): void
    {
        $view = file_get_contents(resource_path('views/tenant/pos/index.blade.php'));
        $userController = file_get_contents(app_path('Http/Controllers/Tenant/TenantUserController.php'));

        $this->assertStringContainsString('const branchLineCancellationModes =', $view);
        $this->assertGreaterThanOrEqual(2, substr_count($view, "currentCancellationMode('line')"));
        $this->assertStringContainsString("const lineRequiresPin = currentCancellationMode('line') !== 'auto_approve';", $view);
        $this->assertStringContainsString('id="swal-manager-pin" class="swal2-input" placeholder="Manager code" maxlength="64"', $view);
        $this->assertStringNotContainsString('inputmode="numeric" maxlength="8"', $view);
        $this->assertStringContainsString("'pin'             => ['required', 'string', 'min:4', 'max:64']", $userController);
    }
}
