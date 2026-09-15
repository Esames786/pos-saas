<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * KASHIF-GUIDE-WALKTHROUGH-1 — the manual must actually render.
 *
 * A Blade file that COMPILES can still explode when it runs: the Close Branch
 * outage was `voided@endif`, a directive stuck to a word, which compiled happily
 * and 500'd the page for every user. This page is now dense with PHP inside
 * Blade — a @foreach over destructured arrays, nested ternaries picking badge
 * colours — and none of that is exercised by compiling it.
 *
 * So it is rendered, in both languages, and the claims a reader relies on are
 * checked for presence. CateringGuideController does nothing but choose the
 * language and return this view, so rendering it IS the real path.
 */
class CateringGuideRendersTest extends TestCase
{
    /** The whole page, in English. */
    public function test_the_guide_renders_in_english(): void
    {
        $html = view('tenant.catering.guide', ['lang' => 'en'])->render();

        $this->assertStringContainsString('Catering Guide', $html);
        $this->assertStringContainsString('One real booking, step by step', $html);
        $this->assertStringContainsString('What a quotation is, and what a revision does', $html);
        $this->assertStringContainsString('Going back — how far you can undo', $html);
    }

    /** And in Urdu, which is a different set of branches through the same file. */
    public function test_the_guide_renders_in_urdu(): void
    {
        $html = view('tenant.catering.guide', ['lang' => 'ur'])->render();

        $this->assertStringContainsString('کیٹرنگ گائیڈ', $html);
        $this->assertStringContainsString('ایک اصل بکنگ، قدم بہ قدم', $html);
    }

    /**
     * The walkthrough must state where money and stock actually move, because a
     * manual that is vague about that is worse than none: it is trusted.
     */
    public function test_the_walkthrough_says_where_money_and_stock_move(): void
    {
        $html = view('tenant.catering.guide', ['lang' => 'en'])->render();

        // Revenue is earned at the invoice, not when the money arrived.
        $this->assertStringContainsString('REVENUE IS EARNED HERE', $html);
        // An advance is a liability, not income.
        $this->assertStringContainsString('It is a LIABILITY', $html);
        // Releasing production moves nothing; issuing materials is the one place.
        $this->assertStringContainsString('NO STOCK YET', $html);
        $this->assertStringContainsString('STOCK LEAVES THE STORE', $html);
        // A revision moves no money but does move the bill.
        $this->assertStringContainsString('NO money moves', $html);
    }

    /**
     * The roll-back rules, including the two walls. If the code ever lets a
     * booking walk back past an invoice or a release, this page would be lying
     * — so the words are pinned here alongside the guards that enforce them.
     */
    public function test_the_guide_states_the_two_walls(): void
    {
        $html = view('tenant.catering.guide', ['lang' => 'en'])->render();

        $this->assertStringContainsString('After the final invoice', $html);
        $this->assertStringContainsString('After production is released', $html);
        $this->assertStringContainsString('Going back never touches money.', $html);
    }

    /**
     * CATERING-ACCEPT-CONFIRMS-1 — the flow table used to say acceptance posts
     * nothing and confirmation is a separate click. The second half stopped
     * being true today, and this file's own docblock says a manual that lies is
     * worse than no manual.
     */
    public function test_the_flow_says_acceptance_confirms_the_booking(): void
    {
        $html = view('tenant.catering.guide', ['lang' => 'en'])->render();

        $this->assertStringContainsString('CONFIRMS ITSELF', $html,
            'the manual must say that acceptance carries the booking with it');
        $this->assertStringContainsString('Usually already done for you by step 5', $html,
            'and that Confirm Booking is normally redundant now');
    }
}
