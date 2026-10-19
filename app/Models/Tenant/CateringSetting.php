<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * CATERING-SLICE-1: per-tenant catering settings singleton (branch_id NULL =
 * tenant default row, the manufacturing_posting_settings pattern).
 */
class CateringSetting extends Model
{
    protected $connection = 'tenant';

    public const PRINT_EN = 'en';

    public const PRINT_UR = 'ur';

    public const PRINT_BOTH = 'both';

    public const PRINT_PROFILES = [self::PRINT_EN, self::PRINT_UR, self::PRINT_BOTH];

    public const DEFAULT_REMINDER_OFFSETS = ['d7', 'd3', 'd1', 'same_day'];

    protected $fillable = [
        'branch_id',
        'reminder_recipient_email',
        'send_customer_emails',
        'default_service_charge_percent',
        'print_language_profile',
        'show_kitchen_requirements',
        // KITCHEN-SHEET-PAPER-SETTING-1: har kaghaz ka apna size. Do alag is
        // liye ke ye do MUKHTALIF logon ke liye chhapte hain — kitchen sheet
        // bawarchi ke haath me, quotation graahak ke.
        'kitchen_sheet_paper',
        'quotation_paper',
        'reminder_offsets',
    ];

    /**
     * Jaiz kaghaz — EK fehrist. Screen ka dropdown, validation aur document
     * teenon yahin se parhte hain; teen jagah teen fehristein rakhne ka anjaam
     * ye hota hai ke kisi din naya size sirf do jagah pohnchta hai.
     *
     * @var array<string, string>
     */
    public const PAPER_SIZES = [
        'a5_portrait' => 'A5 khari (148 × 210 mm) — A4 ka aadha, khara chhapta',
        'a5_landscape' => 'A5 chaura (210 × 148 mm)',
        'a4_portrait' => 'A4 lamba (210 × 297 mm)',
        'a4_landscape' => 'A4 chaura (297 × 210 mm)',
    ];

    /** CSS `@page size` ka lafz — browser aur dompdf dono yehi samajhte hain. */
    public static function cssPageSize(?string $paper, string $fallback = 'A4 portrait'): string
    {
        return match ($paper) {
            'a5_landscape' => 'A5 landscape',
            'a5_portrait' => 'A5 portrait',
            'a4_portrait' => 'A4 portrait',
            'a4_landscape' => 'A4 landscape',
            default => $fallback,
        };
    }

    /** Kaghaz ki naap mm me — screen par sheet isi par khinchti hai. */
    public static function paperMm(?string $paper, string $fallback = 'a4_portrait'): array
    {
        return match ($paper ?: $fallback) {
            'a5_landscape' => ['w' => 210, 'h' => 148],
            'a5_portrait' => ['w' => 148, 'h' => 210],
            'a4_landscape' => ['w' => 297, 'h' => 210],
            default => ['w' => 210, 'h' => 297],
        };
    }

    protected function casts(): array
    {
        return [
            'send_customer_emails' => 'boolean',
            'show_kitchen_requirements' => 'boolean',
            'default_service_charge_percent' => 'decimal:4',
            'reminder_offsets' => 'array',
        ];
    }

    /** The tenant-default settings row, created on first access. */
    public static function tenantDefault(): self
    {
        return static::query()->firstOrCreate(
            ['branch_id' => null],
            [
                'reminder_offsets' => self::DEFAULT_REMINDER_OFFSETS,
                // Stated here as well as on the column. firstOrCreate does not
                // read a database default back into the model it returns, so a
                // freshly created row answered NULL to send_customer_emails and
                // every customer email was skipped as "disabled" on a brand-new
                // tenant - the opposite of what the column says.
                'send_customer_emails' => true,
                // Same reason as the line above, and the same trap: firstOrCreate
                // hands back NULL for a column it did not write, and NULL reads
                // as false here — which happens to be the wanted answer, but by
                // accident rather than by the column's own default. Stated so a
                // later change to that default is not silently ignored.
                'show_kitchen_requirements' => false,
            ]
        );
    }
}
