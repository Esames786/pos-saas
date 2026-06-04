<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\ManagerPin;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class TenantUserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with(['defaultBranch', 'roles'])->latest();

        if ($request->filled('search')) {
            $term = '%' . trim($request->search) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                  ->orWhere('email', 'like', $term)
                  ->orWhere('phone', 'like', $term)
                  ->orWhere('employee_code', 'like', $term);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $users = $query->paginate(20)->withQueryString();

        return view('tenant.users.index', compact('users'));
    }

    public function create()
    {
        $branches  = Branch::where('status', 'active')->orderBy('name')->get();
        $terminals = Terminal::where('status', 'active')->orderBy('name')->get();
        $roles     = Role::where('guard_name', 'tenant')->orderBy('name')->get();

        return view('tenant.users.form', compact('branches', 'terminals', 'roles'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'                  => 'required|string|max:100',
            'email'                 => 'required|email|max:150|unique:tenant.users,email',
            'employee_code'         => 'nullable|string|max:50|unique:tenant.users,employee_code',
            'phone'                 => 'nullable|string|max:30',
            'default_branch_id'     => 'nullable|exists:tenant.branches,id',
            'default_terminal_id'   => 'nullable|exists:tenant.terminals,id',
            'status'                => 'required|in:active,inactive',
            'force_password_change' => 'nullable|boolean',
            'password'              => ['required', Password::min(8)],
            'branch_ids'            => 'nullable|array',
            'branch_ids.*'          => 'exists:tenant.branches,id',
            'terminal_ids'          => 'nullable|array',
            'terminal_ids.*'        => 'exists:tenant.terminals,id',
            'roles'                 => 'nullable|array',
            'roles.*'               => 'string',
        ]);

        DB::connection('tenant')->transaction(function () use ($data) {
            $user = User::create([
                'name'                  => $data['name'],
                'email'                 => $data['email'],
                'employee_code'         => $data['employee_code'] ?? null,
                'phone'                 => $data['phone'] ?? null,
                'default_branch_id'     => $data['default_branch_id'] ?? null,
                'default_terminal_id'   => $data['default_terminal_id'] ?? null,
                'status'                => $data['status'],
                'force_password_change' => (bool) ($data['force_password_change'] ?? false),
                'password'              => Hash::make($data['password']),
            ]);

            $this->syncAccess($user, $data);
        });

        return redirect(url('/users'))->with('status', 'User created.');
    }

    public function show(User $user)
    {
        $user->load(['defaultBranch', 'defaultTerminal', 'branches', 'terminals', 'roles']);

        return view('tenant.users.show', compact('user'));
    }

    public function edit(User $user)
    {
        $user->load(['branches', 'terminals', 'roles']);
        $branches  = Branch::where('status', 'active')->orderBy('name')->get();
        $terminals = Terminal::where('status', 'active')->orderBy('name')->get();
        $roles     = Role::where('guard_name', 'tenant')->orderBy('name')->get();

        return view('tenant.users.form', compact('user', 'branches', 'terminals', 'roles'));
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name'                  => 'required|string|max:100',
            'email'                 => 'required|email|max:150|unique:tenant.users,email,' . $user->id,
            'employee_code'         => 'nullable|string|max:50|unique:tenant.users,employee_code,' . $user->id,
            'phone'                 => 'nullable|string|max:30',
            'default_branch_id'     => 'nullable|exists:tenant.branches,id',
            'default_terminal_id'   => 'nullable|exists:tenant.terminals,id',
            'status'                => 'required|in:active,inactive',
            'force_password_change' => 'nullable|boolean',
            'branch_ids'            => 'nullable|array',
            'branch_ids.*'          => 'exists:tenant.branches,id',
            'terminal_ids'          => 'nullable|array',
            'terminal_ids.*'        => 'exists:tenant.terminals,id',
            'roles'                 => 'nullable|array',
            'roles.*'               => 'string',
        ]);

        DB::connection('tenant')->transaction(function () use ($data, $user) {
            $user->update([
                'name'                  => $data['name'],
                'email'                 => $data['email'],
                'employee_code'         => $data['employee_code'] ?? null,
                'phone'                 => $data['phone'] ?? null,
                'default_branch_id'     => $data['default_branch_id'] ?? null,
                'default_terminal_id'   => $data['default_terminal_id'] ?? null,
                'status'                => $data['status'],
                'force_password_change' => (bool) ($data['force_password_change'] ?? false),
            ]);

            $this->syncAccess($user, $data);
        });

        return redirect(url('/users/' . $user->id))->with('status', 'User updated.');
    }

    public function resetPassword(Request $request, User $user)
    {
        $data = $request->validate([
            'new_password'                  => ['required', Password::min(8), 'confirmed'],
            'new_password_confirmation'     => 'required',
        ]);

        $user->update([
            'password'              => Hash::make($data['new_password']),
            'force_password_change' => true,
        ]);

        return redirect(url('/users/' . $user->id))
            ->with('status', 'Password reset. User must change it on next login.');
    }

    public function activate(Request $request, User $user)
    {
        if ($user->id === auth('tenant')->id()) {
            return back()->withErrors(['user' => 'You cannot change your own status.']);
        }

        $newStatus = $user->status === 'active' ? 'inactive' : 'active';
        $user->update(['status' => $newStatus]);

        return redirect(url('/users/' . $user->id))
            ->with('status', 'User ' . $newStatus . '.');
    }

    public function destroy(User $user)
    {
        if ($user->id === auth('tenant')->id()) {
            return back()->withErrors(['user' => 'You cannot deactivate your own account.']);
        }

        $user->update(['status' => 'inactive']);

        return redirect(url('/users'))->with('status', 'User deactivated.');
    }

    public function managerPinForm(User $user)
    {
        $hasPin = ManagerPin::where('user_id', $user->id)->where('is_active', true)->exists();
        return view('tenant.users.manager-pin', compact('user', 'hasPin'));
    }

    public function managerPinStore(Request $request, User $user)
    {
        $request->validate([
            'pin'             => ['required', 'string', 'min:4', 'max:8', 'regex:/^\d+$/'],
            'pin_confirmation' => ['required', 'same:pin'],
        ]);

        ManagerPin::updateOrCreate(
            ['user_id' => $user->id],
            [
                'pin_hash'  => Hash::make($request->pin),
                'is_active' => true,
            ]
        );

        return redirect(url('/users/' . $user->id))
            ->with('status', 'Manager PIN set successfully for ' . $user->name . '.');
    }

    private function syncAccess(User $user, array $data): void
    {
        $defaultBranchId   = isset($data['default_branch_id']) ? (int) $data['default_branch_id'] : null;
        $defaultTerminalId = isset($data['default_terminal_id']) ? (int) $data['default_terminal_id'] : null;

        $branchIds = array_unique(array_filter(array_map('intval', array_merge(
            $data['branch_ids'] ?? [],
            $defaultBranchId ? [$defaultBranchId] : []
        ))));

        $terminalIds = array_unique(array_filter(array_map('intval', array_merge(
            $data['terminal_ids'] ?? [],
            $defaultTerminalId ? [$defaultTerminalId] : []
        ))));

        $branchSync = [];
        foreach ($branchIds as $id) {
            $branchSync[$id] = [
                'is_default' => ($id === $defaultBranchId),
                'is_active'  => true,
            ];
        }
        $user->branches()->sync($branchSync);

        $terminalSync = [];
        foreach ($terminalIds as $id) {
            $terminalSync[$id] = [
                'is_default' => ($id === $defaultTerminalId),
            ];
        }
        $user->terminals()->sync($terminalSync);

        $user->syncRoles($data['roles'] ?? []);
    }
}
