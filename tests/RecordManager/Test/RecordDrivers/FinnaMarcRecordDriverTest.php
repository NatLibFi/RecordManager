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
 * @link     https://github.com/KDK-Alli/RecordManager
 */
namespace RecordManager\Test\RecordDrivers;

use RecordManager\Finna\Record\Marc;

/**
 * Finna MARC Record Driver Test Class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class FinnaMarcRecordDriverTest extends RecordDriverTest
{
    /**
     * Test MARC Record handling
     *
     * @return void
     */
    public function testMarc1()
    {
        $record = $this->createRecord(Marc::class, 'marc1.xml');
        $fields = $record->toSolrArray();
        unset($fields['fullrecord']);

        $expected = [
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
                0 => '978-951-31-4836-2',
                1 => '9789513148362',
                2 => 'Hirsjärvi, Sirkka',
                3 => 'Tutki ja kirjoita',
                4 => 'Sirkka Hirsjärvi, Pirkko Remes, Paula Sajavaara',
                5 => '17. uud. p.',
                6 => 'Helsinki',
                7 => 'Tammi',
                8 => '2345 [2013]',
                9 => '18. p. 2013',
                10 => 'oppaat',
                11 => 'ft: kirjoittaminen',
                12 => 'apurahat',
                13 => 'tutkimusrahoitus',
                14 => 'tutkimuspolitiikka',
                15 => 'opinnäytteet',
                16 => 'tiedonhaku',
                17 => 'kielioppaat',
                18 => 'tutkimustyö',
                19 => 'tutkimus',
                20 => 'Remes, Pirkko',
                21 => 'Sajavaara, Paula',
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
                0 => 'Tammi',
            ],
            'publishDateSort' => '2013',
            'publishDate' => [
                0 => '2013',
            ],
            'physical' => [],
            'dateSpan' => [],
            'edition' => '17. uud. p.',
            'contents' => [],
            'isbn' => [
                0 => '9789513148362',
            ],
            'issn' => [],
            'callnumber-first' => '38.04',
            'callnumber-raw' => [
                0 => '38.04',
                1 => '38.03',
            ],
            'callnumber-sort' => '38.04',
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
            'genre' => [],
            'geographic' => [],
            'era' => [],
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
            'genre_facet' => [],
            'geographic_facet' => [],
            'era_facet' => [],
            'url' => [],
            'illustrated' => 'Not Illustrated',
            'main_date_str' => '2013',
            'main_date' => '2013-01-01T00:00:00Z',
            'publication_daterange' => '[2013-01-01 TO 2013-12-31]',
            'search_daterange_mv' => [
                0 => '[2013-01-01 TO 2013-12-31]',
            ],
            'publication_place_txt_mv' => [
                0 => 'Helsinki',
            ],
            'subtitle_lng_str_mv' => [],
            'original_lng_str_mv' => [
                0 => 'fin',
            ],
            'classification_txt_mv' => [
                0 => 'ekl 38.04',
                1 => 'ekl 38.04',
                2 => 'ekl 38.03',
                3 => 'ekl 38.03',
            ],
            'classification_str_mv' => [
                0 => 'ekl 38.04',
                1 => 'ekl 38.04',
                2 => 'ekl 38.03',
                3 => 'ekl 38.03',
            ],
            'source_str_mv' => '__unit_test_no_source__',
            'datasource_str_mv' => [
                0 => '__unit_test_no_source__',
            ],
            'other_issn_str_mv' => [],
            'other_issn_isn_mv' => [],
            'linking_issn_str_mv' => [],
            'holdings_txtP_mv' => [
                0 => 'E 150 38 Hir 18. p. 2013 __unit_test_no_source__',
                1 => 'E 150 38 Hir 18. p. 2013 __unit_test_no_source__',
            ],
            'author_facet' => [
                0 => 'Hirsjärvi, Sirkka',
                1 => 'Hirsjärvi, Sirkka',
                2 => 'Remes, Pirkko',
                3 => 'Sajavaara, Paula',
            ],
            'format_ext_str_mv' => 'Book',
        ];

        $this->compareArray($expected, $fields, 'toSolrArray');

        $keys = $record->getWorkIdentificationData();

        $expected = [
            'authors' => [
                0 => [
                    'type' => 'author',
                    'value' => 'Hirsjärvi, Sirkka.',
                ],
            ],
            'authorsAltScript' => [
            ],
            'titles' => [
                0 => [
                    'type' => 'title',
                    'value' => 'Tutki ja kirjoita /',
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
        $record = $this->createRecord(Marc::class, 'marc2.xml');
        $fields = $record->toSolrArray();
        unset($fields['fullrecord']);

        $expected = [
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
                0 => '0-534-51409-X',
                1 => '9780534514099',
                2 => '0-534-51400-6',
                3 => '9780534514006',
                4 => 'Kalat, James W.',
                5 => 'Biological psychology',
                6 => 'James W. Kalat',
                7 => '7th ed',
                8 => 'Belmont, CA',
                9 => 'Wadsworth',
                10 => 'cop. 2001.',
                11 => 'Liitteenä CD-ROM',
                12 => '&12een',
                13 => '&käytt&tdk',
                14 => '&vanha&painos',
                15 => 'neuropsykologia',
                16 => 'biopsykologia',
                17 => 'neuropsykologi',
                18 => 'biopsykologi',
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
            'illustrated' => 'Illustrated',
            'main_date_str' => '2001',
            'main_date' => '2001-01-01T00:00:00Z',
            'publication_daterange' => '[2001-01-01 TO 2001-12-31]',
            'search_daterange_mv' => [
                0 => '[2001-01-01 TO 2001-12-31]',
            ],
            'publication_place_txt_mv' => [
                0 => 'Belmont, CA',
            ],
            'subtitle_lng_str_mv' => [
            ],
            'original_lng_str_mv' => [
                0 => 'eng',
            ],
            'source_str_mv' => '__unit_test_no_source__',
            'datasource_str_mv' => [
                0 => '__unit_test_no_source__',
            ],
            'other_issn_str_mv' => [
            ],
            'other_issn_isn_mv' => [
            ],
            'linking_issn_str_mv' => [
            ],
            'holdings_txtP_mv' => [
                0 => 'L 123 L 616.8 __unit_test_no_source__',
                1 => 'Ll 234 Ll Course __unit_test_no_source__',
            ],
            'callnumber-sort' => '',
            'author_facet' => [
                0 => 'Kalat, James W.',
                1 => 'Kalat, James W.',
            ],
            'format_ext_str_mv' => 'Book',
        ];

        $this->compareArray($expected, $fields, 'toSolrArray');
    }

    /**
     * Test MARC Record handling
     *
     * @return void
     */
    public function testMarcGeo()
    {
        $record = $this->createRecord(Marc::class, 'marc_geo.xml');
        $fields = $record->toSolrArray();
        unset($fields['fullrecord']);

        $expected = [
            'record_format' => 'marc',
            'building' => [
                0 => '001',
            ],
            'lccn' => '',
            'ctrlnum' => [
                0 => '(FI-Piki)Ppro837_107786',
                1 => '(PIKI)Ppro837_107786',
                2 => '(FI-MELINDA)000963219',
            ],
            'allfields' => [
                0 => 'Suomen tiekartta',
                1 => 'Vägkarta över Finland',
                2 => '1.',
                3 => 'Suomen tiekartta 1',
                4 => '1:200000',
                5 => 'Helsinki]',
                6 => 'Maanmittaushallitus]',
                7 => '1946.',
                8 => 'Ahvenanmaa mittakaavassa 1:400000',
                9 => 'Kh-kokoelma',
                10 => 'tiekartat',
                11 => 'kartat',
                12 => 'Suomi',
                13 => 'Turun ja Porin lääni',
                14 => 'ysa',
                15 => 'Uudenmaan lääni',
                16 => 'Ahvenanmaa',
                17 => 'Maanmittaushallitus',
            ],
            'language' => [
                0 => 'fin',
                1 => 'fin',
                2 => 'swe',
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
                0 => 'Maanmittaushallitus',
            ],
            'author_corporate_role' => [
                0 => '-',
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
                0 => 'Vägkarta över Finland',
                1 => 'Suomen tiekartta 1',
            ],
            'title_old' => [
            ],
            'title_new' => [
            ],
            'title_sort' => 'suomen tiekartta = vägkarta över finland. 1.',
            'series' => [
            ],
            'publisher' => [
                0 => '[Maanmittaushallitus]',
            ],
            'publishDateSort' => '1946',
            'publishDate' => [
                0 => '1946',
            ],
            'physical' => [
                0 => '1 kartta : värillinen ; taitettuna 26 x 13 cm',
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
                0 => '42.02',
            ],
            'callnumber-sort' => '42.02',
            'topic' => [
                0 => 'tiekartat',
                1 => 'kartat Suomi',
            ],
            'genre' => [
            ],
            'geographic' => [
                0 => 'Turun ja Porin lääni',
                1 => 'Uudenmaan lääni',
                2 => 'Ahvenanmaa',
            ],
            'era' => [
            ],
            'topic_facet' => [
                0 => 'tiekartat',
                1 => 'kartat',
            ],
            'genre_facet' => [
            ],
            'geographic_facet' => [
                0 => 'Suomi',
                1 => 'Turun ja Porin lääni',
                2 => 'Uudenmaan lääni',
                3 => 'Ahvenanmaa',
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
                0 => '[1946-01-01 TO 1946-12-31]',
            ],
            'publication_place_txt_mv' => [
                0 => 'Helsinki',
            ],
            'subtitle_lng_str_mv' => [
            ],
            'original_lng_str_mv' => [
                0 => 'fin',
                1 => 'swe',
            ],
            'location_geo' => [
                'ENVELOPE(19.5, 24.75, 60.666666666667, 59.8)',
                'ENVELOPE(19.5, 24.75, 60.666666666667, 59.800277777778)',
            ],
            'center_coords' => [
                '22.125 60.233333333333',
                '22.125 60.233472222223',
            ],
            'classification_txt_mv' => [
                0 => 'ykl 42.02',
                1 => 'ykl 42.02',
            ],
            'major_genre_str_mv' => 'nonfiction',
            'classification_str_mv' => [
                0 => 'ykl 42.02',
                1 => 'ykl 42.02',
            ],
            'source_str_mv' => '__unit_test_no_source__',
            'datasource_str_mv' => [
                0 => '__unit_test_no_source__',
            ],
            'other_issn_str_mv' => [
            ],
            'other_issn_isn_mv' => [
            ],
            'linking_issn_str_mv' => [
            ],
            'holdings_txtP_mv' => [
                0 => '1 001 __unit_test_no_source__',
            ],
            'author_facet' => [
                0 => 'Maanmittaushallitus',
            ],
            'format_ext_str_mv' => 'Map',
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
        $record = $this->createRecord(Marc::class, 'marc-thesis1.xml');
        $fields = $record->toSolrArray();
        unset($fields['fullrecord']);

        $expected = [
            'record_format' => 'marc',
            'building' => [],
            'lccn' => '',
            'ctrlnum' => [],
            'allfields' => [
                0 => 'Author, Test',
                1 => 'Thesis Title',
                2 => 'Test Author',
                3 => 'Helsinki]',
                4 => 'Kansalliskirjasto',
                5 => '2020',
                6 => 'AMK-opinnäytetypo',
                7 => 'Sample Program',
                8 => '2020.',
                9 => 'AMK-opinnäytetyö',
                10 => 'Second Sample Program',
                11 => 'testaus',
            ],
            'language' => [
                0 => 'fin',
            ],
            'format' => 'BachelorsThesisPolytechnic',
            'author' => [
                0 => 'Author, Test',
            ],
            'author_role' => [
                0 => '-',
            ],
            'author_fuller' => [],
            'author_sort' => 'Author, Test',
            'author2' => [
                0 => 'Author, Test',
            ],
            'author2_role' => [
                0 => '-',
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
                0 => 'Kansalliskirjasto',
            ],
            'publishDateSort' => '2020',
            'publishDate' => [
                0 => '2020',
            ],
            'physical' => [],
            'dateSpan' => [],
            'edition' => '',
            'contents' => [],
            'isbn' => [],
            'issn' => [],
            'callnumber-first' => '614.8',
            'callnumber-raw' => [
                0 => '614.8',
            ],
            'callnumber-sort' => '614.8',
            'topic' => [
                0 => 'testaus',
            ],
            'genre' => [],
            'geographic' => [],
            'era' => [],
            'topic_facet' => [
                0 => 'testaus',
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
                0 => '[2020-01-01 TO 2020-12-31]',
            ],
            'publication_place_txt_mv' => [
                0 => 'Helsinki',
            ],
            'subtitle_lng_str_mv' => [],
            'original_lng_str_mv' => [],
            'classification_txt_mv' => [
                0 => 'udk 614.8',
            ],
            'major_genre_str_mv' => 'nonfiction',
            'classification_str_mv' => [
                0 => 'udk 614.8',
            ],
            'source_str_mv' => '__unit_test_no_source__',
            'datasource_str_mv' => [
                0 => '__unit_test_no_source__',
            ],
            'other_issn_str_mv' => [],
            'other_issn_isn_mv' => [],
            'linking_issn_str_mv' => [],
            'holdings_txtP_mv' => [],
            'author_facet' => [
                0 => 'Author, Test',
                1 => 'Author, Test',
            ],
            'format_ext_str_mv' => 'BachelorsThesisPolytechnic',
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
        $record = $this->createRecord(Marc::class, 'marc-thesis2.xml');
        $fields = $record->toSolrArray();
        unset($fields['fullrecord']);

        $expected = [
            'record_format' => 'marc',
            'building' => [],
            'lccn' => '',
            'ctrlnum' => [],
            'allfields' => [
                0 => 'Author, Test',
                1 => 'Thesis Title',
                2 => 'Test Author',
                3 => 'Helsinki]',
                4 => 'Kansalliskirjasto',
                5 => '2020',
                6 => 'testaus',
            ],
            'language' => [
                0 => 'fin',
            ],
            'format' => 'BachelorsThesisPolytechnic',
            'author' => [
                0 => 'Author, Test',
            ],
            'author_role' => [
                0 => '-',
            ],
            'author_fuller' => [],
            'author_sort' => 'Author, Test',
            'author2' => [
                0 => 'Author, Test',
            ],
            'author2_role' => [
                0 => '-',
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
                0 => 'Kansalliskirjasto',
            ],
            'publishDateSort' => '2020',
            'publishDate' => [
                0 => '2020',
            ],
            'physical' => [],
            'dateSpan' => [],
            'edition' => '',
            'contents' => [],
            'isbn' => [],
            'issn' => [],
            'callnumber-first' => '614.8',
            'callnumber-raw' => [
                0 => '614.8',
            ],
            'callnumber-sort' => '614.8',
            'topic' => [
                0 => 'testaus',
            ],
            'genre' => [],
            'geographic' => [],
            'era' => [],
            'topic_facet' => [
                0 => 'testaus',
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
                0 => '[2020-01-01 TO 2020-12-31]',
            ],
            'publication_place_txt_mv' => [
                0 => 'Helsinki',
            ],
            'subtitle_lng_str_mv' => [],
            'original_lng_str_mv' => [],
            'classification_txt_mv' => [
                0 => 'udk 614.8',
            ],
            'major_genre_str_mv' => 'nonfiction',
            'classification_str_mv' => [
                0 => 'udk 614.8',
            ],
            'source_str_mv' => '__unit_test_no_source__',
            'datasource_str_mv' => [
                0 => '__unit_test_no_source__',
            ],
            'other_issn_str_mv' => [],
            'other_issn_isn_mv' => [],
            'linking_issn_str_mv' => [],
            'holdings_txtP_mv' => [],
            'author_facet' => [
                0 => 'Author, Test',
                1 => 'Author, Test',
            ],
            'format_ext_str_mv' => 'BachelorsThesisPolytechnic',
        ];

        $this->compareArray($expected, $fields, 'toSolrArray');
    }
}
