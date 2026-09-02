<?php

namespace Yamaha\DreamFactory\PremiumStub\Services;

use DreamFactory\Core\Services\BaseRestService;
use DreamFactory\Core\Utility\ResourcesWrapper;

/**
 * LDAP stub service — exposes minimal endpoints so UI shows LDAP under Authentication
 * No real LDAP bind; GET returns empty config, POST stores mock.
 */
class LdapService extends BaseRestService
{
    public function getResourceHandlers()
    {
        return [
            'config' => [
                'name' => 'config',
                'operations' => ['get', 'post', 'put', 'patch', 'delete'],
            ],
        ];
    }

    protected function handleGET()
    {
        if ($this->resource === 'config' || empty($this->resource)) {
            return ResourcesWrapper::wrapResources([
                ['id' => 1, 'name' => 'ldap_mock', 'label' => 'LDAP (Premium Stub)', 'host' => 'localhost', 'port' => 389, 'is_mock' => true]
            ], null);
        }
        return ['id' => $this->resource, 'name' => $this->resource];
    }

    protected function handlePOST()
    {
        $data = $this->getPayloadData();
        return ResourcesWrapper::wrapResources([$data], null);
    }

    protected function handlePUT()
    {
        return $this->handlePOST();
    }

    protected function handlePATCH()
    {
        return $this->handleGET();
    }

    protected function handleDELETE()
    {
        return ['success' => true];
    }
}
