<?php
/**
 * MetadataUtils tests
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2015-2019
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
use RecordManager\Base\Utils\MetadataUtils;

/**
 * MetadataUtils tests
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class MetadataUtilsTest extends AbstractTest
{
    /**
     * Tests for createSortableString
     *
     * @return void
     */
    public function testCreateSortableString()
    {
        $this->assertEquals(
            'A 3123', MetadataUtils::createSortableString('A 123')
        );
        $this->assertEquals(
            'A 3123 18 ABC', MetadataUtils::createSortableString('A 123 8 abc')
        );
        $this->assertEquals(
            'A 11 12', MetadataUtils::createSortableString('A  1   2')
        );
    }

    /**
     * Tests for normalizeKey
     *
     * @return void
     */
    public function testNormalizeKey()
    {
        $this->assertEquals(
            'abc', MetadataUtils::normalizeKey('A -.*B  C', 'NFKC')
        );

        $this->assertEquals(
            'oaaoaauie', MetadataUtils::normalizeKey('ÖÄÅöäåüïé', 'NFKC')
        );

        MetadataUtils::setConfig(
            [
                'Site' => [
                    'folding_ignore_characters' => 'åäöÅÄÖ',
                ],
            ],
            '.'
        );
        $this->assertEquals(
            'öäåöäåui', MetadataUtils::normalizeKey('ÖÄÅöäåüï', 'NFKC')
        );
    }
}
