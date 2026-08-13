<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    /** PROD-READINESS-1: brute-force lockout — attempts per key before lockout. */
    private const LOGIN_MAX_ATTEMPTS = 5;

    /** Seconds an exhausted key stays locked. */
    private const LOGIN_DECAY_SECONDS = 120;

    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $guard = app()->bound('tenant') ? 'tenant' : 'central';

        // PROD-READINESS-1: throttle by email+IP (+guard, so a tenant flood
        // cannot lock the central panel). Applies BEFORE the attempt so
        // password guessing burns the limiter, and the message never reveals
        // whether the email exists.
        $throttleKey = 'login|'.$guard.'|'.Str::lower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::LOGIN_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()
                ->withErrors(['email' => __('Too many login attempts. Please try again in :seconds seconds.', ['seconds' => $seconds])])
                ->withInput(['email' => $request->email]);
        }

        if (auth($guard)->attempt($data, $request->boolean('remember'))) {
            RateLimiter::clear($throttleKey);
            $request->session()->regenerate();

            return redirect('/dashboard');
        }

        RateLimiter::hit($throttleKey, self::LOGIN_DECAY_SECONDS);

        return back()
            ->withErrors(['email' => __('auth.failed')])
            ->withInput(['email' => $request->email]);
    }

    public function showChangePassword()
    {
        return view('auth.change-password');
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'old_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $guard = app()->bound('tenant') ? 'tenant' : 'central';
        $user = auth($guard)->user();

        if (! $user || ! Hash::check($data['old_password'], $user->password)) {
            return back()->withErrors([
                'old_password' => __('auth.old_password_wrong'),
            ]);
        }

        $user->update(['password' => Hash::make($data['password'])]);

        return redirect('/dashboard')->with('status', __('auth.password_updated'));
    }

    /**
     * SHIFT-TIMEZONE-BUSINESS-DATE-1 (C): set the current user's DISPLAY timezone — used only for
     * rendering timestamps in the portal. It never affects a shift's business date, which is
     * anchored to the branch business timezone at open. Empty clears the override (falls back to
     * branch -> Asia/Karachi). IANA identifiers only; numeric offsets are rejected.
     */
    public function updateTimezone(Request $request)
    {
        $data = $request->validate([
            'timezone' => ['nullable', 'string', Rule::in(timezone_identifiers_list())],
        ]);

        $guard = app()->bound('tenant') ? 'tenant' : 'central';
        $user = auth($guard)->user();

        if ($user) {
            $user->update(['timezone' => $data['timezone'] ?: null]);
        }

        return back()->with('status', 'Display timezone updated.');
    }

    public function switchLocale(string $locale)
    {
        if (! in_array($locale, ['en', 'ar', 'ur'])) {
            $locale = 'en';
        }

        session(['locale' => $locale]);

        return back();
    }

    public function logout(Request $request)
    {
        $guard = app()->bound('tenant') ? 'tenant' : 'central';

        auth($guard)->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
