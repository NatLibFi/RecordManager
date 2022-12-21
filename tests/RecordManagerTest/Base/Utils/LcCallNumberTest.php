<?php
/**
 * LcCallNumber tests
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2015
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

use RecordManager\Base\Utils\LcCallNumber;

/**
 * LcCallNumber tests
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class LcCallNumberTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Tests for call number handling
     *
     * @return void
     */
    public function testCallNumber()
    {
        $cn = new LcCallNumber('AC901.M5 vol. 1013, no. 8');
        $this->assertTrue($cn->isValid());
        $this->assertEquals(
            'AC 3901 M15',
            $cn->getSortKey()
        );

        $cn = new LcCallNumber('GV1101 .D7 1980');
        $this->assertTrue($cn->isValid());
        $this->assertEquals(
            'GV 41101 D17',
            $cn->getSortKey()
        );

        $cn = new LcCallNumber('XV1101 .D7 1980');
        $this->assertFalse($cn->isValid());
    }
}
