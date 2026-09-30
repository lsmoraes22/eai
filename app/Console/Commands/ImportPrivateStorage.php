<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ImportPrivateStorage extends Command
{
    protected $signature = 'app:import-private-storage {--source=public : Legacy disk: public or local} {--copy : Copy files after stopping all integration writers}';
    protected $description = 'Inspect or copy legacy public integration files into private storage; never delete or overwrite originals.';

    public function handle(): int
    {
        $sourceName = $this->option('source');
        if (!in_array($sourceName, ['public', 'local'], true)) {
            $this->error('Source must be public or local.');
            return self::INVALID;
        }
        $source = Storage::disk($sourceName);
        $target = Storage::disk('integrations');
        // Only application-owned integration directories; do not copy unrelated public assets.
        $roots = array_combine(
            ['token', 'tokens', 'polling', 'webhooks', 'clients', 'temp/xsd', 'temp/json_schema'],
            ['token', 'tokens', 'polling', 'webhooks', 'clients', 'temp/xsd', 'temp/json_schema']
        );
        if ($sourceName === 'local') {
            // The old ClientStorageServices used this prefix on the default local disk.
            $roots['public/clients'] = 'clients';
        }
        $copied = $identical = $pending = $conflicts = 0;

        try {
            foreach ($roots as $root => $destinationRoot) {
                foreach ($source->allFiles($root) as $path) {
                    $destination = $destinationRoot . substr($path, strlen($root));
                    $sourceHash = $this->checksum($source, $path);
                    if ($target->exists($destination)) {
                        if (hash_equals($sourceHash, $this->checksum($target, $destination))) {
                            $identical++;
                        } else {
                            $conflicts++;
                            $this->error("Conflicting file; preserved both versions: {$path}");
                        }
                        continue;
                    }

                    if (!$this->option('copy')) {
                        $pending++;
                        continue;
                    }

                    $stream = $source->readStream($path);
                    if (!is_resource($stream)) {
                        throw new \RuntimeException('Could not open legacy file.');
                    }
                    try {
                        if (!$target->writeStream($destination, $stream)) {
                            throw new \RuntimeException('Private storage write failed.');
                        }
                    } finally {
                        fclose($stream);
                    }
                    if (!hash_equals($sourceHash, $this->checksum($target, $destination))) {
                        throw new \RuntimeException('Copied file checksum mismatch; keep writers stopped.');
                    }
                    $copied++;
                }
            }
        } catch (\Throwable $e) {
            $this->error('Import failed (' . $e::class . '). Originals were not deleted; keep writers stopped and inspect the private copy.');
            return self::FAILURE;
        }

        $this->info("Copied: {$copied}; identical: {$identical}; pending: {$pending}; conflicts: {$conflicts}.");
        $this->warn('Legacy public files remain in place. Block HTTP access before transition; archive/remove them separately after verification.');
        return $conflicts > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function checksum($disk, string $path): string
    {
        $stream = $disk->readStream($path);
        if (!is_resource($stream)) {
            throw new \RuntimeException('Could not read file for verification.');
        }
        try {
            $hash = hash_init('sha256');
            if (hash_update_stream($hash, $stream) === false) {
                throw new \RuntimeException('Could not verify file.');
            }
            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }
}
