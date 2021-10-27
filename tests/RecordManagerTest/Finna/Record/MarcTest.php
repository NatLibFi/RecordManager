<?php
/**
 * Finna MARC Record Driver Test Class
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
 * @link     https://github.com/NatLibFi/RecordManager
 */
namespace RecordManagerTest\Finna\Record;

use RecordManager\Finna\Record\Marc;

/**
 * Finna MARC Record Driver Test Class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class MarcTest extends \RecordManagerTest\Base\Record\RecordTest
{
    /**
     * Test MARC Record handling
     *
     * @return void
     */
    public function testMarc1()
    {
        $record = $this->createRecord(
            Marc::class,
            'marc1.xml',
            [
                '__unit_test_no_source__' => [
                    'authority' => [
                        '*' => 'testauth'
                    ],
                ]
            ],
            'base',
            [
                $this->createMock(\RecordManager\Base\Record\PluginManager::class)
            ]
        );
        $fields = $record->toSolrArray();
        unset($fields['fullrecord']);

        $expected = [
            'record_format' => 'marc',
            'building' => [
                '150',
                '150',
            ],
            'lccn' => '',
            'ctrlnum' => [
                'FCC005246184',
                '378890',
                '401416',
            ],
            'allfields' => [
                '978-951-31-4836-2',
                '9789513148362',
                '2345',
                'FOO',
                'Hirsjärvi, Sirkka',
                'Tutki ja kirjoita',
                'Sirkka Hirsjärvi, Pirkko Remes, Paula Sajavaara',
                '17. uud. p.',
                'Helsinki',
                'Tammi',
                '2345 [2013]',
                '18. p. 2013',
                'Summary field',
                'oppaat',
                'ft: kirjoittaminen',
                'apurahat',
                'tutkimusrahoitus',
                'tutkimuspolitiikka',
                'opinnäytteet',
                'tiedonhaku',
                'kielioppaat',
                'tutkimustyö',
                'tutkimus',
                'Remes, Pirkko',
                'Sajavaara, Paula',
            ],
            'language' => [
                'fin',
                'fin',
            ],
            'format' => 'Book',
            'author' => [
                'Hirsjärvi, Sirkka',
            ],
            'author_role' => [
                '-',
            ],
            'author_fuller' => [],
            'author_sort' => 'Hirsjärvi, Sirkka',
            'author2' => [
                'Hirsjärvi, Sirkka',
                'Remes, Pirkko',
                'Sajavaara, Paula',
            ],
            'author2_role' => [
                '-',
                '-',
                '-',
            ],
            'author2_fuller' => [],
            'author_corporate' => [],
            'author_corporate_role' => [],
            'author2_id_str_mv' => [],
            'author2_id_role_str_mv' => [],
            'author_additional' => [],
            'title' => 'Tutki ja kirjoita',
            'title_sub' => '',
            'title_short' => 'Tutki ja kirjoita',
            'title_full' => 'Tutki ja kirjoita / Sirkka Hirsjärvi, Pirkko Remes, Paula Sajavaara',
            'title_alt' => [],
            'title_old' => [],
            'title_new' => [],
            'title_sort' => 'tutki ja kirjoita / sirkka hirsjärvi, pirkko remes, paula sajavaara',
            'series' => [],
            'publisher' => [
                'Tammi',
            ],
            'publishDateSort' => '2013',
            'publishDate' => [
                '2013',
            ],
            'physical' => [],
            'dateSpan' => [],
            'edition' => '17. uud. p.',
            'contents' => [],
            'isbn' => [
                '9789513148362',
            ],
            'issn' => [],
            'callnumber-first' => '38.04',
            'callnumber-raw' => [
                '38.04',
                '38.03',
                'QC861.2 .B36',
            ],
            'callnumber-sort' => '38.04',
            'topic' => [
                'oppaat',
                'ft: kirjoittaminen',
                'apurahat',
                'tutkimusrahoitus',
                'tutkimuspolitiikka',
                'opinnäytteet',
                'tiedonhaku',
                'kielioppaat',
                'tutkimustyö',
                'tutkimus',
            ],
            'genre' => [],
            'geographic' => [],
            'era' => [],
            'topic_facet' => [
                'oppaat',
                'ft: kirjoittaminen',
                'apurahat',
                'tutkimusrahoitus',
                'tutkimuspolitiikka',
                'opinnäytteet',
                'tiedonhaku',
                'kielioppaat',
                'tutkimustyö',
                'tutkimus',
            ],
            'genre_facet' => [],
            'geographic_facet' => [],
            'era_facet' => [],
            'url' => [],
            'illustrated' => 'Not Illustrated',
            'main_date_str' => '2013',
            'main_date' => '2013-01-01T00:00:00Z',
            'publication_daterange' => '[2013-01-01 TO 2013-12-31]',
            'search_daterange_mv' => [
                '[2013-01-01 TO 2013-12-31]',
            ],
            'publication_place_txt_mv' => [
                'Helsinki',
            ],
            'subtitle_lng_str_mv' => [],
            'original_lng_str_mv' => [
                'fin',
            ],
            'classification_txt_mv' => [
                'dlc qc861.2.b36',
                'dlc QC861.2 .B36',
                'ekl 38.04',
                'ekl 38.04',
                'ekl 38.03',
                'ekl 38.03',
            ],
            'classification_str_mv' => [
                'dlc qc861.2.b36',
                'dlc QC861.2 .B36',
                'ekl 38.04',
                'ekl 38.04',
                'ekl 38.03',
                'ekl 38.03',
            ],
            'callnumber-subject' => 'QC',
            'callnumber-label' => 'QC861',
            'callnumber-sort' => '38.04',
            'source_str_mv' => '__unit_test_no_source__',
            'datasource_str_mv' => [
                '__unit_test_no_source__',
            ],
            'other_issn_str_mv' => [],
            'other_issn_isn_mv' => [],
            'linking_issn_str_mv' => [],
            'holdings_txtP_mv' => [
                'E 150 38 Hir 18. p. 2013 __unit_test_no_source__',
                'E 150 38 Hir 18. p. 2013 __unit_test_no_source__',
            ],
            'author_facet' => [
                'Hirsjärvi, Sirkka',
                'Hirsjärvi, Sirkka',
                'Remes, Pirkko',
                'Sajavaara, Paula',
            ],
            'format_ext_str_mv' => 'Book',
            'author2_id_str_mv' => [
                'testauth.(TEST)1',
                'testauth.(TEST)1',
                'testauth.(TEST)2',
                'testauth.(TEST)3',
            ],
            'topic_id_str_mv' => [],
            'description' => 'Summary field',
        ];

        $this->compareArray($expected, $fields, 'toSolrArray');

        $keys = $record->getWorkIdentificationData();

        $expected = [
            'authors' => [
                [
                    'type' => 'author',
                    'value' => 'Hirsjärvi, Sirkka.',
                ],
            ],
            'authorsAltScript' => [
            ],
            'titles' => [
                [
                    'type' => 'title',
                    'value' => 'Tutki ja kirjoita /',
                ],
            ],
            'titlesAltScript' => [
            ],
        ];

        $this->compareArray($expected, $keys, 'getWorkIdentificationData');

        $this->assertEquals(
            ['(FOO)2345', '(FI-MELINDA)005246184'], $record->getUniqueIDs()
        );
    }

    /**
     * Test MARC Record handling
     *
     * @return void
     */
    public function testMarc2()
    {
        $record = $this->createRecord(
            Marc::class,
            'marc2.xml',
            [],
            'base',
            [
                $this->createMock(\RecordManager\Base\Record\PluginManager::class)
            ]
        );
        $fields = $record->toSolrArray();
        unset($fields['fullrecord']);

        $expected = [
            'record_format' => 'marc',
            'building' => [
                '123',
                '234',
            ],
            'lccn' => '',
            'ctrlnum' => [
                '1558192',
                'FCC002608043',
            ],
            'allfields' => [
                '0-534-51409-X',
                '9780534514099',
                '0-534-51400-6',
                '9780534514006',
                'Kalat, James W.',
                'Biological psychology',
                'James W. Kalat',
                '7th ed',
                'Belmont, CA',
                'Wadsworth',
                'cop. 2001.',
                'Liitteenä CD-ROM',
                '&12een',
                '&käytt&tdk',
                '&vanha&painos',
                'neuropsykologia',
                'biopsykologia',
                'neuropsykologi',
                'biopsykologi',
            ],
            'language' => [
                'eng',
                'eng',
            ],
            'format' => 'Book',
            'author' => [
                'Kalat, James W.',
            ],
            'author_role' => [
                '-',
            ],
            'author_fuller' => [
            ],
            'author_sort' => 'Kalat, James W.',
            'author2' => [
                'Kalat, James W.',
            ],
            'author2_role' => [
                '-',
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
                'Wadsworth',
            ],
            'publishDateSort' => '2001',
            'publishDate' => [
                '2001',
            ],
            'physical' => [
                'xxiii, 551 sivua : kuvitettu + CD-ROM -levy',
            ],
            'dateSpan' => [
            ],
            'edition' => '7th ed',
            'contents' => [
            ],
            'isbn' => [
                '9780534514099',
                '9780534514006',
            ],
            'issn' => [
            ],
            'callnumber-first' => '',
            'callnumber-raw' => [
            ],
            'topic' => [
                'neuropsykologia',
                'biopsykologia',
                'neuropsykologi',
                'biopsykologi',
            ],
            'genre' => [
            ],
            'geographic' => [
            ],
            'era' => [
            ],
            'topic_facet' => [
                'neuropsykologia',
                'biopsykologia',
                'neuropsykologi',
                'biopsykologi',
            ],
            'genre_facet' => [
            ],
            'geographic_facet' => [
            ],
            'era_facet' => [
            ],
            'url' => [
            ],
            'illustrated' => 'Illustrated',
            'main_date_str' => '2001',
            'main_date' => '2001-01-01T00:00:00Z',
            'publication_daterange' => '[2001-01-01 TO 2001-12-31]',
            'search_daterange_mv' => [
                '[2001-01-01 TO 2001-12-31]',
            ],
            'publication_place_txt_mv' => [
                'Belmont, CA',
            ],
            'subtitle_lng_str_mv' => [
            ],
            'original_lng_str_mv' => [
                'eng',
            ],
            'source_str_mv' => '__unit_test_no_source__',
            'datasource_str_mv' => [
                '__unit_test_no_source__',
            ],
            'other_issn_str_mv' => [
            ],
            'other_issn_isn_mv' => [
            ],
            'linking_issn_str_mv' => [
            ],
            'holdings_txtP_mv' => [
                'L 123 L 616.8 __unit_test_no_source__',
                'Ll 234 Ll Course __unit_test_no_source__',
            ],
            'callnumber-sort' => '',
            'author_facet' => [
                'Kalat, James W.',
                'Kalat, James W.',
            ],
            'format_ext_str_mv' => 'Book',
            'topic_id_str_mv' => [
                'http://www.yso.fi/onto/yso/p14664',
                'test\\\\\\.12',
                'BIOTEST\\.12',
                '(BIOTEST)1234',
            ],
            'description' => '',
        ];

        $this->compareArray($expected, $fields, 'toSolrArray');

        $this->assertEquals(['(FI-MELINDA)002608043'], $record->getUniqueIDs());
    }

    /**
     * Test MARC Record handling
     *
     * @return void
     */
    public function testMarcGeo()
    {
        $record = $this->createRecord(
            Marc::class,
            'marc_geo.xml',
            [],
            'base',
            [
                $this->createMock(\RecordManager\Base\Record\PluginManager::class)
            ]
        );
        $fields = $record->toSolrArray();
        unset($fields['fullrecord']);

        $expected = [
            'record_format' => 'marc',
            'building' => [
                '001',
            ],
            'lccn' => '',
            'ctrlnum' => [
                '(FI-Piki)Ppro837_107786',
                '(PIKI)Ppro837_107786',
                '(FI-MELINDA)000963219',
            ],
            'allfields' => [
                'Suomen tiekartta',
                'Vägkarta över Finland',
                '1.',
                'Suomen tiekartta 1',
                '1:200000',
                'Helsinki',
                'Maanmittaushallitus',
                '1946.',
                'Ahvenanmaa mittakaavassa 1:400000',
                'Kh-kokoelma',
                'tiekartat',
                'kartat',
                'Suomi',
                'Turun ja Porin lääni',
                'ysa',
                'Uudenmaan lääni',
                'Ahvenanmaa',
            ],
            'language' => [
                'fin',
                'fin',
                'swe',
            ],
            'format' => 'Map',
            'author' => [
            ],
            'author_role' => [
            ],
            'author_fuller' => [
            ],
            'author2' => [
            ],
            'author2_role' => [
            ],
            'author2_fuller' => [
            ],
            'author_corporate' => [
                'Maanmittaushallitus',
            ],
            'author_corporate_role' => [
                '-',
            ],
            'author2_id_str_mv' => [
            ],
            'author2_id_role_str_mv' => [
            ],
            'author_additional' => [
            ],
            'title' => 'Suomen tiekartta = Vägkarta över Finland. 1.',
            'title_sub' => 'Vägkarta över Finland. 1.',
            'title_short' => 'Suomen tiekartta',
            'title_full' => 'Suomen tiekartta = Vägkarta över Finland. 1.',
            'title_alt' => [
                'Vägkarta över Finland',
                'Suomen tiekartta 1',
            ],
            'title_old' => [
            ],
            'title_new' => [
            ],
            'title_sort' => 'suomen tiekartta = vägkarta över finland. 1.',
            'series' => [
            ],
            'publisher' => [
                '[Maanmittaushallitus]',
            ],
            'publishDateSort' => '1946',
            'publishDate' => [
                '1946',
            ],
            'physical' => [
                '1 kartta : värillinen ; taitettuna 26 x 13 cm',
            ],
            'dateSpan' => [
            ],
            'edition' => '',
            'contents' => [
            ],
            'isbn' => [
            ],
            'issn' => [
            ],
            'callnumber-first' => '42.02',
            'callnumber-raw' => [
                '42.02',
            ],
            'callnumber-sort' => '42.02',
            'topic' => [
                'tiekartat',
                'kartat Suomi',
            ],
            'genre' => [
            ],
            'geographic' => [
                'Turun ja Porin lääni',
                'Uudenmaan lääni',
                'Ahvenanmaa',
            ],
            'era' => [
            ],
            'topic_facet' => [
                'tiekartat',
                'kartat',
            ],
            'genre_facet' => [
            ],
            'geographic_facet' => [
                'Suomi',
                'Turun ja Porin lääni',
                'Uudenmaan lääni',
                'Ahvenanmaa',
            ],
            'era_facet' => [
            ],
            'url' => [
            ],
            'illustrated' => 'Not Illustrated',
            'main_date_str' => '1946',
            'main_date' => '1946-01-01T00:00:00Z',
            'publication_daterange' => '[1946-01-01 TO 1946-12-31]',
            'search_daterange_mv' => [
                '[1946-01-01 TO 1946-12-31]',
            ],
            'publication_place_txt_mv' => [
                'Helsinki',
            ],
            'subtitle_lng_str_mv' => [
            ],
            'original_lng_str_mv' => [
                'fin',
                'swe',
            ],
            'location_geo' => [
                'ENVELOPE(19.5, 24.75, 60.666666666667, 59.8)',
                'ENVELOPE(19.5, 24.75, 60.666666666667, 59.800277777778)',
            ],
            'center_coords' => '22.125 60.233333333333',
            'classification_txt_mv' => [
                'ykl 42.02',
                'ykl 42.02',
            ],
            'major_genre_str_mv' => 'nonfiction',
            'classification_str_mv' => [
                'ykl 42.02',
                'ykl 42.02',
            ],
            'source_str_mv' => '__unit_test_no_source__',
            'datasource_str_mv' => [
                '__unit_test_no_source__',
            ],
            'other_issn_str_mv' => [
            ],
            'other_issn_isn_mv' => [
            ],
            'linking_issn_str_mv' => [
            ],
            'holdings_txtP_mv' => [
                '1 001 __unit_test_no_source__',
            ],
            'author_facet' => [
                'Maanmittaushallitus',
            ],
            'format_ext_str_mv' => 'Map',
            'topic_id_str_mv' => [],
            'description' => '',
        ];

        $this->compareArray($expected, $fields, 'toSolrArray');
    }

    /**
     * Test MARC Thesis Record handling
     *
     * @return void
     */
    public function testMarcThesis1()
    {
        $record = $this->createRecord(
            Marc::class,
            'marc-thesis1.xml',
            [],
            'finna',
            [
                $this->createMock(\RecordManager\Base\Record\PluginManager::class)
            ]
        );
        $fields = $record->toSolrArray();
        unset($fields['fullrecord']);

        $expected = [
            'record_format' => 'marc',
            'building' => [],
            'lccn' => '',
            'ctrlnum' => [],
            'allfields' => [
                'Author, Test',
                'Thesis Title',
                'Test Author',
                'Helsinki',
                'Kansalliskirjasto',
                '2020',
                'AMK-opinnäytetypo',
                'Sample Program',
                '2020.',
                'AMK-opinnäytetyö',
                'Second Sample Program',
                'testaus',
            ],
            'language' => [
                'fin',
            ],
            'format' => 'BachelorsThesisPolytechnic',
            'author' => [
                'Author, Test',
            ],
            'author_role' => [
                '-',
            ],
            'author_fuller' => [],
            'author_sort' => 'Author, Test',
            'author2' => [
                'Author, Test',
            ],
            'author2_role' => [
                '-',
            ],
            'author2_fuller' => [],
            'author_corporate' => [],
            'author_corporate_role' => [],
            'author2_id_str_mv' => [],
            'author2_id_role_str_mv' => [],
            'author_additional' => [],
            'title' => 'Thesis Title',
            'title_sub' => '',
            'title_short' => 'Thesis Title',
            'title_full' => 'Thesis Title / Test Author',
            'title_alt' => [],
            'title_old' => [],
            'title_new' => [],
            'title_sort' => 'thesis title / test author',
            'series' => [],
            'publisher' => [
                'Kansalliskirjasto',
            ],
            'publishDateSort' => '2020',
            'publishDate' => [
                '2020',
            ],
            'physical' => [],
            'dateSpan' => [],
            'edition' => '',
            'contents' => [],
            'isbn' => [],
            'issn' => [],
            'callnumber-first' => '614.8',
            'callnumber-raw' => [
                '614.8',
            ],
            'callnumber-sort' => '614.8',
            'topic' => [
                'testaus',
            ],
            'genre' => [],
            'geographic' => [],
            'era' => [],
            'topic_facet' => [
                'testaus',
            ],
            'genre_facet' => [],
            'geographic_facet' => [],
            'era_facet' => [],
            'url' => [],
            'illustrated' => 'Not Illustrated',
            'main_date_str' => '2020',
            'main_date' => '2020-01-01T00:00:00Z',
            'publication_daterange' => '[2020-01-01 TO 2020-12-31]',
            'search_daterange_mv' => [
                '[2020-01-01 TO 2020-12-31]',
            ],
            'publication_place_txt_mv' => [
                'Helsinki',
            ],
            'subtitle_lng_str_mv' => [],
            'original_lng_str_mv' => [],
            'classification_txt_mv' => [
                'udk 614.8',
            ],
            'major_genre_str_mv' => 'nonfiction',
            'classification_str_mv' => [
                'udk 614.8',
            ],
            'source_str_mv' => '__unit_test_no_source__',
            'datasource_str_mv' => [
                '__unit_test_no_source__',
            ],
            'other_issn_str_mv' => [],
            'other_issn_isn_mv' => [],
            'linking_issn_str_mv' => [],
            'holdings_txtP_mv' => [],
            'author_facet' => [
                'Author, Test',
                'Author, Test',
            ],
            'format_ext_str_mv' => 'BachelorsThesisPolytechnic',
            'topic_id_str_mv' => [
                'http://www.yso.fi/onto/yso/p8471',
            ],
            'description' => '',
        ];

        $this->compareArray($expected, $fields, 'toSolrArray');
    }

    /**
     * Test MARC Thesis Record handling
     *
     * @return void
     */
    public function testMarcThesis2()
    {
        $record = $this->createRecord(
            Marc::class,
            'marc-thesis2.xml',
            [],
            'finna',
            [
                $this->createMock(\RecordManager\Base\Record\PluginManager::class)
            ]
        );
        $fields = $record->toSolrArray();
        unset($fields['fullrecord']);

        $expected = [
            'record_format' => 'marc',
            'building' => [],
            'lccn' => '',
            'ctrlnum' => [],
            'allfields' => [
                'Author, Test',
                'Thesis Title',
                'Test Author',
                'Helsinki',
                'Kansalliskirjasto',
                '2020',
                'testaus',
            ],
            'language' => [
                'fin',
            ],
            'format' => 'BachelorsThesisPolytechnic',
            'author' => [
                'Author, Test',
            ],
            'author_role' => [
                '-',
            ],
            'author_fuller' => [],
            'author_sort' => 'Author, Test',
            'author2' => [
                'Author, Test',
            ],
            'author2_role' => [
                '-',
            ],
            'author2_fuller' => [],
            'author_corporate' => [],
            'author_corporate_role' => [],
            'author2_id_str_mv' => [],
            'author2_id_role_str_mv' => [],
            'author_additional' => [],
            'title' => 'Thesis Title',
            'title_sub' => '',
            'title_short' => 'Thesis Title',
            'title_full' => 'Thesis Title / Test Author',
            'title_alt' => [],
            'title_old' => [],
            'title_new' => [],
            'title_sort' => 'thesis title / test author',
            'series' => [],
            'publisher' => [
                'Kansalliskirjasto',
            ],
            'publishDateSort' => '2020',
            'publishDate' => [
                '2020',
            ],
            'physical' => [],
            'dateSpan' => [],
            'edition' => '',
            'contents' => [],
            'isbn' => [],
            'issn' => [],
            'callnumber-first' => '614.8',
            'callnumber-raw' => [
                '614.8',
            ],
            'callnumber-sort' => '614.8',
            'topic' => [
                'testaus',
            ],
            'genre' => [],
            'geographic' => [],
            'era' => [],
            'topic_facet' => [
                'testaus',
            ],
            'genre_facet' => [],
            'geographic_facet' => [],
            'era_facet' => [],
            'url' => [],
            'illustrated' => 'Not Illustrated',
            'main_date_str' => '2020',
            'main_date' => '2020-01-01T00:00:00Z',
            'publication_daterange' => '[2020-01-01 TO 2020-12-31]',
            'search_daterange_mv' => [
                '[2020-01-01 TO 2020-12-31]',
            ],
            'publication_place_txt_mv' => [
                'Helsinki',
            ],
            'subtitle_lng_str_mv' => [],
            'original_lng_str_mv' => [],
            'classification_txt_mv' => [
                'udk 614.8',
            ],
            'major_genre_str_mv' => 'nonfiction',
            'classification_str_mv' => [
                'udk 614.8',
            ],
            'source_str_mv' => '__unit_test_no_source__',
            'datasource_str_mv' => [
                '__unit_test_no_source__',
            ],
            'other_issn_str_mv' => [],
            'other_issn_isn_mv' => [],
            'linking_issn_str_mv' => [],
            'holdings_txtP_mv' => [],
            'author_facet' => [
                'Author, Test',
                'Author, Test',
            ],
            'format_ext_str_mv' => 'BachelorsThesisPolytechnic',
            'topic_id_str_mv' => [
                'http://www.yso.fi/onto/yso/p8471',
            ],
            'description' => '',
        ];

        $this->compareArray($expected, $fields, 'toSolrArray');
    }
}
