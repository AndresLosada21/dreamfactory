<?php

namespace Yamaha\DreamFactory\PremiumStub;

use DreamFactory\Core\Enums\LicenseLevel;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Services\ServiceType;
use DreamFactory\Core\System\Components\SystemResourceManager;
use DreamFactory\Core\System\Components\SystemResourceType;
use Yamaha\DreamFactory\PremiumStub\Resources\LimitResource;
use Yamaha\DreamFactory\PremiumStub\Resources\LimitCacheResource;
use Yamaha\DreamFactory\PremiumStub\Resources\SchedulerResource;
use Yamaha\DreamFactory\PremiumStub\Resources\EventScriptResource;
use Yamaha\DreamFactory\PremiumStub\Resources\ScriptTypeResource;
use Yamaha\DreamFactory\PremiumStub\Resources\ServiceReportResource;
use Yamaha\DreamFactory\PremiumStub\Resources\EventResource;
use Yamaha\DreamFactory\PremiumStub\Resources\JobResource;
use Yamaha\DreamFactory\PremiumStub\Services\LdapService;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function boot()
    {
        // No migrations needed — mock resources are file-backed empty
    }

    public function register()
    {
        // Register premium System Resources — all GOLD, no DB table
        $this->app->resolving('df.system.resource', function (SystemResourceManager $rm) {
            $map = [
                'limit' => ['class' => LimitResource::class, 'label' => 'Limits', 'desc' => 'Rate limiting (premium stub GOLD)'],
                'limit_cache' => ['class' => LimitCacheResource::class, 'label' => 'Limit Cache', 'desc' => 'Rate limiting cache (premium stub)'],
                'scheduler' => ['class' => SchedulerResource::class, 'label' => 'Scheduler', 'desc' => 'API scheduling (premium stub GOLD)'],
                'event_script' => ['class' => EventScriptResource::class, 'label' => 'Event Scripts', 'desc' => 'Event scripts (premium stub GOLD)'],
                'script_type' => ['class' => ScriptTypeResource::class, 'label' => 'Script Types', 'desc' => 'Script types (premium stub)'],
                // Handle case variant script_Type used by frontend resolver
                'script_Type' => ['class' => ScriptTypeResource::class, 'label' => 'Script Types', 'desc' => 'Script types (case variant)'],
                'service_report' => ['class' => ServiceReportResource::class, 'label' => 'Service Reports', 'desc' => 'Lifecycle reporting (premium stub)'],
                // Optional but helps API docs
                'event' => ['class' => EventResource::class, 'label' => 'Events', 'desc' => 'Events (premium stub)'],
                'job' => ['class' => JobResource::class, 'label' => 'Jobs', 'desc' => 'Jobs (premium stub)'],
            ];
            foreach ($map as $name => $cfg) {
                if ($rm->getResourceType($name) === null) {
                    $rm->addType(new SystemResourceType([
                        'name' => $name,
                        'label' => $cfg['label'],
                        'description' => $cfg['desc'],
                        'class_name' => $cfg['class'],
                    ]));
                }
            }
        });

        // Register LDAP service types — appear under Authentication in UI
        $this->app->resolving('df.service', function (ServiceManager $sm) {
            $types = [
                [
                    'name' => 'ldap',
                    'label' => 'LDAP',
                    'description' => 'LDAP Authentication (premium stub GOLD — mock)',
                    'group' => ServiceTypeGroups::LDAP,
                    'config_handler' => null,
                    'factory' => function ($config) { return new LdapService($config); },
                ],
                [
                    'name' => 'adldap',
                    'label' => 'Active Directory / LDAP',
                    'description' => 'AD/LDAP (premium stub GOLD)',
                    'group' => ServiceTypeGroups::LDAP,
                    'config_handler' => null,
                    'factory' => function ($config) { return new LdapService($config); },
                ],
            ];
            foreach ($types as $t) {
                if ($sm->getServiceType($t['name']) === null) {
                    $sm->addType(new ServiceType([
                        'name' => $t['name'],
                        'label' => $t['label'],
                        'description' => $t['description'],
                        'group' => $t['group'],
                        'subscriptionRequired' => LicenseLevel::GOLD,
                        'config_handler' => $t['config_handler'],
                        'factory' => $t['factory'],
                    ]));
                }
            }
        });
    }
}
