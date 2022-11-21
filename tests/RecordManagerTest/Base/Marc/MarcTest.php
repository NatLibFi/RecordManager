<?php
/**
 * MARC Handler Test Class
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2022.
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
namespace RecordManagerTest\Base\Marc;

use RecordManager\Base\Marc\Marc;
use RecordManagerTest\Base\Feature\FixtureTrait;

/**
 * MARC Handler Test Class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class MarcTest extends \PHPUnit\Framework\TestCase
{
    use FixtureTrait;

    /**
     * Data provider for testGetFieldsSubfieldsBySpecs
     *
     * @return array
     */
    public function getTestGetFieldsSubfieldsBySpecs(): array
    {
        return [
            [
                [
                    'Shinmeikai gendai kanwa jiten / Kageyama Terukuni [hoka]'
                    . ' hencho.',
                    '漢字源 : 上級漢和辞典  / 藤堂明保影山輝國 [ほか] 編著.',
                ],
                [[Marc::GET_BOTH, '245', ['a', 'b', 'c']]],
                false,
                false,
            ],
            [
                [
                    'Shinmeikai gendai kanwa jiten /',
                    'Kageyama Terukuni [hoka] hencho.',
                    '漢字源 :',
                    '上級漢和辞典  /',
                    '藤堂明保影山輝國 [ほか] 編著.',
                ],
                [[Marc::GET_BOTH, '245', ['a', 'b', 'c']]],
                false,
                true,
            ],
            [
                [
                    'Shinmeikai gendai kanwa jiten /',
                    'Kageyama Terukuni [hoka] hencho.',
                ],
                [[Marc::GET_NORMAL, '245', ['a', 'b', 'c']]],
                false,
                true,
            ],
            [
                [
                    '漢字源 :',
                    '上級漢和辞典  /',
                    '藤堂明保影山輝國 [ほか] 編著.',
                ],
                [[Marc::GET_ALT, '245', ['a', 'b', 'c']]],
                false,
                true,
            ],
            [
                [
                    'Hc KAISA 06EIKOTI 22219439490006253',
                    'Hc KAISA 06LAINA 22121097080006253',
                ],
                [[Marc::GET_BOTH, '852', []]],
                false,
                false,
            ],
            [
                [
                    'Hc',
                    'KAISA',
                    '06EIKOTI',
                    '22219439490006253',
                ],
                [[Marc::GET_BOTH, '852', []]],
                true,
                true,
            ],
        ];
    }

    /**
     * Test testGetFieldsSubfieldsBySpecs
     *
     * @param array $expected       Expected results
     * @param array $specs          Field specs
     * @param bool  $firstOnly      Whether to only first field
     * @param bool  $splitSubfields Whether to split subfields
     *
     * @dataProvider getTestGetFieldsSubfieldsBySpecs
     *
     * @return void
     */
    public function testGetFieldsSubfieldsBySpecs(
        array $expected,
        array $specs,
        bool $firstOnly,
        bool $splitSubfields
    ): void {
        $marc = new Marc($this->getFixture('record/marc_alt_script.xml'));

        $this->assertEquals(
            $expected,
            $marc->getFieldsSubfieldsBySpecs($specs, $firstOnly, $splitSubfields)
        );
    }

    /**
     * Test invalid indicator handling
     *
     * @return void
     */
    public function testInvalidIndicatorHandling(): void
    {
        $marc = new Marc($this->getFixture('record/marc3.xml'));

        $field = [
            'tag' => '035',
            'subfields' => []
        ];
        $this->assertEmpty($marc->getWarnings());
        $marc->getIndicator($field, 1);
        $marc->getIndicator($field, 2);
        $this->assertEquals(
            [
                'indicator 1 missing',
                'indicator 2 missing'
            ],
            $marc->getWarnings()
        );

        $this->expectExceptionMessage("Invalid indicator '3' requested");
        $marc->getIndicator($field, 3);
    }

    /**
     * Test changing a record
     *
     * @return void
     */
    public function testChanging(): void
    {
        $marc = new Marc($this->getFixture('record/marc3.xml'));

        // Test updating subfields:
        $fields = ['760', '762', '765'];
        foreach ($fields as $code) {
            foreach ($marc->getFields($code) as $fieldIdx => $marcfield) {
                foreach ($marc->getSubfields($marcfield, 'w')
                    as $subfieldIdx => $marcsubfield
                ) {
                    $targetId = 'foo.' . $marcsubfield;
                    $marc->updateFieldSubfield(
                        $code,
                        $fieldIdx,
                        'w',
                        $subfieldIdx,
                        $targetId
                    );
                }
            }
        }
        $f760 = $marc->getField('760');
        $this->assertEquals(
            'Main Series',
            $marc->getSubfield($f760, 'a')
        );
        $this->assertEquals(
            'foo.12',
            $marc->getSubfield($f760, 'w')
        );
        $this->assertEquals(
            [
                [
                    'tag' => '762',
                    'i1' => '0',
                    'i2' => ' ',
                    'subfields' => [
                        [
                            'code' => 'a',
                            'data' => 'Subseries',
                        ],
                    ],
                ],
                [
                    'tag' => '762',
                    'i1' => '0',
                    'i2' => ' ',
                    'subfields' => [
                        [
                            'code' => 'a',
                            'data' => 'Subseries ID',
                        ],
                        [
                            'code' => 'w',
                            'data' => 'foo.123',
                        ],
                    ],
                ],
                [
                    'tag' => '762',
                    'i1' => '0',
                    'i2' => ' ',
                    'subfields' => [
                        [
                            'code' => 'a',
                            'data' => 'Subseries 2 ID',
                        ],
                        [
                            'code' => 'w',
                            'data' => 'foo.234',
                        ],
                    ],
                ],
            ],
            $marc->getFields('762')
        );

        // Test adding a field:
        $this->assertEquals(1, count($marc->getFields('700')));
        $marc->addField(
            '700',
            ' ',
            ' ',
            [
                ['a' => 'Sajavaara, Paula']
            ]
        );

        $f700s = $marc->getFields('700');
        $this->assertEquals(2, count($f700s));
        $this->assertEquals(
            'Sajavaara, Paula',
            $marc->getSubfield($f700s[1], 'a')
        );

        $this->assertEquals([], $marc->getFields('007'));
        $marc->addField('007', '', '', 'cr');
        $this->assertEquals(['cr'], $marc->getFields('007'));

        // Test adding a subfield:
        $expected = [
            'tag' => '245',
            'i1' => '1',
            'i2' => '0',
            'subfields' =>
            [
                0 =>
                [
                    'code' => 'a',
                    'data' => 'Tutki ja kirjoita /',
                ],
                1 =>
                [
                    'code' => 'c',
                    'data' => 'Sirkka Hirsjärvi, Pirkko Remes, Paula Sajavaara.',
                ],
            ],
        ];
        $this->assertEquals($expected, $marc->getField('245'));
        $marc->addFieldSubfield('245', 0, 'n', '1');
        $expected['subfields'][] = ['code' => 'n', 'data' => '1'];
        $this->assertEquals($expected, $marc->getField('245'));

        // Test deletion:
        $marc->deleteFields('700');
        $this->assertEquals(0, count($marc->getFields('700')));

        // Test adding an empty field:
        $marc->addField(
            '700',
            ' ',
            ' ',
            []
        );
        $this->assertEmpty($marc->getWarnings());
        $this->assertEquals(
            [],
            $marc->getFieldsSubfieldsBySpecs(
                [
                    [Marc::GET_BOTH, '700', ['a']]
                ]
            )
        );
        $this->assertEquals(['missing subfields in 700'], $marc->getWarnings());
    }

    /**
     * Test updating an invalid field
     *
     * @return void
     */
    public function testUpdateInvalidField(): void
    {
        $marc = new Marc($this->getFixture('record/marc3.xml'));

        $this->expectExceptionMessage('Field 245[1] not found');
        $marc->updateFieldSubfield('245', 1, 'a', 0, 'foo');
    }

    /**
     * Test updating an invalid subfield
     *
     * @return void
     */
    public function testUpdateInvalidSubfield(): void
    {
        $marc = new Marc($this->getFixture('record/marc3.xml'));

        $this->expectExceptionMessage('Subfield 245[0]/a[1] not found');
        $marc->updateFieldSubfield('245', 0, 'a', 1, 'foo');
    }

    /**
     * Test legacy serialization
     *
     * @return void
     */
    public function testLegacySerialization(): void
    {
        $expected = $this->getFixture('marc_formats/marc_in_json.json');

        for ($version = 1; $version <= 3; $version++) {
            $marc = new Marc(
                $this->getFixture("marc_formats/legacy_v$version.json")
            );

            $this->assertJsonStringEqualsJsonString(
                $expected,
                $marc->toFormat('JSON'),
                "Legacy serialization v$version"
            );
        }

        $this->expectExceptionMessage('Unrecognized MARC JSON format: {"v": 9}');
        new Marc('{"v": 9}');
    }
}
