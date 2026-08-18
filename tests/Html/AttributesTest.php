<?php

/**
 * (c) Joffrey Demetz <joffrey.demetz@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JDZ\Ui\Tests\Html;

use JDZ\Ui\Html\Attributes;
use PHPUnit\Framework\TestCase;

class AttributesTest extends TestCase
{
    public function testParseMergeRoundTrip(): void
    {
        $attrs = Attributes::parse('class="a b" data-x="1"');

        $this->assertSame(['class' => 'a b', 'data-x' => '1'], $attrs);
        // merge() returns a leading space, ready to drop after a tag name.
        $this->assertSame(' class="a b" data-x="1"', Attributes::merge($attrs));
    }
}
