<?php
/**
 * Autoloader
 *
 * PHP version 7
 *
 * Copyright (c) The National Library of Finland 2017.
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

/**
 * Autoloader
 *
 * This class provides an autoloader for RecordManager classes.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Autoloader
{
    /**
     * Directories to check
     *
     * @var array
     */
    protected $directories = [
        __DIR__ . '/../..',
        __DIR__ . '/..',
        __DIR__ . '/../Record',
    ];

    /**
     * Autoloader instance
     *
     * @var Autoloader|null
     */
    protected static $loader = null;

    /**
     * Get loader
     *
     * @return Autoloader
     */
    public static function getLoader()
    {
        if (null !== static::$loader) {
            return static::$loader;
        }

        static::$loader = new Autoloader();
        return static::$loader;
    }

    /**
     * Add a directory to autoloader
     *
     * @param string $directory Directory
     *
     * @return void
     */
    public function addDirectory($directory)
    {
        if (!in_array($directory, $this->directories)) {
            $this->directories[] = $directory;
        }
    }

    /**
     * Constructor
     */
    protected function __construct()
    {
        spl_autoload_register([$this, 'load']);
    }

    /**
     * Autoloader callback
     *
     * @param string $className Requested class
     *
     * @return void
     */
    protected function load($className)
    {
        foreach ($this->directories as $directory) {
            $path = $directory ? "$directory/$className.php" : "src/$className.php";
            $path = str_replace('\\', DIRECTORY_SEPARATOR, $path);
            if (file_exists($path)) {
                include $path;
                break;
            }
        }
    }
}

Autoloader::getLoader();
