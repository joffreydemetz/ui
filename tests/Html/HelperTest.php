<?php

declare(strict_types=1);

namespace JDZ\Ui\Tests\Html;

use JDZ\Ui\Html\Helper;
use PHPUnit\Framework\TestCase;

/**
 * Concrete subclass exercising the two framework hooks.
 */
class HookedHelper extends Helper
{
    protected static function cleanBlocks(string $html): string
    {
        return str_replace('<div class="jcontainer">', '<div>', $html);
    }

    protected static function cleanLinks(string $html): string
    {
        return str_replace('data-contact="dpo"', 'href="/contact/"', $html);
    }
}

class HelperTest extends TestCase
{
    public function testEmptyishContentCollapsesToEmptyString(): void
    {
        $this->assertSame('', Helper::clean(''));
        $this->assertSame('', Helper::clean('<p> </p>'));
        $this->assertSame('', Helper::clean('<p><br/></p>'));
    }

    public function testFrenchTypographyGetsNonBreakingSpaces(): void
    {
        $this->assertSame(
            'Bonjour&nbsp;: le «&nbsp;monde&nbsp;»&nbsp;!',
            Helper::clean('Bonjour : le « monde » !')
        );
    }

    public function testDisallowedTagsAreStripped(): void
    {
        $cleaned = Helper::clean('<p>ok</p><script>alert("x")</script>');

        $this->assertStringNotContainsString('<script', $cleaned);
        $this->assertStringContainsString('<p>ok</p>', $cleaned);
    }

    public function testStraightApostropheBecomesTypographic(): void
    {
        $this->assertSame('<p>l’été</p>', Helper::clean("<p>l'été</p>"));
    }

    public function testImgWithoutAltGetsEmptyAlt(): void
    {
        $this->assertStringContainsString('alt=""', Helper::clean('<p><img src="a.jpg"></p>'));
    }

    public function testImgKeepsExistingAlt(): void
    {
        $this->assertStringContainsString('alt="Photo"', Helper::clean('<p><img src="a.jpg" alt="Photo"></p>'));
    }

    public function testSubclassHooksRun(): void
    {
        $this->assertStringContainsString(
            '<div><p>x</p></div>',
            HookedHelper::clean('<div class="jcontainer"><p>x</p></div>')
        );
        $this->assertStringContainsString(
            'href="/contact/"',
            HookedHelper::clean('<p><a data-contact="dpo">contact</a></p>')
        );
    }

    public function testCleanDomStripsInlineStylesAndEmptyNodes(): void
    {
        $html = '<p style="color:red">ok</p><p></p>';

        $cleaned = Helper::cleanDom($html);

        $this->assertStringNotContainsString('style=', $cleaned);
        $this->assertStringContainsString('ok', $cleaned);
        $this->assertStringNotContainsString('<p></p>', $cleaned);
    }

    /**
     * An empty paragraph after content is dropped: the pattern had a stray quote
     * and only matched `<p></p>"`.
     */
    public function testCleanDropsEmptyParagraphsAfterContent(): void
    {
        $this->assertSame('<p>Hello</p><p>World</p>', Helper::clean('<p>Hello</p><p> </p><p>World</p><p></p>'));
        $this->assertSame('<p>Hello</p>', Helper::clean('<p>Hello<br /> </p>'));
    }
}
