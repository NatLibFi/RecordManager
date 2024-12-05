<?php

/**
 * Trait adding functionality for loading fixtures.
 *
 * PHP version 8
 *
 * Copyright (C) Villanova University 2020.
 * Copyright (C) The National Library of Finland 2020-2021.
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
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:testing:unit_tests Wiki
 */

namespace RecordManagerTest\Base\Feature;

use RuntimeException;

use function sprintf;

/**
 * Trait adding functionality for loading fixtures.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:testing:unit_tests Wiki
 */
trait FixtureTrait
{
    /**
     * Get the base directory containing fixtures.
     *
     * @param string $module Module containing fixture.
     *
     * @return string
     */
    protected function getFixtureDir($module = 'Base')
    {
        return __DIR__ . "/../../../fixtures/$module/";
    }

    /**
     * Get the path of a fixture file.
     *
     * @param string $filename Filename relative to fixture directory.
     * @param string $module   Module containing fixture.
     *
     * @return string
     * @throws RuntimeException
     */
    protected function getFixturePath($filename, $module = 'Base')
    {
        $realFilename = realpath($this->getFixtureDir($module) . $filename);
        if (
            !$realFilename || !file_exists($realFilename)
            || !is_readable($realFilename)
        ) {
            throw new RuntimeException(
                sprintf('Unable to resolve fixture to fixture file: %s', $filename)
            );
        }
        return $realFilename;
    }

    /**
     * Load a fixture file.
     *
     * @param string $filename Filename relative to fixture directory.
     * @param string $module   Module containing fixture.
     *
     * @return string
     * @throws RuntimeException
     */
    protected function getFixture($filename, $module = 'Base')
    {
        return file_get_contents($this->getFixturePath($filename, $module));
    }
}
