<?php

declare(strict_types=1);

namespace BuTing\CliAppUpdater;

use RuntimeException;
use ZipArchive;

/** File transaction only. Database migrations and process reloads belong to the host application. */
final class Installer
{
    private string $root;
    private string $work;
    private array $preserve;
    private array $limits;

    public function __construct(string $root, string $work, array $preserve = [], array $limits = [])
    {
        $this->root = $root;
        $this->work = $work;
        $this->preserve = array_merge(['.git', '.env', '.user.ini', 'runtime', 'storage', 'upload', 'uploads', 'public/upload', 'public/uploads', 'public/storage'], $preserve);
        foreach ($this->preserve as $path) {
            Files::relative($path);
        }
        $this->limits = $limits + ['max_files' => 50000, 'max_expanded_bytes' => 10737418240];
        foreach ($this->limits as $limit) {
            if (!is_int($limit) || $limit < 1) {
                throw new \InvalidArgumentException('Archive limits must be positive integers');
            }
        }
    }

    public function assertReady(): void
    {
        if (is_file($this->work . '/transaction.json')) {
            $transaction = Files::json($this->work . '/transaction.json');
            if (!in_array($transaction['status'] ?? '', ['complete', 'rolled_back'], true)) {
                throw new RuntimeException('An interrupted update requires rollback before continuing');
            }
        }
    }

    public function install(string $archive, array $release, array $state, array $hooks = []): array
    {
        $this->assertReady();
        $id = gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
        $stage = Files::directory($this->work . '/stage-' . $id);
        $backup = Files::directory($this->work . '/backups/' . $id);
        $recorded = false;
        try {
            $entries = $this->extract($archive, $stage);
            $previous = Files::json($this->work . '/state.json');
            $createdDirectories = [];
            $backupBytes = 0;
            foreach ($entries as $relative => &$entry) {
                $relative = (string) $relative;
                $target = Files::target($this->root, $relative);
                if (file_exists($target) && !is_file($target)) {
                    throw new RuntimeException('A directory conflicts with an update file: ' . $relative);
                }
                $entry['existed'] = is_file($target);
                $entry['old_mode'] = $entry['existed'] ? fileperms($target) & 0777 : null;
                $parent = dirname($target);
                while (!is_dir($parent)) {
                    $createdDirectories[substr($parent, strlen($this->root) + 1)] = true;
                    if (file_exists($parent)) {
                        throw new RuntimeException('A file conflicts with an update directory');
                    }
                    $parent = dirname($parent);
                }
                if (!is_writable($parent)) {
                    throw new RuntimeException('Project directory is not writable: ' . $relative);
                }
                $backupBytes += $entry['existed'] ? filesize($target) : 0;
            }
            unset($entry);
            $this->space($this->work, $backupBytes);
            foreach ($entries as $relative => &$entry) {
                $relative = (string) $relative;
                if ($entry['existed']) {
                    Files::copy(Files::target($this->root, $relative), $backup . '/files/' . $relative, 0600);
                    $entry['old_sha256'] = hash_file('sha256', $backup . '/files/' . $relative);
                }
            }
            unset($entry);
            $this->space($this->root, array_sum(array_column($entries, 'size')));
            $transaction = [
                'id' => $id, 'status' => 'applying', 'project_dir' => $this->root, 'archive' => basename($archive),
                'release' => $release, 'previous_state' => $previous,
                'entries' => $entries, 'created_directories' => array_keys($createdDirectories),
            ];
            Files::writeJson($backup . '/manifest.json', $transaction);
            // Persist the complete recovery plan BEFORE changing the first application file.
            Files::writeJson($this->work . '/transaction.json', $transaction);
            $recorded = true;
            try {
                self::hook($hooks, 'before_apply', $release);
                foreach ($entries as $relative => $entry) {
                    $relative = (string) $relative;
                    $target = Files::target($this->root, $relative);
                    Files::copy($stage . '/' . $relative, $target, $entry['old_mode'] ?? $entry['mode']);
                }
                self::hook($hooks, 'after_apply', $release);
                Files::writeJson($this->work . '/state.json', $state);
                $transaction['status'] = 'complete';
                Files::writeJson($this->work . '/transaction.json', $transaction);
            } catch (\Throwable $error) {
                try {
                    $this->rollback();
                } catch (\Throwable $recoveryError) {
                    throw new RuntimeException('Update failed and file recovery is incomplete; keep maintenance enabled and run rollback', 0, $recoveryError);
                }
                throw new RuntimeException('Update failed; application files and version state were restored. Check application/database hooks before leaving maintenance', 0, $error);
            }
        } finally {
            Files::removeTree($stage);
            if (!$recorded) {
                Files::removeTree($backup);
            }
        }
        // A reload failure does not invalidate the already committed version or undo database work.
        try {
            self::hook($hooks, 'after_commit', $release);
        } catch (\Throwable $error) {
            throw new RuntimeException('Update is installed, but after_commit failed; finish cache/process/maintenance handling manually', 0, $error);
        }
        return $release + ['backup_dir' => $backup];
    }

