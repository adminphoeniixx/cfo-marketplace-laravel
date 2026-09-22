<?php

namespace App\Support;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Str;

/**
 * A legal page's body, turned into HTML fit to serve.
 *
 * The bodies are typed into a textarea in the admin panel, and they are not
 * all typed the same way: the returns policy and the licence list were written
 * as HTML, the privacy policy as Markdown. Escaping the HTML — the obvious
 * defence — published a licence page with `<p>` printed across it, so both
 * have to pass.
 *
 * What makes that safe is a whitelist rather than trust. Every element not on
 * the list is unwrapped or, where it can carry code, dropped with its
 * contents; every attribute not on the list is removed; and a link survives
 * only if it goes somewhere a link can go. An admin account is not a licence
 * to put a `<script>` on a page every shopper reads.
 *
 * Headings come back with ids so the page can offer its own contents.
 */
final class LegalHtml
{
    /** What may appear. Prose and tables — no images, no embeds, no forms. */
    private const TAGS = [
        'p', 'br', 'hr', 'h2', 'h3', 'h4', 'strong', 'b', 'em', 'i', 'u', 's',
        'ul', 'ol', 'li', 'a', 'blockquote', 'code', 'pre', 'table', 'thead',
        'tbody', 'tr', 'th', 'td', 'small', 'sup', 'sub',
    ];

    /** Taken out with everything inside them, rather than unwrapped. */
    private const DROPPED = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input',
        'button', 'select', 'textarea', 'link', 'meta', 'base', 'svg',
    ];

    /** @var array<string, list<string>> */
    private const ATTRIBUTES = [
        'a' => ['href', 'title'],
        'th' => ['colspan', 'rowspan'],
        'td' => ['colspan', 'rowspan'],
    ];

    /** Where a link may point. Not `javascript:`, and not `data:`. */
    private const SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /** Everything above ASCII, for the round trip through libxml. */
    private const UNICODE = [0x80, 0x10FFFF, 0, 0x1FFFFF];

    /**
     * @return array{html: string, sections: list<array{id: string, title: string}>}
     */
    public static function render(?string $body): array
    {
        // Markdown first, HTML passed through rather than escaped — a body
        // that is already HTML comes out of here unchanged, and one written in
        // Markdown is converted. The whitelist below is what both then face.
        $html = Str::markdown((string) $body, [
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);

        if (trim($html) === '') {
            return ['html' => '', 'sections' => []];
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        /*
        | libxml reads a fragment as Latin-1 unless it is told otherwise, and
        | the usual `<meta charset>` trick swallows everything after it when
        | there is no implied body. Numeric entities sidestep the question —
        | every non-ASCII character goes in as an entity and comes back out as
        | itself below. The wrapper gives the fragment a single root.
        */
        $dom->loadHTML(
            '<div>'.mb_encode_numericentity($html, self::UNICODE, 'UTF-8').'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->documentElement;

        if (! $root instanceof DOMElement) {
            return ['html' => '', 'sections' => []];
        }

        $sections = [];
        $seen = [];

        self::clean($root, $sections, $seen);

        $out = '';

        foreach ($root->childNodes as $child) {
            $out .= (string) $dom->saveHTML($child);
        }

        return [
            'html' => trim(mb_decode_numericentity($out, self::UNICODE, 'UTF-8')),
            'sections' => $sections,
        ];
    }

    /**
     * Depth first, children before the element itself — so an element that is
     * about to be unwrapped has already had its contents cleaned.
     *
     * @param  list<array{id: string, title: string}>  $sections
     * @param  array<string, true>  $seen
     */
    private static function clean(DOMNode $node, array &$sections, array &$seen): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMComment) {
                $child->remove();

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            self::clean($child, $sections, $seen);

            $name = strtolower($child->nodeName);

            if (in_array($name, self::DROPPED, true)) {
                $child->remove();

                continue;
            }

            if (! in_array($name, self::TAGS, true)) {
                self::unwrap($child);

                continue;
            }

            self::cleanAttributes($child, $name);

            if ($name === 'h2') {
                $title = trim($child->textContent);
                $id = self::uniqueId($title, $seen);

                $child->setAttribute('id', $id);
                $sections[] = ['id' => $id, 'title' => $title];
            }
        }
    }

    /** Keep the words, lose the tag. */
    private static function unwrap(DOMElement $element): void
    {
        while ($element->firstChild) {
            $element->parentNode?->insertBefore($element->firstChild, $element);
        }

        $element->remove();
    }

    private static function cleanAttributes(DOMElement $element, string $name): void
    {
        $allowed = self::ATTRIBUTES[$name] ?? [];

        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $attributeName = strtolower($attribute->nodeName);

            if (! in_array($attributeName, $allowed, true)) {
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            if ($attributeName === 'href' && ! self::safeLink($attribute->nodeValue ?? '')) {
                $element->removeAttribute('href');
            }
        }
    }

    private static function safeLink(string $href): bool
    {
        $href = trim($href);

        if ($href === '') {
            return false;
        }

        // Same page, or the same host.
        if (str_starts_with($href, '#') || str_starts_with($href, '/')) {
            return true;
        }

        $scheme = parse_url($href, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), self::SCHEMES, true);
    }

    /**
     * @param  array<string, true>  $seen
     */
    private static function uniqueId(string $title, array &$seen): string
    {
        $base = Str::slug($title) ?: 'section';
        $id = $base;
        $suffix = 2;

        while (isset($seen[$id])) {
            $id = $base.'-'.$suffix++;
        }

        $seen[$id] = true;

        return $id;
    }
}
