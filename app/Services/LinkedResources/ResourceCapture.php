<?php

namespace App\Services\LinkedResources;

use App\Models\CadEndpoint;
use App\Models\LinkedResource;
use Illuminate\Support\Facades\Storage;

class ResourceCapture
{
    public function __construct(private ResourcePolicy $policies, private SafeResourceDownloader $downloader) {}

    public function discover(CadEndpoint $endpoint, string $body, string $sourcePath, bool $capture = true): bool
    {
        $policy = $this->policies->forEndpoint($endpoint);
        if ($policy === null) {
            return true;
        }
        try {
            $document = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new CaptureException('invalid_source_json');
        }
        $items = data_get($document, $policy['collection_path']);
        if (! is_array($items) || ! array_is_list($items) || count($items) > $policy['max_references']) {
            throw new CaptureException('invalid_collection');
        }

        // Validate the whole discovery before starting any download.
        $references = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new CaptureException('invalid_reference');
            }
            $id = data_get($item, $policy['id_path']);
            $revision = isset($policy['revision_path']) ? data_get($item, $policy['revision_path']) : null;
            $url = data_get($item, $policy['url_path']);
            if ((! is_string($id) && ! is_int($id)) || (string) $id === '' || strlen((string) $id) > 1024
                || (isset($policy['revision_path']) && ((! is_string($revision) && ! is_int($revision)) || (string) $revision === '' || strlen((string) $revision) > 1024))
                || ! is_string($url) || $url === '' || strlen($url) > 16384) {
                throw new CaptureException('invalid_reference');
            }
            $references[] = [(string) $id, $revision === null ? null : (string) $revision, $url];
        }

        $success = true;
        foreach ($references as [$id, $revision, $url]) {
            $identity = hash('sha256', json_encode([$id, $revision], JSON_THROW_ON_ERROR));
            $success = $this->withLock($endpoint->client_id, $endpoint->id, $identity, function () use ($endpoint, $identity, $id, $revision, $url, $sourcePath, $policy, $capture) {
                $resource = LinkedResource::firstOrCreate([
                    'client_id' => $endpoint->client_id,
                    'endpoint_id' => $endpoint->id,
                    'identity_hash' => $identity,
                ], [
                    'external_id' => $id, 'revision' => $revision,
                    'download_url' => $url, 'source_path' => $sourcePath,
                    'state' => LinkedResource::PENDING,
                ]);
                if ($resource->state !== LinkedResource::CAPTURED) {
                    // A re-query may supply a fresh signature for the same identity.
                    $resource->update(['download_url' => $url]);
                }

                return ! $capture || $this->captureLocked($resource, $policy);
            }) && $success;
        }

        return $success;
    }

    public function recover(LinkedResource $resource): bool
    {
        $endpoint = $resource->endpoint;
        if (! $endpoint || ! $endpoint->ativo || $endpoint->client_id !== $resource->client_id) {
            return false;
        }
        $policy = $this->policies->forEndpoint($endpoint);
        if ($policy === null) {
            return false;
        }

        return $this->withLock($resource->client_id, $resource->endpoint_id, $resource->identity_hash,
            fn () => $this->captureLocked($resource->fresh(), $policy));
    }

    private function captureLocked(LinkedResource $resource, array $policy): bool
    {
        $disk = Storage::disk('integrations');
        $base = "linked-resources/{$resource->client_id}/{$resource->endpoint_id}/{$resource->id}";
        $stage = "{$base}/staging/content.part";
        $target = "{$base}/captured/content";

        try {
            if ($resource->state === LinkedResource::CAPTURED || $resource->captured_at !== null) {
                if (! $this->verified($target, $resource)) {
                    throw new CaptureException('integrity_failed');
                }
                if ($resource->state !== LinkedResource::CAPTURED) {
                    $resource->update(['state' => LinkedResource::CAPTURED, 'error_code' => null]);
                }

                return true;
            }
            // Metadata is committed before publication, allowing recovery after rename.
            if ($disk->exists($target)) {
                if (! $this->verified($target, $resource)) {
                    throw new CaptureException('publication_conflict');
                }
            } else {
                if (! $this->verified($stage, $resource)) {
                    if ($disk->exists($stage) && ! $disk->delete($stage)) {
                        throw new CaptureException('storage_failed');
                    }
                    $resource->update([
                        'state' => LinkedResource::CAPTURING,
                        'attempts' => $resource->attempts + 1,
                        'error_code' => null,
                        'staging_path' => $stage,
                        'storage_path' => null,
                        'sha256' => null,
                        'byte_size' => null,
                        'http_status' => null,
                    ]);
                    if (! $disk->makeDirectory(dirname($stage))) {
                        throw new CaptureException('storage_failed');
                    }
                    $sink = @fopen($disk->path($stage), 'x+b');
                    if ($sink === false) {
                        throw new CaptureException('storage_failed');
                    }
                    try {
                        if (! chmod($disk->path($stage), 0600)) {
                            throw new CaptureException('storage_failed');
                        }
                        $result = $this->downloader->download($resource->download_url, $policy, $sink);
                        if (! fflush($sink) || ! fsync($sink)) {
                            throw new CaptureException('storage_failed');
                        }
                    } finally {
                        fclose($sink);
                    }
                    $size = filesize($disk->path($stage));
                    $hash = hash_file('sha256', $disk->path($stage));
                    if ($size === false || $size !== $result['byte_size'] || $size > $policy['max_bytes'] || $hash === false) {
                        throw new CaptureException('integrity_failed');
                    }
                    $resource->update(['byte_size' => $size, 'sha256' => $hash, 'http_status' => $result['http_status']]);
                }
                if (! $disk->makeDirectory(dirname($target)) || ! $disk->move($stage, $target)) {
                    throw new CaptureException('storage_failed');
                }
            }
            if (! $this->verified($target, $resource)) {
                throw new CaptureException('integrity_failed');
            }
            $resource->update([
                'state' => LinkedResource::CAPTURED, 'storage_path' => $target,
                'staging_path' => null, 'captured_at' => now(), 'error_code' => null,
            ]);

            return true;
        } catch (\Throwable $e) {
            // Keep a verified stage for replay, discard incomplete transfers.
            if (! $this->verified($stage, $resource)) {
                $disk->delete($stage);
            }
            $code = $e instanceof CaptureException ? $e->getMessage() : 'capture_failed';
            $resource->update(['state' => LinkedResource::FAILED, 'error_code' => $code]);

            return false;
        }
    }

    private function verified(string $path, LinkedResource $resource): bool
    {
        $disk = Storage::disk('integrations');
        if ($resource->sha256 === null || $resource->byte_size === null || ! $disk->exists($path)) {
            return false;
        }
        $absolute = $disk->path($path);

        return filesize($absolute) === $resource->byte_size
            && hash_file('sha256', $absolute) === $resource->sha256;
    }

    private function withLock(int $clientId, int $endpointId, string $identity, callable $operation): bool
    {
        // Single-instance/local-disk contract: flock has no TTL and releases on crash.
        $disk = Storage::disk('integrations');
        $directory = 'linked-resources/.locks';
        if (! $disk->makeDirectory($directory)) {
            throw new CaptureException('storage_failed');
        }
        $lock = @fopen($disk->path("{$directory}/{$clientId}-{$endpointId}-{$identity}.lock"), 'c+b');
        if ($lock === false) {
            throw new CaptureException('storage_failed');
        }
        try {
            if (! chmod($disk->path("{$directory}/{$clientId}-{$endpointId}-{$identity}.lock"), 0600)) {
                throw new CaptureException('storage_failed');
            }
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                return false;
            }

            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
