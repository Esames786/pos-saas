<?php

namespace App\Services\Edge\AutoUpdate;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * EDGE 0.9 (stream B) — the owner-set maintenance window in which the appliance may apply an update by itself.
 *
 * HH:MM start/end on the appliance clock (or an explicit IANA timezone), half-open [start, end). A window may wrap midnight
 * (23:00–02:00). An invalid or empty (start = end) window contains NO minute — fail closed: the updater then waits for an
 * explicit "Update now". Pure value object; no I/O.
 */
final class EdgeMaintenanceWindow
{
    public function __construct(
        public readonly string $start,
        public readonly string $end,
        public readonly ?string $tz = null,
    ) {
    }

    public static function fromConfig(): self
    {
        $tz = config('edge.auto_update.window_tz');

        return new self(
            (string) config('edge.auto_update.window_start', '03:00'),
            (string) config('edge.auto_update.window_end', '05:00'),
            is_string($tz) && $tz !== '' ? $tz : null,
        );
    }

    /** A K2/K6 `maintenance_window` {start,end,tz} overrides the configured one when it is well formed. */
    public static function fromArray(mixed $window, self $fallback): self
    {
        if (! is_array($window) || ! isset($window['start'], $window['end'])) {
            return $fallback;
        }
        $candidate = new self((string) $window['start'], (string) $window['end'], isset($window['tz']) && is_string($window['tz']) && $window['tz'] !== '' ? $window['tz'] : $fallback->tz);

        return $candidate->valid() ? $candidate : $fallback;
    }

    public function valid(): bool
    {
        return self::minutes($this->start) !== null && self::minutes($this->end) !== null && $this->timezone() !== null
            && self::minutes($this->start) !== self::minutes($this->end);
    }

    public function contains(CarbonInterface $now): bool
    {
        if (! $this->valid()) {
            return false;
        }
        $local = CarbonImmutable::instance($now)->setTimezone($this->timezone());
        $m = ((int) $local->format('G')) * 60 + (int) $local->format('i');
        $s = self::minutes($this->start);
        $e = self::minutes($this->end);

        return $s < $e ? ($m >= $s && $m < $e) : ($m >= $s || $m < $e);
    }

    public function describe(): string
    {
        return $this->valid()
            ? $this->start . '–' . $this->end . ' (' . $this->timezone() . ')'
            : 'not set (updates wait for "Update now")';
    }

    /** @return array{start:string,end:string,tz:?string,valid:bool} */
    public function toArray(): array
    {
        return ['start' => $this->start, 'end' => $this->end, 'tz' => $this->timezone(), 'valid' => $this->valid()];
    }

    private function timezone(): ?string
    {
        $tz = $this->tz ?? (string) config('app.timezone', 'UTC');
        try {
            new \DateTimeZone($tz);

            return $tz;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function minutes(string $hhmm): ?int
    {
        if (! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($hhmm), $m)) {
            return null;
        }

        return ((int) $m[1]) * 60 + (int) $m[2];
    }
}
