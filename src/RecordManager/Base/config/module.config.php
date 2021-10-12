<?php
/**
 * Base module configuration
 *
 * PHP version 7
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
namespace RecordManager\Base\Module\Config;

use Laminas\ServiceManager\Factory\InvokableFactory;

return [
    'recordmanager' => [
        'plugin_managers' => [
            'base_record' => [
                'factories' => [
                    \RecordManager\Base\Record\Dc::class => \RecordManager\Base\Record\AbstractRecordFactory::class,
                    \RecordManager\Base\Record\Eaccpf::class => \RecordManager\Base\Record\AbstractRecordFactory::class,
                    \RecordManager\Base\Record\Ead::class => \RecordManager\Base\Record\AbstractRecordFactory::class,
                    \RecordManager\Base\Record\Ead3::class => \RecordManager\Base\Record\AbstractRecordFactory::class,
                    \RecordManager\Base\Record\Ese::class => \RecordManager\Base\Record\AbstractRecordFactory::class,
                    \RecordManager\Base\Record\Forward::class => \RecordManager\Base\Record\RecordFactory::class,
                    \RecordManager\Base\Record\ForwardAuthority::class
                         => \RecordManager\Base\Record\AbstractRecordFactory::class,
                    \RecordManager\Base\Record\Lido::class => \RecordManager\Base\Record\AbstractRecordFactory::class,
                    \RecordManager\Base\Record\Lrmi::class => \RecordManager\Base\Record\AbstractRecordFactory::class,
                    \RecordManager\Base\Record\Marc::class => \RecordManager\Base\Record\AbstractRecordFactory::class,
                    \RecordManager\Base\Record\MarcAuthority::class
                        => \RecordManager\Base\Record\AbstractRecordFactory::class,
                    \RecordManager\Base\Record\Qdc::class => \RecordManager\Base\Record\AbstractRecordFactory::class,
                ],
                'aliases' => [
                    'dc' => \RecordManager\Base\Record\Dc::class,
                    'eaccpf' => \RecordManager\Base\Record\Eaccpf::class,
                    'ead' => \RecordManager\Base\Record\Ead::class,
                    'ead3' => \RecordManager\Base\Record\Ead3::class,
                    'ese' => \RecordManager\Base\Record\Ese::class,
                    'forward' => \RecordManager\Base\Record\Forward::class,
                    'forwardauthority' => \RecordManager\Base\Record\ForwardAuthority::class,
                    'lido' => \RecordManager\Base\Record\Lido::class,
                    'lrmi' => \RecordManager\Base\Record\Lrmi::class,
                    'marc' => \RecordManager\Base\Record\Marc::class,
                    'marcauthority' => \RecordManager\Base\Record\MarcAuthority::class,
                    'qdc' => \RecordManager\Base\Record\Qdc::class,
                ],
            ],
            'base_enrichment' => [
                'factories' => [
                    \RecordManager\Base\Enrichment\AuthEnrichment::class
                        => \RecordManager\Base\Enrichment\AuthEnrichmentFactory::class,
                    \RecordManager\Base\Enrichment\EadOnkiLightEnrichment::class
                        => \RecordManager\Base\Enrichment\AbstractEnrichmentFactory::class,
                    \RecordManager\Base\Enrichment\LrmiOnkiLightEnrichment::class
                        => \RecordManager\Base\Enrichment\AbstractEnrichmentFactory::class,
                    \RecordManager\Base\Enrichment\MarcAuthEnrichment::class
                        => \RecordManager\Base\Enrichment\AuthEnrichmentFactory::class,
                    \RecordManager\Base\Enrichment\MarcAuthOnkiLightEnrichment::class
                        => \RecordManager\Base\Enrichment\AbstractEnrichmentFactory::class,
                    \RecordManager\Base\Enrichment\MarcOnkiLightEnrichment::class
                        => \RecordManager\Base\Enrichment\AbstractEnrichmentFactory::class,
                    \RecordManager\Base\Enrichment\MusicBrainzEnrichment::class
                        => \RecordManager\Base\Enrichment\AbstractEnrichmentFactory::class,
                    \RecordManager\Base\Enrichment\NominatimGeocoder::class
                        => \RecordManager\Base\Enrichment\AbstractEnrichmentFactory::class,
                    \RecordManager\Base\Enrichment\OnkiLightEnrichment::class
                        => \RecordManager\Base\Enrichment\AbstractEnrichmentFactory::class,
                ],
                'aliases' => [
                    'AuthEnrichment'
                        => \RecordManager\Base\Enrichment\AuthEnrichment::class,
                    'EadOnkiLightEnrichment'
                        => \RecordManager\Base\Enrichment\EadOnkiLightEnrichment::class,
                    'LrmiOnkiLightEnrichment'
                        => \RecordManager\Base\Enrichment\LrmiOnkiLightEnrichment::class,
                    'MarcAuthEnrichment'
                        => \RecordManager\Base\Enrichment\MarcAuthEnrichment::class,
                    'MarcAuthOnkiLightEnrichment'
                        => \RecordManager\Base\Enrichment\MarcAuthOnkiLightEnrichment::class,
                    'MarcOnkiLightEnrichment'
                        => \RecordManager\Base\Enrichment\MarcOnkiLightEnrichment::class,
                    'MusicBrainzEnrichment'
                        => \RecordManager\Base\Enrichment\MusicBrainzEnrichment::class,
                    'NominatimGeocoder'
                        => \RecordManager\Base\Enrichment\NominatimGeocoder::class,
                    'OnkiLightEnrichment'
                        => \RecordManager\Base\Enrichment\OnkiLightEnrichment::class,
                ],
            ],
        ],
    ],
    'service_manager' => [
        'factories' => [
            \RecordManager\Base\Controller\SolrUpdate::class
                => \RecordManager\Base\Controller\AbstractBaseWithSolrUpdaterFactory::class,
            \RecordManager\Base\Database\AbstractAuthorityDatabase::class
                => \RecordManager\Base\Database\AbstractAuthorityDatabaseFactory::class,
            \RecordManager\Base\Database\AbstractDatabase::class
                => \RecordManager\Base\Database\AbstractDatabaseFactory::class,
            \RecordManager\Base\Deduplication\DedupHandler::class
                => \RecordManager\Base\Deduplication\DedupHandlerFactory::class,
            \RecordManager\Base\Enrichment\PluginManager::class
                => \RecordManager\Base\ServiceManager\AbstractPluginManagerFactory::class,
            \RecordManager\Base\Record\PluginManager::class
                => \RecordManager\Base\ServiceManager\AbstractPluginManagerFactory::class,
            \RecordManager\Base\Settings\Ini::class => InvokableFactory::class,
            \RecordManager\Base\Solr\PreviewCreator::class => \RecordManager\Base\Solr\SolrUpdaterFactory::class,
            \RecordManager\Base\Solr\SolrComparer::class => \RecordManager\Base\Solr\SolrComparerFactory::class,
            \RecordManager\Base\Solr\SolrUpdater::class => \RecordManager\Base\Solr\SolrUpdaterFactory::class,
            \RecordManager\Base\Utils\Logger::class => \RecordManager\Base\Utils\LoggerFactory::class,
        ],
    ],
];
