<?php
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
                    Record\ForwardAuthority::class => Record\AbstractRecordFactory::class,
                    Record\Lido::class => Record\AbstractRecordFactory::class,
                    Record\Lrmi::class => Record\AbstractRecordFactory::class,
                    Record\Marc::class => Record\AbstractRecordFactory::class,
                    Record\MarcAuthority::class => Record\AbstractRecordFactory::class,
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
        ],
    ],
    'service_manager' => [
        'factories' => [
            Controller\SolrUpdate::class => Controller\AbstractBaseFactory::class,
            Database\AbstractDatabase::class => Database\AbstractDatabaseFactory::class,
            Record\PluginManager::class => ServiceManager\AbstractPluginManagerFactory::class,
            Settings\Ini::class => InvokableFactory::class,
            Utils\Logger::class => Utils\LoggerFactory::class,
        ],
    ],
];
