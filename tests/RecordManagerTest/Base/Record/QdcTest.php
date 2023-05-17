<?php

/**
 * QDC Record Driver Test Class
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2023.
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

use RecordManager\Base\Record\Qdc;

/**
 * QDC Record Driver Test Class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class QdcTest extends RecordTestBase
{
    /**
     * Test QQDC record handling
     *
     * @return void
     */
    public function testQdc1()
    {
        $record = $this->createRecord(
            Qdc::class,
            'qdc1.xml',
            [],
            'Base',
            [$this->createMock(\RecordManager\Base\Http\ClientManager::class)]
        );
        $fields = $record->toSolrArray();
        unset($fields['fullrecord']);

        $expected = [
            'record_format' => 'qdc',
            'ctrlnum' => '10138_331330',
            'allfields' => [
                'Urine : The potential, value chain and its sustainable management',
                'Viskari, Eeva-Liisa',
                'Lehtoranta, Suvi',
                'Malila, Riikka',
                'urine',
                'fertilizer',
                'value chain',
                'agriculture',
                'nutrient recovery',
                'virtsa',
                'lannoitteet',
                'ravinteet',
                'uudelleenkäyttö',
                'maatalous',
                '2021-06-16T06:31:44Z',
                '2021',
                'Article',
                'Eeva-Liisa Viskari, Suvi Lehtoranta, Riikka Malila. Urine : The'
                    . ' potential, value chain and its sustainable management. '
                    . 'Sanitation Value Chain (2021) 5, 1, pages 10-12. '
                    . 'https://doi.org/10.34416/svc.00029',
                '2432-5058',
                'http://hdl.handle.net/10138/331330',
                'https://doi.org/10.34416/svc.00029',
                'en',
                'Sanitation Value Chain 5:1',
                'CC BY-NC-ND 4.0',
                'Sanitation Project, Research Institute for Humanity and Nature',
                'http://dx.doi.org/https://doi.org/10.34416/svc.00029',
                '10138_331330',
            ],
            'language' => [
                'en',
            ],
            'format' => 'Article',
            'author' => [
                'Viskari, Eeva-Liisa',
                'Lehtoranta, Suvi',
                'Malila, Riikka',
            ],
            'author2' => [],
            'author_corporate' => [],
            'author_sort' => 'Viskari, Eeva-Liisa',
            'title_full' => 'Urine : The potential, value chain and its sustainable'
                    . ' management',
            'title' => 'Urine : The potential, value chain and its sustainable'
                    . ' management',
            'title_short' => 'Urine',
            'title_sub' => 'The potential, value chain and its sustainabl'
                    . 'e management',
            'title_sort' => 'urine the potential value chain and its sustainable'
                    . ' management',
            'publisher' => [
                'Sanitation Project, Research Institute for Humanity and Nature',
            ],
            'publishDate' => '2021',
            'isbn' => [],
            'issn' => [
                '2432-5058',
            ],
            'doi_str_mv' => [
                '10.34416/svc.00029',
            ],
            'topic_facet' => [
                'urine',
                'fertilizer',
                'value chain',
                'agriculture',
                'nutrient recovery',
                'virtsa',
                'lannoitteet',
                'ravinteet',
                'uudelleenkäyttö',
                'maatalous',
            ],
            'topic' => [
                'urine',
                'fertilizer',
                'value chain',
                'agriculture',
                'nutrient recovery',
                'virtsa',
                'lannoitteet',
                'ravinteet',
                'uudelleenkäyttö',
                'maatalous',
            ],
            'url' => [
                'http://hdl.handle.net/10138/331330',
                'https://doi.org/10.34416/svc.00029',
            ],
            'contents' => [],
            'description' => '',
            'series' => [],
          ];

        $this->compareArray($expected, $fields, 'toSolrArray');

        $keys = $record->getWorkIdentificationData();

        $expected = [
            [
                'authors' => [
                    [
                        'type' => 'author',
                        'value' => 'Viskari, Eeva-Liisa',
                    ],
                ],
                'authorsAltScript' => [
                ],
                'titles' => [
                    [
                        'type' => 'title',
                        'value' => 'urine the potential value chain and its'
                            . ' sustainable management',
                    ],
                    [
                        'type' => 'title',
                        'value' => 'Urine : The potential, value chain and its'
                            . ' sustainable management',
                    ],
                ],
                'titlesAltScript' => [
                ],
            ],
        ];

        $this->compareArray($expected, $keys, 'getWorkIdentificationData');
    }
}
