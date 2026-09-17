<?php

/**
 * EAD Record Driver Test Class
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

use RecordManager\Base\Record\Ead;

/**
 * EAD3 Record Driver Test Class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Minna Rönkä <minna.ronka@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class EadTest extends RecordTestBase
{
    /**
     * Test EAD record handling
     *
     * @return void
     */
    public function testEad1()
    {
        $record = $this->createRecord(
            Ead::class,
            'ead1.xml',
            [
                '__unit_test_no_source__' => [
                    'driverParams' => [
                        'addIdToHierarchyTitle=false',
                    ],
                ],
            ]
        );
        $fields = $record->toSolrArray();
        unset($fields['fullrecord']);

        $expected = [
            'allfields' => [
                'Suomi päivä "Finland Day" New Yorkin maailmannäyttelyssä.',
                'USA_1287',
                '1940',
                '172 vedosta',
                'Siirtolaisuusinstituutin arkisto. Hämeenkatu 13, 20500 Turku',
                'fin',
                'eng',
                'Haapaniemi, Fred',
                '1912-1966',
                'Siirtolaisuusinstituutti',
                'Sivu 1',
                'Suomi päivä "Finland Day" New Yorkin maailmannäyttelyssä kesäkuussa 1940.',
                'Image/Photo',
                'Yhdysvallat',
                '40.71278, -74.00611',
                'New York (kaupunki)',
                'muuttoliike',
                'siirtolaisuus',
                'siirtolaiset',
                'ulkosuomalaiset',
                'amerikansuomalaiset',
                'tapahtumat',
                'maailmannäyttelyt',
                'CC BY 4.0',
                'Haapaniemi, Fred (arkisto)',
                'Haapaniemi, Fred (arkisto)',
            ],
            'author' => [],
            'author2' => [
                'Haapaniemi, Fred',
            ],
            'author_corporate' => [],
            'author_sort' => '',
            'ctrlnum' => [],
            'description' => 'Suomi päivä "Finland Day" New Yorkin maailmannäyttelyssä kesäkuussa 1940.',
            'format' => 'Image/Photo',
            'geographic' => [
                'Yhdysvallat',
                'New York (kaupunki)',
            ],
            'geographic_facet' => [
                'Yhdysvallat',
                'New York (kaupunki)',
            ],
            'hierarchy_parent_id' => '239',
            'hierarchy_parent_title' => 'Haapaniemi, Fred (arkisto)',
            'hierarchy_sequence' => '0000003',
            'hierarchy_top_id' => '239',
            'hierarchy_top_title' => 'Haapaniemi, Fred (arkisto)',
            'hierarchytype' => 'Default',
            'institution' => 'Siirtolaisuusinstituutti',
            'isbn' => [],
            'issn' => [],
            'language' => [
                'fin',
                'eng',
            ],
            'long_lat' => 'POINT(-74.00611 40.71278)',
            'physical' => [
                '172 vedosta',
            ],
            'publishDate' => [],
            'publishDateRange' => [],
            'publishDateSort' => '',
            'record_format' => 'ead',
            'series' => '',
            'thumbnail' => '',
            'title' => 'USA_1287 Suomi päivä "Finland Day" New Yorkin maailmannäyttelyssä.',
            'title_full' => 'USA_1287 Suomi päivä "Finland Day" New Yorkin maailmannäyttelyssä.',
            'title_short' => 'Suomi päivä "Finland Day" New Yorkin maailmannäyttelyssä.',
            'title_sort' => 'suomi päivä finland day new yorkin maailmannäyttelyssä',
            'title_sub' => 'USA_1287',
            'topic' => [
                'muuttoliike',
                'siirtolaisuus',
                'siirtolaiset',
                'ulkosuomalaiset',
                'amerikansuomalaiset',
                'tapahtumat',
                'maailmannäyttelyt',
            ],
            'topic_facet' => [
                'muuttoliike',
                'siirtolaisuus',
                'siirtolaiset',
                'ulkosuomalaiset',
                'amerikansuomalaiset',
                'tapahtumat',
                'maailmannäyttelyt',
            ],
        ];

        $this->compareArray($expected, $fields, 'toSolrArray');
    }
}
