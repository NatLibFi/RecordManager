<?php
/**
 * MetadataUtils tests
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2015-2019
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
namespace RecordManagerTest\Base\Utils;

use RecordManager\Base\Utils\MetadataUtils;

/**
 * MetadataUtils tests
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class MetadataUtilsTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Tests for createSortableString
     *
     * @return void
     */
    public function testCreateSortableString()
    {
        $this->assertEquals(
            'A 3123', MetadataUtils::createSortableString('A 123')
        );
        $this->assertEquals(
            'A 3123 18 ABC', MetadataUtils::createSortableString('A 123 8 abc')
        );
        $this->assertEquals(
            'A 11 12', MetadataUtils::createSortableString('A  1   2')
        );
    }

    /**
     * Tests for normalizeKey
     *
     * @return void
     */
    public function testNormalizeKey()
    {
        $this->assertEquals(
            'abc', MetadataUtils::normalizeKey('A -.*B  C', 'NFKC')
        );

        $this->assertEquals(
            'oaaoaauie', MetadataUtils::normalizeKey('ÖÄÅöäåüïé', 'NFKC')
        );

        MetadataUtils::setConfig(
            [
                'Site' => [
                    'folding_ignore_characters' => 'åäöÅÄÖ',
                ],
            ],
            '.'
        );
        $this->assertEquals(
            'öäåöäåui', MetadataUtils::normalizeKey('ÖÄÅöäåüï', 'NFKC')
        );
    }

    /**
     * Test leading punctuation removal
     *
     * @return void
     */
    public function testStripLeadingPunctuation()
    {
        $values = [
            '.123' => '123',
            '/ . foo.' => 'foo.',
            '© 1979' => '© 1979',
            '-foo' => '-foo',
        ];
        foreach ($values as $from => $to) {
            $this->assertEquals(
                $to, MetadataUtils::stripLeadingPunctuation($from), $from
            );
        }

        $this->assertEquals(
            'foo', MetadataUtils::stripLeadingPunctuation('foo', '.-')
        );
    }

    /**
     * Test trailing punctuation removal
     *
     * @return void
     */
    public function testStripTrailingPunctuation()
    {
        $values = [
            '123.' => '123.',
            'foo /' => 'foo',
            '1979© ' => '1979©',
            'foo--' => 'foo--',
            'bar /:;,=([' => 'bar',
        ];
        foreach ($values as $from => $to) {
            $this->assertEquals(
                $to, MetadataUtils::stripTrailingPunctuation($from), $from
            );
        }

        $this->assertEquals(
            'foo', MetadataUtils::stripTrailingPunctuation('foo/]', ']')
        );

        $this->assertEquals(
            'foo', MetadataUtils::stripTrailingPunctuation('foo/:©', '©')
        );
    }

    /**
     * Test coordinate conversion
     *
     * @return void
     */
    public function testCoordinateToDecimal()
    {
        $values = [
            '' => 'NAN',
            ' ' => 'NAN',
            'W0765200' => -76.866666666667,
            'e0250831' => 25.141944444444,
            'e0250831.123' => 25.14197861111111,
            'E 0250831' => 25.141944444444,
            'W072.123' => -72.123,
            '-65.123' => -65.123,
            '+65.123' => 65.123,
            'E02508.31' => 25.1385,
            'N372500' => 37.416666666666664,
            'E079.533265' => 79.533265,
            'S012.583377' => -012.583377,
            '+079.533265' => 79.533265,
            '-012.583377' => -012.583377,
            '079.533265' => 79.533265,
            'E07932.5332' => 79.54222,
            'E0793235' => 79.54305555555555,
            'E0793235.575' => 79.54321527777778,
        ];

        foreach ($values as $from => $to) {
            $this->assertEquals(
                $to, MetadataUtils::coordinateToDecimal($from), $from
            );
        }
    }
}
