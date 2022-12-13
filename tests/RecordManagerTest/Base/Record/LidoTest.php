<?php
/**
 * LIDO Record Driver Test Class
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2020-2022.
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
            'title_full' => 'Luonnonsuojelusäädökset / toimittanut Raimo Luhtanen;'
                . ' Säädökset',
            'title_short' => 'Luonnonsuojelusäädökset / toimittanut Raimo Luhtanen;'
                . ' Säädökset',
            'title' => 'Luonnonsuojelusäädökset / toimittanut Raimo Luhtanen;'
                . ' Säädökset',
            'title_sort' => 'Luonnonsuojelusäädökset / toimittanut Raimo Luhtanen;'
                . ' Säädökset',
            'title_alt' => [],
            'format' => 'Kirja',
            'identifier' => '26054',
            'institution' => 'Test Institution',
            'author' => [
                'Designer, Test',
                'Luhtanen, Raimo'
            ],
            'author_sort' => 'Designer, Test',
            'topic_facet' => [
                'retkeily',
                'ulkoilu',
            ],
            'topic' => [
                'retkeily',
                'ulkoilu',
            ],
            'material_str_mv' => [],
            'geographic_facet' => [],
            'geographic' => [],
            'era' => [],
            'era_facet' => [],
            'collection' => '',
            'ctrlnum' => [
                '(knp)M011-320623',
            ],
            'isbn' => [
                '9789518593730',
                '9789518593731',
                '9789518593732',
            ],
            'issn' => [
                '0357-5284',
            ],
            'allfields' => [
                'knp-247394',
                'Kirja',
                'Säädökset',
                'Luonnonsuojelusäädökset / toimittanut Raimo Luhtanen',
                'Test Institution',
                '26054',
                '9518593736',
                '9789518593731',
                '9789518593732',
                '0357-5284',
                'retkeily',
                'ulkoilu',
                'Luhtanen, Raimo',
                'Designer, Test',
                'M011-320623',
                'Test Institution',
                '247394',
            ]
        ];

        $this->compareArray($expected, $fields, 'toSolrArray');

        $keys = $record->getWorkIdentificationData();
        $expected = [
            [
                'authors' => [
                    [
                        'type' => 'author',
                        'value' => 'Designer, Test',
                    ],
                    [
                        'type' => 'author',
                        'value' => 'Luhtanen, Raimo',
                    ]
                ],
                'authorsAltScript' => [],
                'titles' => [
                    [
                        'type' => 'title',
                        'value' => 'Luonnonsuojelusäädökset / toimittanut Raimo'
                        . ' Luhtanen; Säädökset',
                    ],
                ],
                'titlesAltScript' => [],
            ]
        ];

        $this->compareArray($expected, $keys, 'getWorkIdentificationData');
    }

    /**
     * Test LIDO record handling with title merging disabled
     *
     * @return void
     */
    public function testLido1NonMergedTitle()
    {
        $record = $this->createRecord(
            Lido::class,
            'lido1.xml',
            [
                '__unit_test_no_source__' => [
                    'driverParams' => [
                        'mergeTitleValues=false',
                        'mergeTitleSets=false'
                    ]
                ]
            ]
        );
        $fields = $record->toSolrArray();
        unset($fields['fullrecord']);

        $expected = [
            'record_format' => 'lido',
            'title_full' => 'Luonnonsuojelusäädökset / toimittanut Raimo Luhtanen',
            'title_short' => 'Luonnonsuojelusäädökset / toimittanut Raimo Luhtanen',
            'title' => 'Luonnonsuojelusäädökset / toimittanut Raimo Luhtanen',
            'title_sort' => 'Luonnonsuojelusäädökset / toimittanut Raimo Luhtanen',
            'title_alt' => [
                'Säädökset'
            ],
            'format' => 'Kirja',
            'identifier' => '26054',
            'institution' => 'Test Institution',
            'author' => [
                'Designer, Test',
                'Luhtanen, Raimo'
            ],
            'author_sort' => 'Designer, Test',
            'topic_facet' => [
                'retkeily',
                'ulkoilu',
            ],
            'topic' => [
                'retkeily',
                'ulkoilu',
            ],
            'material_str_mv' => [],
            'geographic_facet' => [],
            'geographic' => [],
            'era' => [],
            'era_facet' => [],
            'collection' => '',
            'ctrlnum' => [
                '(knp)M011-320623',
            ],
            'isbn' => [
                '9789518593730',
                '9789518593731',
                '9789518593732',
            ],
            'issn' => [
                '0357-5284',
            ],
            'allfields' => [
                'knp-247394',
                'Kirja',
                'Säädökset',
                'Luonnonsuojelusäädökset / toimittanut Raimo Luhtanen',
                'Test Institution',
                '26054',
                '9518593736',
                '9789518593731',
                '9789518593732',
                '0357-5284',
                'retkeily',
                'ulkoilu',
                'Luhtanen, Raimo',
                'Designer, Test',
                'M011-320623',
                'Test Institution',
                '247394',
            ]
        ];

        $this->compareArray($expected, $fields, 'toSolrArray');

        $keys = $record->getWorkIdentificationData();
        $expected = [
            [
                'authors' => [
                    [
                        'type' => 'author',
                        'value' => 'Designer, Test',
                    ],
                    [
                        'type' => 'author',
                        'value' => 'Luhtanen, Raimo',
                    ]
                ],
                'authorsAltScript' => [],
                'titles' => [
                    [
                        'type' => 'title',
                        'value' => 'Luonnonsuojelusäädökset / toimittanut Raimo'
                        . ' Luhtanen',
                    ],
                    [
                        'type' => 'title',
                        'value' => 'Säädökset',
                    ],
                ],
                'titlesAltScript' => [],
            ]
        ];

        $this->compareArray($expected, $keys, 'getWorkIdentificationData');
    }

    /**
     * Test LIDO work identification data handling
     *
     * @return void
     */
    public function testLidoWorkKeys()
    {
        $record = $this->createRecord(Lido::class, 'lido2.xml');
        $keys = $record->getWorkIdentificationData();
        $expected = [
            [
                'authors' => [],
                'authorsAltScript' => [],
                'titles' => [
                    [
                        'type' => 'title',
                        'value' => 'Kitchen tool; Scissors',
                    ],
                    [
                        'type' => 'title',
                        'value' => 'Keittiövälineet; Sakset',
                    ],
                ],
                'titlesAltScript' => [],
            ]
        ];

        $this->compareArray($expected, $keys, 'getWorkIdentificationData');
    }
}
