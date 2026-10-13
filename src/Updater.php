<?php

declare(strict_types=1);

namespace BuTing\CliAppUpdater;

use InvalidArgumentException;
use RuntimeException;

final class Updater
{
    private array $config;
    private string $work;
    private string $root;
    private HttpClient $http;
    private Installer $installer;
    private bool $machineRead = false;
    private ?string $machineId = null;

    public function __construct(array $config)
    {
        if (PHP_INT_SIZE < 8) {
            throw new RuntimeException('A 64-bit PHP runtime is required');
        }
        foreach (['base_url', 'product_code', 'product_key', 'project_dir', 'work_dir'] as $name) {
            if (!isset($config[$name]) || !is_string($config[$name]) || $config[$name] === '') {
                throw new InvalidArgumentException('Missing configuration: ' . $name);
            }
        }
        $this->config = $config + [
            'client_key' => '', 'machine_id' => null, 'upgrade_token' => '', 'domain' => '', 'client_name' => '',
            'version_code' => 0, 'channel' => 'stable', 'preserve' => [], 'http' => [],
            'max_package_bytes' => 2147483648, 'archive_limits' => [],
        ];
        if (!in_array($this->config['channel'], ['stable', 'beta'], true)) {
            throw new InvalidArgumentException('channel must be stable or beta');
        }
        self::version($this->config['version_code']);
        if (!is_int($this->config['max_package_bytes']) || $this->config['max_package_bytes'] < 1) {
            throw new InvalidArgumentException('max_package_bytes must be a positive integer');
        }
        if ($this->config['client_key'] !== '' && !preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $this->config['client_key'])) {
            throw new InvalidArgumentException('client_key must contain 8-64 letters, digits, underscores or hyphens');
        }
        if ($this->config['machine_id'] !== null && (!is_string($this->config['machine_id']) || strlen($this->config['machine_id']) > 512)) {
            throw new InvalidArgumentException('machine_id must be null or a local identifier of at most 512 bytes');
        }
        $this->config['domain'] = OfflineLicense::domain($this->config['domain']);
        if (!is_dir($config['project_dir'])) {
            throw new InvalidArgumentException('project_dir must already exist');
        }
        $this->root = Files::directory($config['project_dir']);
        $this->work = Files::directory($config['work_dir']);
        if (Files::within($this->work, $this->root) || Files::within($this->root, $this->work)) {
            throw new InvalidArgumentException('work_dir and project_dir must be separate directories, neither inside the other');
        }
        $this->http = new HttpClient($config['base_url'], $this->config['http']);
        $this->installer = new Installer($this->root, $this->work, $this->config['preserve'], $this->config['archive_limits']);
    }

    /** Persistent installation identity and last committed version; safe to display. */
    public function status(): array
    {
        return $this->locked(function (): array {
            $state = $this->state();
            $state['transaction_status'] = is_file($this->work . '/transaction.json')
                ? (Files::json($this->work . '/transaction.json')['status'] ?? 'unknown') : 'none';
            unset($state['identity'], $state['machine_tag']);
            return $state;
        });
    }

    /** Reads or initializes the local installation ID; no HTTP request is made. */
    public function getClientKey(): string
    {
        return $this->locked(function (): string {
            return $this->state()['client_key'];
        });
    }

    public function report(): array
    {
        return $this->locked(function (): array {
            return $this->request('client/report', true);
        });
    }

    public function authorize(): array
    {
        return $this->locked(function (): array {
            return $this->request('client/authorize', true);
        });
    }

    /** Includes a short-lived download ticket; never put the complete result in ordinary logs. */
    public function check(): array
    {
        return $this->locked(function (): array {
            $this->installer->assertReady();
            return $this->checkUpdate();
        });
    }

    /** Download only, without changing the installed version. */
    public function download(array $release): string
    {
        return $this->locked(function () use ($release): string {
            $this->validateRelease($release);
            return $this->downloadPackage($release);
        });
    }

    /** Synchronous in any SAPI. The caller owns request deadlines and application maintenance. */
    public function update(array $hooks = []): ?array
    {
        $this->validateHooks($hooks);
        return $this->locked(function () use ($hooks): ?array {
            $this->installer->assertReady();
            $release = $this->checkUpdate();
            if (!$release['update']) {
                return null;
            }
            $archive = $this->downloadPackage($release);
            try {
                $state = $this->state();
                $state['version_code'] = $release['version_code'];
                $state['version'] = $release['version'];
                $state['updated_at'] = gmdate(DATE_ATOM);
                return $this->installer->install($archive, self::summary($release), $state, $hooks);
            } finally {
                if (is_file($archive)) {
                    unlink($archive);
                }
            }
        });
    }

    /** Restores the most recent transaction's files and version state, never the database. */
    public function rollback(array $hooks = []): void
    {
        $this->validateHooks($hooks);
        $this->locked(function () use ($hooks): void {
            $this->state();
            $this->installer->rollback($hooks);
        });
    }

    public static function summary(array $release): array
    {
        return array_intersect_key($release, array_flip(['update', 'status_code', 'version', 'version_code', 'changelog', 'published_at']))
            + ['requirements' => $release['package']['requirements'] ?? ''];
    }

    private function checkUpdate(): array
    {
        $release = $this->request('upgrade/check', false);
        if (!isset($release['update']) || !is_bool($release['update'])) {
            throw new RuntimeException('Missing update flag');
        }
        if ($release['update']) {
            $this->validateRelease($release);
            if ($release['version_code'] <= $this->state()['version_code']) {
                throw new RuntimeException('Server returned a non-increasing version');
            }
        }
        return $release;
    }

    private function validateRelease(array $release): void
    {
        self::version($release['version_code'] ?? null);
        $package = $release['package'] ?? [];
        if (($release['update'] ?? false) !== true || !is_string($release['version'] ?? null)
            || !is_array($package) || ($package['platform'] ?? '') !== 'php'
            || (int) ($package['package_type'] ?? 0) !== 1
            || strtolower(pathinfo($package['file_name'] ?? '', PATHINFO_EXTENSION)) !== 'zip'
            || !preg_match('/^[a-f0-9]{64}$/D', $package['sha256'] ?? '')
            || !is_int($package['file_size'] ?? null) || $package['file_size'] < 1
            || $package['file_size'] > $this->config['max_package_bytes']) {
            throw new RuntimeException('Expected a full PHP ZIP package with valid size and SHA-256');
        }
    }

    private function downloadPackage(array $release): string
    {
        $directory = Files::directory($this->work . '/downloads');
        $part = $directory . '/' . bin2hex(random_bytes(12)) . '.part';
        $package = $release['package'];
        $available = disk_free_space($directory);
        if ($available === false || $available < $package['file_size'] + 16777216) {
            throw new RuntimeException('Insufficient free disk space for package download');
        }
        $this->http->download($package['file_url'], $part, $package['file_size'], $package['sha256']);
        $path = substr($part, 0, -5) . '.zip';
        if (!rename($part, $path)) {
            unlink($part);
            throw new RuntimeException('Cannot finalize verified download');
        }
        return $path;
    }

    private function request(string $route, bool $post): array
    {
        $state = $this->state();
        $headers = ['Product-Key' => $this->config['product_key'], 'Client-Key' => $state['client_key']];
        if ($this->config['upgrade_token'] !== '') {
            $headers['Upgrade-Token'] = $this->config['upgrade_token'];
        }
        $data = [
            'version_code' => $state['version_code'], 'platform' => 'php', 'channel' => $this->config['channel'],
            'domain' => $this->config['domain'], 'os_info' => ['system' => PHP_OS_FAMILY, 'php' => PHP_VERSION],
        ];
        if ($this->config['client_name'] !== '') {
            $data['client_name'] = $this->config['client_name'];
        }
        return $this->http->request('/api/' . $route . '/' . rawurlencode($this->config['product_code']), $headers, $data, $post);
    }

    private function state(): array
    {
        $path = $this->work . '/state.json';
        $identity = hash('sha256', rtrim($this->config['base_url'], '/') . "\n" . $this->config['product_code'] . "\n" . $this->root);
        if (!is_file($path)) {
            if (is_file($this->work . '/transaction.json')) {
                throw new RuntimeException('State is missing; restore work_dir from backup before continuing');
            }
            $machine = $this->config['client_key'] === '' ? $this->machine() : null;
            $tag = $machine === null ? null : ClientIdentity::tag($machine, $identity);
            $state = [
                'identity' => $identity,
                'client_key' => $this->config['client_key'] ?: ($tag === null ? bin2hex(random_bytes(16)) : substr($tag, 0, 32)),
                'client_key_source' => $this->config['client_key'] !== '' ? 'configured' : ($tag === null ? 'random' : 'machine'),
                'version_code' => $this->config['version_code'],
            ];
            if ($tag !== null) {
                $state['machine_tag'] = $tag;
            }
            Files::writeJson($path, $state);
        }
        $state = Files::json($path);
        if (($state['identity'] ?? '') !== $identity || !preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $state['client_key'] ?? '')
            || ($this->config['client_key'] !== '' && $this->config['client_key'] !== $state['client_key'])) {
            throw new RuntimeException('Installation state does not match product, directory or client_key');
        }
        self::version($state['version_code'] ?? null);
        if (isset($state['machine_tag'])) {
            $machine = $this->machine();
            if ($machine !== null && !hash_equals($state['machine_tag'], ClientIdentity::tag($machine, $identity))) {
                throw new RuntimeException('State belongs to a different machine; use a new work_dir for a copied installation');
            }
        }
        // Existing random IDs remain valid; never silently invalidate already approved licenses.
        $state['client_key_source'] = $state['client_key_source'] ?? 'legacy';
        return $state;
    }

    private function machine(): ?string
    {
        if (!$this->machineRead) {
            $this->machineId = $this->config['machine_id'] ?? ClientIdentity::detect();
            $this->machineId = $this->machineId === '' ? null : $this->machineId;
            $this->machineRead = true;
        }
        return $this->machineId;
    }

    private function locked(callable $action)
    {
        $lock = fopen($this->work . '/update.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open update lock');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another updater operation is running');
            }
            return $action();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function version($value): void
    {
        if (!is_int($value) || $value < 0 || $value > 9007199254740991) {
            throw new InvalidArgumentException('version_code must be an integer between 0 and 9007199254740991');
        }
    }

    private function validateHooks(array $hooks): void
    {
        foreach ($hooks as $name => $hook) {
            if (!in_array($name, ['before_apply', 'after_apply', 'after_commit', 'before_rollback', 'after_rollback'], true) || !is_callable($hook)) {
                throw new InvalidArgumentException('Unknown or invalid application hook: ' . $name);
            }
        }
    }
}
