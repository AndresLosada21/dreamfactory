<?php

namespace Yamaha\DreamFactory\Informix;

use DreamFactory\Core\Components\DbSchemaExtensions;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Services\ServiceType;
use DreamFactory\Core\SqlDb\Database\Schema\SqlSchema;
use Illuminate\Database\DatabaseManager;
use Yamaha\DreamFactory\Informix\Database\InformixConnector;
use Yamaha\DreamFactory\Informix\Models\InformixConfig;
use Yamaha\DreamFactory\Informix\Services\Informix;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function register()
    {
        $this->app->resolving('df.db.schema', function (DbSchemaExtensions $schemas) {
            $schemas->extend('informix', function ($connection) {
                return new SqlSchema($connection);
            });
        });

        $this->app->resolving('db', function (DatabaseManager $database) {
            $database->extend('informix', function (array $config) {
                return (new InformixConnector())->connect($config);
            });
        });

        $this->app->resolving('df.service', function (ServiceManager $services) {
            $services->addType(new ServiceType([
                'name' => 'informix',
                'label' => 'Informix',
                'description' => 'Independent Informix database service using PDO Informix.',
                'group' => ServiceTypeGroups::DATABASE,
                'config_handler' => InformixConfig::class,
                'factory' => function (array $config) {
                    return new Informix($config);
                },
            ]));
        });
    }
}
