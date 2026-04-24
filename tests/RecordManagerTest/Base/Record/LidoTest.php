<?php

/**
 * LIDO Record Driver Test Class
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2020-2025.
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

namespace RecordManagerTest\Base\Record;

use RecordManager\Base\Record\Lido;

/**
 * Base LIDO Record Driver Test Class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class LidoTest extends RecordTestBase
{
    /**
     * Test LIDO record handling
     *
     * @return void
     */
    public function testLido1()
    {
        $record = $this->createRecord(
            Lido::class,
            'lido1.xml',
            [],
            'Base',
            [],
            [
                'Metadata Language Code Mappings' => [
                    'fin' => 'fi',
                    'swe' => 'sv',
                    'en-gb' => 'en',
                    'eng' => 'en',
                    'sme' => 'se',
                ],
            ],
        );
        $fields = $record->toSolrArray();
        unset($fields['fullrecord']);

        $expected = [
            'record_format' => 'lido',
            'title_full' => 'English Title; English Alt Title',
            'title_short' => 'English Title; English Alt Title',
            'title' => 'English Title; English Alt Title',
            'title_sort' => 'english title english alt title',
            'title_alt' => ['Luonnonsuojelusäädökset / toimittanut Raimo Luhtanen; Säädökset'],
            'description' => '',
            'format' => 'Kirja',
            'institution' => 'Test Institution',
            'author' => [
                'Designer, Test',
                'Luhtanen, Raimo',
            ],
            'author_sort' => 'Designer, Test',
            'author2' => [],
            'topic_facet' => [
                'retkeily',
                'ulkoilu',
            ],
            'topic' => [
                'retkeily',
                'ulkoilu',
            ],
            'geographic_facet' => [],
            'geographic' => [],
            'era' => [],
            'era_facet' => [],
            'collection' => '',
            'ctrlnum' => [
                '(knp)M011-320623',
            ],
            'isbn' => [
                '9789518593730',
                '9789518593731',
                '9789518593732',
            ],
            'issn' => [
                '0357-5284',
            ],
            'url' => [],
            'thumbnail' => '',
            'allfields' => [
                'knp-247394',
                'Kirja',
                'Säädökset',
                'Luonnonsuojelusäädökset / toimittanut Raimo Luhtanen',
                'English Title',
                'English Alt Title',
                'Test Institution',
                '26054',
                '9518593736',
                '9789518593731',
                '9789518593732',
                '0357-5284',
                'retkeily',
                'ulkoilu',
                'Luhtanen, Raimo',
                'Designer, Test',
                'M011-320623',
                'Test Institution',
                '247394',
            ],
            'publishDate' => [],
            'publishDateRange' => [],
            'publishDateSort' => '',
        ];

        $this->compareArray($expected, $fields, 'toSolrArray');

        $keys = $record->getWorkIdentificationData();
        $expected = [
            [
                'authors' => [
                    [
                        'type' => 'author',
                        'value' => 'Designer, Test',
                    ],
                    [
                        'type' => 'author',
                        'value' => 'Luhtanen, Raimo',
                    ],
                ],
                'authorsAltScript' => [],
                'titles' => [
                    [
                        'type' => 'title',
                        'value' => 'English Title; English Alt Title',
                    ],
                    [
                        'type' => 'title',
                        'value' => 'Luonnonsuojelusäädökset / toimittanut Raimo'
                        . ' Luhtanen; Säädökset',
                    ],
                ],
                'titlesAltScript' => [],
            ],
        ];

        $this->compareArray($expected, $keys, 'getWorkIdentificationData');
    }

    /**
     * Test LIDO record handling with title merging disabled
     *
     * @return void
     */
    public function testLido1NonMergedTitle()
    {
        $record = $this->createRecord(
            Lido::class,
            'lido1.xml',
            [
                '__unit_test_no_source__' => [
                    'driverParams' => [
                        'mergeTitleValues=false',
                        'mergeTitleSets=false',
                        'defaultDisplayLanguage=fi',
                    ],
                ],
            ],
            'Base',
            [],
            [
                'Metadata Language Code Mappings' => [
                    'fin' => 'fi',
                    'swe' => 'sv',
                    'en-gb' => 'en',
                    'eng' => 'en',
                    'sme' => 'se',
                ],
            ],
        );
        $fields = $record->toSolrArray();
        unset($fields['fullrecord']);

        $expected = [
            'record_format' => 'lido',
            'title_full' => 'Luonnonsuojelusäädökset / toimittanut Raimo Luhtanen',
            'title_short' => 'Luonnonsuojelusäädökset / toimittanut Raimo Luhtanen',
            'title' => 'Luonnonsuojelusäädökset / toimittanut Raimo Luhtanen',
            'title_sort' => 'luonnonsuojelusäädökset toimittanut raimo luhtanen',
            'title_alt' => [
                'Säädökset',
                'English Title',
            ],
            'description' => '',
            'format' => 'Kirja',
            'institution' => 'Test Institution',
            'author' => [
                'Designer, Test',
                'Luhtanen, Raimo',
            ],
            'author_sort' => 'Designer, Test',
            'author2' => [],
            'topic_facet' => [
                'retkeily',
                'ulkoilu',
            ],
            'topic' => [
                'retkeily',
                'ulkoilu',
            ],
            'geographic_facet' => [],
            'geographic' => [],
            'era' => [],
            'era_facet' => [],
            'collection' => '',
            'ctrlnum' => [
                '(knp)M011-320623',
            ],
            'isbn' => [
                '9789518593730',
                '9789518593731',
                '9789518593732',
            ],
            'issn' => [
                '0357-5284',
            ],
            'url' => [],
            'thumbnail' => '',
            'allfields' => [
                'knp-247394',
                'Kirja',
                'Säädökset',
                'Luonnonsuojelusäädökset / toimittanut Raimo Luhtanen',
                'English Title',
                'English Alt Title',
                'Test Institution',
                '26054',
                '9518593736',
                '9789518593731',
                '9789518593732',
                '0357-5284',
                'retkeily',
                'ulkoilu',
                'Luhtanen, Raimo',
                'Designer, Test',
                'M011-320623',
                'Test Institution',
                '247394',
            ],
            'publishDate' => [],
            'publishDateRange' => [],
            'publishDateSort' => '',
        ];

        $this->compareArray($expected, $fields, 'toSolrArray');

        $keys = $record->getWorkIdentificationData();
        $expected = [
            [
                'authors' => [
                    [
                        'type' => 'author',
                        'value' => 'Designer, Test',
                    ],
                    [
                        'type' => 'author',
                        'value' => 'Luhtanen, Raimo',
                    ],
                ],
                'authorsAltScript' => [],
                'titles' => [
                    [
                        'type' => 'title',
                        'value' => 'Luonnonsuojelusäädökset / toimittanut Raimo'
                        . ' Luhtanen',
                    ],
                    [
                        'type' => 'title',
                        'value' => 'Säädökset',
                    ],
                                        [
                        'type' => 'title',
                        'value' => 'English Title',
                    ],
                ],
                'titlesAltScript' => [],
            ],
        ];

        $this->compareArray($expected, $keys, 'getWorkIdentificationData');
    }

    /**
     * Test LIDO title handling when title equals work type
     *
     * @return void
     */
    public function testLido3TitleEqualsWorkType()
    {
        $record = $this->createRecord(Lido::class, 'lido3.xml');
        $fields = $record->toSolrArray();

        $this->assertEquals('Maisema', $fields['title']);
        $this->assertEquals('Maisema', $fields['title_full']);
        $this->assertEquals('Maisema', $fields['title_short']);
        $this->assertEquals('maisema', $fields['title_sort']);

        $record = $this->createRecord(
            Lido::class,
            'lido3.xml',
            [
                '__unit_test_no_source__' => [
                    'driverParams' => [
                        'allowTitleToMatchFormat=true',
                    ],
                ],
            ]
        );
        $fields = $record->toSolrArray();

        $this->assertEquals('Maalaus', $fields['title']);
        $this->assertEquals('Maalaus', $fields['title_full']);
        $this->assertEquals('Maalaus', $fields['title_short']);
        $this->assertEquals('maalaus', $fields['title_sort']);
    }

    /**
     * Test LIDO work identification data handling
     *
     * @return void
     */
    public function testLidoWorkKeys()
    {
        $record = $this->createRecord(Lido::class, 'lido2.xml');
        $keys = $record->getWorkIdentificationData();
        $expected = [
            [
                'authors' => [],
                'authorsAltScript' => [],
                'titles' => [
                    [
                        'type' => 'title',
                        'value' => 'Kitchen tool; Scissors',
                    ],
                    [
                        'type' => 'title',
                        'value' => 'Keittiövälineet; Sakset',
                    ],
                ],
                'titlesAltScript' => [],
            ],
        ];

        $this->compareArray($expected, $keys, 'getWorkIdentificationData');
    }

    /**
     * Test getTitles
     *
     * @return void
     */
    public function testGetTitles()
    {
        $record = $this->createRecord(
            Lido::class,
            'lido1.xml',
            [],
            'Base',
            [],
            [
                'Metadata Language Code Mappings' => [
                    'fin' => 'fi',
                    'swe' => 'sv',
                    'en-gb' => 'en',
                    'eng' => 'en',
                    'sme' => 'se',
                ],
            ],
        );
        $reflection = new \ReflectionObject($record);
        $getTitles = $reflection->getMethod('getTitles');

        $this->assertEquals(
            [
                'preferred' => 'English Title; English Alt Title',
                'alternate' => ['Luonnonsuojelusäädökset / toimittanut Raimo Luhtanen; Säädökset'],
            ],
            $getTitles->invokeArgs($record, [])
        );
        $this->assertEquals(
            [
                'preferred' => 'Luonnonsuojelusäädökset / toimittanut Raimo Luhtanen; Säädökset',
                'alternate' => [],
            ],
            $getTitles->invokeArgs($record, ['fi'])
        );
        $record = $this->createRecord(
            Lido::class,
            'lido1.xml',
            [
                '__unit_test_no_source__' => [
                    'driverParams' => [
                        'mergeTitleValues=false',
                    ],
                ],
            ],
            'Base',
            [],
            [
                'Metadata Language Code Mappings' => [
                    'fin' => 'fi',
                    'swe' => 'sv',
                    'en-gb' => 'en',
                    'eng' => 'en',
                    'sme' => 'se',
                ],
            ],
        );
        $this->assertEquals(
            [
                'preferred' => 'English Title',
                'alternate' => [
                    'Luonnonsuojelusäädökset / toimittanut Raimo Luhtanen',
                    'English Alt Title',
                ],
            ],
            $getTitles->invokeArgs($record, [])
        );
        $this->assertEquals(
            [
                'preferred' => 'Luonnonsuojelusäädökset / toimittanut Raimo Luhtanen',
                'alternate' => [
                    'Säädökset',
                ],
            ],
            $getTitles->invokeArgs($record, ['fi'])
        );
    }

    /**
     * Test LIDO hierarchy handling
     *
     * @return void
     */
    public function testLidoHierarchies()
    {
        $record = $this->createRecord(
            Lido::class,
            'lido_hierarchy.xml',
            [
                '__unit_test_no_source__' => [
                    'driverParams' => [
                        'indexHierarchies=true',
                        'addIdToHierarchyTitle=true',
                        'defaultDisplayLanguage=fi',
                    ],
                ],
            ]
        );
        $fields = $record->toSolrArray();

        $this->assertEquals('testit-200', $fields['hierarchy_top_id']);
        $this->assertEquals('Yksikkötestikokoelma; Kaikki testit kautta aikojen', $fields['hierarchy_top_title']);
        $this->assertEquals('testit-404', $fields['hierarchy_parent_id']);
        $this->assertEquals('Puuttuvien testien kokoelma', $fields['hierarchy_parent_title']);
        $this->assertEquals('testi-000000418', $fields['hierarchy_sequence']);
        $this->assertEquals('testi-418 Testi joka puuttui', $fields['title_in_hierarchy']);
        $this->assertContains('Yksikkötestikokoelma; Kaikki testit kautta aikojen', $fields['allfields']);
        $this->assertContains('Puuttuvien testien kokoelma', $fields['allfields']);
        $this->assertContains('testi-418 Testi joka puuttui', $fields['allfields']);
        $this->assertEquals('Testiarkisto', $fields['collection']);
    }

    /**
     * Data provider for testLidoRootElementHandling
     *
     * @return \Iterator
     */
    public static function lidoRootElementProvider(): \Iterator
    {
        $schema10 = 'schemaLocation="http://www.lido-schema.org http://www.lido-schema.org/schema/v1.0/lido-v1.0.xsd"';
        $schema11 = 'schemaLocation="http://www.lido-schema.org http://www.lido-schema.org/schema/v1.1/lido-v1.1.xsd"';
        yield 'lido 1.0 with lidoWrap' => [
            "<lidoWrap $schema10><lido><lidoRecID type=\"ITEM\">123</lidoRecID></lido></lidoWrap>",
            "<lidoWrap $schema10><lido><lidoRecID type=\"ITEM\">123</lidoRecID></lido></lidoWrap>",
        ];
        yield 'lido 1.1 with lidoWrap' => [
            "<lidoWrap $schema11><lido><lidoRecID type=\"ITEM\">123</lidoRecID></lido></lidoWrap>",
            "<lidoWrap $schema11><lido><lidoRecID type=\"ITEM\">123</lidoRecID></lido></lidoWrap>",
        ];
        yield 'lido 1.0 without lidoWrap' => [
            "<lido $schema10><lidoRecID type=\"ITEM\">123</lidoRecID></lido>",
            "<lidoWrap $schema10><lido><lidoRecID type=\"ITEM\">123</lidoRecID></lido></lidoWrap>",
        ];
        yield 'lido 1.1 without lidoWrap' => [
            "<lido $schema11><lidoRecID type=\"ITEM\">123</lidoRecID></lido>",
            "<lidoWrap $schema11><lido><lidoRecID type=\"ITEM\">123</lidoRecID></lido></lidoWrap>",
        ];
        yield 'unspecified lido version without lidoWrap' => [
            '<lido><lidoRecID type="ITEM">123</lidoRecID></lido>',
            "<lidoWrap $schema11><lido><lidoRecID type=\"ITEM\">123</lidoRecID></lido></lidoWrap>",
        ];
    }

    /**
     * Test LIDO root element handling.
     *
     * @param string $input    Input XML
     * @param string $expected Expected result XML
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('lidoRootElementProvider')]
    public function testLidoRootElementHandling(string $input, string $expected): void
    {
        $prolog = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $record = $this->createRecordFromString($prolog . $input, Lido::class);
        $this->assertEquals($prolog . $expected, trim($record->toXML()));
    }
}
