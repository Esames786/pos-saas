<?php

namespace Tests\MySql\Support;

/**
 * W3/W4 S3c — the fields of a form on a rendered page, exactly as a browser posts them: hidden/text inputs, checked
 * radios and checkboxes, each select's selected option (else its first), textareas — never anything disabled or inside
 * a <template> (a row the page has not added yet) — parsed the way PHP parses a post. (The same reading as
 * ProductScreenV2MySqlTest's, plus the template rule.)
 */
trait ReadsFormOnPage
{
    /** @param bool $put  the edit form (it carries _method=PUT), else the add form */
    protected function formOnPage(string $html, string $action, bool $put = true): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);
        $form = null;
        foreach ($dom->getElementsByTagName('form') as $f) {
            $isPut = $xpath->query('.//input[@name="_method"][@value="PUT" or @value="put" or @value="PATCH"]', $f)->length > 0;
            $method = strtoupper($f->getAttribute('method') ?: 'GET');
            if ($method === 'POST' && $isPut === $put && rtrim(parse_url($f->getAttribute('action'), PHP_URL_PATH) ?? '', '/') === rtrim($action, '/')) {
                $form = $f;
                break;
            }
        }
        $this->assertNotNull($form, "the save form posting to {$action}");
        $pairs = [];
        foreach ($xpath->query('.//input|.//select|.//textarea', $form) as $el) {
            $name = $el->getAttribute('name');
            if ($name === '' || $el->hasAttribute('disabled') || $xpath->query('ancestor::template', $el)->length) {
                continue;
            }
            if ($el->nodeName === 'select') {
                $picked = null;
                $opts = $xpath->query('.//option', $el);
                foreach ($opts as $o) {
                    if ($o->hasAttribute('selected')) {
                        $picked = $o;
                    }
                }
                $picked ??= $opts->item(0);
                $value = $picked ? ($picked->hasAttribute('value') ? $picked->getAttribute('value') : $picked->textContent) : '';
            } elseif ($el->nodeName === 'textarea') {
                $value = $el->textContent;
            } else {
                $type = strtolower($el->getAttribute('type') ?: 'text');
                if (in_array($type, ['radio', 'checkbox'], true) && ! $el->hasAttribute('checked')) {
                    continue;
                }
                if (in_array($type, ['submit', 'button', 'file', 'image', 'reset'], true)) {
                    continue;
                }
                $value = $el->hasAttribute('value') ? $el->getAttribute('value') : ($type === 'checkbox' || $type === 'radio' ? 'on' : '');
            }
            $pairs[] = rawurlencode($name) . '=' . rawurlencode($value);
        }
        parse_str(implode('&', $pairs), $out);

        return $out;
    }
}
