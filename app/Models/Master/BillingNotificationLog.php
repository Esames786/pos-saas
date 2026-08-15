<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

/**
 * CLOUD-BILLING-3A — one row per billing email that has been claimed/sent (at-most-once).
 */
class BillingNotificationLog extends Model
{
    protected $connection = 'master';

    protected $table = 'billing_notification_log';

    protected $fillable = [
        'event',
        'subject_type',
        'subject_id',
        'recipient',
        'sent_at',
    ];

    protected $casts = [
        'subject_id' => 'integer',
        'sent_at' => 'datetime',
    ];
}
