<?php

declare(strict_types=1);

namespace JDZ\Ui\Html;

/**
 * Whitelist sanitiser for rich-text admin fields. Nothing typed into a
 * `content` / `description` textarea reaches the DB — and therefore the front's
 * `|raw` — without passing through here.
 *
 * The tag + attribute whitelist is the SAME one `jizy-editor` enforces
 * client-side on paste (`jizy-editor/lib/js/Sanitizer.js`). The two are
 * deliberate mirrors — same tags, same attributes, same renames, same URL rules,
 * same block-whitespace rule. Keep them in step; **the server is the one that
 * decides**, because the editor is not the only way a POST reaches the field.
 *
 * `clean()` runs the DOM whitelist FIRST so it has the final word on which
 * markup exists, then `Helper::clean()` applies the shared French typography
 * over an already-safe fragment. That order matters: `Helper::clean()` is
 * `strip_tags`-based and is NOT a sanitiser — running it first would leave a
 * `<script>` body behind as visible text. `whitelist()` is the same pass without
 * the typography, and is what the mirror is checked against.
 *
 * @author Joffrey Demetz <joffrey.demetz@gmail.com>
 */
final class Sanitizer
{
    /** Paragraphs + emphasis + link. */
    public const MINIMAL = 'minimal';

    /** Adds headings, lists, quotes, figures and images. */
    public const FULL = 'full';

    /** tag => allowed attributes. */
    private const TAGS_MINIMAL = [
        'p' => ['style', 'align'],
        'br' => [],
        'strong' => [],
        'em' => [],
        'a' => ['href', 'title', 'target'],
    ];

    /** tag => allowed attributes, on top of TAGS_MINIMAL. */
    private const TAGS_FULL = [
        'h2' => ['style', 'align'],
        'h3' => ['style', 'align'],
        'h4' => ['style', 'align'],
        'u' => [],
        's' => [],
        'ul' => [],
        'ol' => [],
        'li' => ['style', 'align'],
        'blockquote' => [],
        'figure' => ['class'],
        'figcaption' => [],
        'img' => ['src', 'alt'],
    ];

