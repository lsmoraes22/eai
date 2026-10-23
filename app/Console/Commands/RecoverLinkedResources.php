<?php

namespace App\Console\Commands;

use App\Models\CadEndpoint;
use App\Models\LinkedResource;
use App\Services\LinkedResources\ResourceCapture;
use App\Services\LinkedResources\ResourcePolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RecoverLinkedResources extends Command
{
    protected $signature = 'app:recover-linked-resources {--endpoint=} {--resource=}';

    protected $description = 'Redescobre referências preservadas e recupera capturas privadas interrompidas.';

    public function handle(ResourceCapture $capture, ResourcePolicy $policies): int
    {
        $failed = false;
        // An empty configuration is a no-op; no resource table access is needed.
        $ids = array_keys(config('linked_resources.endpoints', []));
        $endpoints = CadEndpoint::whereIn('id', $ids)->where('ativo', true)
            ->when($this->option('endpoint'), fn ($q, $id) => $q->whereKey($id))->get();

        foreach ($endpoints as $endpoint) {
            try {
                if ($policies->forEndpoint($endpoint) === null) {
                    continue;
                }
                if (! $this->option('resource')) {
                    $code = $endpoint->client->code ?: Str::slug($endpoint->client->name);
                    $base = 'polling/'.$code.'/json/incoming/'.Str::slug($endpoint->nome);
                    $paths = [];
                    foreach (['raw', 'validated', 'processed', 'refused'] as $state) {
                        $paths = array_merge($paths, Storage::disk('integrations')->files("{$base}/{$state}"));
                    }
                    // Fetch filenames start with a timestamp; replay oldest first,
                    // regardless of which pipeline folder currently holds the parent.
                    usort($paths, fn ($a, $b) => strcmp(basename($a), basename($b)));
                    foreach ($paths as $path) {
                        try {
                            if (! $capture->discover($endpoint, Storage::disk('integrations')->get($path), $path, false)) {
                                $failed = true;
                            }
                        } catch (\Throwable) {
                            $failed = true;
                            $this->error('Falha na redescoberta para endpoint ID '.$endpoint->id);
                        }
                    }
                }
                LinkedResource::where('endpoint_id', $endpoint->id)
                    ->when($this->option('resource'), fn ($q, $id) => $q->whereKey($id))
                    ->chunkById(100, function ($resources) use ($capture, &$failed) {
                        foreach ($resources as $resource) {
                            if (! $capture->recover($resource)) {
                                $failed = true;
                                $this->error('Captura pendente: recurso ID '.$resource->id);
                            }
                        }
                    });
            } catch (\Throwable) {
                $failed = true;
                $this->error('Falha na recuperação para endpoint ID '.$endpoint->id);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
