<?php
/**
 * Authority database factory
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
namespace RecordManager\Base;

use Laminas\ServiceManager\Factory\InvokableFactory;

return [
    'recordmanager' => [
        'plugin_managers' => [
            'base_record' => [
                'factories' => [
                    Record\Dc::class => Record\AbstractRecordFactory::class,
                    Record\Eaccpf::class => Record\AbstractRecordFactory::class,
                    Record\Ead::class => Record\AbstractRecordFactory::class,
                    Record\Ead3::class => Record\AbstractRecordFactory::class,
                    Record\Ese::class => Record\AbstractRecordFactory::class,
                    Record\Forward::class => Record\RecordFactory::class,
                    Record\ForwardAuthority::class
                         => Record\AbstractRecordFactory::class,
                    Record\Lido::class => Record\AbstractRecordFactory::class,
                    Record\Lrmi::class => Record\AbstractRecordFactory::class,
                    Record\Marc::class => Record\AbstractRecordFactory::class,
                    Record\MarcAuthority::class
                        => Record\AbstractRecordFactory::class,
                    Record\Qdc::class => Record\AbstractRecordFactory::class,
                ],
                'aliases' => [
                    'dc' => Record\Dc::class,
                    'eaccpf' => Record\Eaccpf::class,
                    'ead' => Record\Ead::class,
                    'ead3' => Record\Ead3::class,
                    'ese' => Record\Ese::class,
                    'forward' => Record\Forward::class,
                    'forwardauthority' => Record\ForwardAuthority::class,
                    'lido' => Record\Lido::class,
                    'lrmi' => Record\Lrmi::class,
                    'marc' => Record\Marc::class,
                    'marcauthority' => Record\MarcAuthority::class,
                    'qdc' => Record\Qdc::class,
                ],
            ],
            'base_enrichment' => [
                'factories' => [
                    Enrichment\AuthEnrichment::class
                        => Enrichment\AuthEnrichmentFactory::class,
                    Enrichment\EadOnkiLightEnrichment::class
                        => Enrichment\AbstractEnrichmentFactory::class,
                    Enrichment\LrmiOnkiLightEnrichment::class
                        => Enrichment\AbstractEnrichmentFactory::class,
                    Enrichment\MarcAuthEnrichment::class
                        => Enrichment\AuthEnrichmentFactory::class,
                    Enrichment\MarcAuthOnkiLightEnrichment::class
                        => Enrichment\AbstractEnrichmentFactory::class,
                    Enrichment\MarcOnkiLightEnrichment::class
                        => Enrichment\AbstractEnrichmentFactory::class,
                    Enrichment\MusicBrainzEnrichment::class
                        => Enrichment\AbstractEnrichmentFactory::class,
                    Enrichment\NominatimGeocoder::class
                        => Enrichment\AbstractEnrichmentFactory::class,
                    Enrichment\OnkiLightEnrichment::class
                        => Enrichment\AbstractEnrichmentFactory::class,
                ],
                'aliases' => [
                    'AuthEnrichment'
                        => Enrichment\AuthEnrichment::class,
                    'EadOnkiLightEnrichment'
                        => Enrichment\EadOnkiLightEnrichment::class,
                    'LrmiOnkiLightEnrichment'
                        => Enrichment\LrmiOnkiLightEnrichment::class,
                    'MarcAuthEnrichment'
                        => Enrichment\MarcAuthEnrichment::class,
                    'MarcAuthOnkiLightEnrichment'
                        => Enrichment\MarcAuthOnkiLightEnrichment::class,
                    'MarcOnkiLightEnrichment'
                        => Enrichment\MarcOnkiLightEnrichment::class,
                    'MusicBrainzEnrichment'
                        => Enrichment\MusicBrainzEnrichment::class,
                    'NominatimGeocoder'
                        => Enrichment\NominatimGeocoder::class,
                    'OnkiLightEnrichment'
                        => Enrichment\OnkiLightEnrichment::class,
                ],
            ],
        ],
    ],
    'service_manager' => [
        'factories' => [
            Controller\SolrUpdate::class
                => Controller\AbstractBaseWithSolrUpdaterFactory::class,
            Database\AbstractAuthorityDatabase::class
                => Database\AbstractAuthorityDatabaseFactory::class,
            Database\AbstractDatabase::class
                => Database\AbstractDatabaseFactory::class,
            Deduplication\DedupHandler::class
                => Deduplication\DedupHandlerFactory::class,
            Enrichment\PluginManager::class
                => ServiceManager\AbstractPluginManagerFactory::class,
            Record\PluginManager::class
                => ServiceManager\AbstractPluginManagerFactory::class,
            Settings\Ini::class => InvokableFactory::class,
            Solr\PreviewCreator::class => Solr\SolrUpdaterFactory::class,
            Solr\SolrUpdater::class => Solr\SolrUpdaterFactory::class,
            Utils\Logger::class => Utils\LoggerFactory::class,
        ],
    ],
];