    /**
     * Dropped WITH their content — an unwrap would leak script bodies or CSS as
     * text. Everything else outside the whitelist is unwrapped (children kept).
     */
    private const DROPPED = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed',
        'param', 'applet', 'noscript', 'template', 'link', 'meta', 'base',
        'form', 'input', 'select', 'option', 'textarea', 'button', 'label',
        'svg', 'math', 'canvas', 'audio', 'video', 'source', 'track',
        'map', 'area', 'title', 'head',
    ];

    /** Word / Google-Docs shapes folded onto the whitelist before it is applied. */
    private const RENAMED = [
        'b' => 'strong',
        'i' => 'em',
        'strike' => 's',
        'del' => 's',
        'h1' => 'h2',
        'h5' => 'h3',
        'h6' => 'h3',
        'div' => 'p',
        'section' => 'p',
        'article' => 'p',
        'address' => 'p',
        'pre' => 'p',
        'dl' => 'ul',
        'dt' => 'li',
        'dd' => 'li',
        'q' => 'blockquote',
    ];

    /** Elements that carry meaning with no text of their own. */
    private const VOID = ['br', 'img'];

    /**
     * Blocks that may sit straight in the body; anything else there gets a `<p>`.
     * Declared once — the old per-site copy hard-coded this list a second time
     * inside `wrapOrphans()`, which is exactly how the two drifted.
     */
    private const BLOCKS = ['p', 'h2', 'h3', 'h4', 'ul', 'ol', 'blockquote', 'figure'];

    /** Everything that starts its own line — used to spot whitespace between blocks. */
    private const BLOCK_LEVEL = [
        'p', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote', 'figure', 'figcaption',
    ];

    /** Blocks that may carry an alignment. */
    private const ALIGNABLE = ['p', 'h2', 'h3', 'h4', 'li'];

    /** The three alignments worth storing — `left` is the default, so it is dropped. */
    private const ALIGNMENTS = ['center', 'right', 'justify'];

    /** The only two classes a `<figure>` may keep; they are front-CSS contract. */
    private const FIGURE_CLASSES = ['pull-left', 'pull-right'];

    /** Anything that makes an element a wrapper rather than a paragraph. */
    private const BLOCK_CONTENT = [
        'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'dl',
        'blockquote', 'figure', 'div', 'section', 'article', 'address', 'pre', 'table',
    ];

    /** Whitelisted tags that may not legally hold a block. */
    private const INLINE = ['strong', 'em', 'u', 's', 'a'];

    /** Schemes an `href` may use; anything else (javascript:, data:, …) is dropped. */
    private const HREF_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /** Schemes an `img src` may use — no `data:` payloads in stored content. */
    private const SRC_SCHEMES = ['http', 'https'];

    /**
     * The whitelist, then the French typography.
     *
     * @param string $html  raw editor / textarea input
     * @param string $tier  self::FULL or self::MINIMAL
     */
    public static function clean(string $html, string $tier = self::FULL): string
    {
        $html = self::whitelist($html, $tier);
        if ('' === $html) {
            return '';
        }

        // Typography + entity fixes over an already-whitelisted fragment. The
        // helper only ever removes tags and rewrites text, so it cannot widen
        // what the pass above allowed.
        return \trim(Helper::clean($html));
    }

    /**
     * The whitelist on its own — the exact mirror of `JiZy.Editor.clean()`.
     *
     * @param string $html  raw editor / textarea input
     * @param string $tier  self::FULL or self::MINIMAL
     */
    public static function whitelist(string $html, string $tier = self::FULL): string
    {
        $html = \trim($html);
        if ('' === $html) {
            return '';
        }

        $allowed = self::MINIMAL === $tier
            ? self::TAGS_MINIMAL
            : self::TAGS_MINIMAL + self::TAGS_FULL;

        $body = self::parse($html);
        if (null === $body) {
            return '';
        }

        self::walk($body, $allowed);
        self::trimBlockWhitespace($body);
        self::wrapOrphans($body);
        self::prune($body);

        return \trim(self::serialize($body));
    }

    /** Load a fragment and hand back its `<body>`, or null when it holds nothing. */
    private static function parse(string $html): ?\DOMElement
    {
        $doc = new \DOMDocument();
        $doc->strictErrorChecking = false;
        $doc->recover = true;

        $previous = \libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8">' . '<body>' . $html . '</body>',
            \LIBXML_NOWARNING | \LIBXML_NOERROR | \LIBXML_NONET | \LIBXML_PARSEHUGE
        );
        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);

        if (!$loaded) {
            return null;
        }

        $body = $doc->getElementsByTagName('body')->item(0);

        return $body instanceof \DOMElement ? $body : null;
    }

    /**
     * Depth-first over a snapshot of the child list: rename, drop, unwrap or
     * strip attributes. Iterating a live DOMNodeList while moving nodes skips
     * siblings — always snapshot first.
     *
     * @param array<string,string[]> $allowed
     */
    private static function walk(\DOMNode $node, array $allowed): void
    {
        foreach (\iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $child->parentNode?->removeChild($child);
                continue;
            }

            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = \strtolower($child->nodeName);

            if (\in_array($tag, self::DROPPED, true)) {
                $child->parentNode?->removeChild($child);
                continue;
            }

            if (isset(self::RENAMED[$tag])
                && !('p' === self::RENAMED[$tag] && self::holdsBlock($child))
            ) {
                $child = self::rename($child, self::RENAMED[$tag]);
                $tag = \strtolower($child->nodeName);
            }

            // Children first: an unwrap re-parents them, and they must already
            // be clean when they land in the parent.
            self::walk($child, $allowed);

            if (!isset($allowed[$tag])
                || (\in_array($tag, self::INLINE, true) && self::holdsBlock($child))
            ) {
                self::unwrap($child);
                continue;
            }

            self::stripAttributes($child, $tag, $allowed[$tag]);
        }
    }

    /**
     * Nesting a block inside something that cannot hold one — `<p>` in `<p>`, a
     * paragraph in a `<strong>` — is invalid markup, and every parser re-shapes
     * it differently, which is what made `clean(clean($x))` stop matching
     * `clean($x)` on a Word or Google-Docs paste. Both shapes come from the same
     * place: a wrapper element that is not really inline content.
     *
     * A `<div>` full of paragraphs is a wrapper, so it is unwrapped rather than
     * renamed to `<p>`; an inline tag left holding a block goes the same way,
     * and its text is kept either way.
     */
    private static function holdsBlock(\DOMElement $el): bool
    {
        foreach (self::BLOCK_CONTENT as $name) {
            if ($el->getElementsByTagName($name)->length > 0) {
                return true;
            }
        }

        return false;
    }

    /** Replace an element by one of another name, keeping its children. */
    private static function rename(\DOMElement $el, string $name): \DOMElement
    {
        $replacement = $el->ownerDocument->createElement($name);

        foreach (\iterator_to_array($el->childNodes) as $child) {
            $replacement->appendChild($child);
        }

        $el->parentNode?->replaceChild($replacement, $el);

        return $replacement;
    }

    /** Drop an element, promoting its children into its place. */
    private static function unwrap(\DOMElement $el): void
    {
        $parent = $el->parentNode;
        if (null === $parent) {
            return;
        }

        foreach (\iterator_to_array($el->childNodes) as $child) {
            $parent->insertBefore($child, $el);
        }

        $parent->removeChild($el);
    }

    /**
     * Keep only the whitelisted attributes, and only when their value survives
     * validation. A link or image with an unusable URL loses its tag, not just
     * its attribute — a bare `<a>` or a broken `<img>` is worse than none.
     *
     * @param string[] $keep
     */
    private static function stripAttributes(\DOMElement $el, string $tag, array $keep): void
    {
        foreach (\iterator_to_array($el->attributes ?? []) as $attr) {
            if (!\in_array(\strtolower($attr->nodeName), $keep, true)) {
                $el->removeAttribute($attr->nodeName);
            }
        }

        if (\in_array($tag, self::ALIGNABLE, true)) {
            self::applyAlign($el, self::alignOf($el));

            return;
        }

        if ('figure' === $tag) {
            // Filtered token by token, not matched whole: the editor parks its
            // own `is-selected` marker here while a figure is being edited, and
            // losing `pull-left` to it would silently un-float the image.
            $kept = \array_values(\array_intersect(
                \preg_split('~\s+~', \strtolower(\trim($el->getAttribute('class')))) ?: [],
                self::FIGURE_CLASSES
            ));

            if ([] === $kept) {
                $el->removeAttribute('class');

                return;
            }

            $el->setAttribute('class', $kept[0]);

            return;
        }

        if ('a' === $tag) {
            $href = self::url($el->getAttribute('href'), self::HREF_SCHEMES, true);
            if ('' === $href) {
                self::unwrap($el);

                return;
            }
            $el->setAttribute('href', $href);

            // `_blank` is the only destination the admin offers, and the only
            // one worth the `noopener` the front layout already sets.
            if ('_blank' !== $el->getAttribute('target')) {
                $el->removeAttribute('target');
            }

            return;
        }

        if ('img' === $tag) {
            $src = self::url($el->getAttribute('src'), self::SRC_SCHEMES, false);
            if ('' === $src) {
                $el->parentNode?->removeChild($el);

                return;
            }
            $el->setAttribute('src', $src);
            if (!$el->hasAttribute('alt')) {
                $el->setAttribute('alt', '');
            }
        }
    }

    /**
     * The alignment an element carries, `''` when it has none we keep.
     *
     * `style` is accepted for one declaration only — `text-align` with a value
     * worth storing. Anything else in there and the whole attribute goes,
     * because a half-kept `style` is how a whitelist starts leaking. A legacy
     * `align="…"` attribute is read as the same thing; `applyAlign()` writes it
     * back as a style, which is the shape the legacy sites already have on disk.
     */
    private static function alignOf(\DOMElement $el): string
    {
        $declarations = \array_filter(\array_map('trim', \explode(';', $el->getAttribute('style'))));

        $value = '';
        $onlyOurs = true;

        foreach ($declarations as $declaration) {
            if (\preg_match('~^text-align\s*:\s*([a-z]+)$~i', $declaration, $m)) {
                $value = $m[1];
                continue;
            }

            $onlyOurs = false;
        }

        if (!$onlyOurs) {
            $value = '';
        }

        if ('' === $value) {
            $value = $el->getAttribute('align');
        }

        $value = \strtolower(\trim($value));

        return \in_array($value, self::ALIGNMENTS, true) ? $value : '';
    }

    /** Write an alignment back as the one shape stored: an inline `text-align`. */
    private static function applyAlign(\DOMElement $el, string $value): void
    {
        $el->removeAttribute('align');

        if ('' !== $value) {
            $el->setAttribute('style', 'text-align: ' . $value . ';');

            return;
        }

        $el->removeAttribute('style');
    }

    /**
     * Validate a URL against a scheme whitelist. Relative URLs pass; anything
     * carrying a scheme must name one of $schemes. Protocol-relative `//host`
     * is rejected — it inherits the page scheme and reads as relative.
     *
     * @param string[] $schemes
     */
    private static function url(string $value, array $schemes, bool $allowFragment): string
    {
        // Control characters are how `java&#1;script:` gets past a naive check.
        $value = \trim(\preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');
        if ('' === $value) {
            return '';
        }

        if (\str_starts_with($value, '#')) {
            return $allowFragment ? $value : '';
        }

        if (\str_starts_with($value, '//')) {
            return '';
        }

        if (\preg_match('~^([a-z][a-z0-9+.\-]*):~i', $value, $m)) {
            return \in_array(\strtolower($m[1]), $schemes, true) ? $value : '';
        }

        return $value;
    }

    /** A block-level element, or the edge of its parent — never running text. */
    private static function blockish(?\DOMNode $node): bool
    {
        if (null === $node) {
            return true;
        }

        return $node instanceof \DOMElement
            && \in_array(\strtolower($node->nodeName), self::BLOCK_LEVEL, true);
    }

    /**
     * A newline between two `<li>` is indentation, not a space in the sentence —
     * so whitespace with a block on both sides goes, and whitespace next to an
     * inline stays. That is what lets the editor's source view round-trip byte
     * for byte, and what keeps `<blockquote><strong>a</strong> <em>b</em>` from
     * losing its space.
     */
    private static function trimBlockWhitespace(\DOMNode $node): void
    {
        foreach (\iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                self::trimBlockWhitespace($child);
                continue;
            }

            if (!$child instanceof \DOMText || '' !== \trim($child->wholeText)) {
                continue;
            }

            if (self::blockish($child->previousSibling) && self::blockish($child->nextSibling)) {
                $node->removeChild($child);
            }
        }
    }

    /**
     * Text and inline nodes sitting straight in the body — what unwrapping a
     * Word `<div>` soup leaves behind — get gathered into paragraphs.
     */
    private static function wrapOrphans(\DOMElement $body): void
    {
        $paragraph = null;

        foreach (\iterator_to_array($body->childNodes) as $child) {
            $isBlock = $child instanceof \DOMElement
                && \in_array(\strtolower($child->nodeName), self::BLOCKS, true);

            if ($isBlock) {
                $paragraph = null;
                continue;
            }

            // Whitespace between two blocks is layout noise, not content.
            if ($child instanceof \DOMText && '' === \trim($child->wholeText)) {
                $body->removeChild($child);
                continue;
            }

            if (null === $paragraph) {
                $paragraph = $body->ownerDocument->createElement('p');
                $body->insertBefore($paragraph, $child);
            }

            $paragraph->appendChild($child);
        }
    }

    /** Remove elements left with no text and no void descendant. */
    private static function prune(\DOMNode $node): void
    {
        foreach (\iterator_to_array($node->childNodes) as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            self::prune($child);

            $tag = \strtolower($child->nodeName);
            if (\in_array($tag, self::VOID, true)) {
                continue;
            }

            if ('' !== \trim($child->textContent)) {
                continue;
            }

            // A `<figure>` is kept by the `<img>` it holds, never by its caption
            // — which is how an empty `<figcaption>` gets dropped on save.
            foreach (self::VOID as $void) {
                if ($child->getElementsByTagName($void)->length > 0) {
                    continue 2;
                }
            }

            $child->parentNode?->removeChild($child);
        }
    }

    /** Serialise the body's children — the wrapper itself never ships. */
    private static function serialize(\DOMElement $body): string
    {
        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $body->ownerDocument->saveHTML($child);
        }

        return $out;
    }
}
