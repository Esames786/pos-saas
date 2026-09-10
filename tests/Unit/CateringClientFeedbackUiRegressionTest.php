<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Browser-only client fixes need a source contract as well as MySQL domain
 * proof. These assertions prevent the exact state/unit/time regressions from
 * disappearing during a later Blade refactor.
 */
class CateringClientFeedbackUiRegressionTest extends TestCase
{
    public function test_customer_search_and_service_time_keep_the_operator_contract(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/tenant/catering/events/partials/event-form-support.blade.php'
        );

        $this->assertIsString($source);
        $this->assertStringContainsString('templateResult', $source);
        $this->assertStringContainsString('templateSelection', $source);
        // EVENT-FORM-KEYBOARD-3 — this used to pin `allowInput: false` and a
        // read-only box. That lock existed for a real reason: arbitrary letters
        // once reached the canonical H:i value. But a box nobody can type in is
        // only one way to keep bad data out, and it cost the counter its rhythm.
        //
        // The reason is answered directly now — a parser that returns nothing
        // for anything that is not a real time, so flatpickr keeps the value it
        // had rather than storing rubbish. What is pinned is the REFUSAL, which
        // is the thing that actually mattered.
        $this->assertStringContainsString('const typedTime = function (str)', $source);
        $this->assertStringContainsString('parseDate: typedTime', $source);
        $this->assertStringContainsString('if (! m) return undefined;', $source,
            'anything that is not a time must be refused, not guessed at');
        $this->assertStringContainsString('hours >= 0 && hours <= 23', $source);
        $this->assertStringContainsString('minutes >= 0 && minutes <= 59', $source);
        $this->assertStringNotContainsString('instance.altInput.readOnly = true', $source,
            'the service time can be typed again');
        $this->assertStringContainsString('data-open-service-time', file_get_contents(
            dirname(__DIR__, 2).'/resources/views/tenant/catering/events/partials/event-form-fields.blade.php'
        ));
        // That prohibition is about the SERVICE TIME box, where free-typed text
        // once reached the canonical value. The DATE boxes are a different
        // question and do carry a parser — see the test below.
        $this->assertStringNotContainsString('parseDate: function (text)', $source);
        $this->assertStringContainsString("dateFormat: 'H:i'", $source,
            'the friendly AM/PM input must still submit canonical 24-hour time');
    }

    /**
     * EVENT-FORM-KEYBOARD-1 — a booking can be finished without the mouse.
     *
     * Two asks from the counter: a key that saves the booking, PRINTED on the
     * button (a shortcut nobody is told about is a shortcut nobody uses), and
     * date boxes that take what an operator actually types.
     *
     * The boxes already allowed typing, but only in the shape flatpickr prints —
     * "Wed, 09 Sep 2026" — which nobody types. They now read 9/10, 9-10-26,
     * 091026, +7 and today, DAY FIRST, because that is how a date is written and
     * said here.
     *
     * The refusals matter more than the acceptances. JavaScript's Date carries
     * overflow forward, so new Date(2026, 98, 99) is a perfectly valid object in
     * June 2034 — "99/99" would have become a date instead of a mistake. Every
     * parsed date is checked back against what was typed, and a day that does
     * not exist (31 February, 29 February in a common year) is refused. A
     * silently wrong EVENT date is the one outcome this box must never produce.
     */
    public function test_the_booking_form_can_be_finished_from_the_keyboard(): void
    {
        $support = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/tenant/catering/events/partials/event-form-support.blade.php'
        );
        $form = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/tenant/catering/events/form.blade.php'
        );

        // The key, and the button that names it.
        $this->assertStringContainsString("e.key.toLowerCase() !== 's'", $form,
            'Ctrl+S must save the booking, not the browser page');
        $this->assertStringContainsString("document.querySelector('form[data-event-ajax]')", $form);
        $this->assertStringContainsString('form.requestSubmit()', $form,
            'requestSubmit runs the browser validation and fires the event the ajax pipeline listens for');
        $this->assertStringContainsString('(Ctrl+S)', $form,
            'the shortcut is printed on the button');

        // The dates take what people type.
        $this->assertStringContainsString('const typedDate = function (str)', $support);
        $this->assertStringContainsString('parseDate: typedDate', $support);
        foreach (["'today'", "'aaj'", "'kal'", 'tomorrow'] as $word) {
            $this->assertStringContainsString($word, $support, "the date box understands {$word}");
        }

        // And refuse what cannot be a date.
        $this->assertStringContainsString('const made = (y, m, d) =>', $support,
            'every parsed date is built through one checked constructor');
        $this->assertStringContainsString('made.getDate() === d', $support,
            'the built date is checked back against what was typed, or overflow becomes a real date');
        $this->assertStringContainsString('y >= 2000 && y <= 2100', $support);

        // Enter accepts and moves on; the calendar must not swallow the next Tab.
        $this->assertStringContainsString("if (e.key === 'Enter')", $support);
        $this->assertStringContainsString('fp.close();', $support);

        // EVENT-FORM-KEYBOARD-2 — the calendar follows the typing. 09-10-2026 was
        // understood on the keystroke, but the month underneath stayed put until
        // Enter, and an operator reads that as "manual entry does not work".
        //
        // jumpToDate, never setDate: setDate rewrites the box mid-word and throws
        // the caret to the end, so the next keystroke lands in the wrong place.
        $this->assertStringContainsString('fp.jumpToDate(parsed);', $support,
            'the calendar must move to the month being typed');
        $this->assertStringNotContainsString('fp.setDate(parsed, false)', $support,
            'committing mid-word would rewrite the box under the caret');
        $this->assertStringContainsString("classList.add('is-invalid')", $support,
            'a typo is shown while the caret is still in the box');
    }

    /**
     * CATERING-OVERPAYMENT-1 (step 4) — the authority to create a liability is
     * checked in the CONTROLLER, not merely hidden on the screen.
     *
     * Hiding the checkbox behind @can stops an honest operator and nobody else:
     * the field is a form post, and a form post can be written by hand. So the
     * flag is dropped server-side for anyone who has not been granted
     * `tenant.catering.advances.overpay`, and the receipt then meets the same
     * refusal every other path meets.
     *
     * Read from the source because that is where the decision is: this is a
     * contract about the shape of the code, like the parseDate rules above.
     */
    public function test_the_overpayment_authority_is_enforced_server_side(): void
    {
        $controller = file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Controllers/Tenant/Catering/CateringAdvanceController.php'
        );
        $modal = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/tenant/catering/events/show.blade.php'
        );

        // The flag survives only for someone who holds the permission.
        $this->assertStringContainsString(
            "\$request->user()?->can('tenant.catering.advances.overpay')", $controller,
            'the permission is checked where the decision is made, not only where the box is drawn');
        $this->assertStringContainsString("\$data['allow_overpayment'] = \$request->boolean('allow_overpayment')", $controller,
            'and the posted value is replaced rather than trusted');

        // Any departure from a plain payment must say why — both directions.
        $this->assertStringContainsString('needs a reason recorded against it', $controller);
        $this->assertStringContainsString("'amount' => ['required', 'numeric', 'not_in:0']", $controller,
            'a minus hands money back; only zero means nothing at all');

        // Money out is a refund, never a negative receipt.
        $this->assertStringContainsString('CateringRefundService::class)->record(', $controller);
        $this->assertStringNotContainsString("'amount' => \$amount,", $controller,
            'a negative amount must never be written onto a receipt row');

        // The screen asks before it does it, and asks only when the arithmetic
        // says the bill has been crossed. The checkbox this replaced made the
        // operator decide before typing the amount — impossible to answer
        // honestly when money arrives in instalments and the third one is what
        // crosses the total.
        $this->assertStringNotContainsString('type="checkbox" value="1" name="allow_overpayment"', $modal,
            'the decision is no longer taken before the amount is known');
        $this->assertStringContainsString("auth()->user()?->can('tenant.catering.advances.overpay')", $modal,
            'and the screen still respects the permission it can no longer hide a box behind');
        $this->assertStringContainsString('Yes, take the full amount', $modal,
            'the confirm names what is being agreed to');
        $this->assertStringContainsString('id="adv-reason-wrap"', $modal);
    }

    /**
     * CATERING-OVERPAYMENT-1 §4b — the SCREEN must accept everything the
     * controller accepts.
     *
     * Reported from the floor the day it shipped: typing -5000 produced
     * "Value must be greater than or equal to 0.01" and the form never
     * submitted. The controller had moved to `not_in:0` so that a minus could
     * hand credit back; the input kept `min="0.01"` from before, so the browser
     * refused the keystroke and the whole path was unreachable. Nothing was
     * broken server-side, which is exactly why no other guard noticed.
     */
    public function test_the_amount_box_accepts_what_the_controller_accepts(): void
    {
        $modal = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/tenant/catering/events/show.blade.php'
        );
        $controller = file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Controllers/Tenant/Catering/CateringAdvanceController.php'
        );

        $this->assertMatchesRegularExpression(
            '/<input type="number" step="0\.01" name="amount"/', $modal,
            'the receipt Amount box must not carry a min — the browser would refuse the minus '
            .'that hands credit back, before the controller ever saw it');

        // The pairing is the point: a floor is only wrong relative to the rule
        // behind it. If the controller ever goes back to refusing negatives,
        // this assertion is what says the box may constrain them again.
        $this->assertStringContainsString("'amount' => ['required', 'numeric', 'not_in:0']", $controller);

        // The refund modal is a different box with a different job: money goes
        // OUT of it, so it keeps a floor and a ceiling. The ceiling used to be
        // the credit; since CATERING-REFUND-BEYOND-CREDIT-1 it is everything
        // RECEIVED, because money covering a bill may now be handed back by
        // someone allowed to decide it. What can never move is that ceiling:
        // money that never arrived cannot go back, whatever anyone holds.
        $this->assertStringContainsString('max="{{ $position[\'refund_ceiling\'] }}"', $modal,
            'the Refund box still cannot exceed what was actually received');
        $this->assertStringContainsString('Yes, pay it back anyway', $modal,
            'and going past the credit is asked out loud, not assumed');
    }

    /**
     * The event screen is a full-width working screen: the navigation starts
     * collapsed every visit.
     *
     * A revision once remembered the last choice in localStorage, so a single
     * click left the sidebar open on every later visit — reported 2026-09-09.
     */
    public function test_the_event_screen_starts_with_the_sidebar_collapsed(): void
    {
        $view = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/tenant/catering/events/show.blade.php'
        );

        $this->assertStringContainsString("document.body.classList.add('nosidebar');", $view);
        $this->assertStringNotContainsString("localStorage.getItem('cateringSidebar')", $view,
            'the collapsed state must not be gated on a choice made in some earlier session');
        $this->assertStringNotContainsString("localStorage.setItem('cateringSidebar'", $view,
            'and nothing should be written that nothing reads');
    }

    /**
     * CATERING-NEGATIVE-PAYMENT-1 §5 — the operator sees the whole picture
     * before confirming, and works none of it out.
     *
     * A confirm that says only "this goes past the credit" leaves the person
     * holding the money to do the arithmetic that decides whether they are about
     * to reopen a debt. Every figure the decision rests on is named.
     */
    public function test_the_refund_confirm_shows_the_whole_position(): void
    {
        $modal = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/tenant/catering/events/show.blade.php'
        );

        foreach ([
            'Invoice / bill',
            'Total received',
            'Applied to the bill',
            'Customer credit available',
            'taken from credit',
            'reopens as money owed to you',
            'Balance due afterwards',
            'Credit left afterwards',
        ] as $line) {
            $this->assertStringContainsString($line, $modal,
                "the refund confirm must state '{$line}' rather than leave it to be worked out");
        }

        // Drawn from position(), not recomputed on the screen where it could drift.
        foreach (['billed', 'gross_received', 'applied', 'balance_due', 'refundable', 'refund_ceiling'] as $key) {
            $this->assertStringContainsString("\$position['{$key}']", $modal,
                "the confirm must take {$key} from the one authority");
        }
    }

    public function test_punch_uses_additive_supply_and_clears_instruction_state(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/tenant/catering/events/show.blade.php'
        );

        $this->assertIsString($source);
        $this->assertStringContainsString('pm-own', $source);
        $this->assertStringContainsString('pm-cust', $source);
        // PUNCH-ENTRY-TABLE-1: the running total column is gone. Required Qty
        // is the recipe's answer and Own + Party is checked against it, so a
        // third number saying the same thing had nothing left to add.
        $this->assertStringContainsString('pm-req', $source);
        $this->assertStringContainsString('clearPunchInstructions()', $source);
        $this->assertStringContainsString('loadPunchInstructions(row, idx)', $source);
        $this->assertStringContainsString("unitCode: p.unit_code || '—'", $source);
        $this->assertStringNotContainsString('unitCode: (p.mats', $source);
        $this->assertStringContainsString('minimumInputLength: 0', $source);
        $this->assertStringContainsString("el.select2('open')", $source);
    }
}
