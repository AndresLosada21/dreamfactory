<?php
// Determinus GOLD unlock — Service.php subscription check now respects GOLD license
$path = '/opt/dreamfactory/vendor/dreamfactory/df-core/src/Models/Service.php';
if (!file_exists($path)) $path = '/app/vendor/dreamfactory/df-core/src/Models/Service.php';
if (!file_exists($path)) exit(0);
$c = file_get_contents($path);
$old = <<<'OLD'
        } else {
            if (null !== $typeInfo = ServiceManager::getServiceType($this->type)) {
                if ($subscription = $typeInfo->subscriptionRequired()) {
                    throw new BadRequestException("Provisioning Failed. '$subscription' subscription required for this service type.");
                }
            }
        }
OLD;
$new = <<<'NEW'
        } else {
            if (null !== $typeInfo = ServiceManager::getServiceType($this->type)) {
                if ($subscription = $typeInfo->subscriptionRequired()) {
                    $cur = \DreamFactory\Core\Utility\Environment::getLicenseLevel();
                    $levels = [\DreamFactory\Core\Enums\LicenseLevel::OPEN_SOURCE=>0,\DreamFactory\Core\Enums\LicenseLevel::SILVER=>1,\DreamFactory\Core\Enums\LicenseLevel::GOLD=>2];
                    if (($levels[$cur] ?? 0) < ($levels[$subscription] ?? 0)) {
                        throw new BadRequestException("Provisioning Failed. '$subscription' subscription required for this service type.");
                    }
                }
            }
        }
NEW;
if (strpos($c, $old) !== false) {
    file_put_contents($path, str_replace($old, $new, $c));
    echo "patched Service.php GOLD check\n";
} elseif (strpos($c, '$cur = \DreamFactory') !== false) {
    echo "already patched\n";
} else {
    echo "not found\n";
}
