<?php

/**
 * XmlTransformation tests
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2026
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

namespace RecordManagerTest\Base\Utils;

use RecordManager\Base\Utils\XslTransformation;
use RecordManagerTest\Base\Feature\FixtureTrait;

/**
 * XmlTransformation tests
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Minna Rönkä <minna.ronka@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class XslTransformationTest extends \PHPUnit\Framework\TestCase
{
    use FixtureTrait;

    /**
     * Test LIDO-EDM transformation with image
     *
     * @return void
     */
    public function testLido2EdmTransformationWithImage()
    {
        $xslTransformation = $this->getXslTransformation('lido2edm_image.properties');
        $lidoRecord = $this->getFixture('utils/XslTransformation/lido_image.xml');
        $expectedEdm = $this->getFixture('utils/XslTransformation/lido_image_edm.xml');
        $transformedEdm = $xslTransformation->transform($lidoRecord);
        $this->assertSame($expectedEdm, $transformedEdm);
    }

    /**
     * Test LIDO-EDM transformation with video
     *
     * @return void
     */
    public function testLido2EdmTransformationWithVideo()
    {
        $xslTransformation = $this->getXslTransformation('lido2edm_video.properties');
        $lidoRecord = $this->getFixture('utils/XslTransformation/lido_video.xml');
        $expectedEdm = $this->getFixture('utils/XslTransformation/lido_video_edm.xml');
        $transformedEdm = $xslTransformation->transform($lidoRecord);
        $this->assertSame($expectedEdm, $transformedEdm);
    }

    /**
     * Test LIDO-EDM transformation with text
     *
     * @return void
     */
    public function testLido2EdmTransformationWithText()
    {
        $xslTransformation = $this->getXslTransformation('lido2edm_text.properties');
        $lidoRecord = $this->getFixture('utils/XslTransformation/lido_text.xml');
        $expectedEdm = $this->getFixture('utils/XslTransformation/lido_text_edm.xml');
        $transformedEdm = $xslTransformation->transform($lidoRecord);
        $this->assertSame($expectedEdm, $transformedEdm);
    }

    /**
     * Test LIDO-EDM transformation with 3D
     *
     * @return void
     */
    public function testLido2EdmTransformationWith3D()
    {
        $xslTransformation = $this->getXslTransformation('lido2edm_3D.properties');
        $lidoRecord = $this->getFixture('utils/XslTransformation/lido_3D.xml');
        $expectedEdm = $this->getFixture('utils/XslTransformation/lido_3D_edm.xml');
        $transformedEdm = $xslTransformation->transform($lidoRecord);
        $this->assertSame($expectedEdm, $transformedEdm);
    }

    /**
     * Test LIDO-EDM transformation with incomplete data
     *
     * @return void
     */
    public function testLido2EdmTransformationWithIncompleteData()
    {
        $xslTransformation = $this->getXslTransformation('lido2edm_incomplete.properties');
        $lidoRecord = $this->getFixture('utils/XslTransformation/lido_incomplete.xml');
        $expectedEdm = $this->getFixture('utils/XslTransformation/lido_incomplete_edm.xml');
        $transformedEdm = $xslTransformation->transform($lidoRecord);
        $this->assertSame($expectedEdm, $transformedEdm);
    }

    /**
     * Create XmlTransformation
     *
     * @param string $config Transformation config file name
     *
     * @return XslTransformation
     */
    protected function getXslTransformation(string $config)
    {
        return new XslTransformation(
            $this->getConfigDir(),
            $config
        );
    }

    /**
     * Get config directory
     *
     * @return string
     */
    protected function getConfigDir()
    {
        return $this->getFixtureDir() . 'config/xsltransformationtest/transformations';
    }
}
