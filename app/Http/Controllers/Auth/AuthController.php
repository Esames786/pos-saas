<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $guard = app()->bound('tenant') ? 'tenant' : 'central';

        if (auth($guard)->attempt($data, $request->boolean('remember'))) {
            $request->session()->regenerate();
            return redirect('/dashboard');
        }

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
            'password'     => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $guard = app()->bound('tenant') ? 'tenant' : 'central';
        $user  = auth($guard)->user();

        if (!$user || !Hash::check($data['old_password'], $user->password)) {
            return back()->withErrors([
                'old_password' => __('auth.old_password_wrong'),
            ]);
        }

        $user->update(['password' => Hash::make($data['password'])]);

        return redirect('/dashboard')->with('status', __('auth.password_updated'));
    }

    public function switchLocale(string $locale)
    {
        if (!in_array($locale, ['en', 'ar'])) {
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
