<?php

/**
 * DOAJ Record Driver Test Class
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2025.
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

use RecordManager\Base\Record\Doaj;

/**
 * DOAJ Record Driver Test Class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class DoajTest extends RecordTestBase
{
    /**
     * Test DOAJ record handling
     *
     * @return void
     */
    public function testDoaj1()
    {
        $record = $this->createRecord(
            Doaj::class,
            'doaj1.xml',
            [],
            'Base',
            [$this->createMock(\RecordManager\Base\Http\HttpService::class)]
        );
        $fields = $record->toSolrArray();
        unset($fields['fullrecord']);

        $expected = [
            'record_format' => 'doaj',
            'ctrlnum' => [
                '__unit_test_no_id__',
            ],
            'allfields' => [
                'ger',
                'Verlag Krause und Pachernegg GmbH',
                'Journal für Mineralstoffwechsel',
                '1023-7763',
                '1680-9408',
                '1998-01-01',
                '5',
                '1',
                '25',
                '29',
                '648',
                'Leitfaden zur medikamentösen Standardtherapie in der Osteoporose',
                'http://www.kup.at/kup/pdf/648.pdf',
                '',
                '__unit_test_no_id__',
            ],
            'language'  => [
                'ger',
            ],
            'format' => 'Article',
            'author'  => [
            ],
            'title_full' => 'Leitfaden zur medikamentösen Standardtherapie in der Osteoporose',
            'title' => 'Leitfaden zur medikamentösen Standardtherapie in der Osteoporose',
            'title_short' => 'Leitfaden zur medikamentösen Standardtherapie in der Osteoporose',
            'title_sort' => 'leitfaden zur medikamentösen standardtherapie in der osteoporose',
            'title_sub' => '',
            'publisher'  => [
                'Verlag Krause und Pachernegg GmbH',
            ],
            'publishDate' => '1998',
            'publishDateRange' => [
                '1998',
            ],
            'topic_facet'  => [
                'Empfehlung',
            ],
            'topic'  => [
                'Empfehlung',
            ],
            'url' => [
                'http://www.kup.at/kup/pdf/648.pdf',
            ],
            'fulltext' => '',
        ];

        $this->compareArray($expected, $fields, 'toSolrArray');

        $keys = $record->getWorkIdentificationData();

        $expected = [
            [
                'authors' => [],
                'authorsAltScript' => [
                ],
                'titles' => [
                    [
                        'type' => 'title',
                        'value' => 'leitfaden zur medikamentösen standardtherapie in der osteoporose',
                    ],
                    [
                        'type' => 'title',
                        'value' => 'Leitfaden zur medikamentösen Standardtherapie in der Osteoporose',
                    ],
                ],
                'titlesAltScript' => [
                ],
            ],
        ];

        $this->compareArray($expected, $keys, 'getWorkIdentificationData');
    }
}
