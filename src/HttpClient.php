<?php

declare(strict_types=1);

namespace BuTing\CliAppUpdater;

use RuntimeException;

/** Native cURL transport. Credentials are sent only to the configured API origin. */
final class HttpClient
{
    private string $baseUrl;
    private array $options;

    public function __construct(string $baseUrl, array $options = [])
    {
        $this->options = $options + ['allow_http' => false, 'connect_timeout' => 10, 'timeout' => 30, 'download_timeout' => 1800, 'ca_file' => ''];
        $parts = parse_url($baseUrl);
        if (!$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['scheme'] ?? '', $this->options['allow_http'] ? ['http', 'https'] : ['https'], true)) {
            throw new \InvalidArgumentException('base_url must be a trusted HTTPS URL (HTTP requires allow_http)');
        }
        foreach (['connect_timeout', 'timeout', 'download_timeout'] as $name) {
            if (!is_int($this->options[$name]) || $this->options[$name] < 1) {
                throw new \InvalidArgumentException($name . ' must be a positive integer');
            }
        }
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function request(string $path, array $headers, array $data, bool $post = false): array
    {
        $url = $this->baseUrl . $path;
        if (!$post) {
            $url .= '?' . http_build_query($data, '', '&', PHP_QUERY_RFC3986);
        }
        $body = '';
        $status = $this->perform($url, $headers, static function ($curl, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 2097152) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        }, $post ? json_encode($data, JSON_THROW_ON_ERROR) : null, $this->options['timeout']);
        if ($status < 200 || $status >= 300) {
            // Do not echo server bodies: they may contain keys, tokens or HTML debug traces.
            throw new RuntimeException('API request rejected (HTTP ' . $status . ')', $status);
        }
        $response = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($response) || ($response['status'] ?? null) !== 1 || !is_array($response['data'] ?? null)) {
            throw new RuntimeException('API returned an invalid or unsuccessful response');
        }
        return $response['data'];
    }

    public function download(string $path, string $destination, int $size, string $sha256): void
    {
        // Ignore file_full_url: the platform's relative ticket route stays on the configured origin.
        if (!preg_match('~^/api/packages/[1-9][0-9]*/download/[a-f0-9]{64}$~D', $path)) {
            throw new RuntimeException('Invalid download ticket path');
        }
        $output = fopen($destination, 'xb');
        if ($output === false) {
            throw new RuntimeException('Cannot create download file');
        }
        $bytes = 0;
        $hash = hash_init('sha256');
        try {
            chmod($destination, 0600);
            $status = $this->perform($this->baseUrl . $path, [], static function ($curl, string $chunk) use ($output, $size, &$bytes, $hash): int {
                $length = strlen($chunk);
                if ($bytes + $length > $size || fwrite($output, $chunk) !== $length) {
                    return 0;
                }
                $bytes += $length;
                hash_update($hash, $chunk);
                return $length;
            }, null, $this->options['download_timeout']);
            if ($status !== 200) {
                throw new RuntimeException('Download rejected (HTTP ' . $status . '); check for updates again to obtain a fresh ticket', $status);
            }
            if ($bytes !== $size || !hash_equals($sha256, hash_final($hash))) {
                throw new RuntimeException('Package size or SHA-256 mismatch');
            }
            if (!fflush($output)) {
                throw new RuntimeException('Cannot flush download');
            }
        } catch (\Throwable $error) {
            fclose($output);
            unlink($destination);
            throw $error;
        }
        fclose($output);
    }

    private function perform(string $url, array $headers, callable $write, ?string $body, int $timeout): int
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Cannot initialize cURL');
        }
        try {
            $lines = ['Accept: application/json', 'User-Agent: CliAppUpdater/1.0'];
            foreach ($headers as $name => $value) {
                if (!is_string($value) || preg_match('/[\r\n\x00]/', $value)) {
                    throw new \InvalidArgumentException('Invalid request header');
                }
                $lines[] = $name . ': ' . $value;
            }
            $settings = [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => $this->options['connect_timeout'],
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_LOW_SPEED_LIMIT => 1,
                CURLOPT_LOW_SPEED_TIME => 60,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION => $write,
            ];
            if ($body !== null) {
                $settings[CURLOPT_POST] = true;
                $settings[CURLOPT_POSTFIELDS] = $body;
                $lines[] = 'Content-Type: application/json';
            }
            if ($this->options['ca_file'] !== '') {
                $settings[CURLOPT_CAINFO] = $this->options['ca_file'];
            }
            $settings[CURLOPT_HTTPHEADER] = $lines;
            curl_setopt_array($curl, $settings);
            if (curl_exec($curl) === false) {
                throw new RuntimeException('HTTP transport failed (cURL ' . curl_errno($curl) . '); no response credentials were logged');
            }
            return (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        } finally {
            curl_close($curl);
        }
    }
}
