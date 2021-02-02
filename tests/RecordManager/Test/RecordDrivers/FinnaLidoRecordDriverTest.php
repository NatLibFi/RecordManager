<?php
/**
 * Finna LIDO Record Driver Test Class
 *
 * PHP version 7
 *
 * Copyright (C) Eero Heikkinen 2013.
 * Copyright (C) The National Library of Finland 2017.
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
 * @author   Eero Heikkinen <eero.heikkinen@gmail.com>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
namespace RecordManager\Test\RecordDrivers;

use RecordManager\Finna\Record\Lido;

/**
 * Finna LIDO Record Driver Test Class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Eero Heikkinen <eero.heikkinen@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class FinnaLidoRecordDriverTest extends RecordDriverTest
{
    /**
     * Test Musketti LIDO record handling
     *
     * @return void
     */
    public function testMusketti1()
    {
        $fields = $this->createRecord(Lido::class, 'musketti1.xml')->toSolrArray();

        $this->assertContains('metalli', $fields['material']);
        $this->assertContains('kupari', $fields['material']);

        $this->assertContains('ruokatalous ja elintarviketeollisuus', $fields['classification_txt_mv']);
        $this->assertContains('ruoan valmistus', $fields['classification_txt_mv']);
        $this->assertContains('työkalut ja välineet', $fields['classification_txt_mv']);
        $this->assertContains('astiat ja välineet', $fields['classification_txt_mv']);
        $this->assertContains('kahvipannu', $fields['classification_txt_mv']);

        $this->assertContains('taloustarvikkeet', $fields['topic']);
        $this->assertContains('nautintoaineet', $fields['topic']);
        $this->assertContains('kahvi', $fields['topic']);
        $this->assertContains('suomalais-ugrilaiset kansat', $fields['topic']);
        $this->assertContains('saamelaiset', $fields['topic']);
        $this->assertContains('porolappalaiset', $fields['topic']);
        $this->assertContains('pannut', $fields['topic']);
        $this->assertContains('kahvipannut', $fields['topic']);

        $this->assertEquals('kahvipannu', $fields['title']);

        $this->assertEquals('Suomen kansallismuseo/KM', $fields['institution']);

        $this->assertEquals('S3168:23', $fields['identifier']);

        $this->assertEquals('Kuparinen, mustunut ja kolhiintunut poromiesten käyttämä kahvipannu.', $fields['description']);

        $this->assertContains('korkeus, suurin 12.50, halkaisija 13 cm', $fields['measurements']);

        $this->assertContains('saamelaiset', $fields['culture']);

        $this->assertEquals('http://muisti.nba.fi/m/S3168_23/sa009218.jpg', $fields['thumbnail']);

        $this->assertEquals('Seurasaaren ulkomuseon kokoelmat', $fields['collection']);

        $this->assertEquals('esine', $fields['format']);

        $this->assertContains('Utsjoki, Lappi', $fields['allfields']);
        $this->assertContains('teollinen tuote', $fields['allfields']);
        $this->assertContains('Museovirasto/MV', $fields['allfields']);
    }

    /**
     * Test Musketti LIDO record handling
     *
     * @return void
     */
    public function testMusketti2()
    {
        $fields = $this->createRecord(Lido::class, 'musketti2.xml')->toSolrArray();
        unset($fields['fullrecord']);

        $expected = [
            'record_format' => 'lido',
            'title_full' => 'Imatrankoski',
            'title_short' => 'Imatrankoski',
            'title' => 'Imatrankoski',
            'title_sort' => 'Imatrankoski',
            'format' => 'kuva',
            'institution' => 'Museoviraston kuva-arkisto/',
            'author' => [
                0 => 'Hintze Harry, kuvaaja',
            ],
            'author_sort' => 'Hintze Harry, kuvaaja',
            'topic_facet' => [
            ],
            'topic' => [
            ],
            'material' => [
            ],
            'era_facet' => '1897',
            'era' => '1897',
            'geographic_facet' => [
                0 => 'Imatrankoski, Ruokolahti',
                1 => 'Imatrankoski',
                2 => 'Ruokolahti',
            ],
            'geographic' => [
                0 => 'Imatrankoski, Ruokolahti',
            ],
            'collection' => 'Kansatieteen kuvakokoelma',
            'thumbnail' => 'http://muisti.nba.fi/m/4878_1/00013199.jpg',
            'allfields' => [
                0 => 'musketti_www.M012:4878:1',
                1 => 'Museovirasto/MV',
                2 => 'kuva',
                3 => 'Museovirasto/MV',
                4 => 'Museovirasto/MV',
                5 => 'Museovirasto/MV',
                6 => 'valokuva',
                7 => 'Museovirasto/MV',
                8 => 'Museovirasto/MV',
                9 => 'Museovirasto/MV',
                10 => 'Museovirasto/MV',
                11 => 'Imatrankoski',
                12 => 'Museovirasto/MV',
                13 => 'Museovirasto/MV',
                14 => 'Museovirasto/MV',
                15 => '33,1.',
                16 => 'Museovirasto/MV',
                17 => 'Museovirasto/MV',
                18 => 'Museovirasto/MV',
                19 => 'Imatra. val. H.Hintze 1897 Antr.',
                20 => 'Museovirasto/MV',
                21 => 'Museovirasto/MV',
                22 => 'Museovirasto/MV',
                23 => '33,1.',
                24 => 'Museovirasto/MV',
                25 => 'Museovirasto/MV',
                26 => 'Museovirasto/MV',
                27 => 'Imatra. 1897',
                28 => 'Museovirasto/MV',
                29 => 'Museovirasto/MV',
                30 => 'Museovirasto/MV',
                31 => '33,1. Imatra.',
                32 => 'Museovirasto/MV',
                33 => 'Museovirasto/MV',
                34 => 'Museovirasto/MV',
                35 => 'Museovirasto/MV',
                36 => 'Museoviraston kuva-arkisto/',
                37 => 'Museovirasto/MV',
                38 => 'Museovirasto/MV',
                39 => 'Museovirasto/MV',
                40 => '4878:1',
                41 => 'Museovirasto/MV',
                42 => 'Museovirasto/MV',
                43 => 'Museovirasto/MV',
                44 => 'Museovirasto/MV',
                45 => 'valmistus',
                46 => 'Museovirasto/MV',
                47 => 'Museovirasto/MV',
                48 => 'Hintze Harry',
                49 => 'Museovirasto/MV',
                50 => 'Museovirasto/MV',
                51 => 'Museovirasto/MV',
                52 => 'Museovirasto/MV',
                53 => 'Museovirasto/MV',
                54 => '1897',
                55 => 'Museovirasto/MV',
                56 => '1897',
                57 => 'Museovirasto/MV',
                58 => '1897',
                59 => 'Museovirasto/MV',
                60 => 'Museovirasto/MV',
                61 => 'Museovirasto/MV',
                62 => 'Ruokolahti',
                63 => 'Museovirasto/MV',
                64 => 'Museovirasto/MV',
                65 => 'Museovirasto/MV',
                66 => 'Museovirasto/MV',
                67 => 'Museovirasto/MV',
                68 => 'Imatrankoski',
                69 => 'Museovirasto/MV',
                70 => '1897',
                71 => 'Museovirasto/MV',
                72 => 'Museovirasto/MV',
                73 => 'Imatrankoski, Ruokolahti',
                74 => 'Museovirasto/MV',
                75 => 'Museovirasto/MV',
                76 => 'Imatrankoski',
                77 => 'Museovirasto/MV',
                78 => 'Museovirasto/MV',
                79 => 'luonnon paikka',
                80 => 'Museovirasto/MV',
                81 => 'Museovirasto/MV',
                82 => 'Museovirasto/MV',
                83 => 'Museovirasto/MV',
                84 => 'Ruokolahti ..',
                85 => 'Museovirasto/MV',
                86 => 'Museovirasto/MV',
                87 => 'kunta/kaupunki (Suomi)',
                88 => 'Museovirasto/MV',
                89 => 'Museovirasto/MV',
                90 => 'Museovirasto/MV',
                91 => 'Museovirasto/MV',
                92 => 'Museovirasto/MV',
                93 => 'Museovirasto/MV',
                94 => 'Museovirasto/MV',
                95 => 'Museovirasto/MV',
                96 => 'Museovirasto/MV',
                97 => 'Museovirasto',
                98 => 'Museovirasto/MV',
                99 => 'Museovirasto/MV',
                100 => 'Hintze Harry',
                101 => 'Museovirasto/MV',
                102 => 'Museovirasto/MV',
                103 => 'Museovirasto/MV',
                104 => 'Museovirasto/MV',
                105 => 'Museovirasto/MV',
                106 => '4878:1',
                107 => 'Museovirasto/MV',
                108 => '4878:1',
                109 => 'Museovirasto/MV',
                110 => 'Museovirasto/MV',
                111 => 'Museovirasto/MV',
                112 => 'Museovirasto/MV',
                113 => 'Museovirasto/MV',
                114 => 'Museovirasto/MV',
                115 => 'Museovirasto/MV',
                116 => 'Museovirasto/MV',
                117 => 'Museovirasto/MV',
                118 => 'Museovirasto/MV',
                119 => 'Museovirasto/MV',
                120 => 'Museovirasto/MV',
                121 => 'Museovirasto/MV',
                122 => 'Museovirasto/MV',
            ],
            'identifier' => '4878:1',
            'measurements' => [
                0 => '12 x 17 cm, 12 cm',
            ],
            'culture' => [
            ],
            'rights' => 'Museovirasto/MV',
            'artist_str_mv' => [
            ],
            'photographer_str_mv' => [
            ],
            'finder_str_mv' => [
            ],
            'manufacturer_str_mv' => [
            ],
            'designer_str_mv' => [
            ],
            'classification_str_mv' => [
                0 => 'valokuva',
            ],
            'classification_txt_mv' => [
                0 => 'valokuva',
            ],
            'exhibition_str_mv' => [
            ],
            'main_date_str' => '1897',
            'main_date' => '1897-01-01T00:00:00Z',
            'search_daterange_mv' => [
                0 => '[1897-01-01 TO 1897-12-31]',
                1 => '[1897-01-01 TO 1897-12-31]',
            ],
            'creation_daterange' => '[1897-01-01 TO 1897-12-31]',
            'source_str_mv' => '__unit_test_no_source__',
            'datasource_str_mv' => '__unit_test_no_source__',
            'online_boolean' => true,
            'online_str_mv' => '__unit_test_no_source__',
            'free_online_boolean' => true,
            'free_online_str_mv' => '__unit_test_no_source__',
            'location_geo' => [
            ],
            'center_coords' => '',
            'usage_rights_str_mv' => [
                0 => '',
            ],
            'author_facet' => [
                0 => 'Hintze Harry',
            ],
            'format_ext_str_mv' => [
                0 => 'kuva',
            ],
            'hierarchy_parent_title' => [
                0 => 'Kansatieteen kuvakokoelma',
            ],
            'ctrlnum' => [
            ],
        ];

        $this->compareArray($expected, $fields, 'toSolrArray');
    }

    /**
     * Test Lusto LIDO record handling
     *
     * @return void
     */
    public function testLusto1()
    {
        $fields = $this->createRecord(Lido::class, 'lusto1.xml')->toSolrArray();

        $this->assertEquals('E01025:3', $fields['identifier']);

        $this->assertContains('muovi, metalli', $fields['material']);

        $this->assertContains('istutus', $fields['topic']);
        $this->assertContains('kantovälineet', $fields['topic']);
        $this->assertContains('metsänhoito', $fields['topic']);
        $this->assertContains('metsänviljely', $fields['topic']);
        $this->assertContains('metsätalous', $fields['topic']);

        $this->assertEquals('[1980-01-01 TO 1999-12-31]', $fields['creation_daterange']);

        $this->assertEquals('Esine', $fields['format']);

        $this->assertContains('pituus 65 cm, leveys 55 cm, korkeus enimmillään 26 cm', $fields['measurements']);
    }

    /**
     * Test VTM LIDO record handling
     *
     * @return void
     */
    public function testVtm1()
    {
        $fields = $this->createRecord(Lido::class, 'vtm1.xml')->toSolrArray();

        $this->assertContains('kangas', $fields['material']);
        $this->assertContains('öljy', $fields['material']);

        $this->assertContains('maalaus', $fields['classification_txt_mv']);

        $this->assertEquals('Venetsia', $fields['title']);

        $this->assertEquals('Ateneumin taidemuseo', $fields['institution']);

        $this->assertEquals('A V 4724', $fields['identifier']);

        $this->assertContains('41 x 51 cm', $fields['measurements']);

        $this->assertEquals('http://ndl.fng.fi/ndl/zoomview/muusa01/0051A721-E48D-42B4-BD02-98D8C4681A50.jpg', $fields['thumbnail']);

        $this->assertEquals('Richter', $fields['collection']);

        $this->assertEquals('maalaus', $fields['format']);

        $this->assertEquals(['Salokivi, Santeri, taiteilija'], $fields['author']);
        $this->assertEquals('[1911-01-01 TO 1911-12-31]', $fields['creation_daterange']);
    }

    /**
     * Test Tuusula LIDO record handling
     *
     * @return void
     */
    public function testTuusula1()
    {
        $fields = $this->createRecord(Lido::class, 'tuusula1.xml')->toSolrArray();

        $this->assertContains('kangas', $fields['material']);
        $this->assertContains('pahvi', $fields['material']);
        $this->assertContains('öljy', $fields['material']);

        $this->assertContains('maalaus', $fields['classification_txt_mv']);

        $this->assertEquals('Rantakiviä', $fields['title']);

        $this->assertEquals('Tuusulan taidemuseo', $fields['institution']);

        $this->assertEquals('Rantamaisema, jonka matalassa vedessä näkyy maalauksen etuosassa punervanharmaita kiviä sekä rantakalliota harmaansinisen vaalean veden rannalla. Taustana pelkää vettä, mikä valtaa suurimman osa kuva-alasta. Veden kuvaus heijastuksineen on kiinnostanut Pekka Halosta koko hänen taiteellisen uransa aikana. Hänen viimeisten vuosien maisemissa vesiaiheet ovat lähinnä rantaviivojen, vesien ja jäänpinnan heijastusten kuvauksia. Horisontti on usein laskenut hyvin alas, sitä tuskin enää näkyy ollenkaan, kuten tässä teoksessa. Halosen maisemat muuttuvat yhä seesteisemmiksi ja pelkistetyimmiksi', $fields['description']);

        $this->assertEquals('Tla TM T 374', $fields['identifier']);

        $this->assertNotContains('25H112', $fields['topic']);
        $this->assertNotContains('Rantamaisema, jonka matalassa vedessä näkyy maalauksen etuosassa punervanharmaita kiviä sekä rantakalliota harmaansinisen vaalean veden rannalla. Taustana pelkää vettä, mikä valtaa suurimman osa kuva-alasta.', $fields['topic']);

        $this->assertContains('50 x 41 cm', $fields['measurements']);

        $this->assertEquals('http://ndl.fng.fi/ndl/zoomview/muusa24/3D313279-45A5-469A-885E-766C66F0F6DC.jpg', $fields['thumbnail']);

        $this->assertEquals('Pekka Halosen seuran kokoelma / Antti Halosen kokoelma', $fields['collection']);

        $this->assertContains('Pystymetsän Pekka. Pekka Halosen maalauksia vuosilta 1887-1932. Halosenniemi, Tuusula 17.4.-17.10.2010', $fields['exhibition_str_mv']);

        $this->assertEquals('maalaus', $fields['format']);

        $this->assertEquals(['Halonen, Pekka, taiteilija'], $fields['author']);
        $this->assertEquals('[1930-01-01 TO 1930-12-31]', $fields['creation_daterange']);
    }

    /**
     * Test Design Museum LIDO record handling
     *
     * @return void
     */
    public function testDesign1()
    {
        $fields = $this->createRecord(Lido::class, 'design1.xml')->toSolrArray();

        $this->assertEquals('Kuva', $fields['format']);

        $this->assertRegExp('/aterimet/', $fields['title']);
        $this->assertRegExp('/lusikka, haarukka, veitsi/', $fields['title']);
        $this->assertRegExp('/Triennale/', $fields['title']);

        $this->assertEquals('45106', $fields['identifier']);

        $this->assertContains('ruostumaton teräs', $fields['material']);

        $this->assertEquals('Designmuseo', $fields['institution']);
    }

    /**
     * Test work identification keys
     *
     * @return void
     */
    public function testWorkIdentificationKeys()
    {
        $record = $this->createRecord(Lido::class, 'lido_workkeys.xml');

        $expected = [
            'titles' => [
                ['type' => 'title', 'value' => 'Rantakiviä litteitä'],
                ['type' => 'title', 'value' => 'Shore Stones'],
                ['type' => 'title', 'value' => 'other'],
            ],
            'authors' => [
                ['type' => 'author', 'value' => 'Halonen, Pekka']
            ],
            'titlesAltScript' => [],
            'authorsAltScript' => []
        ];

        $this->compareArray(
            $expected,
            $record->getWorkIdentificationData(),
            'getWorkIdentificationData'
        );
    }
}
