<?php

/**
 * ForwardAuthority Record Driver Test Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2026.
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

use RecordManager\Base\Record\ForwardAuthority;

/**
 * ForwardAuthority Record Driver Test Class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Minna Rönkä <minna.ronka@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class ForwardAuthorityTest extends RecordTestBase
{
    /**
     * Test ForwardAuthority record handling
     *
     * @return void
     */
    public function testForwardAuthority1()
    {
        $record = $this->createRecord(
            ForwardAuthority::class,
            'forward_authority_1.xml',
        );
        $fields = $record->toSolrArray();
        unset($fields['fullrecord']);

        $expected = [
            'allfields' => [
                'Kookaburra_0026. Uusitalo: Jussien viisi vuosikymmentä. SKF 10. HS 6.12.2002. Emu_0027',
                'Jussi-palkinnot 1951 parhaasta ohjauksesta sekä käsikirjoituksesta '
                    . '(yhdessä FrilledLizard_0028 kanssa) elokuvasta Radio tekee murron.',
                'Cassowary_0019',
                'Cassowary_0019',
            ],
            'birth_date' => '1900',
            'birth_place' => 'Keuruu',
            'death_date' => '1901',
            'death_place' => 'Vantaa',
            'field_of_activity' => [],
            'heading' => 'Cassowary_0019',
            'language' => '',
            'occupation' => [
                'Dingo_0018',
            ],
            'record_format' => 'forwardAuthority',
            'record_type' => 'elonet_henkilo',
            'related_place' => [
                'Suomi',
            ],
            'source' => '__unit_test_no_source__',
            'use_for' => [
                'Cassowary_0019',
            ],
          ];

        $this->compareArray($expected, $fields, 'toSolrArray');
    }
}
