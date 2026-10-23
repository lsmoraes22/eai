<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Compatibility with files written before the private integrations disk. */
class LegacyIntegrationStorage
{
    public function token(string $path): ?string
    {
        if (Storage::disk('integrations')->exists($path)) {
            return Storage::disk('integrations')->get($path);
        }
        if (Storage::disk('local')->exists($path)) {
            return Crypt::decryptString(Storage::disk('local')->get($path));
        }

        return null;
    }

    public function importDirectory(string $directory, bool $encrypted, bool $includePublic = false): void
    {
        foreach ($includePublic ? ['local', 'public'] : ['local'] as $sourceName) {
            $source = Storage::disk($sourceName);
            foreach ($source->files($directory) as $path) {
                $lock = Cache::lock('eai:legacy-import:'.hash('sha256', $path), 60);
                if (! $lock->get()) {
                    continue;
                }
                try {
                    if (! $source->exists($path)) {
                        continue;
                    }
                    $content = $source->get($path);
                    if ($encrypted && $sourceName === 'local') {
                        $content = Crypt::decryptString($content);
                    }
                    $target = Storage::disk('integrations');
                    // Already processed legacy inputs must never be reintroduced.
                    foreach ([$path, str_replace('/raw/', '/validated/', $path), str_replace('/raw/', '/refused/', $path), str_replace('/raw/', '/processed/', $path)] as $existing) {
                        if ($target->exists($existing)) {
                            if ($target->get($existing) !== $content) {
                                throw new RuntimeException('Legacy integration file conflict.');
                            }

                            continue 2;
                        }
                    }
                    if (! $target->put($path, $content)) {
                        throw new RuntimeException('Legacy integration file import failed.');
                    }
                    // Preserve originals; the private copy becomes the pipeline input.
                } finally {
                    $lock->release();
                }
            }
        }
    }
}
