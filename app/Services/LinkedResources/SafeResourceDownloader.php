<?php

namespace App\Services\LinkedResources;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class SafeResourceDownloader
{
    /** @param resource $sink */
    public function download(string $url, array $policy, $sink): array
    {
        $started = microtime(true);
        $parts = parse_url($url);
        if (strlen($url) > 16384 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || ! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new CaptureException('unsafe_url');
        }
        $host = strtolower($parts['host'] ?? '');
        if (! in_array($host, $policy['allowed_hosts'], true) || filter_var($host, FILTER_VALIDATE_IP)) {
            throw new CaptureException('host_not_allowed');
        }
        if (! extension_loaded('curl')) {
            throw new CaptureException('curl_unavailable');
        }
        $ips = $this->resolve($host, min($policy['connect_timeout'], $policy['timeout']));
        if ($ips === []) {
            throw new CaptureException('dns_failed');
        }
        foreach ($ips as $ip) {
            $packed = @inet_pton($ip);
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)
                || $packed === false
                || (strlen($packed) === 4 && ord($packed[0]) >= 224)
                || (strlen($packed) === 16 && (
                    (ord($packed[0]) & 0xE0) !== 0x20
                    || substr($packed, 0, 2) === "\x20\x02" // 6to4 can embed private IPv4.
                    || (substr($packed, 0, 2) === "\x20\x01" && ord($packed[2]) < 2) // Transition/special /23.
                ))) {
                throw new CaptureException('unsafe_address');
            }
        }
        $remaining = (int) floor(($policy['timeout'] - (microtime(true) - $started)) * 1000);
        if ($remaining < 1) {
            throw new CaptureException('timeout');
        }

        $bytes = 0;
        $declaredSize = null;
        $headerBytes = 0;
        $failure = null;
        $ip = str_contains($ips[0], ':') ? '['.$ips[0].']' : $ips[0];
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '',
            CURLOPT_NETRC => CURL_NETRC_IGNORED,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => ["{$host}:443:{$ip}"],
            CURLOPT_CONNECTTIMEOUT_MS => min($policy['connect_timeout'] * 1000, $remaining),
            CURLOPT_TIMEOUT_MS => $remaining,
            CURLOPT_HTTPHEADER => ['Accept: application/octet-stream', 'Accept-Encoding: identity'],
            CURLOPT_HTTP_CONTENT_DECODING => false,
            CURLOPT_HEADERFUNCTION => function ($curl, string $header) use (&$headerBytes, &$declaredSize, &$failure, $policy): int {
                $headerBytes += strlen($header);
                if ($headerBytes > 65536) {
                    $failure = 'headers_too_large';
                } elseif (preg_match('/^Content-Length:\s*(\d+)/i', $header, $match) && (float) $match[1] > $policy['max_bytes']) {
                    $failure = 'too_large';
                } elseif (preg_match('/^Content-Length:\s*(\d+)/i', $header, $match)) {
                    $declaredSize = (int) $match[1];
                } elseif (preg_match('/^Content-Encoding:\s*(\S+)/i', $header, $match) && strtolower($match[1]) !== 'identity') {
                    $failure = 'unsupported_encoding';
                }

                return $failure === null ? strlen($header) : 0;
            },
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use ($sink, &$bytes, &$failure, $policy): int {
                if ($bytes + strlen($chunk) > $policy['max_bytes']) {
                    $failure = 'too_large';

                    return 0;
                }
                $written = fwrite($sink, $chunk);
                if ($written !== strlen($chunk)) {
                    $failure = 'storage_failed';

                    return 0;
                }
                $bytes += $written;

                return $written;
            },
        ];

        try {
            $status = $this->execute($options);
        } catch (CaptureException $e) {
            throw new CaptureException($failure ?? $e->getMessage());
        }
        if ($failure !== null) {
            throw new CaptureException($failure);
        }
        // Reject redirects and partial responses; do not persist provider bodies/errors.
        if ($status !== 200) {
            throw new CaptureException('http_'.$status);
        }
        if ($declaredSize !== null && $declaredSize !== $bytes) {
            throw new CaptureException('incomplete_response');
        }
        if (! fflush($sink)) {
            throw new CaptureException('storage_failed');
        }

        return ['byte_size' => $bytes, 'http_status' => $status];
    }

    protected function resolve(string $host, int $timeout): array
    {
        // Native DNS can outlive cURL timeouts. Bound it in a child process using
        // the already-installed Symfony Process component; no shell interpolation.
        $process = new Process([PHP_BINARY, '-r',
            '$records = @dns_get_record($argv[1], DNS_A | DNS_AAAA); echo json_encode($records ?: []);',
            $host,
        ]);
        $process->setTimeout($timeout);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new CaptureException('timeout');
        } catch (\Throwable) {
            throw new CaptureException('dns_failed');
        }
        if (! $process->isSuccessful()) {
            throw new CaptureException('dns_failed');
        }
        $records = json_decode($process->getOutput(), true);
        $ips = [];
        foreach ($records ?: [] as $record) {
            if (isset($record['ip']) || isset($record['ipv6'])) {
                $ips[] = $record['ip'] ?? $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    protected function execute(array $options): int
    {
        if (! extension_loaded('curl')) {
            throw new CaptureException('curl_unavailable');
        }
        // A new handle has no original request headers, cookies, auth or proxy state.
        $curl = curl_init();
        try {
            curl_setopt_array($curl, $options);
            if (curl_exec($curl) === false) {
                throw new CaptureException(curl_errno($curl) === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'transport_failed');
            }

            return (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($curl);
        }
    }
}
