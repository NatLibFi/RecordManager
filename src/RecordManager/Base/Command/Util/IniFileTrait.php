<?php

/**
 * Ini file handling utility trait
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2022-2023.
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

namespace RecordManager\Base\Command\Util;

/**
 * Ini file handling utility trait
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
trait IniFileTrait
{
    /**
     * Check if a line is a comment line (contains a comment and nothing else)
     *
     * @param string $line Line to check
     *
     * @return bool
     */
    protected function isCommentLine(string $line): bool
    {
        $line = trim($line);
        return strncmp($line, ';', 1) === 0;
    }

    /**
     * Get section name from a string
     *
     * @param string $line Line to check
     *
     * @return string Section name or empty string if not a section
     */
    protected function getSectionFromLine(string $line): string
    {
        if (
            $line
            && str_starts_with($line, '[')
            && str_ends_with($line, ']')
            && $line !== '[]'
        ) {
            return substr($line, 1, -1);
        }
        return '';
    }
}
