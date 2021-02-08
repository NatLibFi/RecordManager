<?php
/**
 * NDL EAD3 Record Driver Test Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2020.
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
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
namespace RecordManager\Test\RecordDrivers;

use RecordManager\Finna\Record\Ead3;

/**
 * NDL EAD3 Record Driver Test Class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class Ead3RecordDriverTest extends RecordDriverTest
{
    protected $driver = '\RecordManager\Finna\Record\Ead3';

    /**
     * Test AHAA EAD3 record handling
     *
     * @return void
     */
    public function testAhaa()
    {
        // 1985-02-02/1995-12-01
        $fields = $this->createRecord(Ead3::class, 'ahaa.xml')->toSolrArray();
        $this->assertContains(
            '[1985-02-02 TO 1995-12-01]',
            $fields['search_daterange_mv']
        );
    }

    /**
     * Test AHAA EAD3 record handling
     *
     * @return void
     */
    public function testAhaa2()
    {
        // 1985-02/1995-12
        $fields = $this->createRecord(Ead3::class, 'ahaa2.xml')->toSolrArray();
        $this->assertContains(
            '[1985-02-01 TO 1995-12-31]',
            $fields['search_daterange_mv']
        );
    }

    /**
     * Test AHAA EAD3 record handling
     *
     * @return void
     */
    public function testAhaa3()
    {
        // 1985-02/1995-11
        $fields = $this->createRecord(Ead3::class, 'ahaa3.xml')->toSolrArray();
        $this->assertContains(
            '[1985-02-01 TO 1995-11-30]',
            $fields['search_daterange_mv']
        );
    }

    /**
     * Test AHAA EAD3 record handling
     *
     * @return void
     */
    public function testAhaa4()
    {
        // 1985/1995
        $fields = $this->createRecord(Ead3::class, 'ahaa4.xml')->toSolrArray();
        $this->assertContains(
            '[1985-01-01 TO 1995-12-31]',
            $fields['search_daterange_mv']
        );
    }

    /**
     * Test AHAA EAD3 record handling
     *
     * @return void
     */
    public function testAhaa5()
    {
        // uuuu-uu-10/1995-05-uu
        $fields = $this->createRecord(Ead3::class, 'ahaa5.xml')->toSolrArray();
        $this->assertContains(
            '[0000-01-10 TO 1995-05-31]',
            $fields['search_daterange_mv']
        );
    }

    /**
     * Test AHAA EAD3 record handling
     *
     * @return void
     */
    public function testAhaa6()
    {
        // unknown/open
        $fields = $this->createRecord(Ead3::class, 'ahaa6.xml')->toSolrArray();
        $this->assertContains(
            '[0000-01-01 TO 9999-12-31]',
            $fields['search_daterange_mv']
        );
    }

    /**
     * Test AHAA EAD3 record handling
     *
     * @return void
     */
    public function testAhaa8()
    {
        // uuuu-12-uu/unknown
        $fields = $this->createRecord(Ead3::class, 'ahaa8.xml')->toSolrArray();
        $this->assertContains(
            '[0000-12-01 TO 9999-12-31]',
            $fields['search_daterange_mv']
        );
    }

    /**
     * Test AHAA EAD3 record handling
     *
     * @return void
     */
    public function testAhaa9()
    {
        // 1900/1940-03-02
        $fields = $this->createRecord(Ead3::class, 'ahaa9.xml')->toSolrArray();
        $this->assertContains(
            '[1900-01-01 TO 1940-03-02]',
            $fields['search_daterange_mv']
        );
    }

    /**
     * Test AHAA EAD3 record handling
     *
     * @return void
     */
    public function testAhaa10()
    {
        // 195u/1960-01-01
        $fields = $this->createRecord(Ead3::class, 'ahaa10.xml')->toSolrArray();
        $this->assertContains(
            '[1950-01-01 TO 1960-01-01]',
            $fields['search_daterange_mv']
        );
    }

    /**
     * Test AHAA EAD3 record handling
     *
     * @return void
     */
    public function testAhaa11()
    {
        // uu5u-11-05/u960-01-01
        $fields = $this->createRecord(Ead3::class, 'ahaa11.xml')->toSolrArray();
        $this->assertContains(
            '[0050-11-05 TO 9960-01-01]',
            $fields['search_daterange_mv']
        );
    }

    /**
     * Test FSD EAD3 record handling
     *
     * @return void
     */
    public function testFsd()
    {
        // uu5u-11-05/u960-01-01
        $fields = $this->createRecord(Ead3::class, 'fsd.xml')->toSolrArray();
        $this->assertContains(
            '[2014-02-17 TO 2014-03-14]',
            $fields['search_daterange_mv']
        );
    }

    /**
     * Test FSD EAD3 record handling.
     *
     * @return void
     */
    public function testFsd2()
    {
        $fields = $this->createRecord(Ead3::class, 'fsd2.xml')->toSolrArray();
        $this->assertContains(
            '[2017-01-20 TO 2018-04-30]',
            $fields['search_daterange_mv']
        );
    }
}
