<?php

/**
 * MetadataUtils tests
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2015-2023
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
 * @link     https://github.com/NatLibFi/RecordManager
 */

namespace RecordManagerTest\Base\Utils;

use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;

/**
 * MetadataUtils tests
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class MetadataUtilsTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Metadata utilities
     *
     * @var MetadataUtils
     */
    protected $metadataUtils;

    /**
     * Standard setup method.
     *
     * @return void
     */
    public function setUp(): void
    {
        $this->metadataUtils = new MetadataUtils(
            RECMAN_BASE_PATH,
            [
                'Site' => [
                    'articles' => 'articles.lst',
                ],
            ],
            $this->createMock(\RecordManager\Base\Utils\Logger::class)
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
            'abc',
            $this->metadataUtils->normalizeKey('A -.*B  C', 'NFKC')
        );

        $this->assertEquals(
            'oaaoaauie',
            $this->metadataUtils->normalizeKey('ÖÄÅöäåüïé', 'NFKC')
        );

        $metadataUtils = new MetadataUtils(
            '.',
            [
                'Site' => [
                    'key_folding_rules' => '',
                    'folding_ignore_characters' => 'åäöÅÄÖ',
                ],
            ],
            $this->createMock(Logger::class)
        );
        $this->assertEquals(
            'aaöäåöäåui',
            $metadataUtils->normalizeKey('AaÖÄÅöäåüï', 'NFKC')
        );

        $metadataUtils = new MetadataUtils(
            '.',
            [],
            $this->createMock(Logger::class)
        );
        $this->assertEquals(
            'aaoaaoaaui',
            $metadataUtils->normalizeKey('AaÖÄÅöäåüï', 'NFKC')
        );

        $metadataUtils = new MetadataUtils(
            '.',
            [
                'Site' => [
                    'key_folding_rules' => ':: NFD; :: lower; a\U00000308>AE;'
                        . ' o\U00000308>OE; a\U0000030A>AA; :: Latin; ::'
                        . ' [:Nonspacing Mark:] Remove; :: [:Punctuation:] Remove;'
                        . ' :: [:Whitespace:] Remove; :: NFKC; AE>ä; OE>ö; AA>å',
                ],
            ],
            $this->createMock(Logger::class)
        );
        $this->assertEquals(
            'aaöäåöäåui',
            $metadataUtils->normalizeKey('AaÖÄÅöäåüï', 'NFKC')
        );
    }

    /**
     * Data provider for testStripPunctuation
     *
     * @return array
     */
    public function stripPunctuationProvider(): array
    {
        return [
            ['123', '.123',],
            ['foo', '/ . foo.',],
            ['© 1979', '© 1979',],
            ['foo bar',' foo-bar ',],
            [
                'foo bar',
                "\t\\#*!¡?/:;., foo \t\\#*!¡?/:;.,=(['\"´`” ̈ bar =(['\"´`” ̈",
            ],
            ['...', '...',],
            ['foo', 'foo', '[\.\-]',],
            ['foo', '... foo', '[\.\-]',],
        ];
    }

    /**
     * Test punctuation removal
     *
     * @param string  $expected    Expected result
     * @param string  $str         String to process
     * @param ?string $punctuation Punctuation regexp to override default
     *
     * @dataProvider stripPunctuationProvider
     *
     * @return void
     */
    public function testStripPunctuation(
        string $expected,
        string $str,
        ?string $punctuation = null
    ): void {
        $this->assertEquals(
            $expected,
            $this->metadataUtils->stripPunctuation($str, $punctuation),
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
            '...' => '...',
        ];
        foreach ($values as $from => $to) {
            $this->assertEquals(
                $to,
                $this->metadataUtils->stripLeadingPunctuation($from),
                $from
            );
        }

        $this->assertEquals(
            'foo',
            $this->metadataUtils->stripLeadingPunctuation('foo', '.-')
        );

        $this->assertEquals(
            'foo',
            $this->metadataUtils->stripLeadingPunctuation('... foo', ' .-', false)
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
                $to,
                $this->metadataUtils->stripTrailingPunctuation($from),
                $from
            );
        }

        $this->assertEquals(
            'foo',
            $this->metadataUtils->stripTrailingPunctuation('foo/]', ']')
        );

        $this->assertEquals(
            'foo',
            $this->metadataUtils->stripTrailingPunctuation('foo/:©', '©')
        );
    }

    /**
     * Data provider for testHasTrailingPunctuation
     *
     * @return array
     */
    public function hasTrailingPunctuationProvider(): array
    {
        return [
            [true, '123.'],
            [false, 'Mattila P.'],
            [true, 'foo /'],
            [false, '1979© '],
            [false, 'foo--'],
            [true, 'bar /:;,=(['],
        ];
    }

    /**
     * Test hasTrailingPunctuation
     *
     * @param bool   $expected Expected result
     * @param string $str      String to process
     *
     * @dataProvider hasTrailingPunctuationProvider
     *
     * @return void
     */
    public function testHasTrailingPunctuation(
        bool $expected,
        string $str
    ): void {
        $this->assertEquals(
            $expected,
            $this->metadataUtils->hasTrailingPunctuation($str),
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

        // Test by rounding to lowest precision for PHP 8 compatibility
        // (see https://github.com/php/php-src/blob/PHP-8.0/UPGRADING#L584#L587):
        $precision = min(ini_get('precision'), ini_get('serialize_precision')) ?: 15;
        foreach ($values as $from => $to) {
            $this->assertEquals(
                is_string($to) ? $to : round($to, $precision),
                round($this->metadataUtils->coordinateToDecimal($from), $precision),
                $from
            );
        }
    }

    /**
     * Tests for isbn10to13
     *
     * @return void
     */
    public function testIsbn10to13()
    {
        $this->assertEquals(false, $this->metadataUtils->isbn10to13(''));
        $this->assertEquals(false, $this->metadataUtils->isbn10to13('foo'));
        $this->assertEquals(
            false,
            $this->metadataUtils->isbn10to13('9514920988 foo')
        );
        // Invalid ISBN:
        $this->assertEquals(false, $this->metadataUtils->isbn10to13('9514920096'));
        $this->assertEquals(
            '9789514920981',
            $this->metadataUtils->isbn10to13('9514920988')
        );
        $this->assertEquals(
            false,
            $this->metadataUtils->isbn10to13('951-492-098-8')
        );
    }

    /**
     * Tests for normalizeISBN
     *
     * @return void
     */
    public function testNormalizeISBN()
    {
        $this->assertEquals('', $this->metadataUtils->normalizeISBN(''));
        $this->assertEquals('', $this->metadataUtils->normalizeISBN('foo'));
        // Invalid ISBN:
        $this->assertEquals('', $this->metadataUtils->normalizeISBN('9514920096'));
        $this->assertEquals(
            '9789514920981',
            $this->metadataUtils->normalizeISBN('9514920988')
        );
        $this->assertEquals(
            '9789514920981',
            $this->metadataUtils->normalizeISBN('951-492-098-8')
        );
        $this->assertEquals(
            '9789514920981',
            $this->metadataUtils->normalizeISBN('9789514920981')
        );
        $this->assertEquals(
            '9789514920981',
            $this->metadataUtils->normalizeISBN('978-951-492098-1')
        );
    }

    /**
     * Data provider for testCreateSortTitle
     *
     * @return array
     */
    public function createSortTitleProvider(): array
    {
        return [
            ['', '', true],
            ['Theme is this', 'theme is this', true],
            ['The Me', 'me', true],
            ['The Me', 'the me', false],
            ['"The Others"', 'others', true],
            ["L'Avion", 'avion', true],
            ["Ll'Avion", 'll avion', true],
        ];
    }

    /**
     * Tests for cretateSortTitle
     *
     * @param string $title        Title
     * @param string $expected     Expected result
     * @param bool   $stripArticle Whether to strip any article from the beginning
     *
     * @return void
     *
     * @dataProvider createSortTitleProvider
     */
    public function testCreateSortTitle(
        string $title,
        string $expected,
        bool $stripArticle
    ): void {
        $this->assertEquals(
            $expected,
            $this->metadataUtils->createSortTitle($title, $stripArticle)
        );
    }
}
