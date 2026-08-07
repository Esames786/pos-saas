<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgeLocalAuthService;
use Illuminate\Http\Request;
use Throwable;

/**
 * EDGE-LOCAL-AUTH-1 (Section 9) — Branch-Server-only local login/logout/status. These routes exist
 * ONLY on a branch_server (registered in routes/edge_runtime.php, on the Edge route allowlist). They
 * authenticate via the Edge credential (EdgeLocalAuthService) — never the Cloud users.password — and
 * establish the standard `tenant` session. They do NOT open /pos.
 */
class EdgeLocalAuthController extends Controller
{
    public function __construct(
        private readonly EdgeLocalAuthService $auth,
        private readonly EdgeBranchContext $context,
    ) {
    }

    public function showLogin()
    {
        if (auth('tenant')->check()) {
            return redirect('/edge/local/status');
        }

        return view('edge.auth.login', ['branchId' => $this->context->boundBranchId()]);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'employee_code' => ['required', 'string', 'max:50'],
            'credential' => ['required', 'string', 'max:255'],
        ]);

        try {
            $user = $this->auth->verifyForLogin($data['employee_code'], $data['credential'], $request->ip());
        } catch (Throwable $e) {
            return back()->withErrors(['login' => $e->getMessage()])->withInput($request->only('employee_code'));
        }

        $this->auth->login($user);
        $request->session()->regenerate(); // migrate session id, keep auth — prevents fixation

        return redirect('/edge/local/status');
    }

    public function logout(Request $request)
    {
        $uid = auth('tenant')->id();
        $this->auth->logout($uid, $request->ip());
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/edge/local/login');
    }

    /** Authenticated non-secret status — proves roles/permissions/order-types resolve after local login. */
    public function status(Request $request)
    {
        $user = auth('tenant')->user();

        return response()->json([
            'authenticated' => true,
            'runtime_mode' => 'branch_server',
            'csrf_token' => csrf_token(), // the authenticated session's own token (for logout / SPA)
            'user' => [
                'id' => $user->id,
                'employee_code' => $user->employee_code,
                'name' => $user->name,
            ],
            'branch_id' => $this->context->boundBranchId(),
            'activation_epoch' => $this->context->activationEpoch(),
            'roles' => $user->getRoleNames()->values(),
            'permissions_count' => $user->getAllPermissions()->count(),
            'allowed_order_types' => $user->effectiveAllowedOrderTypes(),
            'default_order_type' => $user->effectiveDefaultOrderType(),
        ]);
    }
}
