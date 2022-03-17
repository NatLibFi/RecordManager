<?php
/**
 * LRMI Record Driver Test Class
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
namespace RecordManagerTest\Base\Record;

use RecordManager\Base\Record\Lrmi;

/**
 * LRMI Record Driver Test Class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class LrmiTest extends RecordTest
{
    /**
     * Test LRMI Record handling
     *
     * @return void
     */
    public function testLrmi1()
    {
        $record = $this->createRecord(
            Lrmi::class,
            'lrmi1.xml',
            [],
            'Base',
            [$this->createMock(\RecordManager\Base\Http\ClientManager::class)]
        );
        $fields = $record->toSolrArray();
        unset($fields['fullrecord']);

        $expected = [
            'record_format' => 'lrmi',
            'ctrlnum' => '11',
            'allfields' => [
                'oai:aoe.fi:11',
                'Opetuksen ja oppimisen suunnittelu, Learning Design',
                'Planering av undevisning och lärande',
                'Designing Learning Processes',
                '2019-12-17T08:51:19Z',
                'Learning Design – opetuksen ja oppimisen suunnittelu tarkoittaa'
                . ' sekä opettajan opetuksen suunnittelua ja valmistelua...',
                'Learning Design means planning teaching and student’s goal-oriented'
                . ' learning...',
                'Learning Design – planering av undervisning och lärande betyder'
                . ' både att läraren planerar sin egen undervisning...',
                'CCBY4.0',
                'video',
                'teksti',
                '2019-12-17T08:51:19Z',
                '2020-10-15T09:24:47Z',
                'Koli, Hanne',
                'oppiminen',
                'https://www.yso.fi/onto/yso/p2945',
                'opetus',
                'https://www.yso.fi/onto/yso/p2630',
                'oppimisprosessi',
                'https://www.yso.fi/onto/yso/p5103',
                'oppimistehtävä',
                'https:oppimistehtv',
                'ohjaus (neuvonta ja opastus)',
                'https://www.yso.fi/onto/yso/p178',
                'pedagogiikka',
                'https://www.yso.fi/onto/yso/p1584',
                'digipedagogiikka',
                'https:digipedagogiikka',
                'oppimisympäristö',
                'https://www.yso.fi/onto/yso/p4835',
                'A Video Learning design',
                'A Video Learning design',
                'A Video Learning design',
                'https://aoe.fi/api/download/AVideoLearningdesign-1576572679231.mp4',
                '6',
                'video/mp4',
                '31820850',
                'en',
                'A Video Planering av undervisning och lärande',
                'A Video Planering av undervisning och lärande',
                'A Video Planering av undervisning och lärande',
                'https://aoe.fi/api/download/AVideoPlaneringavundervisningoch'
                . 'larande-1576572679208.mp4',
                '3',
                'video/mp4',
                '30562026',
                'sv',
                'A Video Opetuksen ja oppimisen suunnittelu',
                'A Video Opetuksen ja oppimisen suunnittelu',
                'A Video Opetuksen ja oppimisen suunnittelu',
                'https://aoe.fi/api/download/AVideoOpetuksenjaoppimisensuunnittelu'
                . '-1576572679174.mp4',
                'video/mp4',
                '31795910',
                'fi',
                'Ohjaaja tai mentori',
                'Opettaja',
                'Asiantuntija tai ammattilainen',
                'käsikirjoitus',
                'sv',
                'fi',
                'en',
                'lukiokoulutus',
                'ammatillinen koulutus',
                'omaehtoinen osaamisen kehittäminen',
                'korkeakoulutus',
                'Itsenäinen opiskelu',
                'Opettajan materiaalit ja osaamisen kehittäminen',
                'Kasvatustieteet',
                '11',
            ],
            'language' => [
                'en',
                'sv',
                'fi',
            ],
            'format' => 'LearningMaterial',
            'author' => [
                'Koli, Hanne',
            ],
            'author2' => [
                'Koli, Hanne',
            ],
            'author_corporate' => [],
            'author_sort' => 'Koli, Hanne',
            'title_full' => 'Opetuksen ja oppimisen suunnittelu, Learning Design',
            'title' => 'Opetuksen ja oppimisen suunnittelu, Learning Design',
            'title_short' => 'Opetuksen ja oppimisen suunnittelu, Learning Design',
            'title_alt' => [
                'Planering av undevisning och lärande',
                'Designing Learning Processes',
            ],
            'title_sort' => 'opetuksen ja oppimisen suunnittelu, learning design',
            'publisher' => [
                '',
            ],
            'publishDate' => '2019',
            'isbn' => [],
            'issn' => [],
            'topic_facet' => [
                'oppiminen',
                'opetus',
                'oppimisprosessi',
                'oppimistehtävä',
                'ohjaus (neuvonta ja opastus)',
                'pedagogiikka',
                'digipedagogiikka',
                'oppimisympäristö',
            ],
            'topic' => [
                'oppiminen',
                'opetus',
                'oppimisprosessi',
                'oppimistehtävä',
                'ohjaus (neuvonta ja opastus)',
                'pedagogiikka',
                'digipedagogiikka',
                'oppimisympäristö',
            ],
            'url' => [],
            'contents' => [
                'Learning Design – opetuksen ja oppimisen suunnittelu tarkoittaa'
                . ' sekä opettajan opetuksen suunnittelua ja valmistelua...',
                'Learning Design means planning teaching and student’s goal-oriented'
                . ' learning...',
                'Learning Design – planering av undervisning och lärande betyder'
                . ' både att läraren planerar sin egen undervisning...',
            ],
            'description' => 'Learning Design means planning teaching and student’s'
                . ' goal-oriented learning...',
            'series' => [],
        ];

        $this->compareArray($expected, $fields, 'toSolrArray');

        $keys = $record->getWorkIdentificationData();

        $expected = [
            [
                'authors' => [
                    0 => [
                        'type' => 'author',
                        'value' => 'Koli, Hanne',
                    ],
                ],
                'authorsAltScript' => [
                ],
                'titles' => [
                    0 => [
                        'type' => 'title',
                        'value'
                            => 'opetuksen ja oppimisen suunnittelu, learning design',
                    ],
                    1 => [
                        'type' => 'title',
                        'value'
                            => 'Opetuksen ja oppimisen suunnittelu, Learning Design',
                    ],
                ],
                'titlesAltScript' => [
                ],
            ]
        ];

        $this->compareArray($expected, $keys, 'getWorkIdentificationData');
    }
}
