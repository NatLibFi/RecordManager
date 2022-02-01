<?php
/**
 * LineBasedMarcFormatter tests
 *
 * PHP version 7
 *
 * Copyright (C) Villanova University 2022
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
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
namespace RecordManagerTest\Base\Utils;

use RecordManager\Base\Utils\LineBasedMarcFormatter;

/**
 * LineBasedMarcFormatter tests
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class LineBasedMarcFormatterTest extends \PHPUnit\Framework\TestCase
{
    use \RecordManagerTest\Base\Feature\FixtureTrait;

    /**
     * Test that an Alma example parses correctly.
     *
     * @return void
     */
    public function testAlmaExample()
    {
        // This should work with default configs:
        $formatter = new LineBasedMarcFormatter();

        $input = $this->getFixture('utils/LineBasedMarcFormatter/alma.txt');
        $this->assertXmlStringEqualsXmlFile(
            $this->getFixturePath('utils/LineBasedMarcFormatter/alma.xml'),
            $formatter->convertLineBasedMarcToXml($input)
        );
    }

    /**
     * Test that a GeniePlus example parses correctly.
     *
     * @return void
     */
    public function testGeniePlusExample()
    {
        // This format requires non-default configs:
        $formatter = new LineBasedMarcFormatter(
            [
                [
                    'subfieldRegExp' => '/‡([a-z0-9])/',
                    'endOfLineMarker' => '^',
                    'ind1Offset' => 3,
                    'ind2Offset' => 4,
                    'contentOffset' => 4,
                    'firstSubfieldOffset' => 5,
                ],
            ]
        );

        $input = $this->getFixture('utils/LineBasedMarcFormatter/genieplus.txt');
        $this->assertXmlStringEqualsXmlFile(
            $this->getFixturePath('utils/LineBasedMarcFormatter/genieplus.xml'),
            $formatter->convertLineBasedMarcToXml($input)
        );
    }
}
