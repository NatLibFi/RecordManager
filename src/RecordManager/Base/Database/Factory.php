<?php
/**
 * Database factory class
 *
 * PHP version 7
 *
 * Copyright (c) The National Library of Finland 2020.
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
namespace RecordManager\Base\Database;

/**
 * Database registry class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class Factory
{
    /**
     * Create a database class
     *
     * @param array $config RecordManager configuration
     *
     * @return DatabaseInterface
     */
    public static function createDatabase(array $config): DatabaseInterface
    {
        $backend = $config['Database']['backend'] ?? 'Mongo';
        if (empty($config[$backend])) {
            throw new \Exception("Configuration section $backend missing");
        }
        $dbConfig = $config[$backend];
        if ('Mongo' === $backend) {
            return new MongoDatabase($dbConfig);
        }
        return new PDODatabase($dbConfig);
    }
}