    public function rollback(array $hooks = []): void
    {
        $path = $this->work . '/transaction.json';
        if (!is_file($path)) {
            throw new RuntimeException('No update is available for rollback');
        }
        $transaction = Files::json($path);
        if (($transaction['project_dir'] ?? '') !== $this->root || !preg_match('/^[0-9]{14}-[a-f0-9]{12}$/D', $transaction['id'] ?? '')) {
            throw new RuntimeException('Recovery manifest does not match this project');
        }
        if ($transaction['status'] === 'rolled_back') {
            return;
        }
        $backup = $this->work . '/backups/' . $transaction['id'];
        // Check EVERY backup before restoring anything; a missing backup must never delete live data.
        foreach ($transaction['entries'] as $relative => $entry) {
            $relative = (string) $relative;
            Files::target($this->root, $relative);
            if ($entry['existed'] && (!is_file($backup . '/files/' . $relative)
                || !hash_equals($entry['old_sha256'], hash_file('sha256', $backup . '/files/' . $relative)))) {
                throw new RuntimeException('Recovery backup is missing or damaged: ' . $relative);
            }
        }
        $transaction['status'] = 'rolling_back';
        Files::writeJson($path, $transaction);
        self::hook($hooks, 'before_rollback', $transaction['release']);
        foreach ($transaction['entries'] as $relative => $entry) {
            $relative = (string) $relative;
            $target = Files::target($this->root, $relative);
            if ($entry['existed']) {
                Files::copy($backup . '/files/' . $relative, $target, $entry['old_mode']);
            } elseif (is_file($target) && !unlink($target)) {
                throw new RuntimeException('Cannot remove newly installed file: ' . $relative);
            }
        }
        $directories = $transaction['created_directories'];
        usort($directories, static function (string $left, string $right): int {
            return strlen($right) <=> strlen($left);
        });
        foreach ($directories as $relative) {
            $directory = Files::target($this->root, $relative);
            if (is_dir($directory) && count(scandir($directory)) === 2 && !rmdir($directory)) {
                throw new RuntimeException('Cannot remove empty update directory');
            }
        }
        Files::writeJson($this->work . '/state.json', $transaction['previous_state']);
        $transaction['status'] = 'rolled_back';
        Files::writeJson($path, $transaction);
        Files::removeTree($this->work . '/stage-' . $transaction['id']);
        if (preg_match('/^[a-f0-9]{24}\.zip$/D', $transaction['archive'] ?? '')) {
            Files::removeTree($this->work . '/downloads/' . $transaction['archive']);
        }
        self::hook($hooks, 'after_rollback', $transaction['release']);
    }

