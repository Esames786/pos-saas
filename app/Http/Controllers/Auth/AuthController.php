<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

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

    public function logout(Request $request)
    {
        $guard = app()->bound('tenant') ? 'tenant' : 'central';

        auth($guard)->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
