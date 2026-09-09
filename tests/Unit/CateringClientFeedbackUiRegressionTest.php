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
        $this->assertStringContainsString('allowInput: false', $source);
        $this->assertStringContainsString('instance.altInput.readOnly = true', $source);
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
    }

    public function test_punch_uses_additive_supply_and_clears_instruction_state(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/tenant/catering/events/show.blade.php'
        );

        $this->assertIsString($source);
        $this->assertStringContainsString('pm-own', $source);
        $this->assertStringContainsString('pm-cust', $source);
        $this->assertStringContainsString('pm-total', $source);
        $this->assertStringContainsString('clearPunchInstructions()', $source);
        $this->assertStringContainsString('loadPunchInstructions(row, idx)', $source);
        $this->assertStringContainsString("unitCode: p.unit_code || '—'", $source);
        $this->assertStringNotContainsString('unitCode: (p.mats', $source);
        $this->assertStringContainsString('minimumInputLength: 0', $source);
        $this->assertStringContainsString("el.select2('open')", $source);
    }
}
