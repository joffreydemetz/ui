<?php

declare(strict_types=1);

namespace JDZ\Ui\Tests\Html;

use JDZ\Ui\Html\Sanitizer;
use PHPUnit\Framework\TestCase;

/**
 * The server whitelist, against the corpus `jizy-editor/tests/sanitizer.test.js`
 * runs client-side. The two are deliberate mirrors — when one changes, the other
 * has to move with it, and the server is the one that decides.
 *
 * Everything here asserts `whitelist()`, the pass without French typography;
 * `clean()` (whitelist + `Helper::clean()`) gets its own section at the end,
 * because typography is where the two halves legitimately diverge.
 */
class SanitizerTest extends TestCase
{
    private function clean(string $html, string $tier = Sanitizer::FULL): string
    {
        return Sanitizer::whitelist($html, $tier);
    }

    // -- XSS -------------------------------------------------------------

    public function testScriptAndStyleAreDroppedWithTheirBody(): void
    {
        $this->assertStringNotContainsString('alert', $this->clean('<p>hi</p><script>alert(1)</script>'));
        $this->assertStringNotContainsString('body{', $this->clean('<style>body{x:1}</style><p>a</p>'));
    }

    public function testEventHandlersAreDropped(): void
    {
        $this->assertSame('<p>hi</p>', $this->clean('<p onclick="alert(1)">hi</p>'));
    }

    public function testInlineStyleThatIsNotAnAlignmentIsDropped(): void
    {
        $this->assertSame('<p>a</p>', $this->clean('<p style="color:red">a</p>'));
    }

