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
            'command' => [
                'factories' => [
                    \RecordManager\Base\Command\Logs\Send::class => \RecordManager\Base\Command\AbstractBaseFactory::class,
                    \RecordManager\Base\Command\Records\CheckDedup::class => \RecordManager\Base\Command\AbstractBaseFactory::class,
                    \RecordManager\Base\Command\Records\CountValues::class => \RecordManager\Base\Command\Solr\AbstractBaseWithSolrUpdaterFactory::class,
                    \RecordManager\Base\Command\Records\Deduplicate::class => \RecordManager\Base\Command\AbstractBaseFactory::class,
                    \RecordManager\Base\Command\Records\DeleteSource::class => \RecordManager\Base\Command\AbstractBaseFactory::class,
                    \RecordManager\Base\Command\Records\Dump::class => \RecordManager\Base\Command\AbstractBaseFactory::class,
                    \RecordManager\Base\Command\Records\Export::class => \RecordManager\Base\Command\AbstractBaseFactory::class,
                    \RecordManager\Base\Command\Records\Harvest::class => \RecordManager\Base\Command\Records\HarvestFactory::class,
                    \RecordManager\Base\Command\Records\Import::class => \RecordManager\Base\Command\AbstractBaseFactory::class,
                    \RecordManager\Base\Command\Records\MarkDeleted::class => \RecordManager\Base\Command\AbstractBaseFactory::class,
                    \RecordManager\Base\Command\Records\MarkForUpdate::class => \RecordManager\Base\Command\AbstractBaseFactory::class,
                    \RecordManager\Base\Command\Records\PurgeDeleted::class => \RecordManager\Base\Command\AbstractBaseFactory::class,
                    \RecordManager\Base\Command\Records\Renormalize::class => \RecordManager\Base\Command\AbstractBaseFactory::class,
                    \RecordManager\Base\Command\Records\Suppress::class => \RecordManager\Base\Command\AbstractBaseFactory::class,
                    \RecordManager\Base\Command\Records\Unsuppress::class => \RecordManager\Base\Command\AbstractBaseFactory::class,
                    \RecordManager\Base\Command\Solr\CheckIndex::class => \RecordManager\Base\Command\Solr\AbstractBaseWithSolrUpdaterFactory::class,
                    \RecordManager\Base\Command\Solr\CompareRecords::class => \RecordManager\Base\Command\Solr\CompareRecordsFactory::class,
                    \RecordManager\Base\Command\Solr\Delete::class => \RecordManager\Base\Command\Solr\AbstractBaseWithSolrUpdaterFactory::class,
                    \RecordManager\Base\Command\Solr\DumpUpdates::class => \RecordManager\Base\Command\Solr\AbstractBaseWithSolrUpdaterFactory::class,
                    \RecordManager\Base\Command\Solr\Optimize::class => \RecordManager\Base\Command\Solr\AbstractBaseWithSolrUpdaterFactory::class,
                    \RecordManager\Base\Command\Solr\UpdateIndex::class => \RecordManager\Base\Command\Solr\AbstractBaseWithSolrUpdaterFactory::class,
                    \RecordManager\Base\Command\Sources\AddSetting::class => \RecordManager\Base\Command\AbstractBaseFactory::class,
                    \RecordManager\Base\Command\Sources\RemoveSetting::class => \RecordManager\Base\Command\AbstractBaseFactory::class,
                    \RecordManager\Base\Command\Sources\Search::class => \RecordManager\Base\Command\AbstractBaseFactory::class,
                ],
            ],
            'enrichment' => [
                'factories' => [
                    \RecordManager\Base\Enrichment\AuthEnrichment::class => \RecordManager\Base\Enrichment\AuthEnrichmentFactory::class,
                    \RecordManager\Base\Enrichment\EadOnkiLightEnrichment::class => \RecordManager\Base\Enrichment\AbstractEnrichmentFactory::class,
                    \RecordManager\Base\Enrichment\LrmiOnkiLightEnrichment::class => \RecordManager\Base\Enrichment\AbstractEnrichmentFactory::class,
                    \RecordManager\Base\Enrichment\MarcAuthEnrichment::class => \RecordManager\Base\Enrichment\AuthEnrichmentFactory::class,
                    \RecordManager\Base\Enrichment\MarcAuthOnkiLightEnrichment::class => \RecordManager\Base\Enrichment\AbstractEnrichmentFactory::class,
                    \RecordManager\Base\Enrichment\MarcOnkiLightEnrichment::class => \RecordManager\Base\Enrichment\AbstractEnrichmentFactory::class,
                    \RecordManager\Base\Enrichment\MusicBrainzEnrichment::class => \RecordManager\Base\Enrichment\AbstractEnrichmentFactory::class,
                    \RecordManager\Base\Enrichment\NominatimGeocoder::class => \RecordManager\Base\Enrichment\AbstractEnrichmentFactory::class,
                    \RecordManager\Base\Enrichment\OnkiLightEnrichment::class => \RecordManager\Base\Enrichment\AbstractEnrichmentFactory::class,
                ],
                'aliases' => [
                    'AuthEnrichment' => \RecordManager\Base\Enrichment\AuthEnrichment::class,
                    'EadOnkiLightEnrichment' => \RecordManager\Base\Enrichment\EadOnkiLightEnrichment::class,
                    'LrmiOnkiLightEnrichment' => \RecordManager\Base\Enrichment\LrmiOnkiLightEnrichment::class,
                    'MarcAuthEnrichment' => \RecordManager\Base\Enrichment\MarcAuthEnrichment::class,
                    'MarcAuthOnkiLightEnrichment' => \RecordManager\Base\Enrichment\MarcAuthOnkiLightEnrichment::class,
                    'MarcOnkiLightEnrichment' => \RecordManager\Base\Enrichment\MarcOnkiLightEnrichment::class,
                    'MusicBrainzEnrichment' => \RecordManager\Base\Enrichment\MusicBrainzEnrichment::class,
                    'NominatimGeocoder' => \RecordManager\Base\Enrichment\NominatimGeocoder::class,
                    'OnkiLightEnrichment' => \RecordManager\Base\Enrichment\OnkiLightEnrichment::class,
                ],
            ],
            'harvest' => [
                'factories' => [
                    \RecordManager\Base\Harvest\HTTPFiles::class => \RecordManager\Base\Harvest\AbstractBaseFactory::class,
                    \RecordManager\Base\Harvest\OaiPmh::class => \RecordManager\Base\Harvest\AbstractBaseFactory::class,
                    \RecordManager\Base\Harvest\Sfx::class => \RecordManager\Base\Harvest\AbstractBaseFactory::class,
                    \RecordManager\Base\Harvest\SierraApi::class => \RecordManager\Base\Harvest\AbstractBaseFactory::class,
                ],
                'aliases' => [
                    'OAI-PMH' => \RecordManager\Base\Harvest\OaiPmh::class,
                    'SFX' => \RecordManager\Base\Harvest\Sfx::class,
                    // Legacy alias:
                    'sfx' => \RecordManager\Base\Harvest\Sfx::class,
                    'SierraApi' => \RecordManager\Base\Harvest\SierraApi::class,
                    // Legacy alias:
                    'sierra' => \RecordManager\Base\Harvest\SierraApi::class,
                ],
            ],
            'record' => [
                'factories' => [
                    \RecordManager\Base\Record\Dc::class => \RecordManager\Base\Record\AbstractRecordWithHttpClientManagerFactory::class,
                    \RecordManager\Base\Record\Eaccpf::class => \RecordManager\Base\Record\AbstractRecordFactory::class,
                    \RecordManager\Base\Record\Ead::class => \RecordManager\Base\Record\AbstractRecordFactory::class,
                    \RecordManager\Base\Record\Ead3::class => \RecordManager\Base\Record\AbstractRecordFactory::class,
                    \RecordManager\Base\Record\Ese::class => \RecordManager\Base\Record\AbstractRecordFactory::class,
                    \RecordManager\Base\Record\Forward::class => \RecordManager\Base\Record\AbstractRecordFactory::class,
                    \RecordManager\Base\Record\ForwardAuthority::class => \RecordManager\Base\Record\AbstractRecordFactory::class,
                    \RecordManager\Base\Record\Lido::class => \RecordManager\Base\Record\AbstractRecordFactory::class,
                    \RecordManager\Base\Record\Lrmi::class => \RecordManager\Base\Record\AbstractRecordWithHttpClientManagerFactory::class,
                    \RecordManager\Base\Record\Marc::class => \RecordManager\Base\Record\AbstractRecordFactory::class,
                    \RecordManager\Base\Record\MarcAuthority::class => \RecordManager\Base\Record\AbstractRecordFactory::class,
                    \RecordManager\Base\Record\Qdc::class => \RecordManager\Base\Record\AbstractRecordWithHttpClientManagerFactory::class,
                ],
                'aliases' => [
                    'dc' => \RecordManager\Base\Record\Dc::class,
                    'eaccpf' => \RecordManager\Base\Record\Eaccpf::class,
                    'ead' => \RecordManager\Base\Record\Ead::class,
                    'ead3' => \RecordManager\Base\Record\Ead3::class,
                    'ese' => \RecordManager\Base\Record\Ese::class,
                    'forward' => \RecordManager\Base\Record\Forward::class,
                    'forwardAuthority' => \RecordManager\Base\Record\ForwardAuthority::class,
                    'lido' => \RecordManager\Base\Record\Lido::class,
                    'lrmi' => \RecordManager\Base\Record\Lrmi::class,
                    'marc' => \RecordManager\Base\Record\Marc::class,
                    'marcAuthority' => \RecordManager\Base\Record\MarcAuthority::class,
                    'qdc' => \RecordManager\Base\Record\Qdc::class,
                ],
            ],
            'splitter' => [
                'factories' => [
                    \RecordManager\Base\Splitter\Ead::class => \RecordManager\Base\Splitter\AbstractBaseFactory::class,
                    \RecordManager\Base\Splitter\Ead3::class => \RecordManager\Base\Splitter\AbstractBaseFactory::class,
                    \RecordManager\Base\Splitter\File::class => \RecordManager\Base\Splitter\AbstractBaseFactory::class,
                ],
            ],
        ],
    ],
    'service_manager' => [
        'factories' => [
            \RecordManager\Base\Command\PluginManager::class => \RecordManager\Base\ServiceManager\AbstractPluginManagerFactory::class,
            \RecordManager\Base\ConsoleRunner::class => \RecordManager\Base\ConsoleRunnerFactory::class,
            \RecordManager\Base\Controller\CreatePreview::class => \RecordManager\Base\Controller\CreatePreviewFactory::class,
            \RecordManager\Base\Controller\OaiPmhProvider::class => \RecordManager\Base\Controller\OaiPmhProviderFactory::class,
            \RecordManager\Base\Database\AbstractAuthorityDatabase::class => \RecordManager\Base\Database\AbstractAuthorityDatabaseFactory::class,
            \RecordManager\Base\Database\AbstractDatabase::class => \RecordManager\Base\Database\AbstractDatabaseFactory::class,
            \RecordManager\Base\Deduplication\DedupHandler::class => \RecordManager\Base\Deduplication\DedupHandlerFactory::class,
            \RecordManager\Base\Enrichment\PluginManager::class => \RecordManager\Base\ServiceManager\AbstractPluginManagerFactory::class,
            \RecordManager\Base\Harvest\HTTPFiles::class => \RecordManager\Base\Harvest\AbstractBaseFactory::class,
            \RecordManager\Base\Harvest\OaiPmh::class => \RecordManager\Base\Harvest\AbstractBaseFactory::class,
            \RecordManager\Base\Harvest\Sfx::class => \RecordManager\Base\Harvest\AbstractBaseFactory::class,
            \RecordManager\Base\Harvest\SierraApi::class => \RecordManager\Base\Harvest\AbstractBaseFactory::class,
            \RecordManager\Base\Harvest\PluginManager::class => \RecordManager\Base\ServiceManager\AbstractPluginManagerFactory::class,
            \RecordManager\Base\Http\ClientManager::class => \RecordManager\Base\Http\ClientManagerFactory::class,
            \RecordManager\Base\Record\PluginManager::class => \RecordManager\Base\ServiceManager\AbstractPluginManagerFactory::class,
            \RecordManager\Base\Settings\Ini::class => InvokableFactory::class,
            \RecordManager\Base\Solr\PreviewCreator::class => \RecordManager\Base\Solr\SolrUpdaterFactory::class,
            \RecordManager\Base\Solr\SolrComparer::class => \RecordManager\Base\Solr\SolrUpdaterFactory::class,
            \RecordManager\Base\Solr\SolrUpdater::class => \RecordManager\Base\Solr\SolrUpdaterFactory::class,
            \RecordManager\Base\Splitter\PluginManager::class => \RecordManager\Base\ServiceManager\AbstractPluginManagerFactory::class,
            \RecordManager\Base\Utils\FieldMapper::class => \RecordManager\Base\Utils\FieldMapperFactory::class,
            \RecordManager\Base\Utils\Logger::class => \RecordManager\Base\Utils\LoggerFactory::class,
            \RecordManager\Base\Utils\MetadataUtils::class => \RecordManager\Base\Utils\MetadataUtilsFactory::class,
            \RecordManager\Base\Utils\WorkerPoolManager::class => \RecordManager\Base\Utils\WorkerPoolManagerFactory::class,
        ],
        'shared' => [
            \RecordManager\Base\Database\AbstractDatabase::class => false,
        ],
    ],
];
