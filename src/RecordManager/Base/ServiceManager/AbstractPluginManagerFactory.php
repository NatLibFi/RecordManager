<?php

/**
 * Plugin Manager factory.
 *
 * PHP version 8
 *
 * Copyright (C) Villanova University 2018.
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
 * @category VuFind
 * @package  ServiceManager
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development Wiki
 */

namespace RecordManager\Base\ServiceManager;

use Laminas\ServiceManager\Exception\ServiceNotCreatedException;
use Laminas\ServiceManager\Exception\ServiceNotFoundException;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerExceptionInterface as ContainerException;
use Psr\Container\ContainerInterface;

use function count;

/**
 * Plugin Manager factory.
 *
 * @category VuFind
 * @package  ServiceManager
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development Wiki
 */
class AbstractPluginManagerFactory implements FactoryInterface
{
    /**
     * Create an object
     *
     * @param ContainerInterface $container     Service manager
     * @param string             $requestedName Service being created
     * @param null|array         $options       Extra options (optional)
     *
     * @return object
     *
     * @throws ServiceNotFoundException if unable to resolve the service.
     * @throws ServiceNotCreatedException if an exception is raised when
     * creating a service.
     * @throws ContainerException&\Throwable if any other error occurs
     *
     * @psalm-suppress UndefinedDocblockClass
     */
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null
    ) {
        if (!empty($options)) {
            throw new \Exception('Unexpected options sent to factory.');
        }
        $configKey = $this->getConfigKey($requestedName);
        if (empty($configKey)) {
            $error = 'Problem determining config key for ' . $requestedName;
            throw new \Exception($error);
        }
        $config = $container->get('Config');
        if (!isset($config['recordmanager']['plugin_managers'][$configKey])) {
            throw new ServiceNotCreatedException(
                'Plugin configuration not found at recordmanager => plugin_managers'
                . " => $configKey"
            );
        }
        return new $requestedName(
            $container,
            $config['recordmanager']['plugin_managers'][$configKey]
        );
    }

    /**
     * Determine the configuration key for the specified class name.
     *
     * @param string $requestedName Service being created
     *
     * @return string
     *
     * @throws ServiceNotCreatedException if an exception is raised when
     * creating a service.
     */
    public function getConfigKey($requestedName)
    {
        // Extract namespace of the plugin manager (chop off leading top-level
        // namespace -- e.g. RecordManager\Base -- and trailing PluginManager class):
        $parts = explode('\\', $requestedName);
        if (count($parts) < 4 || array_pop($parts) !== 'PluginManager') {
            throw new ServiceNotCreatedException(
                "Cannot determine config key for $requestedName"
            );
        }
        array_shift($parts);
        array_shift($parts);
        return strtolower(implode('_', $parts));
    }
}
