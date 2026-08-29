<?php

/**
 * (c) Joffrey Demetz <joffrey.demetz@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JDZ\Ui\Tests\Html;

use JDZ\Ui\Html\Image;
use PHPUnit\Framework\TestCase;

class ImageTest extends TestCase
{
    public function testMissingSourceFallsBack(): void
    {
        $image = new Image(sys_get_temp_dir(), 'thumbs', 0, '/media/share.png');

        $html = $image->render('media/nope.jpg', 'Alt "text"');

        $this->assertStringContainsString('src="/media/share.png"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringNotContainsString('"text"', substr($html, strpos($html, 'alt=')));
        // The placeholder does not exist under the public path: nothing to measure.
        $this->assertStringNotContainsString('width=', $html);
    }

    public function testResolvablePlaceholderCarriesItsDimensions(): void
    {
        $dir = $this->publicDir();
        $this->writeJpeg($dir . '/media/share.jpg', 64, 32);

        $image = new Image($dir, 'thumbs', 0, '/media/share.jpg');

        $html = $image->render('media/nope.jpg', 'Alt');

        $this->assertSame('<img src="/media/share.jpg" alt="Alt" width="64" height="32" loading="lazy" />', $html);
    }

    public function testMissingSourceWithoutFallbackRendersPlainTag(): void
    {
        $image = new Image(sys_get_temp_dir());

        $html = $image->render('media/nope.jpg', 'Alt');

        $this->assertStringContainsString('src="/media/nope.jpg"', $html);
        $this->assertStringContainsString('alt="Alt"', $html);
    }

    public function testZoomAddsDataAttr(): void
    {
        $image = new Image(sys_get_temp_dir());

        $html = $image->render('media/nope.jpg', '', true);

        $this->assertStringContainsString('data-zoom="media/nope.jpg"', $html);
    }

    public function testRealImageGetsOrientationAndThumb(): void
    {
        $dir = $this->publicDir();
        $this->writeJpeg($dir . '/media/wide.jpg', 400, 200);

        $image = new Image($dir);
        $html = $image->render('media/wide.jpg', 'Wide', false, 120);

        // width/height are the THUMB's (the file in src), not the original's.
        $this->assertSame(
            '<img src="/thumbs/media_wide.jpg-120.jpg" alt="Wide" width="120" height="60" loading="lazy"'
            . ' data-orientation="landscape" data-src="/media/wide.jpg" />',
            $html
        );
        $this->assertFileExists($dir . '/thumbs/media_wide.jpg-120.jpg');
        $this->assertSame([120, 60], array_slice(getimagesize($dir . '/thumbs/media_wide.jpg-120.jpg'), 0, 2));

        // Cached thumb (second render) reports the same size.
        $this->assertSame($html, $image->render('media/wide.jpg', 'Wide', false, 120));
    }

    public function testOriginalServedCarriesItsOwnDimensions(): void
    {
        $dir = $this->publicDir();
        $this->writeJpeg($dir . '/media/tall.jpg', 200, 400);

        $image = new Image($dir);

        $this->assertSame(
            '<img src="/media/tall.jpg" alt="Tall" width="200" height="400" loading="lazy" data-orientation="portrait" />',
            $image->render('media/tall.jpg', 'Tall')
        );
        // Source narrower than the requested width: no thumb, original dimensions.
        $this->assertSame(
            '<img src="/media/tall.jpg" alt="Tall" width="200" height="400" loading="lazy" data-orientation="portrait" data-zoom="media/tall.jpg" />',
            $image->render('media/tall.jpg', 'Tall', true, 800)
        );
    }

    private function publicDir(): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD not available');
        }

        $dir = sys_get_temp_dir() . '/jdzui-' . uniqid();
        mkdir($dir . '/media', 0777, true);

        return $dir;
    }

    private function writeJpeg(string $path, int $w, int $h): void
    {
        $im = imagecreatetruecolor($w, $h);
        imagejpeg($im, $path);
        imagedestroy($im);
    }
}
