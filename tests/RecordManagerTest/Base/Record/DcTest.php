<?php

/**
 * DC Record Driver Test Class
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

use RecordManager\Base\Record\Dc;

/**
 * DC Record Driver Test Class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class DcTest extends RecordTestBase
{
    /**
     * Test DC record handling
     *
     * @return void
     */
    public function testDc1()
    {
        $record = $this->createRecord(
            Dc::class,
            'dc1.xml',
            [],
            'Base',
            [$this->createMock(\RecordManager\Base\Http\HttpService::class)]
        );
        $fields = $record->toSolrArray();
        unset($fields['fullrecord']);

        $expected = [
            'record_format' => 'dc',
            'ctrlnum' => [
                '1234',
            ],
            'allfields' => [
                'Title : Sub',
                'Author, Primary',
                'Topic',
                'Testing',
                'RecordManager',
                'Long description',
                'https://localhost',
                'Publisher',
                'Author, Secondary',
                '2025',
                'Text',
                '345 pages',
                '12345',
                'http://localhost/12345',
                'RecordManager',
                'eng',
                'http://localhost',
                'Finland',
                'http://localhost/cc0',
                '1234',
            ],
            'language' => [
                'eng',
            ],
            'format' => 'Text',
            'author' => [
                'Author, Primary',
            ],
            'author2' => [
                'Author, Secondary',
            ],
            //'author_corporate' => [],
            'author_sort' => 'Author, Primary',
            'title_full' => 'Title : Sub',
            'title' => 'Title : Sub',
            'title_short' => 'Title',
            'title_sub' => 'Sub',
            'title_sort' => 'title sub',
            'publisher' => [
                'Publisher',
            ],
            'publishDate' => '2025',
            'publishDateRange' => [
                '2025',
            ],
            'isbn' => [],
            'doi_str_mv' => [],
            'topic_facet' => [
                'Topic',
                'Testing',
                'RecordManager',
            ],
            'topic' => [
                'Topic',
                'Testing',
                'RecordManager',
            ],
            'url' => [
                'http://localhost/12345',
                'https://localhost',
            ],
            'contents' => [
                'Long description',
            ],
            'description' => '',
            'fulltext' => '',
          ];

        $this->compareArray($expected, $fields, 'toSolrArray');

        $keys = $record->getWorkIdentificationData();

        $expected = [
            [
                'authors' => [
                    [
                        'type' => 'author',
                        'value' => 'Author, Primary',
                    ],
                ],
                'authorsAltScript' => [
                ],
                'titles' => [
                    [
                        'type' => 'title',
                        'value' => 'title sub',
                    ],
                    [
                        'type' => 'title',
                        'value' => 'Title : Sub',
                    ],
                ],
                'titlesAltScript' => [
                ],
            ],
        ];

        $this->compareArray($expected, $keys, 'getWorkIdentificationData');
    }
}
