<?php

/**
 * DeweyCallNumber tests
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2020
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

use RecordManager\Base\Utils\DeweyCallNumber;

/**
 * DeweyCallNumber tests
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class DeweyCallNumberTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Valid call numbers
     *
     * @var array
     */
    protected $validCallNums = [
        '1 .I39',                 // one digit no fraction
        '1.23 .I39',              // one digit fraction
        '11 .I39',                // two digits no fraction
        '11.34 .I39',             // two digits fraction
        '11.34567 .I39',          // two digits fraction
        '111 .I39',               // no fraction in class
        '111 I39',                // no fraction no period before cutter
        '111Q39',                 // no fraction, no period or space before cutter
        '111.12 .I39',            // fraction in class, space period
        '111.123 I39',            // space but no period before cutter
        '111.134Q39',             // no period or space before cutter
        '322.44 .F816 V.1 1974',  // cutterSuffix - volume and year
        '322.45 .R513 1957',      // cutterSuffix year
        '323 .A512RE NO.23-28',   // cutterSuffix no.
        '323 .A778 ED.2',         // cutterSuffix ed
        '323.09 .K43 V.1',        // cutterSuffix volume
        '324.54 .I39 F',          // letter with space
        '324.548 .C425R',         // letter without space
        '324.6 .A75CUA',          // letters without space
    ];

    /**
     * Invalid call numbers
     *
     * @var array
     */
    protected $invalidCallNums = [
        '',
        'MC1 259',
        'T1 105',
    ];

    /**
     * Tests for valid call numbers
     *
     * @return void
     */
    public function testValidCallNumbers()
    {
        foreach ($this->validCallNums as $current) {
            $deweyCallNumber = new DeweyCallNumber($current);
            $this->assertTrue($deweyCallNumber->isValid());
        }
    }

    /**
     * Tests for invalid call number handling
     *
     * @return void
     */
    public function testInvalidCallNumbers()
    {
        foreach ($this->invalidCallNums as $current) {
            $deweyCallNumber = new DeweyCallNumber($current);
            $this->assertFalse($deweyCallNumber->isValid(), $current);
            $this->assertEquals('', $deweyCallNumber->getSearchString(), $current);
            $this->assertEquals('', $deweyCallNumber->getSortKey(), $current);
        }
    }

    /**
     * Tests for call number handling
     *
     * @return void
     */
    public function testCallNumber()
    {
        $dewey = new DeweyCallNumber('1 .I39');
        $this->assertTrue($dewey->isValid());
        $this->assertEquals('001', $dewey->getNumber(1));
        $this->assertEquals('000', $dewey->getNumber(10));
        $this->assertEquals('000', $dewey->getNumber(100));
        $this->assertEquals('1.I39', $dewey->getSearchString());
        $this->assertEquals('11 I39 ', $dewey->getSortKey());

        $dewey = new DeweyCallNumber('322.44 .F816 V.1 1974');
        $this->assertTrue($dewey->isValid());
        $this->assertEquals('322', $dewey->getNumber(1));
        $this->assertEquals('320', $dewey->getNumber(10));
        $this->assertEquals('300', $dewey->getNumber(100));
        $this->assertEquals('322.44.F816V.11974', $dewey->getSearchString());
        $this->assertEquals('3322.44 F816 V.11 41974', $dewey->getSortKey());
    }
}
