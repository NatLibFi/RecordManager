<?php
/**
 * LIDO Record Driver Test Class
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2020-2021.
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
class LidoTest extends RecordTest
{
    /**
     * Test LIDO record handling
     *
     * @return void
     */
    public function testLido1()
    {
        $record = $this->createRecord(Lido::class, 'lido1.xml');
        $fields = $record->toSolrArray();
        unset($fields['fullrecord']);

        $expected = [
            'record_format' => 'lido',
            'title_full' => 'Luonnonsuojelusäädökset / toimittanut Raimo Luhtanen',
            'title_short' => 'Luonnonsuojelusäädökset / toimittanut Raimo Luhtanen',
            'title' => 'Luonnonsuojelusäädökset / toimittanut Raimo Luhtanen',
            'title_sort' => 'Luonnonsuojelusäädökset / toimittanut Raimo Luhtanen',
            'format' => 'Kirja',
            'institution' => 'Test Institution',
            'author' => [
            ],
            'topic_facet' => [
                0 => 'retkeily',
                1 => 'ulkoilu',
            ],
            'topic' => [
                0 => 'retkeily',
                1 => 'ulkoilu',
            ],
            'material' => [
            ],
            'geographic_facet' => [
            ],
            'geographic' => [
            ],
            'collection' => '',
            'ctrlnum' => [
                0 => '(knp)M011-320623',
            ],
            'isbn' => [
                0 => '9789518593730',
                1 => '9789518593731',
                2 => '9789518593732',
            ],
            'issn' => [
                0 => '0357-5284',
            ],
            'allfields' => [
                0 => 'knp-247394',
                1 => 'Kirja',
                2 => 'Luonnonsuojelusäädökset / toimittanut Raimo Luhtanen',
                3 => 'Test Institution',
                4 => '26054',
                5 => '9518593736',
                6 => '9789518593731',
                7 => '9789518593732',
                8 => '0357-5284',
                9 => 'retkeily',
                10 => 'ulkoilu',
                11 => 'Luhtanen, Raimo',
                12 => 'M011-320623',
                13 => 'Test Institution',
                14 => '247394',
            ]
        ];

        $this->compareArray($expected, $fields, 'toSolrArray');

        $keys = $record->getWorkIdentificationData();
        $expected = [
            [
                'authors' => [],
                'authorsAltScript' => [],
                'titles' => [
                    [
                        'type' => 'title',
                        'value' => 'Luonnonsuojelusäädökset / toimittanut Raimo Luhtanen',
                    ],
                ],
                'titlesAltScript' => [],
            ]
        ];

        $this->compareArray($expected, $keys, 'getWorkIdentificationData');
    }
}
