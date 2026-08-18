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
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD not available');
        }

        $dir = sys_get_temp_dir() . '/jdzui-' . uniqid();
        mkdir($dir . '/media', 0777, true);
        $im = imagecreatetruecolor(400, 200);
        imagejpeg($im, $dir . '/media/wide.jpg');
        imagedestroy($im);

        $image = new Image($dir);
        $html = $image->render('media/wide.jpg', 'Wide', false, 120);

        $this->assertStringContainsString('data-orientation="landscape"', $html);
        $this->assertStringContainsString('/thumbs/media_wide.jpg-120.jpg', $html);
        $this->assertStringContainsString('data-src="/media/wide.jpg"', $html);
        $this->assertFileExists($dir . '/thumbs/media_wide.jpg-120.jpg');
    }
}
