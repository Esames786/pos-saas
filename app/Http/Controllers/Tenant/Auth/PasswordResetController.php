<?php

namespace App\Http\Controllers\Tenant\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

/**
 * Self-service password reset for tenant users (PRD-5).
 *
 * Runs entirely on the tenant subdomain, so the default DB connection is the
 * tenant connection and the `tenant_users` broker reads/writes the tenant's own
 * password_reset_tokens table + users. Always returns a generic message to avoid
 * user enumeration.
 */
class PasswordResetController extends Controller
{
    private const GENERIC = 'If that email is registered, a password reset link has been sent.';

    public function showLinkRequest()
    {
        return view('tenant.auth.forgot-password');
    }

    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        // Never reveal whether the address exists; never let mail errors surface.
        try {
            Password::broker('tenant_users')->sendResetLink(['email' => $request->email]);
        } catch (Throwable $e) {
            report($e);
        }

        return back()->with('status', self::GENERIC);
    }

    public function showReset(Request $request, string $token)
    {
        return view('tenant.auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'token'    => ['required'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::broker('tenant_users')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) {
                $user->forceFill([
                    'password'       => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PasswordReset) {
            return redirect('/login')->with('status', 'Your password has been reset. Please log in.');
        }

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => __($status)]);
    }
}
