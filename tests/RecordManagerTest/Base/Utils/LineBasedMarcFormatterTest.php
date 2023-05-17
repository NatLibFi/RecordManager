<?php

/**
 * LineBasedMarcFormatter tests
 *
 * PHP version 8
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
     * Data provider for testConversion()
     *
     * @return array
     */
    public function getConversionTests(): array
    {
        $genieConfig = [
            [
                'subfieldRegExp' => '/‡([a-z0-9])/',
                'endOfLineMarker' => '^',
                'ind1Offset' => 3,
                'ind2Offset' => 4,
                'contentOffset' => 4,
                'firstSubfieldOffset' => 5,
            ],
        ];

        return [
            'Alma example' => ['alma'],
            'GeniePlus example (good data)' => ['genieplus', $genieConfig],
            'GeniePlus example (with bad XML characters)' =>
                ['bad', $genieConfig, 2],
        ];
    }

    /**
     * Test that an Alma example parses correctly.
     *
     * @param string $fixtureName Base name for test fixtures (assumes .txt file with
     *                            line-based data and .xml file w/ expected results)
     * @param ?array $configs     Custom configurations (if any)
     * @param int    $badChars    Expected number of bad XML characters encountered
     *                            during conversion
     *
     * @return void
     *
     * @dataProvider getConversionTests
     */
    public function testConversion(
        string $fixtureName,
        ?array $configs = null,
        int $badChars = 0
    ): void {
        // This should work with default configs:
        $formatter = new LineBasedMarcFormatter($configs);

        $fixtureBase = 'utils/LineBasedMarcFormatter/' . $fixtureName;
        $input = $this->getFixture($fixtureBase . '.txt');
        $this->assertXmlStringEqualsXmlFile(
            $this->getFixturePath($fixtureBase . '.xml'),
            $formatter->convertLineBasedMarcToXml($input)
        );
        $this->assertEquals($badChars, $formatter->getIllegalXmlCharacterCount());
    }
}
