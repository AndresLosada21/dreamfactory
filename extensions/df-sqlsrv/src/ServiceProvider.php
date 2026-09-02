<?php

namespace Yamaha\DreamFactory\SqlServer;

use DreamFactory\Core\Components\DbSchemaExtensions;
use DreamFactory\Core\Enums\LicenseLevel;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Services\ServiceType;
use Yamaha\DreamFactory\SqlServer\Database\Schema\SqlServerSchema;
use Yamaha\DreamFactory\SqlServer\Models\SqlServerConfig;
use Yamaha\DreamFactory\SqlServer\Services\SqlServer;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function register()
    {
        $this->app->resolving('df.db.schema', function (DbSchemaExtensions $schemas) {
            $schemas->extend('sqlsrv', function ($connection) {
                return new SqlServerSchema($connection);
            });
        });

        $this->app->resolving('df.service', function (ServiceManager $services) {
            $services->addType(new ServiceType([
                'name' => 'sqlsrv',
                'label' => 'SQL Server',
                'description' => 'Independent SQL Server database service using pdo_sqlsrv. Requires Microsoft ODBC Driver (external, not redistributed — see https://learn.microsoft.com/en-us/sql/connect/odbc/download-odbc-driver-for-sql-server). Encrypt defaults to Yes.',
                'group' => ServiceTypeGroups::DATABASE,
                'subscriptionRequired' => LicenseLevel::GOLD,
                'config_handler' => SqlServerConfig::class,
                'factory' => function (array $config) {
                    return new SqlServer($config);
                },
            ]));
        });
    }
}
