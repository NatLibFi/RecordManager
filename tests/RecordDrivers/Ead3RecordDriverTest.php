<?php
/**
 * NDL EAD3 Record Driver Test Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2019.
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
        $fields = $this->processSample('ahaa.xml');
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
        $fields = $this->processSample('ahaa2.xml');
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
        $fields = $this->processSample('ahaa3.xml');
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
        $fields = $this->processSample('ahaa4.xml');
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
        $fields = $this->processSample('ahaa5.xml');
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
        $fields = $this->processSample('ahaa6.xml');
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
        $fields = $this->processSample('ahaa8.xml');
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
        $fields = $this->processSample('ahaa9.xml');
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
        $fields = $this->processSample('ahaa10.xml');
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
        $fields = $this->processSample('ahaa11.xml');
        $this->assertContains(
            '[0050-11-05 TO 9960-01-01]',
            $fields['search_daterange_mv']
        );
    }
}
