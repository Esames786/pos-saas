<?php

namespace App\Services\Printing;

use App\Models\Tenant\CategoryPrinterMapping;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Sales\ShiftService;
use App\Support\TenantClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POS-REMINDER-PAUSE-1 — one terminal's Reminder slips, paused until its shift closes.
 *
 * The pause is a stamp on the terminal's OPEN shift. Closing the shift ends it: the next shift row
 * starts clean, so a switch forgotten OFF cannot carry into tomorrow. Every reader of the rule goes
 * through this class — the KOT reminder plan, the cancellation reminder, the POS badge.
 */
class ReminderPauseService
{
    public const PERMISSION = 'tenant.pos.pause-reminders';

    public function __construct(
        private readonly ShiftService $shifts,
        private readonly TenantClock $clock,
    ) {}

    /** Is the open shift of this terminal pausing its Reminder slips? */
    public function isPausedForTerminal(int|string|null $terminalId): bool
    {
        if (! $terminalId) {
            return false;
        }

        try {
            $shift = Shift::where('terminal_id', (int) $terminalId)
                ->where('status', 'open')
                ->latest('id')
                ->first(['id', 'reminders_paused_at']);
        } catch (\Throwable $e) {
            // Fail OPEN: if the pause cannot be read, Reminder prints as it always did. An extra
            // slip costs nothing; a silently missing one is the failure this feature must not add.
            Log::warning('Reminder pause could not be read; printing as usual.', [
                'terminal_id' => $terminalId, 'error' => $e->getMessage(),
            ]);

            return false;
        }

        return (bool) $shift?->reminders_paused_at;
    }

    /**
     * Does any active Reminder rule fire for this terminal? A terminal with none never shows the
     * switch — a button that changes nothing would only confuse the counter.
     */
    public function terminalHasReminderRule(Terminal $terminal): bool
    {
        return CategoryPrinterMapping::query()
            ->where('print_role', 'reminder')
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $terminal->branch_id))
            ->where(fn ($q) => $q->whereNull('terminal_id')->orWhere('terminal_id', $terminal->id))
            ->whereHas('printer', fn ($q) => $q->where('is_active', true)->where('supports_reminder', true))
            ->exists();
    }

    /** What the POS badge needs, for the terminal selected on that POS. */
    public function statusFor(?Terminal $terminal, ?User $user): array
    {
        $status = [
            'available' => false,
            'paused' => false,
            'paused_by' => null,
            'paused_at' => null,
            'can_toggle' => (bool) $user?->can(self::PERMISSION),
        ];

        if (! $terminal || ! $this->terminalHasReminderRule($terminal)) {
            return $status;
        }

        $status['available'] = true;
        $shift = $this->shifts->activeShiftForTerminal($terminal);
        if ($shift?->reminders_paused_at) {
            $status['paused'] = true;
            $status['paused_by'] = $shift->remindersPausedBy?->name;
            $status['paused_at'] = $this->clock->format($shift->reminders_paused_at, 'H:i', $shift->timezone_name);
        }

        return $status;
    }

    /**
     * Pause or resume, on the terminal's open shift — locked, so a close cannot slip in between.
     * Throws ShiftException when the terminal has no open shift.
     */
    public function setPaused(Terminal $terminal, User $user, bool $paused): Shift
    {
        return DB::connection('tenant')->transaction(function () use ($terminal, $user, $paused) {
            $shift = $this->shifts->lockOpenShiftForTerminal($terminal);

            $shift->forceFill([
                'reminders_paused_at' => $paused ? ($shift->reminders_paused_at ?? now()) : null,
                'reminders_paused_by_user_id' => $paused ? ($shift->reminders_paused_by_user_id ?? $user->id) : null,
            ])->save();

            return $shift;
        });
    }
}
