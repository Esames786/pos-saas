<?php

namespace App\Services\Finance;

use Illuminate\Validation\ValidationException;

/**
 * OFFLINE EDGE — D1 (owner decision, 20 Oct 2026): the ONE manual-journal rule set, shared by Cloud and Edge.
 *
 * Canonical MANUAL-JOURNAL-CASH-LINE-GUARD-1 (7da4190d) wrote these guards into ManualJournalController; the owner
 * ordered them moved into the shared journal service so the Online form, the Edge offline General Journal twin and the
 * Cloud ingestion of offline journals consume the SAME business rule with the SAME messages. ManualJournalService
 * (the Cloud posting authority) is a Cloud-only artifact — it never ships to the appliance — so the rules live here,
 * pure and chart-agnostic (ManualJournalChart), and the service is their Cloud enforcement point (post()), while the
 * Branch Server runs them against its replica BEFORE it builds the envelope (so the till cannot produce an envelope
 * the Cloud would refuse; the Cloud re-runs them anyway — defence in depth, code JOURNAL_RULE_REFUSED).
 *
 *   R1  drawer chosen → the line's GL account must be the one the drawer is linked to (CashBankAccount.account_id)
 *   R2  line on an active drawer's GL account → a drawer is required; exactly one linked → auto-filled,
 *       several (two drawers on the same 1110) → the user must choose
 *   R3  no posting on a group head (an account with accounts under it)
 *   R5  narration must be real: at least 3 characters with a letter or a digit — ".", "-", "..." refused
 *
 * Direction is unchanged — on the right line debit = money IN, credit = money OUT. The rules bind ONLY this path
 * (manual journals); the system's own journals (JournalPostingService) are not touched, and Reverse stays free:
 * reversing an old wrong entry is the remedy. Messages are the canonical ones, byte for byte.
 */
final class ManualJournalRules
{
    public const NARRATION_MESSAGE = 'Write what this entry is for, in at least 3 characters (e.g. "Owner drawing - cash"). '
        . 'A dot or a dash is not a description.';

    /** R5 — narration must be real. */
    public static function guardNarration(string $description): void
    {
        $text = trim($description);

        if (mb_strlen($text) < 3 || ! preg_match('/[\p{L}\p{N}]/u', $text)) {
            throw ValidationException::withMessages(['description' => self::NARRATION_MESSAGE]);
        }
    }

    /**
     * R1–R3 — Cash/Bank only on its own line; no group heads.
     *
     * @param array<int|string, array> $lines canonical keys — account_id, cash_bank_account_id?, debit?, credit?; the array
     *                                        keys are kept (the error keys `lines.<key>.…` address the caller's rows)
     * @return array the lines, with the R2 auto-filled drawer written into cash_bank_account_id
     *
     * @throws ValidationException keyed `lines.<i>.account_id` (R3) / `lines.<i>.cash_bank_account_id` (R1, R2)
     */
    public static function guardCashLines(array $lines, ManualJournalChart $chart): array
    {
        $errors = [];

        foreach ($lines as $i => $line) {
            // An empty line posts nothing (normalizeLines and syncCashBankLines both skip it).
            if ((float) ($line['debit'] ?? 0) <= 0 && (float) ($line['credit'] ?? 0) <= 0) {
                continue;
            }

            $accountId = (int) ($line['account_id'] ?? 0);
            $label = $chart->label($accountId);

            // R3 — group head.
            if ($chart->isGroup($accountId)) {
                $child = $chart->firstActiveChild($accountId);
                $errors["lines.$i.account_id"] = $label . ' is a group. Choose an account under it'
                    . ($child ? ', e.g. ' . $child['code'] . ' ' . $child['name'] . '.' : '.');
                continue;
            }

            $drawerId = (int) ($line['cash_bank_account_id'] ?? 0);

            // R1 — the chosen drawer only on its own line.
            if ($drawerId) {
                $drawer = $chart->drawer($drawerId);
                if ($drawer && ($drawer['account_id'] ?? 0) !== $accountId) {
                    $gl = $drawer['account_id'] ? $chart->account((int) $drawer['account_id']) : null;
                    $errors["lines.$i.cash_bank_account_id"] = $gl
                        ? $drawer['name'] . ' is linked to ' . $gl['code'] . ' ' . $gl['name'] . '. Choose it on the '
                            . $gl['code'] . ' line, not on ' . $label . '.'
                        : $drawer['name'] . ' is not linked to any account in the chart of accounts, so it cannot '
                            . 'be used on a journal line. Link it on the Cash & Bank screen first.';
                }
                continue;
            }

            // R2 — a cash line without a drawer.
            $linked = $chart->activeDrawersFor($accountId);
            if (count($linked) === 1) {
                $lines[$i]['cash_bank_account_id'] = $linked[0]['id'];
            } elseif (count($linked) > 1) {
                $errors["lines.$i.cash_bank_account_id"] = $label . ' is used by more than one cash/bank account ('
                    . implode(', ', array_column($linked, 'name')) . '). Choose which one the money went through.';
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $lines;
    }
}
