<?php

/**
 * RecordManager Application
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2021.
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

namespace RecordManager\Base;

use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/**
 * RecordManager Application
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Application extends ConsoleApplication
{
    /**
     * Gets the default input definition.
     *
     * @return InputDefinition An InputDefinition instance
     */
    protected function getDefaultInputDefinition()
    {
        $result = parent::getDefaultInputDefinition();
        $result->addOption(
            new InputOption(
                'basepath',
                null,
                InputOption::VALUE_REQUIRED,
                'Set base path for configuration files, mappings and'
                . ' transformations'
            )
        );
        $result->addOption(
            new InputOption(
                'config.Section.parameter',
                null,
                InputOption::VALUE_REQUIRED,
                'Override any configuration parameter in recordmanager.ini'
            )
        );
        $result->addOption(
            new InputOption(
                'lock',
                null,
                InputOption::VALUE_OPTIONAL,
                'Lock the process to prevent it from running multiple times in'
                . ' parallel',
                false
            )
        );
        return $result;
    }
}
