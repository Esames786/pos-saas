<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Edge\Concerns\ResolvesEdgePosContext;
use App\Models\Tenant\Customer;
use App\Services\Edge\EdgeAuthorityService;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgeCloudCapabilities;
use App\Services\Edge\EdgeLocalCustomerService;
use App\Services\Edge\EdgePosRuntimeFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * EDGE PHASE 4 — OFFLINE ADD CUSTOMER: the Branch Server twins of Online's POST /pos/customers/quick-store and
 * POST /pos/customers/{customer}/addresses (CustomerController::quickStore / storeAddress). Same request contract, same
 * response shape ({ok, reused, customer{…, addresses[]}} / {ok, address}) so the ONE shared cashier view's quick-add
 * modal works unchanged — on Edge it simply becomes enabled (EdgePosRuntimeFactory capabilities customerCreate /
 * customerAddressCreate follow the Cloud advertisement).
 *
 * Three gates, in order, on every action:
 *   1. the Online route permission `tenant.pos.customers.quick-store` (403 {message, permission} — W0b parity);
 *   2. the Cloud capability advertisement `customer_create` (EdgeCloudCapabilities) — 422 {ok:false, capabilityOff:true,
 *      code: CLOUD_CAPABILITY_NOT_ADVERTISED}: a customer added here must be able to reach the Cloud as a `new_customer`
 *      envelope; without the advertisement the appliance never emits that kind, so it never creates the customer either;
 *   3. the branch-authority lease (EdgeAuthorityService::assertLocalMutationAllowed — the SAME gate every Edge mutation
 *      uses): a standby appliance refuses with the business message (422 {ok:false, code: STANDBY}).
 */
class EdgeLocalCustomerController extends Controller
{
    use ResolvesEdgePosContext;

    public const PERMISSION = 'tenant.pos.customers.quick-store';

    public const CODE_CAPABILITY_OFF = 'CLOUD_CAPABILITY_NOT_ADVERTISED';

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly EdgeLocalCustomerService $customers,
        private readonly EdgeCloudCapabilities $cloud,
        private readonly EdgeAuthorityService $authority,
    ) {
    }

    /** POST /edge/local/pos/customers/quick-store — Online CustomerController::quickStore contract. */
    public function quickStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);
        if ($refused = $this->gate('customerCreate')) {
            return $refused;
        }

        try {
            [$customer, $reused] = $this->customers->quickStore($data);
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'reused' => $reused,
            'customer' => $customer->load('addresses'),
        ]);
    }

    /** POST /edge/local/pos/customers/{customer}/addresses — Online CustomerController::storeAddress contract. */
    public function storeAddress(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:50'],
            'address' => ['required', 'string', 'max:500'],
            'is_default' => ['nullable', 'boolean'],
        ]);
        if ($refused = $this->gate('customerAddressCreate')) {
            return $refused;
        }

        try {
            $address = $this->customers->storeAddress($customer, $data['address'], $data['label'] ?? null, ! empty($data['is_default']));
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'address' => $address]);
    }

    /** Permission → capability advertisement → branch authority (see the class doc). */
    private function gate(string $capability): ?JsonResponse
    {
        if ($denied = $this->denyUnlessCan(self::PERMISSION, 'Adding a customer or address needs the Add Customer permission.')) {
            return $denied;
        }
        if (! $this->cloud->customerCreate()) {
            return response()->json([
                'ok' => false,
                'capabilityOff' => true,
                'code' => self::CODE_CAPABILITY_OFF,
                'message' => EdgePosRuntimeFactory::LABELS[$capability] ?? EdgePosRuntimeFactory::LABELS['customerCreate'],
            ], 422);
        }
        try {
            $this->authority->assertLocalMutationAllowed();
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'code' => 'STANDBY', 'message' => $e->getMessage()], 422);
        }

        return null;
    }
}
