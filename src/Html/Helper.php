<?php

declare(strict_types=1);

namespace JDZ\Ui\Html;

/**
 * HTML content helpers — sanitise / normalise WYSIWYG (redactor) content for
 * front display: tag whitelist, French typography (non-breaking spaces before
 * `: ; ? !`, guillemets), entity fixes, and DOM tidy-up (strip inline styles,
 * drop empty nodes).
 *
 * @author Joffrey Demetz <joffrey.demetz@gmail.com>
 */
abstract class Helper
{
    public static function clean(string $html): string
    {
        if ('' === $html) {
            return '';
        }

        if (preg_match("/^<p>\s*<\/p>$/", $html)) {
            return '';
        }

        if (preg_match("/^<p>\s*<br([\s\/])?>\s*<\/p>$/", $html)) {
            return '';
        }

        $html = str_replace('É', 'É', $html);
        $html = str_replace('é', 'é', $html);
        $html = str_replace('À', 'À', $html);
        $html = str_replace('&nbsp;', ' ', $html);

        $html = strip_tags($html, '<code><span><div><label><a><br><p><b><i><del><strike><s><u><img><video><audio><iframe><object><embed><param><blockquote><mark><cite><small><ul><ol><li><hr><dl><dt><dd><sup><sub><big><pre><code><figure><figcaption><strong><em><table><tr><td><th><tbody><thead><tfoot><h1><h2><h3><h4><h5><h6><footer><header><svg><g><image>');

        $html = str_replace("’", "'", $html);

        $html = mb_ereg_replace("\s+", " ", $html);
        $html = str_replace('« ', '«&nbsp;', $html);
        $html = str_replace(' »', '&nbsp;»', $html);
        $html = str_replace(' :', '&nbsp;:', $html);
        $html = str_replace(' ;', '&nbsp;;', $html);
        $html = str_replace(' ?', '&nbsp;?', $html);
        $html = str_replace(' !', '&nbsp;!', $html);

        // Hook: block-level cleanup. Generic build leaves blocks untouched;
        // framework subclasses strip their editor wrappers here.
        $html = static::cleanBlocks($html);

        // alt attribute on img tags if not set for SEO purposes
        $html = preg_replace_callback("/<img ([^>]+)>/", function ($m) {
            $attrs = Attributes::parse($m[1]);

            if (!isset($attrs['alt'])) {
                $attrs['alt'] = '';
            }

            return '<img' . Attributes::merge($attrs) . '>';
        }, $html);

        // Hook: link / data-attribute rewriting. Generic build leaves links
        // untouched; framework subclasses rewrite their CMS link + contact attrs.
        $html = static::cleanLinks($html);

        $html = str_replace("'", "’", $html);

        $html = mb_ereg_replace('<br />\s*</p>', '</p>', $html);
        $html = mb_ereg_replace('<p>\s*</p>', '', $html);

        return $html;
    }

    /**
     * Block-level element cleanup hook. No-op in the generic build; override to
     * strip framework/editor wrapper markup (e.g. `jcontainer` divs). Runs after
     * typography, before image normalisation.
     */
    protected static function cleanBlocks(string $html): string
    {
        return $html;
    }

    /**
     * Link / data-attribute rewriting hook. No-op in the generic build; override
     * to rewrite framework CMS attributes (e.g. `data-contact`, `data-link-*`).
     * Runs after image normalisation, before apostrophe restoration.
     */
    protected static function cleanLinks(string $html): string
    {
        return $html;
    }

    public static function cleanDom(string $html, array $options = []): string
    {
        $html = mb_ereg_replace('<br />\s*</p>', '</p>', $html);

        $doc = new \DOMDocument();

        $html = trim($html);

        if (!$html) {
            // DOMDocument doesn't support empty value and throws an error.
            // Return empty document instead.
            return '';
        }

        if (substr($html, 0, 1) !== '<') {
            // If HTML does not begin with a tag, we put a body tag around it.
            // If we do not do this, PHP will insert a paragraph tag around
            // the first block of text for some reason which can mess up
            // the newlines.
            $html = '<body>' . $html . '</body>';
        }

        $char_set = !empty($options['char_set']) ? $options['char_set'] : 'auto';
        if ('auto' === $char_set) {
            $char_set = mb_detect_encoding($html);
        } elseif (strpos($char_set, ',')) {
            mb_detect_order($char_set);
            $char_set = mb_detect_encoding($html);
        }
        // turn off error detection for Windows-1252 legacy html
        if (strpos($char_set, '1252')) {
            $options['ignore_errors'] = true;
        }

        $header = '<' . '?' . 'xml version="1.0" encoding="' . $char_set . '">';

        $doc->strictErrorChecking = false;
        $doc->recover = true;
        $doc->xmlStandalone = true;
        $old_internal_errors = \libxml_use_internal_errors(true);
        $load_result = $doc->loadHTML($header . $html, \LIBXML_NOWARNING | \LIBXML_NOERROR | \LIBXML_NONET | \LIBXML_PARSEHUGE);
        \libxml_use_internal_errors($old_internal_errors);

        if (!$load_result) {
            throw new \Exception("Could not load HTML - badly formed?\n\n" . $html);
        }

        $doc->validateOnParse = false;
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = true;

        $xpath = new \DOMXPath($doc);

        foreach ($xpath->query('//*[@style]') as $node) {
            if ($node instanceof \DOMElement) {
                $node->removeAttribute("style");
            }
        }

        foreach ($xpath->query('/child::*//*[not(*) and not(text()[normalize-space()])]') as $node) {
            $node->parentNode->removeChild($node);
        }

        $body = $doc->getElementsByTagName('body');

        if (!$body || 0 === $body->length) {
            return '';
        }

        $body = $body->item(0);
        $value = $doc->savehtml($body);
        $html = substr($value, 6, -7);

        return $html;
    }
}
