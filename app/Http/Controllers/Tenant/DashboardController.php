<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;

class DashboardController extends Controller
{
    public function __invoke()
    {
        return view('tenant.dashboard');
    }
}
