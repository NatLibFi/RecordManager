<?php

/**
 * Module manager.
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2026.
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

namespace RecordManager\Base\ModuleManager;

use Exception;
use Laminas\ServiceManager\ServiceManager;
use Laminas\Stdlib\ArrayUtils;

/**
 * Module manager.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class ModuleManager
{
    /**
     * All loaded modules.
     *
     * @var array
     */
    protected array $loadedModules = [];

    /**
     * All module-specific configs.
     *
     * @var array
     */
    protected array $allConfigs = [];

    /**
     * Constructor.
     *
     * @param ServiceManager $serviceManager Service manager
     * @param array          $modules        List of modules
     */
    public function __construct(
        protected ServiceManager $serviceManager,
        protected array $modules
    ) {
    }

    /**
     * Load and initialize modules.
     *
     * @return void
     */
    public function initialize(): void
    {
        if ($this->loadedModules) {
            return;
        }
        $this->loadedModules = [];
        $this->allConfigs = [];

        $this->loadModules();
        $this->updateServiceManager();
        $this->initializeModules();
    }

    /**
     * Get merged module configuration.
     *
     * @return array
     */
    public function getMergedConfig(): array
    {
        $merged = [];
        foreach ($this->allConfigs as $config) {
            $merged = ArrayUtils::merge($merged, $config);
        }
        return $merged;
    }

    /**
     * Load modules.
     *
     * @return void
     */
    protected function loadModules(): void
    {
        foreach ($this->modules as $moduleName) {
            $moduleClass = "$moduleName\Module";
            if (class_exists($moduleClass)) {
                $module = new $moduleClass();
                if (!($module instanceof ModuleInterface)) {
                    throw new Exception($module::class . ' does not implement ModuleInterface');
                }
                $this->allConfigs[$moduleName] = $module->getConfig();
            }
        }
    }

    /**
     * Update service manager with merged module configuration.
     *
     * @return void
     */
    protected function updateServiceManager(): void
    {
        $mergedConfig = $this->getMergedConfig();

        $saveOverride = $this->serviceManager->getAllowOverride();
        $this->serviceManager->setAllowOverride(true);
        $this->serviceManager->configure($mergedConfig['service_manager'] ?? []);
        $this->serviceManager->setAllowOverride($saveOverride);
    }

    /**
     * Initialize loaded modules.
     *
     * @return void
     */
    protected function initializeModules(): void
    {
        foreach ($this->loadedModules as $module) {
            $module->initialize($this->serviceManager);
        }
    }
}
