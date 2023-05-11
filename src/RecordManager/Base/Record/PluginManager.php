<?php
/**
 * Record plugin manager
 *
 * PHP version 7
 *
 * Copyright (c) The National Library of Finland 2020-2021.
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
namespace RecordManager\Base\Record;

use Laminas\ServiceManager\Exception\InvalidServiceException;
use Laminas\ServiceManager\Exception\ServiceNotFoundException;

/**
 * Record plugin manager
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class PluginManager extends \Laminas\ServiceManager\AbstractPluginManager
{
    /**
     * Cached clean record objects
     *
     * @var array
     */
    protected $cachedObjects = [];

    /**
     * Constructor
     *
     * Make sure plugins are properly initialized.
     *
     * @param mixed $configOrContainerInstance Configuration or container instance
     * @param array $v3config                  If $configOrContainerInstance is a
     *                                         container, this value will be passed
     *                                         to the parent constructor.
     *
     * @psalm-suppress InvalidArgument
     */
    public function __construct(
        $configOrContainerInstance = null,
        array $v3config = []
    ) {
        // These objects are not meant to be shared -- every time we retrieve one,
        // we are building a brand new object.
        $this->sharedByDefault = false;

        parent::__construct($configOrContainerInstance, $v3config);
    }

    /**
     * Get a service by its name
     *
     * @param string     $name    Service name of plugin to retrieve.
     * @param null|array $options Options to use when creating the instance.
     *
     * @return mixed
     *
     * @throws ServiceNotFoundException If the manager does not have
     *         a service definition for the instance, and the service is not
     *         auto-invokable.
     * @throws InvalidServiceException If the plugin created is invalid for the
     *         plugin context.
     *
     * Ignore parameter name mismatch with Psr\Container\ContainerInterface:
     *
     * @psalm-suppress ParamNameMismatch
     */
    public function get($name, ?array $options = null)
    {
        if (!empty($options)) {
            throw new \Exception('Unexpected options passed');
        }
        if (!isset($this->cachedObjects[$name])) {
            $this->cachedObjects[$name] = parent::get($name, $options);
        }
        return clone $this->cachedObjects[$name];
    }
}
