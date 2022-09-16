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
 * @link     https://github.com/NatLibFi/RecordManager
 */
namespace RecordManagerTest\Base\Record;

use RecordManager\Base\Database\DatabaseInterface as Database;
use RecordManager\Base\Record\Marc;

/**
 * MARC Record Driver Test Class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class MarcTest extends RecordTest
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
                'Hirsjärvi, Sirkka',
                'Tutki ja kirjoita',
                'Sirkka Hirsjärvi, Pirkko Remes, Paula Sajavaara',
                '17. uud. p.',
                'Helsinki',
                'Tammi',
                '2345 [2013?]',
                'teksti',
                'txt',
                'rdacontent',
                'käytettävissä ilman laitetta',
                'n',
                'rdamedia',
                'nide',
                'nc',
                'rdacarrier',
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
            'author2_fuller' => [
            ],
            'author_corporate' => [
            ],
            'author_corporate_role' => [
            ],
            'author_additional' => [
            ],
            'title' => 'Tutki ja kirjoita',
            'title_sub' => '',
            'title_short' => 'Tutki ja kirjoita',
            'title_full' => 'Tutki ja kirjoita / Sirkka Hirsjärvi, Pirkko Remes,'
                . ' Paula Sajavaara',
            'title_alt' => [
            ],
            'title_old' => [
            ],
            'title_new' => [
            ],
            'title_sort' => 'tutki ja kirjoita / sirkka hirsjärvi, pirkko remes,'
                . ' paula sajavaara',
            'series' => [
            ],
            'publisher' => [
                'Tammi',
            ],
            'publishDateSort' => '2013',
            'publishDate' => [
                '2013',
            ],
            'physical' => [
            ],
            'dateSpan' => [
            ],
            'edition' => '17. uud. p.',
            'contents' => [
            ],
            'isbn' => [
                '9789513148362',
            ],
            'issn' => [
            ],
            'callnumber-first' => 'QC861.2',
            'callnumber-raw' => [
                '38.04',
                '38.03',
                'QC861.2 .B36',
            ],
            'callnumber-subject' => 'QC',
            'callnumber-label' => 'QC861',
            'callnumber-sort' => 'QC 3861.2',
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
            'genre' => [
            ],
            'geographic' => [
            ],
            'era' => [
            ],
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
            [
                'authors' => [
                    [
                        'type' => 'author',
                        'value' => 'Hirsjärvi, Sirkka.',
                    ],
                ],
                'authorsAltScript' => [],
                'titles' => [
                    [
                        'type' => 'title',
                        'value' => 'Tutki ja kirjoita /',
                    ],
                ],
                'titlesAltScript' => [],
            ]
        ];

        $this->compareArray($expected, $keys, 'getWorkIdentificationData');

        $this->assertEquals(['(FOO)2345'], $record->getUniqueIDs());
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
                '123',
                '234',
            ],
            'lccn' => '',
            'ctrlnum' => [
                '1558192',
                'FCC002608043',
            ],
            'allfields' => [
                'Kalat, James W.',
                'Biological psychology',
                'James W. Kalat',
                '7th ed',
                'Belmont, CA',
                'Wadsworth',
                'cop. 2001.',
                'xxiii, 551 sivua',
                'kuvitettu +',
                'CD-ROM -levy',
                'teksti',
                'txt',
                'rdacontent',
                'käytettävissä ilman laitetta',
                'n',
                'rdamedia',
                'nide',
                'nc',
                'rdacarrier',
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
            'illustrated' => 'Not Illustrated',
        ];

        $this->compareArray($expected, $fields, 'toSolrArray');

        $keys = $record->getWorkIdentificationData();

        $expected = [
            [
                'authors' => [
                    [
                        'type' => 'author',
                        'value' => 'Kalat, James W.',
                    ],
                ],
                'authorsAltScript' => [],
                'titles' => [
                    [
                        'type' => 'title',
                        'value' => 'Biological psychology /',
                    ],
                ],
                'titlesAltScript' => [],
            ]
        ];

        $this->compareArray($expected, $keys, 'getWorkIdentificationData');
    }

    /**
     * Test MARC Record handling
     *
     * @return void
     */
    public function testMarcGeo()
    {
        $dsConfig = [
            '__unit_test_no_source__' => [
                'driverParams' => [
                    'geoCenterField=center_coords'
                ]
            ]
        ];
        $record = $this->createRecord(Marc::class, 'marc_geo.xml', $dsConfig);
        $fields = $record->toSolrArray();
        unset($fields['fullrecord']);

        $expected = [
            'record_format' => 'marc',
            'building' => [
                '001',
            ],
            'center_coords' => [
                '22.125 60.233333333333',
                '22.125 60.233472222223',
            ],
            'long_lat' => [
                'ENVELOPE(19.5, 24.75, 60.666666666667, 59.8)',
                'ENVELOPE(19.5, 24.75, 60.666666666667, 59.800277777778)',
            ],
            'long_lat_display' => [
                '19.5 24.75 60.666666666667 59.8',
                '19.5 24.75 60.666666666667 59.800277777778',
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
                '1 kartta',
                'värillinen',
                'taitettuna 26 x 13 cm',
                'kartografinen kuva',
                'cri',
                'rdacontent',
                'käytettävissä ilman laitetta',
                'n',
                'rdamedia',
                'arkki',
                'nb',
                'rdacarrier',
                'Ahvenanmaa mittakaavassa 1:400000',
                'Kh-kokoelma',
                'tiekartat',
                'kartat',
                'Suomi',
                'Turun ja Porin lääni',
                'yso/fin',
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
            'author_additional' => [
            ],
            'title' => 'Suomen tiekartta = Vägkarta över Finland. 1.',
            'title_sub' => 'Vägkarta över Finland. 1.',
            'title_short' => 'Suomen tiekartta',
            'title_full' => 'Suomen tiekartta = Vägkarta över Finland. 1.',
            'title_alt' => [
                'Vägkarta över Finland',
                'Suomen tiekartta 1'
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
            'callnumber-first' => '',
            'callnumber-raw' => [
                '42.02',
            ],
            'callnumber-sort' => '',
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
        ];

        $this->compareArray($expected, $fields, 'toSolrArray');
    }

    /**
     * Test MARC Record handling
     *
     * @return void
     */
    public function testMarcDewey()
    {
        $record = $this->createRecord(Marc::class, 'marc_dewey.xml');
        $fields = $record->toSolrArray();
        unset($fields['fullrecord']);

        $expected = [
            'record_format' => 'marc',
            'building' => [
            ],
            'lccn' => '',
            'ctrlnum' => [
                'FCC016234029',
                '(OCoLC)123456',
                'ocn234567',
            ],
            'allfields' => [
                'Braudel, Fernand',
                'kirjoittaja',
                'Civilisation matérielle, économie et capitalisme, XVe-XVIIIe'
                    . ' siècle',
                'le possible et l\'impossible',
                'Tome 1',
                'Les structures du quotidien : le possible et l\'impossible',
                'Fernand Braudel',
                'Les structures du quotidien',
                'Paris',
                'Armand Colin',
                '1979',
                '© 1979',
                '543 sivua',
                'kuvitettu',
                '24 cm',
                'teksti',
                'txt',
                'rdacontent',
                'käytettävissä ilman laitetta',
                'n',
                'rdamedia',
                'nide',
                'nc',
                'rdacarrier',
                'Autres tirages : 1980, 1984, 1986, 1988, 1992, 2000.',
                'Bibliogr. p. 497-520. Index',
                'Moeurs et coutumes',
                'Études transculturelles',
                '1500-1800',
                'Sociologie du quotidien',
                'Civilisation',
                'Histoire',
                'Histoire sociale',
                'Économie politique',
                'Histoire moderne et contemporaine',
                'Matérialisme',
                'Capitalisme',
                'Civilisation moderne',
                'Histoire économique',
                'Economic history',
                'Social history',
                'Civilization, Modern',
                'History',
            ],
            'language' => [
                'fre',
                'fre',
            ],
            'format' => 'Book',
            'author' => [
            ],
            'author_role' => [
            ],
            'author_fuller' => [
            ],
            'author2' => [
                'Braudel, Fernand',
            ],
            'author2_role' => [
                'kirjoittaja',
            ],
            'author2_fuller' => [
            ],
            'author_corporate' => [
            ],
            'author_corporate_role' => [
            ],
            'author_additional' => [
            ],
            'title' => 'Civilisation matérielle, économie et capitalisme, XVe-XVIIIe'
                . ' siècle : le possible et l\'impossible. Tome 1, Les structures du'
                . ' quotidien : le possible et l\'impossible',
            'title_sub' => 'le possible et l\'impossible. Tome 1, Les structures du'
                . ' quotidien : le possible et l\'impossible',
            'title_short' => 'Civilisation matérielle, économie et capitalisme,'
                . ' XVe-XVIIIe siècle',
            'title_full' => 'Civilisation matérielle, économie et capitalisme,'
                . ' XVe-XVIIIe siècle : le possible et l\'impossible. Tome 1, Les'
                . ' structures du quotidien : le possible et l\'impossible / Fernand'
                . ' Braudel',
            'title_alt' => [
                'Les structures du quotidien : le possible et l\'impossible'
            ],
            'title_old' => [
            ],
            'title_new' => [
            ],
            'title_sort' => 'civilisation matérielle, économie et capitalisme,'
                . ' xve-xviiie siècle : le possible et l\'impossible. tome 1, les'
                . ' structures du quotidien : le possible et l\'impossible / fernand'
                . ' braudel',
            'series' => [
            ],
            'publisher' => [
                'Armand Colin',
            ],
            'publishDateSort' => '1979',
            'publishDate' => [
                '1979',
            ],
            'physical' => [
                '543 sivua : kuvitettu ; 24 cm',
            ],
            'dateSpan' => [
            ],
            'edition' => '',
            'contents' => [
            ],
            'isbn' => [
                '9782200371005',
            ],
            'issn' => [
            ],
            'callnumber-first' => '',
            'callnumber-raw' => [
                '940.',
                '909.',
                '909.4.',
                '330.903.',
            ],
            'callnumber-sort' => '',
            'topic' => [
                'Moeurs et coutumes Études transculturelles 1500-1800',
                'Sociologie du quotidien Études transculturelles',
                'Civilisation Histoire',
                'Histoire sociale 1500-1800',
                'Économie politique',
                'Histoire moderne et contemporaine',
                'Matérialisme Histoire',
                'Capitalisme Histoire',
                'Civilisation moderne Histoire',
                'Histoire économique',
                'Economic history',
                'Social history',
                'Civilization, Modern History',
            ],
            'genre' => [
            ],
            'geographic' => [
            ],
            'era' => [
            ],
            'topic_facet' => [
                'Moeurs et coutumes',
                'Sociologie du quotidien',
                'Civilisation',
                'Histoire sociale',
                'Économie politique',
                'Histoire moderne et contemporaine',
                'Matérialisme',
                'Capitalisme',
                'Civilisation moderne',
                'Histoire économique',
                'Economic history',
                'Social history',
                'Civilization, Modern',
                'Études transculturelles',
                'Études transculturelles',
                'Histoire',
                'Histoire',
                'Histoire',
                'Histoire',
                'History',
            ],
            'genre_facet' => [
            ],
            'geographic_facet' => [
            ],
            'era_facet' => [
                '1500-1800',
                '1500-1800',
            ],
            'url' => [
            ],
            'illustrated' => 'Illustrated',
            'dewey-hundreds' => '300',
            'dewey-tens' => '330',
            'dewey-ones' => '330',
            'dewey-full' => '330.903',
            'dewey-sort' => '3330.903 ',
            'dewey-raw' => '330.903',
            'oclc_num' => [
                '123456',
                '234567'
            ],
        ];

        $this->compareArray($expected, $fields, 'toSolrArray');
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
        $db->expects($this->exactly(5))
            ->method('findRecord')
            ->will($this->returnValueMap($map));

        $record = $this->createRecord(Marc::class, 'marc_links.xml');
        $record->toSolrArray($db);
        $marc776 = $record->getFields('776');
        $this->assertEquals(2, count($marc776));
        $w = $record->getSubfield($marc776[0], 'w');
        $this->assertEquals('__unit_test_no_source__.4112121', $w);
        $w = $record->getSubfield($marc776[1], 'w');
        $this->assertEquals('__unit_test_no_source__.xyzzy', $w);

        $record = $this->createRecord(
            Marc::class,
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

    /**
     * Tests for getWorkIdentificationData
     *
     * @return void
     */
    public function testGetWorkIdentificationData()
    {
        $record = $this->createRecord(Marc::class, 'marc_alt_script.xml');
        $keys = $record->getWorkIdentificationData();
        $expected = [
            [
                'authors' => [
                    [
                        'type' => 'author',
                        'value' => 'Kageyama, Terukuni,',
                    ],
                ],
                'authorsAltScript' => [
                    [
                        'type' => 'author',
                        'value' => '影山, 輝国,',
                    ],
                ],
                'titles' => [
                    [
                        'type' => 'title',
                        'value' => 'Shinmeikai gendai kanwa jiten /',
                    ],
                    [
                        'type' => 'title',
                        'value' => 'Ōkina katsuji no shinmeikai gendai kanwa jiten',
                    ],
                ],
                'titlesAltScript' => [
                    [
                        'type' => 'title',
                        'value' => '漢字源 : 上級漢和辞典  /',
                    ],
                ],
            ]
        ];
        $this->compareArray($expected, $keys, 'getWorkIdentificationData');

        $record = $this->createRecord(Marc::class, 'marc_analytical.xml');
        $keys = $record->getWorkIdentificationData();
        $expected = [
            [
                'authors' => [
                    [
                        'type' => 'author',
                        'value' => 'Shakespeare, William.',
                    ],
                ],
                'authorsAltScript' => [],
                'titles' => [
                    [
                        'type' => 'title',
                        'value' => 'William Shakespearen suuret draamat. 2 /',
                    ],
                    [
                        'type' => 'title',
                        'value' => 'Suuret draamat',
                    ],
                ],
                'titlesAltScript' => [],
            ],
            [
                'type' => 'analytical',
                'authors' => [
                    [
                        'type' => 'author',
                        'value' => 'Shakespeare, William.',
                    ],
                ],
                'authorsAltScript' => [],
                'titles' => [
                    [
                        'type' => 'title',
                        'value' => 'Hamlet,',
                    ],
                ],
                'titlesAltScript' => [],
            ],
            [
                'type' => 'analytical',
                'authors' => [
                    [
                        'type' => 'author',
                        'value' => 'Shakespeare, William.',
                    ],
                ],
                'authorsAltScript' => [],
                'titles' => [
                    [
                        'type' => 'title',
                        'value' => 'Othello,',
                    ],
                ],
                'titlesAltScript' => [],
            ]
        ];
        $this->compareArray($expected, $keys, 'getWorkIdentificationData');
    }
}
