<?php

namespace App\Support;

/**
 * ONLINE-ORDERING-1 — rich text (Privacy Policy, FAQ answers) jo restaurant ka staff editor me likhta hai, website
 * par dikhne se pehle saaf: sirf likhai ki formatting (paragraph, heading, bold/italic/underline, list, link,
 * quote). Script, style, iframe, form, event attributes (onclick…), javascript: links — sab nikal jate hain.
 * Save par bhi aur dikhate waqt bhi chalta hai.
 */
final class SafeHtml
{
    private const ALLOWED = ['p', 'br', 'h2', 'h3', 'h4', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'a', 'blockquote'];

    /** Jo andar ke text samet poore nikaal diye jate hain. */
    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'noscript', 'form',
        'input', 'textarea', 'select', 'button', 'head', 'title', 'meta', 'link', 'img', 'video', 'audio', 'canvas'];

    public static function clean(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="safe-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->getElementById('safe-root');
        if (! $root) {
            return e(strip_tags($html));
        }
        self::walk($doc, $root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }
        // Editor ke khaali paragraph shuru/aakhir me.
        $out = preg_replace('#^(\s*<p>(<br>)?</p>\s*)+|(\s*<p>(<br>)?</p>\s*)+$#i', '', trim($out)) ?? $out;

        return trim($out);
    }

    /** Sirf text (FAQ ka Google wala jawab, "khaali hai?" ki jaanch). */
    public static function text(?string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</li>'], ' ', (string) $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private static function walk(\DOMDocument $doc, \DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $node->removeChild($child);

                continue;
            }
            if (! $child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP, true)) {
                $node->removeChild($child);

                continue;
            }
            self::walk($doc, $child);

            if ($tag === 'h1') {
                $child = self::rename($doc, $child, 'h2');
                $tag = 'h2';
            } elseif (in_array($tag, ['h5', 'h6'], true)) {
                $child = self::rename($doc, $child, 'h4');
                $tag = 'h4';
            } elseif ($tag === 'div') {
                $child = self::rename($doc, $child, 'p');
                $tag = 'p';
            }
            if (! in_array($tag, self::ALLOWED, true)) {
                self::unwrap($child);

                continue;
            }

            $href = $tag === 'a' ? trim($child->getAttribute('href')) : '';
            foreach (iterator_to_array($child->attributes) as $attr) {
                $child->removeAttribute($attr->name);
            }
            if ($tag === 'a') {
                if (! preg_match('#^(https?://|mailto:|tel:|/(?!/))#i', $href)) {
                    self::unwrap($child);

                    continue;
                }
                $child->setAttribute('href', $href);
                if (preg_match('#^https?://#i', $href)) {
                    $child->setAttribute('target', '_blank');
                    $child->setAttribute('rel', 'noopener nofollow');
                }
            }
        }
    }

    private static function rename(\DOMDocument $doc, \DOMElement $el, string $tag): \DOMElement
    {
        $new = $doc->createElement($tag);
        while ($el->firstChild) {
            $new->appendChild($el->firstChild);
        }
        $el->parentNode?->replaceChild($new, $el);

        return $new;
    }

    private static function unwrap(\DOMElement $el): void
    {
        $parent = $el->parentNode;
        if (! $parent) {
            return;
        }
        while ($el->firstChild) {
            $parent->insertBefore($el->firstChild, $el);
        }
        $parent->removeChild($el);
    }
}
