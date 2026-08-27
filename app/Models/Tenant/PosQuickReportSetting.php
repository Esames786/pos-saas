<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * QUICK-REPORT-SEND-1 — one row per tenant user: their saved Quick Report modal defaults.
 */
class PosQuickReportSetting extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['user_id', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
