<?php

namespace Yamaha\DreamFactory\Oracle;

use DreamFactory\Core\Components\DbSchemaExtensions;
use DreamFactory\Core\Enums\LicenseLevel;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Services\ServiceType;
use Yamaha\DreamFactory\Oracle\Database\Schema\OracleSchema;
use Yamaha\DreamFactory\Oracle\Models\OracleConfig;
use Yamaha\DreamFactory\Oracle\Services\Oracle;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function register()
    {
        $this->app->resolving('df.db.schema', function (DbSchemaExtensions $schemas) {
            $schemas->extend('oracle', function ($connection) {
                return new OracleSchema($connection);
            });
        });

        $this->app->resolving('df.service', function (ServiceManager $services) {
            $services->addType(new ServiceType([
                'name' => 'oracle',
                'label' => 'Oracle',
                'description' => 'Independent Oracle database service using OCI8 via yajra/laravel-oci8 (MIT). Requires Oracle Instant Client and oci8 extension (external, not redistributed).',
                'group' => ServiceTypeGroups::DATABASE,
                'subscriptionRequired' => LicenseLevel::GOLD,
                'config_handler' => OracleConfig::class,
                'factory' => function (array $config) {
                    return new Oracle($config);
                },
            ]));
        });
    }
}
