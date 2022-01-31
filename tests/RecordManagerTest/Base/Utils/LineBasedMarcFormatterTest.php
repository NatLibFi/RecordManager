<?php
/**
 * LineBasedMarcFormatter tests
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2017-2021
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

use RecordManager\Base\Utils\LineBasedMarcFormatter;

/**
 * LineBasedMarcFormatter tests
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class LineBasedMarcFormatterTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Location of configuration files
     *
     * @var string
     */
    const FIXTURE_DIR = __DIR__ . '/../../../fixtures/base/config/fieldmappertest';

    /**
     * Test that an Alma example parses correctly.
     *
     * @return void
     */
    public function testAlmaExample()
    {
        // This should work with default configs:
        $formatter = new LineBasedMarcFormatter();

        $input = <<<ALMALINES
LDR	00936cam a22002654i 4500
001	9917651679506252
005	20211213124319.0
006	m d
007	cr#|||||||||||
008	200511s2020 fi |||||sm||||||||||fin|c
037	__ |b Oamk intra
040	__ |a FI-O |b fin |e rda
041	__ |a fin |b eng
100	1_ |a , |e kirjoittaja.
245	10 |a testitietue testaamiseen / |c .
264	_1 |a [Oulu] : |b Oulun ammattikorkeakoulu, |c kevät 2020.
300	__ |a 1 verkkoaineisto (30 sivua)
336	__ |a teksti |b txt |2 rdacontent
337	__ |a tietokonekäyttöinen |b c |2 rdamedia
338	__ |a verkkoaineisto |b cr |2 rdacarrier
538	__ |a Adobe Acrobat Reader.
567	__ |a .
650	_7 |a pystyviiva | |2 yso/fin
ALMALINES;

        $expected = <<<ALMAXML
<record><leader>6cam a22002654i 4500    </leader><controlfield tag="001">9917651679506252</controlfield><controlfield tag="005">20211213124319.0</controlfield><controlfield tag="006">m d</controlfield><controlfield tag="007">cr#|||||||||||</controlfield><controlfield tag="008">200511s2020 fi |||||sm||||||||||fin|c</controlfield><datafield tag="037" ind1=" " ind2=" "><subfield code="b">Oamk intra</subfield></datafield><datafield tag="040" ind1=" " ind2=" "><subfield code="a">FI-O </subfield><subfield code="b">fin </subfield><subfield code="e">rda</subfield></datafield><datafield tag="041" ind1=" " ind2=" "><subfield code="a">fin </subfield><subfield code="b">eng</subfield></datafield><datafield tag="100" ind1="1" ind2=" "><subfield code="a">, </subfield><subfield code="e">kirjoittaja.</subfield></datafield><datafield tag="245" ind1="1" ind2="0"><subfield code="a">testitietue testaamiseen / </subfield><subfield code="c">.</subfield></datafield><datafield tag="264" ind1=" " ind2="1"><subfield code="a">[Oulu] : </subfield><subfield code="b">Oulun ammattikorkeakoulu, </subfield><subfield code="c">kevät 2020.</subfield></datafield><datafield tag="300" ind1=" " ind2=" "><subfield code="a">1 verkkoaineisto (30 sivua)</subfield></datafield><datafield tag="336" ind1=" " ind2=" "><subfield code="a">teksti </subfield><subfield code="b">txt </subfield><subfield code="2">rdacontent</subfield></datafield><datafield tag="337" ind1=" " ind2=" "><subfield code="a">tietokonekäyttöinen </subfield><subfield code="b">c </subfield><subfield code="2">rdamedia</subfield></datafield><datafield tag="338" ind1=" " ind2=" "><subfield code="a">verkkoaineisto </subfield><subfield code="b">cr </subfield><subfield code="2">rdacarrier</subfield></datafield><datafield tag="538" ind1=" " ind2=" "><subfield code="a">Adobe Acrobat Reader.</subfield></datafield><datafield tag="567" ind1=" " ind2=" "><subfield code="a">.</subfield></datafield><datafield tag="650" ind1=" " ind2="7"><subfield code="a">pystyviiva | </subfield><subfield code="2">yso/fin</subfield></datafield></record>
ALMAXML;

        $this->assertEquals($expected, $formatter->convertLineBasedMarcToXml($input));
    }

    /**
     * Test that a GeniePlus example parses correctly.
     *
     * @return void
     */
    public function testGeniePlusExample()
    {
        // This format requires non-default configs:
        $formatter = new LineBasedMarcFormatter(
            [
                [
                    'subfieldRegExp' => '/‡([a-z0-9])/',
                    'endOfLineMarker' => '^',
                    'ind1Offset' => 3,
                    'ind2Offset' => 4,
                    'contentOffset' => 4,
                    'firstSubfieldOffset' => 5,
                ],
            ]
        );

        $input = <<<GENIEPLUSLINES
001‡9042810^
005‡19830728123748.0^
008‡821118s1983    miu      b    00110 eng  ^
010  ‡a82021744^
020  ‡a0835713954^
040  ‡aDLC‡cDLC‡dVLA^
043  ‡an-us---^
049  ‡aVLAM^
090  ‡aKF2920.3‡b.St1 1983^
10020‡aSt. Pierre, Kent E.‡q(Kent Edgar)^
24510‡aAuditor risk and legal liability /‡cby Kent E. St. Pierre^
2600 ‡aAnn Arbor, Mich. :‡bUMI Research Press,‡cc1983^
300  ‡a124 p. ;‡c24 cm^
440 0‡aResearch for businesss decisions ;‡vno. 59^
500  ‡aRevision of thesis (Ph. D.)--Washington University, St. Louis, 1981^
500  ‡aIncludes index^
504  ‡aBibliography: p. [123]-124^
650 0‡aAuditors‡zUnited States^
907  ‡a.b1001449^
998  ‡amissg^"
GENIEPLUSLINES;

        $expected = <<<GENIEPLUSXML
<record><controlfield tag="001">9042810</controlfield><controlfield tag="005">19830728123748.0</controlfield><controlfield tag="008">821118s1983    miu      b    00110 eng  </controlfield><datafield tag="010" ind1=" " ind2=" "><subfield code="a">82021744</subfield></datafield><datafield tag="020" ind1=" " ind2=" "><subfield code="a">0835713954</subfield></datafield><datafield tag="040" ind1=" " ind2=" "><subfield code="a">DLC</subfield><subfield code="c">DLC</subfield><subfield code="d">VLA</subfield></datafield><datafield tag="043" ind1=" " ind2=" "><subfield code="a">n-us---</subfield></datafield><datafield tag="049" ind1=" " ind2=" "><subfield code="a">VLAM</subfield></datafield><datafield tag="090" ind1=" " ind2=" "><subfield code="a">KF2920.3</subfield><subfield code="b">.St1 1983</subfield></datafield><datafield tag="100" ind1="2" ind2="0"><subfield code="a">St. Pierre, Kent E.</subfield><subfield code="q">(Kent Edgar)</subfield></datafield><datafield tag="245" ind1="1" ind2="0"><subfield code="a">Auditor risk and legal liability /</subfield><subfield code="c">by Kent E. St. Pierre</subfield></datafield><datafield tag="260" ind1="0" ind2=" "><subfield code="a">Ann Arbor, Mich. :</subfield><subfield code="b">UMI Research Press,</subfield><subfield code="c">c1983</subfield></datafield><datafield tag="300" ind1=" " ind2=" "><subfield code="a">124 p. ;</subfield><subfield code="c">24 cm</subfield></datafield><datafield tag="440" ind1=" " ind2="0"><subfield code="a">Research for businesss decisions ;</subfield><subfield code="v">no. 59</subfield></datafield><datafield tag="500" ind1=" " ind2=" "><subfield code="a">Revision of thesis (Ph. D.)--Washington University, St. Louis, 1981</subfield></datafield><datafield tag="500" ind1=" " ind2=" "><subfield code="a">Includes index</subfield></datafield><datafield tag="504" ind1=" " ind2=" "><subfield code="a">Bibliography: p. [123]-124</subfield></datafield><datafield tag="650" ind1=" " ind2="0"><subfield code="a">Auditors</subfield><subfield code="z">United States</subfield></datafield><datafield tag="907" ind1=" " ind2=" "><subfield code="a">.b1001449</subfield></datafield><datafield tag="998" ind1=" " ind2=" "><subfield code="a">missg^"</subfield></datafield></record>
GENIEPLUSXML;

        $this->assertEquals($expected, $formatter->convertLineBasedMarcToXml($input));
    }
}
