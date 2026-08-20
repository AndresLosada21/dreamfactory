<?php

namespace Yamaha\DreamFactory\SqlServer;

use DreamFactory\Core\Components\DbSchemaExtensions;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Services\ServiceType;
use DreamFactory\Core\SqlDb\Database\Schema\SqlSchema;
use Yamaha\DreamFactory\SqlServer\Models\SqlServerConfig;
use Yamaha\DreamFactory\SqlServer\Services\SqlServer;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function register()
    {
        $this->app->resolving('df.db.schema', function (DbSchemaExtensions $schemas) {
            $schemas->extend('sqlsrv', function ($connection) {
                return new SqlSchema($connection);
            });
        });

        $this->app->resolving('df.service', function (ServiceManager $services) {
            $services->addType(new ServiceType([
                'name' => 'sqlsrv',
                'label' => 'SQL Server',
                'description' => 'Independent SQL Server database service using pdo_sqlsrv.',
                'group' => ServiceTypeGroups::DATABASE,
                'config_handler' => SqlServerConfig::class,
                'factory' => function (array $config) {
                    return new SqlServer($config);
                },
            ]));
        });
    }
}
