<?php

/**
 * EAD3 Record Driver Test Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2026.
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
 * @author   Minna Rönkä <minna.ronka@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */

namespace RecordManagerTest\Base\Record;

use RecordManager\Base\Record\Ead3;

/**
 * EAD3 Record Driver Test Class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Minna Rönkä <minna.ronka@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Ead3Test extends RecordTestBase
{
    /**
     * Data provider for testSKS
     *
     * @return array
     */
    public static function sksProvider(): array
    {
        return [
            'addIdToHierarchyTitle=true' => [
                'true',
                '1 1 Sundvall Gustaf Edvard S 1:a) 1',
            ],
            'addIdToHierarchyTitle=false' => [
                'false',
                null,
            ],
        ];
    }

    /**
     * Test SKS EAD3 record handling
     *
     * @param string $addIdToHierarchyTitle    Value for addIdToHierarchyTitle driver param
     * @param ?array $expectedTitleInHierarchy Expected title_in_hierarchy field contents
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sksProvider')]
    public function testSKS(string $addIdToHierarchyTitle, ?string $expectedTitleInHierarchy): void
    {
        $fields = $this->createRecord(
            Ead3::class,
            'sks.xml',
            [
                '__unit_test_no_source__' => [
                    'driverParams' => [
                        "addIdToHierarchyTitle=$addIdToHierarchyTitle",
                    ],
                ],
            ]
        )->toSolrArray();
        unset($fields['fullrecord']);
        $ltr = "\u{200E}";

        $expected = [
            'record_format' => 'ead3',
            'ctrlnum' => [],
            'allfields' => [
                'Yksityisaineisto',
                'SKS:n arkisto, Hallituskatu 1, HKI',
                '242790397',
                'xx.xx.1881-xx.xx.1881',
                'Sundvall Gustaf Edvard S 1:a) 1',
                'Sundvall Gustaf Edvard S 1:a) 1 swe',
                '1',
                's/sundvall_gustaf_edvard/001/00001/00005',
                'SKS KRA S Sundvall Gustaf Edvard 1: a) 1',
                'KRA-K-103174062',
                'A1576669851fpriz',
                'Suomi',
                'Aineistotyyppi: Text 5 Styck',
                'Aineistotyyppi: Text 5 Pieces',
                'Aineistotyyppi: Teksti, järjestetty 5 Kappaletta',
                'Sundvall, Gustaf Edvard',
                'Sundvall, Gustaf Edvard',
                'Sundvall, Gustaf Edvard',
                'Ingman, Anders Wilhelm',
                'Sundvall, Gustaf Edvard',
                'Teksti',
                'Text',
                'Text',
                'Luvia',
                'Luvia',
                'Luvia',
                'folk tales',
                'kansansadut',
                'folksagor',
                'fairy tales',
                'sadut',
                'sagor',
                'folklore collectors',
                'perinteenkerääjät',
                'traditionsinsamlare',
                'Sundvall, Gustaf Edvard',
                'Sundwall, Gustaf Edvard',
                'Sundvall, Gustaf Edvard',
                'Sundwall, Gustaf Edvard',
                'Sundvall, Gustaf Edvard',
                'Sundwall, Gustaf Edvard',
                'Ingman, Anders Wilhelm',
                'Ingman, A.W.',
                'Sundvall, Gustaf Edvard',
                'Sundwall, Gustaf Edvard',
                'Tietosisältö',
                'G. E. Sundvallin tallentama murresatu Luvialta.',
                'Tietopalvelun tarjoamispaikka',
                'SKS:n arkisto, Hallituskatu 1, HKI',
                'Tekninen tyyppi',
                'Digitaalinen',
                'Alkuperäisyys',
                'Kopio',
                'Digitaalisen aineiston tiedostomuoto',
                'TIFF - Tagged Image File Format',
                'Gustaf Edvard Sundvallin kokoelma',
            ],
            'description' => 'G. E. Sundvallin tallentama murresatu Luvialta.',
            'author' => [
                'Sundvall, Gustaf Edvard',
                'Sundwall, Gustaf Edvard',
                'Sundvall, Gustaf Edvard',
                'Sundwall, Gustaf Edvard',
                'Sundvall, Gustaf Edvard',
                'Sundwall, Gustaf Edvard',
                'Ingman, Anders Wilhelm',
                'Ingman, A.W.',
                'Sundvall, Gustaf Edvard',
                'Sundwall, Gustaf Edvard',
            ],
            'author_sort' => 'Sundvall, Gustaf Edvard',
            'author_corporate' => [],
            'geographic_facet' => [
                'Luvia',
                'Luvia',
                'Luvia',
            ],
            'geographic' => [
                'Luvia',
                'Luvia',
                'Luvia',
            ],
            'topic_facet' => [
                'folk tales',
                'kansansadut',
                'folksagor',
                'fairy tales',
                'sadut',
                'sagor',
                'folklore collectors',
                'perinteenkerääjät',
                'traditionsinsamlare',
            ],
            'topic' => [
                'folk tales',
                'kansansadut',
                'folksagor',
                'fairy tales',
                'sadut',
                'sagor',
                'folklore collectors',
                'perinteenkerääjät',
                'traditionsinsamlare',
            ],
            'format' => 'Teksti',
            'institution' => 'SKS:n arkisto, Hallituskatu 1, HKI',
            'series' => 'Tekstit/Gustaf Edvard Sundvallin kokoelma',
            'title_sub' => '1',
            'title_short' => 'Sundvall Gustaf Edvard S 1:a) 1',
            'title' => '1 Sundvall Gustaf Edvard S 1:a) 1',
            'title_sort' => '1 sundvall gustaf edvard s 1 a 1',
            'title_full' => '1 Sundvall Gustaf Edvard S 1:a) 1',
            'language' => [
                'fin',
            ],
            'physical' => [],
            'thumbnail' => '',
            'hierarchytype' => 'Default',
            'hierarchy_top_id' => '237990354',
            'hierarchy_top_title' => 'Gustaf Edvard Sundvallin kokoelma',
            'hierarchy_sequence' => '0000003',
            'hierarchy_parent_id' => '237990354_238008149',
            'hierarchy_parent_title' => 'Tekstit/Gustaf Edvard Sundvallin kokoelma',
            'isbn' => [],
            'issn' => [],
            'publishDate' => [],
            'publishDateRange' => [],
            'publishDateSort' => '',
            'author2' => [],
        ];
        if (null !== $expectedTitleInHierarchy) {
            $expected['title_in_hierarchy'] = $expectedTitleInHierarchy;
        }

        $this->assertEquals(
            $expected,
            $fields
        );
    }

    /**
     * Test getTitleByLanguage
     *
     * @return void
     */
    public function testGetTitleByLanguage()
    {
        $record = $this->createRecord(
            Ead3::class,
            'sks.xml',
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
        $getTitleByLanguage = $reflection->getMethod('getTitleByLanguage');

        $this->assertEquals(
            '1 Sundvall Gustaf Edvard S 1:a) 1',
            $getTitleByLanguage->invokeArgs($record, [])
        );

        $this->assertEquals(
            '1 Sundvall Gustaf Edvard S 1:a) 1 swe',
            $getTitleByLanguage->invokeArgs($record, [false, 'sv'])
        );

        $this->assertEquals(
            '',
            $getTitleByLanguage->invokeArgs($record, [false, 'en'])
        );
    }
}
