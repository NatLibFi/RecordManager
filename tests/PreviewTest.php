<?php
/**
 * Tests for preview creation (stresses mapping file handling)
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2017.
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
 * @link     https://github.com/KDK-Alli/RecordManager
 */
use RecordManager\Solr\PreviewCreator;
use RecordManager\Utils\Logger;

/**
 * Preview creation tests
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class PreviewCreatorTest extends AbstractTest
{
    protected $holdingRecord = <<<EOT
<record>
  <datafield tag="852">
    <subfield code="b">B1</subfield>
  </datafield>
  <datafield tag="852">
    <subfield code="b">A1</subfield>
    <subfield code="c">2</subfield>
  </datafield>
  <datafield tag="852">
    <subfield code="b">A1</subfield>
    <subfield code="c">X</subfield>
  </datafield>
  <datafield tag="852">
    <subfield code="b">C1</subfield>
    <subfield code="c">2</subfield>
  </datafield>
  <datafield tag="852">
    <subfield code="b">D1</subfield>
    <subfield code="c">2</subfield>
  </datafield>
</record>
EOT;

    /**
     * Tests for building field
     *
     * @return void
     */
    public function testBuilding()
    {
        global $configArray;

        $configArray['dataSourceSettings']['test']['driverParams'] = [
            'subLocationInBuilding=c'
        ];

        $preview = $this->getPreviewCreator();

        $result = $preview->preview($this->holdingRecord, 'marc', 'test');
        $this->assertEquals(
            ['B', 'A/2', 'A', 'DEF/2'],
            $result['building']
        );
    }

    /**
     * Create PreviewCreator
     *
     * @return PreviewCreator
     */
    protected function getPreviewCreator()
    {
        $basePath = dirname(__FILE__) . '/configs/mappingfilestest';
        $logger = $this->createMock(Logger::class);
        $preview = new PreviewCreator(null, $basePath, $logger, false);

        return $preview;
    }
}
