<?php

namespace Tests\Support;

/**
 * SLIP-TOP-GAP-1 / SLIP-END-LINES-1 (16–17 Nov 2026): every thermal slip ends with its last printed line,
 * then the cut, with no blank lines in between.
 *
 * The cut (GS V 66 0) feeds the last line to the cutter by itself; the KOT has always ended this way.
 * Blank lines before the cut were empty paper under every bill, bill preview, reminder and report.
 * The owner also said "printer cutter chalna chahye", so the cut itself is asserted first.
 */
trait AssertsThermalSlipEnd
{
    protected function assertSlipEndsWithOneLineThenTheCut(string $bytes, string $label): void
    {
        $cut = "\x1D\x56\x42\x00";
        $this->assertStringEndsWith($cut, $bytes, "{$label}: must still END WITH THE CUT (GS V 66 0) — the cutter has to run");

        // Style resets after the last line (bold off, size normal, align) print nothing — look past them.
        $body = preg_replace('/(?:\x1B[\x45\x61\x2D\x21][\x00-\xFF]|\x1D\x21[\x00-\xFF])+$/', '', substr($bytes, 0, -strlen($cut)));

        $this->assertStringEndsWith("\n", $body, "{$label}: the last printed line must be finished before the cut");
        $this->assertStringEndsNotWith("\n\n", $body, "{$label}: NO blank line before the cut — that is empty paper on every slip");
    }
}
