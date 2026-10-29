<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

/**
 * MASTER-HISAB-1 — kis ne kab entry banayi, badli ya hatayi (pehle aur baad).
 */
class CompanyEntryLog extends Model
{
    public const UPDATED_AT = null;

    protected $connection = 'master';

    protected $table = 'company_entry_logs';

    protected $fillable = ['company_entry_id', 'action', 'before', 'after', 'central_user_id'];

    protected $casts = [
        'before' => 'array',
        'after'  => 'array',
    ];
}
