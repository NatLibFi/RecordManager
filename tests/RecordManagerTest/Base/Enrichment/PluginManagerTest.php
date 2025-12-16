<?php

/**
 * Tests for enrichment plugin manager
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2025.
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
 * @author   Juha Luoma <juha.luoma@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */

namespace RecordManagerTest\Base\Enrichment;

use Psr\Container\ContainerInterface;

/**
 * Tests for enrichment plugin manager
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Juha Luoma <juha.luoma@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class PluginManagerTest extends \PHPUnit\Framework\TestCase
{
    protected array $moduleConfig = [];

    /**
     * Standard setup method.
     *
     * @return void
     */
    public function setUp(): void
    {
        $this->moduleConfig
            = include realpath('./') . '/src/RecordManager/Base/config/module.config.php';
    }

    /**
     * Test plugin manager aliases
     *
     * @return void
     */
    public function testAliases(): void
    {
        $serviceAliases = [
            'AuthEnrichment',
            'MusicBrainzEnrichment',
            'NominatimGeocoder',
            'SkosmosEnrichment',
            'EadOnkiLightEnrichment',
            'Ead3OnkiLightEnrichment',
            'LidoOnkiLightEnrichment',
            'LrmiOnkiLightEnrichment',
            'MarcAuthOnkiLightEnrichment',
            'MarcOnkiLightEnrichment',
            'OnkiLightEnrichment',
            'EadSkosmosEnrichment',
            'Ead3SkosmosEnrichment',
            'LidoSkosmosEnrichment',
            'LrmiSkosmosEnrichment',
            'MarcAuthSkosmosEnrichment',
            'MarcSkosmosEnrichment',
            'MarcAuthEnrichment',
        ];
        $serviceManager = $this->createMock(ContainerInterface::class);
        $serviceManager->expects($this->any())->method('get')->willReturnMap(
            [
                ['Config', $this->moduleConfig],
            ]
        );
        $enrichmentPluginManagerFactory = new \RecordManager\Base\ServiceManager\AbstractPluginManagerFactory();
        $enrichmentPluginManager = ($enrichmentPluginManagerFactory)(
            $serviceManager,
            \RecordManager\Base\Enrichment\PluginManager::class,
        );
        $reflectionClass = new \ReflectionClass($enrichmentPluginManager);
        $aliasesProperty = $reflectionClass->getProperty('aliases');

        $aliasesConfig = $this->moduleConfig['recordmanager']['plugin_managers']['enrichment']['aliases'] ?? [];
        $aliasesValue = $aliasesProperty->getValue($enrichmentPluginManager);
        $this->assertEquals($aliasesConfig, $aliasesValue);
        foreach ($serviceAliases as $alias) {
            $this->assertTrue($enrichmentPluginManager->has($alias));
        }
    }
}