    public function testAnAlignmentRidingAlongWithAnotherPropertyTakesItDownToo(): void
    {
        $this->assertSame('<p>a</p>', $this->clean('<p style="text-align:center;color:red">a</p>'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostileHrefs(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'mixed case' => ['JaVaScRiPt:alert(1)'],
            'control char' => ["java\x01script:alert(1)"],
            'data' => ['data:text/html,<svg onload=alert(1)>'],
            'vbscript' => ['vbscript:msgbox(1)'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileHrefs')]
    public function testAHostileHrefLosesItsTagButKeepsItsText(string $href): void
    {
        $out = $this->clean('<p><a href="' . $href . '">clic</a></p>');

        $this->assertStringNotContainsString('<a', $out);
        $this->assertStringContainsString('clic', $out);
    }

    public function testDataAndProtocolRelativeImagesAreDropped(): void
    {
        $this->assertSame('<p>a</p>', $this->clean('<p>a</p><img src="data:image/svg+xml,PHN2Zz4=" alt="">'));
        $this->assertSame('<p>a</p>', $this->clean('<p>a</p><img src="//evil.tld/x.png" alt="">'));
    }

    public function testIframeSvgAndFormAreDropped(): void
    {
        $this->assertSame('<p>a</p>', $this->clean(
            '<p>a</p><iframe src="https://evil.tld"></iframe><svg onload="alert(1)"></svg>'
            . '<form><input name="x"></form>'
        ));
    }

    // -- whitelist -------------------------------------------------------

    public function testPlainParagraph(): void
    {
        $this->assertSame('<p>Bonjour</p>', $this->clean('<p>Bonjour</p>'));
    }

    public function testBoldAndItalicAreFoldedOntoStrongAndEm(): void
    {
        $this->assertSame(
            '<p><strong>gras</strong> et <em>italique</em></p>',
            $this->clean('<p><b>gras</b> et <i>italique</i></p>')
        );
    }

    public function testUnderlineAndStrikeThroughAreKept(): void
    {
        $this->assertSame(
            '<p><u>souligné</u> et <s>barré</s></p>',
            $this->clean('<p><u>souligné</u> et <s>barré</s></p>')
        );
    }

    public function testStrikeAndDelAreFoldedOntoS(): void
    {
        $this->assertSame('<p><s>a</s><s>b</s></p>', $this->clean('<p><strike>a</strike><del>b</del></p>'));
    }

    public function testH4IsAHeadingOfItsOwnWhileH1AndH5PlusStillFold(): void
    {
        $this->assertSame(
            '<h2>A</h2><h4>B</h4><h3>C</h3><h3>D</h3>',
            $this->clean('<h1>A</h1><h4>B</h4><h5>C</h5><h6>D</h6>')
        );
    }

    public function testSpanIsUnwrapped(): void
    {
        $this->assertSame('<p>a b</p>', $this->clean('<p>a <span class="x">b</span></p>'));
    }

    public function testListsSurvive(): void
    {
        $this->assertSame(
            '<ul><li>un</li><li>deux</li></ul>',
            $this->clean('<ul><li>un</li><li>deux</li></ul>')
        );
    }

    public function testNestedListsSurvive(): void
    {
        $this->assertSame(
            '<ul><li>un<ul><li>un.un</li></ul></li><li>deux</li></ul>',
            $this->clean('<ul><li>un<ul><li>un.un</li></ul></li><li>deux</li></ul>')
        );
    }

    public function testARelativeImageKeepsItsSrcAndGainsAnAlt(): void
    {
        $this->assertSame(
            '<p>a</p><p><img src="media/blog/x.jpg" alt=""></p>',
            $this->clean('<p>a</p><img src="media/blog/x.jpg">')
        );
    }

    public function testHttpsAndMailtoLinksSurvive(): void
    {
        $this->assertSame(
            '<p><a href="https://x.tld/y">y</a> <a href="mailto:a@b.tld">a</a></p>',
            $this->clean('<p><a href="https://x.tld/y">y</a> <a href="mailto:a@b.tld">a</a></p>')
        );
    }

    public function testALinkKeepsATitleAndABlankTarget(): void
    {
        $this->assertSame(
            '<p><a href="https://x.tld" target="_blank" title="Chez X">y</a></p>',
            $this->clean('<p><a href="https://x.tld" target="_blank" title="Chez X">y</a></p>')
        );
    }

    public function testAnyOtherTargetIsDropped(): void
    {
        $this->assertSame(
            '<p><a href="https://x.tld">y</a></p>',
            $this->clean('<p><a href="https://x.tld" target="_top">y</a></p>')
        );
        $this->assertSame(
            '<p><a href="https://x.tld">y</a></p>',
            $this->clean('<p><a href="https://x.tld" target="_self">y</a></p>')
        );
    }

    public function testEmptyElementsArePruned(): void
    {
        $this->assertSame('<p>a</p>', $this->clean('<p>a</p><p></p><h2>  </h2>'));
    }

    public function testAParagraphHoldingOnlyABrIsNotContent(): void
    {
        $this->assertSame('<p><br></p>', $this->clean('<p><br></p>'));
    }

    // -- alignment -------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function alignments(): array
    {
        return ['center' => ['center'], 'right' => ['right'], 'justify' => ['justify']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('alignments')]
    public function testAnAlignmentRoundTripsAsAStyle(string $value): void
    {
        $out = $this->clean('<p style="text-align:' . $value . '">a</p>');

        $this->assertSame('<p style="text-align: ' . $value . ';">a</p>', $out);
        $this->assertSame($out, $this->clean($out));
    }

    public function testALegacyAlignAttributeBecomesTheStyle(): void
    {
        $this->assertSame('<h2 style="text-align: center;">T</h2>', $this->clean('<h2 align="center">T</h2>'));
        $this->assertStringContainsString(
            'style="text-align: right;"',
            $this->clean('<ul><li align="right">x</li></ul>')
        );
    }

    public function testLeftIsTheDefaultSoItIsNotStored(): void
    {
        $this->assertSame('<p>a</p>', $this->clean('<p style="text-align:left">a</p>'));
        $this->assertSame('<p>a</p>', $this->clean('<p align="left">a</p>'));
    }

    public function testAnUnknownAlignmentIsDropped(): void
    {
        $this->assertSame('<p>a</p>', $this->clean('<p style="text-align:inherit">a</p>'));
    }

    public function testAnAlignmentIsCaseInsensitive(): void
    {
        $this->assertSame('<p style="text-align: center;">a</p>', $this->clean('<p style="TEXT-ALIGN: CENTER">a</p>'));
    }

    public function testBlockquoteIsNotAlignable(): void
    {
        $this->assertSame(
            '<blockquote>a</blockquote>',
            $this->clean('<blockquote style="text-align:center">a</blockquote>')
        );
    }

    // -- figures ---------------------------------------------------------

    private const FIGURE = '<figure class="pull-left"><img src="media/blog/x.jpg" alt="A">'
        . '<figcaption>Légende</figcaption></figure>';

    public function testAFloatedFigureSurvivesWhole(): void
    {
        $this->assertSame(self::FIGURE, $this->clean(self::FIGURE));
    }

    public function testAFigureSitsInTheRootNeverWrappedInAParagraph(): void
    {
        $this->assertSame('<p>a</p>' . self::FIGURE, $this->clean('<p>a</p>' . self::FIGURE));
    }

    public function testOnlyTheTwoFrontClassesAreKept(): void
    {
        $this->assertSame(
            '<figure class="pull-right"><img src="media/x.jpg" alt=""></figure>',
            $this->clean('<figure class="pull-right is-selected"><img src="media/x.jpg" alt=""></figure>')
        );

        $this->assertSame(
            '<figure><img src="media/x.jpg" alt=""></figure>',
            $this->clean('<figure class="mso-float"><img src="media/x.jpg" alt=""></figure>')
        );
    }

    public function testAnEmptyCaptionIsDroppedAndTheFigureIsKeptByItsImage(): void
    {
        $this->assertSame(
            '<figure><img src="media/x.jpg" alt=""></figure>',
            $this->clean('<figure><img src="media/x.jpg" alt=""><figcaption>  </figcaption></figure>')
        );
    }

    public function testAFigureWithNoUsableImageGoesWithIt(): void
    {
        $this->assertSame(
            '<figure><figcaption>x</figcaption></figure>',
            $this->clean('<figure><img src="javascript:alert(1)"><figcaption>x</figcaption></figure>')
        );
    }

    // -- paste -----------------------------------------------------------

    public function testWordMarkupKeepsItsTextAndLosesEverythingElse(): void
    {
        $word = '<div class="WordSection1"><p class="MsoNormal" style="margin:0cm">'
            . '<span style="font-size:11pt;font-family:Calibri"><o:p>Un paragraphe </o:p></span>'
            . '<b style="mso-bidi-font-weight:normal"><span style="font-size:11pt">collé</span></b>'
            . '</p><!--[if gte mso 9]><xml><w:WordDocument/></xml><![endif]--></div>';
        $out = $this->clean($word);

        $this->assertStringNotContainsString('style=', $out);
        $this->assertStringNotContainsString('class=', $out);
        $this->assertStringNotContainsString('mso', $out);
        $this->assertStringContainsString('Un paragraphe', $out);
        $this->assertStringContainsString('<strong>collé</strong>', $out);
    }

    public function testGoogleDocsMarkupKeepsItsTextAndLosesEverythingElse(): void
    {
        $gdocs = '<meta charset="utf-8"><b style="font-weight:normal" id="docs-internal-guid-1">'
            . '<p dir="ltr" style="line-height:1.38"><span style="font-weight:700">Titre</span>'
            . '<span style="font-style:italic"> penché</span></p></b>';
        $out = $this->clean($gdocs);

        $this->assertStringNotContainsString('style=', $out);
        $this->assertStringNotContainsString('<meta', $out);
        $this->assertStringContainsString('Titre', $out);
    }

    // -- orphans ---------------------------------------------------------

    public function testBareTextIsWrapped(): void
    {
        $this->assertSame('<p>Bonjour</p>', $this->clean('Bonjour'));
        $this->assertSame('<p>Bonjour</p><h2>T</h2>', $this->clean('Bonjour<h2>T</h2>'));
    }

    public function testDivSoupBecomesParagraphs(): void
    {
        $this->assertSame('<p>un</p><p>deux</p>', $this->clean('<div>un</div><div>deux</div>'));
    }

    public function testWhitespaceBetweenBlocksIsDropped(): void
    {
        $this->assertSame('<p>a</p><p>b</p>', $this->clean("<p>a</p>\n  \n<p>b</p>"));
        $this->assertSame(
            '<ul><li>un</li><li>deux</li></ul>',
            $this->clean("<ul>\n  <li>un</li>\n  <li>deux</li>\n</ul>")
        );
        $this->assertSame(
            '<blockquote><p>a</p></blockquote>',
            $this->clean("<blockquote>\n<p>a</p>\n</blockquote>")
        );
    }

    public function testButNeverTheSpaceBetweenTwoInlineTags(): void
    {
        $this->assertSame(
            '<ul><li><strong>a</strong> <em>b</em></li></ul>',
            $this->clean('<ul><li><strong>a</strong> <em>b</em></li></ul>')
        );
        $this->assertSame(
            '<blockquote><strong>a</strong> <em>b</em></blockquote>',
            $this->clean('<blockquote><strong>a</strong> <em>b</em></blockquote>')
        );
    }

    // -- minimal tier ----------------------------------------------------

    public function testMinimalFlattensHeadingsListsImagesAndFigures(): void
    {
        $this->assertSame('<p>T</p>', $this->clean('<h2>T</h2>', Sanitizer::MINIMAL));
        $this->assertSame('<p>un</p>', $this->clean('<ul><li>un</li></ul>', Sanitizer::MINIMAL));
        $this->assertSame('<p>a</p>', $this->clean('<p>a</p><img src="media/x.jpg" alt="">', Sanitizer::MINIMAL));
        $this->assertSame(
            '<p>L</p>',
            $this->clean('<figure><img src="media/x.jpg" alt=""><figcaption>L</figcaption></figure>', Sanitizer::MINIMAL)
        );
    }

    public function testMinimalHasNoUnderlineOrStrikeEither(): void
    {
        $this->assertSame('<p>ab</p>', $this->clean('<p><u>a</u><s>b</s></p>', Sanitizer::MINIMAL));
    }

    public function testMinimalKeepsEmphasisAndLinks(): void
    {
        $this->assertSame(
            '<p><em>x</em> <a href="https://x.tld">y</a></p>',
            $this->clean('<p><em>x</em> <a href="https://x.tld">y</a></p>', Sanitizer::MINIMAL)
        );
    }

    public function testAnUnknownTierGetsTheFullSet(): void
    {
        $this->assertSame('<h2>T</h2>', $this->clean('<h2>T</h2>', 'nonsense'));
    }

    // -- idempotence -----------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function corpus(): array
    {
        return [
            'div soup + h4' => ['<div>a<b>b</b></div><h4>c</h4>'],
            'alignments' => ['<p align="center">a</p><h4 style="text-align:justify">b</h4>'],
            'figure' => ['<figure class="pull-left"><img src="media/x.jpg" alt="A"><figcaption>L</figcaption></figure>'],
            'figure + marker class' => ['<figure class="pull-right is-selected"><img src="media/x.jpg" alt=""></figure>'],
            'link + inline' => ['<p><a href="https://x.tld" target="_blank" title="T">y</a> <u>u</u> <strike>s</strike></p>'],
            'indented list' => ["<ul>\n<li align=\"right\">un</li>\n<li>deux</li>\n</ul>"],
            'indented quote' => ["<blockquote>\n<p>citation</p>\n</blockquote>"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('corpus')]
    public function testCleaningTwiceChangesNothing(string $input): void
    {
        $once = $this->clean($input);

        $this->assertSame($once, $this->clean($once));
    }

    // -- degenerate input ------------------------------------------------

    public function testDegenerateInputYieldsNothing(): void
    {
        $this->assertSame('', $this->clean(''));
        $this->assertSame('', $this->clean("   \n "));
        $this->assertSame('', $this->clean('<p></p>'));
    }

    public function testUnclosedTagsAreRecovered(): void
    {
        $this->assertSame('<p>a<strong>b</strong></p>', $this->clean('<p>a<strong>b'));
    }

    public function testAccentsSurvive(): void
    {
        $this->assertSame('<p>Élève à côté</p>', $this->clean('<p>Élève à côté</p>'));
    }

    // -- clean() = whitelist + typography --------------------------------

    public function testCleanAddsTheFrenchTypographyOnTop(): void
    {
        $this->assertStringContainsString('&nbsp;:', Sanitizer::clean('<p>Attention : voici</p>'));
        $this->assertStringContainsString('’', Sanitizer::clean("<p>l'ami</p>"));
    }

    public function testCleanKeepsWhatTheWhitelistJustAllowed(): void
    {
        // `Helper::clean()` is strip_tags-based: every tag the whitelist now
        // keeps has to be in its allow-list too, or it silently unwraps them.
        $out = Sanitizer::clean(
            '<h4>Titre</h4><p style="text-align:center"><u>u</u> <s>s</s></p>' . self::FIGURE
        );

        foreach (['<h4>', '<u>', '<s>', '<figure', '<figcaption>', 'text-align: center;'] as $needle) {
            $this->assertStringContainsString($needle, $out);
        }
    }

    public function testCleanIsIdempotent(): void
    {
        $once = Sanitizer::clean('<p>Bonjour : <b>toi</b></p>');

        $this->assertSame($once, Sanitizer::clean($once));
    }

    public function testCleanCollapsesTheEmptyishParagraphHelperRejects(): void
    {
        // `Helper::clean()` treats `<p><br></p>` as nothing at all — the one
        // place the server deliberately says less than the client whitelist.
        $this->assertSame('<p><br></p>', Sanitizer::whitelist('<p><br></p>'));
        $this->assertSame('', Sanitizer::clean('<p><br></p>'));
    }
}