    private function extract(string $archive, string $stage): array
    {
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('Package is not a valid ZIP archive');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > $this->limits['max_files']) {
                throw new RuntimeException('Archive entry count exceeds limit or is empty');
            }
            $entries = $seen = $spellings = [];
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $directory = substr($stat['name'], -1) === '/';
                $relative = Files::relative($directory ? substr($stat['name'], 0, -1) : $stat['name']);
                $key = strtolower($relative);
                $prefix = '';
                foreach (explode('/', $relative) as $segment) {
                    $prefix = $prefix === '' ? $segment : $prefix . '/' . $segment;
                    $folded = strtolower($prefix);
                    if (isset($spellings[$folded]) && $spellings[$folded] !== $prefix) {
                        throw new RuntimeException('Case-colliding ZIP directory or file names');
                    }
                    $spellings[$folded] = $prefix;
                }
                if (isset($seen[$key]) || !empty($stat['encryption_method'])) {
                    throw new RuntimeException('Duplicate path or encrypted ZIP entry');
                }
                $seen[$key] = $directory;
                $system = $attributes = 0;
                $zip->getExternalAttributesIndex($index, $system, $attributes);
                $type = ($attributes >> 16) & 0170000;
                if (!in_array($type, [0, 0100000, 0040000], true)) {
                    throw new RuntimeException('ZIP links and special files are forbidden');
                }
                $total += $stat['size'];
                if ($stat['size'] < 0 || $total > $this->limits['max_expanded_bytes']) {
                    throw new RuntimeException('Expanded archive exceeds size limit');
                }
                if (!$directory && !$this->protected($relative)) {
                    $entries[$relative] = ['size' => $stat['size'], 'crc' => sprintf('%08x', $stat['crc']), 'mode' => 0644 | (($attributes >> 16) & 0111)];
                }
            }
            foreach ($seen as $path => $directory) {
                for ($parent = dirname((string) $path); $parent !== '.'; $parent = dirname($parent)) {
                    if (isset($seen[$parent]) && !$seen[$parent]) {
                        throw new RuntimeException('Conflicting ZIP file and directory paths');
                    }
                }
            }
            if (!$entries) {
                throw new RuntimeException('Archive contains no installable files');
            }
            $this->space($stage, $total);
            foreach ($entries as $relative => $entry) {
                $relative = (string) $relative;
                $target = $stage . '/' . $relative;
                Files::directory(dirname($target));
                $input = $zip->getStream($relative);
                $output = fopen($target, 'xb');
                if ($input === false || $output === false) {
                    if (is_resource($input)) {
                        fclose($input);
                    }
                    if (is_resource($output)) {
                        fclose($output);
                    }
                    throw new RuntimeException('Cannot extract ZIP entry');
                }
                try {
                    $written = stream_copy_to_stream($input, $output, $entry['size'] + 1);
                } finally {
                    fclose($input);
                    fclose($output);
                }
                if ($written !== $entry['size'] || hash_file('crc32b', $target) !== $entry['crc']) {
                    throw new RuntimeException('ZIP entry failed size or CRC validation');
                }
            }
            return $entries;
        } finally {
            $zip->close();
        }
    }

    private function protected(string $relative): bool
    {
        foreach (explode('/', $relative) as $segment) {
            if (preg_match('/^\.env(?:\.|$)|^\.git$/i', $segment)) {
                return true;
            }
        }
        foreach ($this->preserve as $path) {
            if (Files::within($relative, $path)) {
                return true;
            }
        }
        return false;
    }

    private function space(string $path, int $bytes): void
    {
        $available = disk_free_space($path);
        if ($available === false || $available < $bytes + 16777216) {
            throw new RuntimeException('Insufficient free disk space for staging, backup or installation');
        }
    }

    public static function hook(array $hooks, string $name, array $release): void
    {
        if (isset($hooks[$name])) {
            if (!is_callable($hooks[$name])) {
                throw new \InvalidArgumentException('Hook is not callable: ' . $name);
            }
            if ($hooks[$name]($release) === false) {
                throw new RuntimeException('Application hook rejected update: ' . $name);
            }
        }
    }
}
