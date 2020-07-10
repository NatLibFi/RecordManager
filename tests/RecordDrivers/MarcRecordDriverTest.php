<?php
/**
 * MARC Record Driver Test Class
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2020.
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

use RecordManager\Base\Database\Database;

/**
 * MARC Record Driver Test Class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class MarcRecordDriverTest extends RecordDriverTest
{
    protected $driver = '\RecordManager\Base\Record\Marc';

    /**
     * Test MARC Record handling
     *
     * @return void
     */
    public function testMarc1()
    {
        $record = $this->createRecord('marc1.xml');
        $fields = $record->toSolrArray();
        unset($fields['fullrecord']);

        $expected = [
            'recordtype' => 'marc',
            'record_format' => 'marc',
            'building' => [
                0 => '150',
                1 => '150',
            ],
            'lccn' => '',
            'ctrlnum' => [
                0 => 'FCC005246184',
                1 => '378890',
                2 => '401416',
            ],
            'allfields' => [
                0 => 'Hirsjärvi, Sirkka',
                1 => 'Tutki ja kirjoita',
                2 => 'Sirkka Hirsjärvi, Pirkko Remes, Paula Sajavaara',
                3 => '17. uud. p.',
                4 => 'Helsinki',
                5 => 'Tammi',
                6 => '2013.',
                7 => 'teksti',
                8 => 'txt',
                9 => 'rdacontent',
                10 => 'käytettävissä ilman laitetta',
                11 => 'n',
                12 => 'rdamedia',
                13 => 'nide',
                14 => 'nc',
                15 => 'rdacarrier',
                16 => '18. p. 2013',
                17 => 'oppaat',
                18 => 'ft: kirjoittaminen',
                19 => 'apurahat',
                20 => 'tutkimusrahoitus',
                21 => 'tutkimuspolitiikka',
                22 => 'opinnäytteet',
                23 => 'tiedonhaku',
                24 => 'kielioppaat',
                25 => 'tutkimustyö',
                26 => 'tutkimus',
                27 => 'Remes, Pirkko',
                28 => 'Sajavaara, Paula',
            ],
            'language' => [
                0 => 'fin',
                1 => 'fin',
            ],
            'format' => 'Book',
            'author' => [
                0 => 'Hirsjärvi, Sirkka',
            ],
            'author_role' => [
                0 => '-',
            ],
            'author_fuller' => [],
            'author_sort' => 'Hirsjärvi, Sirkka',
            'author2' => [
                0 => 'Hirsjärvi, Sirkka',
                1 => 'Remes, Pirkko',
                2 => 'Sajavaara, Paula',
            ],
            'author2_role' => [
                0 => '-',
                1 => '-',
                2 => '-',
            ],
            'author2_fuller' => [
            ],
            'author_corporate' => [
            ],
            'author_corporate_role' => [
            ],
            'author2_id_str_mv' => [],
            'author2_id_role_str_mv' => [],
            'author_additional' => [
            ],
            'title' => 'Tutki ja kirjoita',
            'title_sub' => '',
            'title_short' => 'Tutki ja kirjoita',
            'title_full' => 'Tutki ja kirjoita / Sirkka Hirsjärvi, Pirkko Remes, Paula Sajavaara',
            'title_alt' => [
            ],
            'title_old' => [
            ],
            'title_new' => [
            ],
            'title_sort' => 'tutki ja kirjoita / sirkka hirsjärvi, pirkko remes, paula sajavaara',
            'series' => [
            ],
            'publisher' => [
                0 => 'Tammi',
            ],
            'publishDateSort' => '2013',
            'publishDate' => [
                0 => '2013',
            ],
            'physical' => [
            ],
            'dateSpan' => [
            ],
            'edition' => '17. uud. p.',
            'contents' => [
            ],
            'isbn' => [
                0 => '9789513148362',
            ],
            'issn' => [
            ],
            'callnumber-first' => '',
            'callnumber-raw' => [
                0 => '38.04',
                1 => '38.03',
            ],
            'callnumber-sort' => '',
            'topic' => [
                0 => 'oppaat',
                1 => 'ft: kirjoittaminen',
                2 => 'apurahat',
                3 => 'tutkimusrahoitus',
                4 => 'tutkimuspolitiikka',
                5 => 'opinnäytteet',
                6 => 'tiedonhaku',
                7 => 'kielioppaat',
                8 => 'tutkimustyö',
                9 => 'tutkimus',
            ],
            'genre' => [
            ],
            'geographic' => [
            ],
            'era' => [
            ],
            'topic_facet' => [
                0 => 'oppaat',
                1 => 'ft: kirjoittaminen',
                2 => 'apurahat',
                3 => 'tutkimusrahoitus',
                4 => 'tutkimuspolitiikka',
                5 => 'opinnäytteet',
                6 => 'tiedonhaku',
                7 => 'kielioppaat',
                8 => 'tutkimustyö',
                9 => 'tutkimus',
            ],
            'genre_facet' => [
            ],
            'geographic_facet' => [
            ],
            'era_facet' => [
            ],
            'url' => [
            ],
            'illustrated' => 'Not Illustrated',
        ];

        $this->compareArray($expected, $fields, 'toSolrArray');

        $keys = $record->getWorkIdentificationData();

        $expected = [
            'authors' => [
                0 => [
                    'type' => 'author',
                    'value' => 'Hirsjärvi, Sirkka',
                ],
            ],
            'authorsAltScript' => [
            ],
            'titles' => [
                0 => [
                    'type' => 'title',
                    'value' => 'tutki ja kirjoita / sirkka hirsjärvi, pirkko remes, paula sajavaara',
                ],
            ],
            'titlesAltScript' => [
            ],
        ];

        $this->compareArray($expected, $keys, 'getWorkIdentificationData');
    }

    /**
     * Test MARC Record handling
     *
     * @return void
     */
    public function testMarc2()
    {
        $record = $this->createRecord('marc2.xml');
        $fields = $record->toSolrArray();
        unset($fields['fullrecord']);

        $expected = [
            'recordtype' => 'marc',
            'record_format' => 'marc',
            'building' => [
                0 => '123',
                1 => '234',
            ],
            'lccn' => '',
            'ctrlnum' => [
                0 => '1558192',
                1 => 'FCC002608043',
            ],
            'allfields' => [
                0 => 'Kalat, James W.',
                1 => 'Biological psychology',
                2 => 'James W. Kalat',
                3 => '7th ed',
                4 => 'Belmont, CA',
                5 => 'Wadsworth',
                6 => 'cop. 2001.',
                7 => 'xxiii, 551 sivua',
                8 => 'kuvitettu +',
                9 => 'CD-ROM -levy',
                10 => 'teksti',
                11 => 'txt',
                12 => 'rdacontent',
                13 => 'käytettävissä ilman laitetta',
                14 => 'n',
                15 => 'rdamedia',
                16 => 'nide',
                17 => 'nc',
                18 => 'rdacarrier',
                19 => 'Liitteenä CD-ROM',
                20 => '&12een',
                21 => '&käytt&tdk',
                22 => '&vanha&painos',
                23 => 'neuropsykologia',
                24 => 'http://www.yso.fi/onto/yso/p14664',
                25 => 'biopsykologia',
                26 => 'http://www.yso.fi/onto/yso/p9372',
                27 => 'neuropsykologi',
                28 => 'biopsykologi',
            ],
            'language' => [
                0 => 'eng',
                1 => 'eng',
            ],
            'format' => 'Book',
            'author' => [
                0 => 'Kalat, James W.',
            ],
            'author_role' => [
                0 => '-',
            ],
            'author_fuller' => [
            ],
            'author_sort' => 'Kalat, James W.',
            'author2' => [
                0 => 'Kalat, James W.',
            ],
            'author2_role' => [
                0 => '-',
            ],
            'author2_fuller' => [
            ],
            'author_corporate' => [
            ],
            'author_corporate_role' => [
            ],
            'author2_id_str_mv' => [],
            'author2_id_role_str_mv' => [],
            'author_additional' => [
            ],
            'title' => 'Biological psychology',
            'title_sub' => '',
            'title_short' => 'Biological psychology',
            'title_full' => 'Biological psychology / James W. Kalat',
            'title_alt' => [
            ],
            'title_old' => [
            ],
            'title_new' => [
            ],
            'title_sort' => 'biological psychology / james w. kalat',
            'series' => [
            ],
            'publisher' => [
                0 => 'Wadsworth',
            ],
            'publishDateSort' => '2001',
            'publishDate' => [
                0 => '2001',
            ],
            'physical' => [
                0 => 'xxiii, 551 sivua : kuvitettu + CD-ROM -levy',
            ],
            'dateSpan' => [
            ],
            'edition' => '7th ed',
            'contents' => [
            ],
            'isbn' => [
                0 => '9780534514099',
                1 => '9780534514006',
            ],
            'issn' => [
            ],
            'callnumber-first' => '',
            'callnumber-raw' => [
            ],
            'topic' => [
                0 => 'neuropsykologia',
                1 => 'biopsykologia',
                2 => 'neuropsykologi',
                3 => 'biopsykologi',
            ],
            'genre' => [
            ],
            'geographic' => [
            ],
            'era' => [
            ],
            'topic_facet' => [
                0 => 'neuropsykologia',
                1 => 'biopsykologia',
                2 => 'neuropsykologi',
                3 => 'biopsykologi',
            ],
            'genre_facet' => [
            ],
            'geographic_facet' => [
            ],
            'era_facet' => [
            ],
            'url' => [
            ],
            'illustrated' => 'Not Illustrated',
        ];

        $this->compareArray($expected, $fields, 'toSolrArray');

        $keys = $record->getWorkIdentificationData();

        $expected = [
            'authors' => [
                0 => [
                    'type' => 'author',
                    'value' => 'Kalat, James W.',
                ],
            ],
            'authorsAltScript' => [
            ],
            'titles' => [
                0 => [
                    'type' => 'title',
                    'value' => 'biological psychology / james w. kalat',
                ],
            ],
            'titlesAltScript' => [
            ],
        ];

        $this->compareArray($expected, $keys, 'getWorkIdentificationData');
    }

    /**
     * Test MARC Record linking
     *
     * @return void
     */
    public function testMarcLinking()
    {
        $db = $this->createMock(Database::class);
        $map = [
            [
                [
                    'source_id' => '__unit_test_no_source__',
                    'linking_id' => '(FI-NL)961827'
                ],
                [
                    'projection' => ['_id' => 1]
                ],
                [
                    '_id' => '__unit_test_no_source__.4132317'
                ]
            ],
            [
                [
                    'source_id' => '__unit_test_no_source__',
                    'linking_id' => '961827'
                ],
                [
                    'projection' => ['_id' => 1]
                ],
                [
                    '_id' => '__unit_test_no_source__.4112121'
                ]
            ],
            [
                [
                    'source_id' => '__unit_test_no_source__',
                    'linking_id' => '(FI-NL)xyzzy'
                ],
                [
                    'projection' => ['_id' => 1]
                ],
                null
            ]
        ];
        $db->expects($this->exactly(4))
            ->method('findRecord')
            ->will($this->returnValueMap($map));

        $record = $this->createRecord('marc_links.xml');
        $record->toSolrArray($db);
        $marc776 = $record->getFields('776');
        $this->assertEquals(2, count($marc776));
        $w = $record->getSubfield($marc776[0], 'w');
        $this->assertEquals('__unit_test_no_source__.4112121', $w);
        $w = $record->getSubfield($marc776[1], 'w');
        $this->assertEquals('__unit_test_no_source__.xyzzy', $w);

        $record = $this->createRecord(
            'marc_links.xml',
            [
                '__unit_test_no_source__' => [
                    'driverParams' => ['003InLinkingID=true']
                ]
            ]
        );
        $record->toSolrArray($db);
        $marc776 = $record->getFields('776');
        $this->assertEquals(2, count($marc776));
        $w = $record->getSubfield($marc776[0], 'w');
        $this->assertEquals('__unit_test_no_source__.4132317', $w);
        $w = $record->getSubfield($marc776[1], 'w');
        $this->assertEquals('__unit_test_no_source__.xyzzy', $w);
    }
}
