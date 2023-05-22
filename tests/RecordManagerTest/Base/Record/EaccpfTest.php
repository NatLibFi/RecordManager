<?php

/**
 * EAC-CPF Record Driver Test Class
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
 * @author   Minna Rönkä <minna.ronka@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */

namespace RecordManagerTest\Base\Record;

use RecordManager\Base\Record\Eaccpf;

/**
 * EAC-CPF Record Driver Test Class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Minna Rönkä <minna.ronka@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class EaccpfTest extends RecordTestBase
{
    /**
     * Test EAC-CPF record handling
     *
     * @return void
     */
    public function testEaccpf1()
    {
        $record = $this->createRecord(
            Eaccpf::class,
            'eaccpf1.xml',
            [],
            'Base',
            [$this->createMock(\RecordManager\Base\Http\ClientManager::class)]
        );
        $fields = $record->toSolrArray();
        unset($fields['fullrecord']);

        $expected = [
            'record_format' => 'eaccpf',
            'allfields' => [
                'Kansallisarkisto',
                'Tietoa kirjailijan elämästä',
                'Sukunimi Etunimi',
                'Toinensuku Toinennimi',
            ],
            'source' => 'Kansallisarkisto',
            'record_type' => 'person',
            'heading' => 'Sukunimi Etunimi',
            'use_for' => [
                'Toinensuku Toinennimi',
            ],
            'birth_date' => '1950',
            'death_date' => '2000',
            'birth_place' => 'Tampere',
            'death_place' => 'Joensuu',
            'related_place' => [
                'Helsinki',
                'Oulu',
            ],
            'field_of_activity' => [],
            'occupation' => [
                'runoilija',
                'kirjailija',
            ],
            'language' => 'fin',
          ];

        $this->compareArray($expected, $fields, 'toSolrArray');
    }
}
