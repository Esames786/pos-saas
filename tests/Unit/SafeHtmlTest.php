<?php

namespace Tests\Unit;

use App\Support\SafeHtml;
use PHPUnit\Framework\TestCase;

/**
 * ONLINE-ORDERING-1 — Privacy Policy / FAQ rich text goes on a public website: only text formatting may survive.
 */
class SafeHtmlTest extends TestCase
{
    public function test_formatting_survives(): void
    {
        $html = '<h2>Information We Collect</h2><p>We collect <strong>only</strong> <em>what</em> <u>we</u> need:</p>'
            .'<ul><li>Your name</li><li>Your phone</li></ul><ol><li>One</li></ol><blockquote>Note</blockquote>';

        $this->assertSame($html, SafeHtml::clean($html));
    }

    public function test_scripts_events_and_bad_links_are_removed(): void
    {
        $out = SafeHtml::clean('<p onclick="steal()" style="color:red" class="x">Hi<script>alert(1)</script></p>'
            .'<a href="javascript:alert(1)">bad</a><a href="//evil.test">proto</a><iframe src="https://evil.test"></iframe>'
            .'<img src=x onerror="alert(1)"><style>body{}</style><!-- note -->');

        $this->assertSame('<p>Hi</p>badproto', $out);
    }

    public function test_links_open_safely_and_other_tags_are_unwrapped(): void
    {
        $this->assertSame('<p><a href="https://kashiffood.com" target="_blank" rel="noopener nofollow">site</a> <a href="tel:03212749000">call</a></p>',
            SafeHtml::clean('<p><a href="https://kashiffood.com" onmouseover="x()">site</a> <a href="tel:03212749000">call</a></p>'));
        $this->assertSame('<h2>Title</h2><p>Box</p><p>in <strong>span</strong></p>',
            SafeHtml::clean('<h1>Title</h1><div>Box</div><p>in <span style="x"><strong>span</strong></span></p>'));
    }

    public function test_empty_editor_is_empty_and_text_is_plain(): void
    {
        $this->assertSame('', SafeHtml::clean('<p><br></p>'));
        $this->assertSame('', SafeHtml::text(SafeHtml::clean('<p><br></p><p>   </p>')));
        $this->assertSame('Pay in cash — 30–45 minutes.', SafeHtml::text('<p>Pay in <strong>cash</strong> &mdash; 30–45 minutes.</p>'));
    }
}
