<?php

namespace App\Services\LinkedResources;

use App\Models\CadEndpoint;

class ResourcePolicy
{
    public function forEndpoint(CadEndpoint $endpoint): ?array
    {
        $policy = config('linked_resources.endpoints.'.$endpoint->id);
        if (! is_array($policy) || ($policy['enabled'] ?? false) !== true) {
            return null;
        }

        if (strtolower((string) $endpoint->direcao) !== 'entrada'
            || strtolower((string) $endpoint->extensao) !== 'json') {
            throw new CaptureException('invalid_endpoint');
        }
        if (config('filesystems.disks.integrations.driver') !== 'local') {
            throw new CaptureException('local_storage_required');
        }

        foreach (['collection_path', 'url_path', 'id_path'] as $key) {
            if (! is_string($policy[$key] ?? null) || trim($policy[$key]) === '') {
                throw new CaptureException('invalid_configuration');
            }
        }
        if (isset($policy['revision_path']) && (! is_string($policy['revision_path']) || $policy['revision_path'] === '')) {
            throw new CaptureException('invalid_configuration');
        }
        if (! is_array($policy['allowed_hosts'] ?? null) || $policy['allowed_hosts'] === []) {
            throw new CaptureException('invalid_configuration');
        }
        foreach ($policy['allowed_hosts'] as $host) {
            if (! is_string($host) || ! preg_match('/^(?=.{1,253}$)[a-z0-9]+(?:[.-][a-z0-9]+)*$/D', $host)) {
                throw new CaptureException('invalid_configuration');
            }
        }

        $policy += ['max_bytes' => 10 * 1024 * 1024, 'timeout' => 15, 'connect_timeout' => 5, 'max_references' => 20];
        foreach (['max_bytes' => 100 * 1024 * 1024, 'timeout' => 30, 'connect_timeout' => 10, 'max_references' => 20] as $key => $maximum) {
            if (! is_int($policy[$key]) || $policy[$key] < 1 || $policy[$key] > $maximum) {
                throw new CaptureException('invalid_configuration');
            }
        }

        return $policy;
    }
}
