<?php

declare(strict_types=1);

namespace BuTing\CliAppUpdater;

use RuntimeException;
use ZipArchive;

/** File transaction only. Database migrations and process reloads belong to the host application. */
final class Installer
{
    /** @var string */
    private $root;
    /** @var string */
    private $work;
    /** @var array */
    private $preserve;
    /** @var array */
    private $limits;

    public function __construct(string $root, string $work, array $preserve = [], array $limits = [])
    {
        $this->root = $root;
        $this->work = $work;
        $this->preserve = array_merge(['.git', '.env', '.user.ini', 'runtime', 'storage', 'upload', 'uploads', 'public/upload', 'public/uploads', 'public/storage'], $preserve);
        foreach ($this->preserve as $path) {
            Files::relative($path);
        }
        $this->limits = $limits + Manifest::LIMITS;
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

    /** A delta is usable only when the recorded base is still present on disk. */
    public function matchesBase(array $state, int $packageId): bool
    {
        if ($packageId < 1 || ($state['package_id'] ?? 0) !== $packageId || !isset($state['installed_files'])
            || !preg_match('/^[a-f0-9]{64}$/D', $state['package_sha256'] ?? '')) {
            return false;
        }
        foreach (Manifest::files($state['installed_files'], $this->limits) as $path => $file) {
            if ($this->protected((string) $path)) {
                continue;
            }
            $target = Files::target($this->root, (string) $path);
            if (!is_file($target) || filesize($target) !== $file['size'] || !hash_equals($file['sha256'], hash_file('sha256', $target))) {
                return false;
            }
        }
        return true;
    }

    public function install(string $archive, array $release, array $state, array $hooks = [], ?array $delta = null, ?array $targetFiles = null): array
    {
        $this->assertReady();
        $id = gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
        $stage = Files::directory($this->work . '/stage-' . $id);
        $backup = Files::directory($this->work . '/backups/' . $id);
        $recorded = false;
        try {
            $previous = Files::json($this->work . '/state.json');
            if ($delta !== null && !$this->matchesBase($previous, $delta['base_package_id'])) {
                throw new RuntimeException('Delta baseline changed before installation; check for updates again');
            }
            $entries = $this->extract($archive, $stage, $delta);
            $oldFiles = Manifest::files($previous['installed_files'] ?? [], $this->limits);
            $files = $delta === null ? [] : $oldFiles;
            foreach ($entries as $path => $entry) {
                $files[$path] = ['size' => $entry['size'], 'sha256' => hash_file('sha256', $stage . '/' . $path),
                    'mode' => ($entry['mode'] & 0111) ? 0755 : 0644];
            }
            $deleted = $delta === null ? array_keys(array_diff_key($oldFiles, $files)) : $delta['deleted'];
            $retained = [];
            foreach ($deleted as $path) {
                $path = Files::relative((string) $path);
                unset($files[$path]);
                if ($this->protected($path) || !isset($oldFiles[$path])) {
                    continue;
                }
                $target = Files::target($this->root, $path);
                if (!file_exists($target)) {
                    continue;
                }
                if (!is_file($target) || !hash_equals($oldFiles[$path]['sha256'], hash_file('sha256', $target))) {
                    $retained[] = $path;
                    continue;
                }
                // Never remove an untracked or locally modified file. Deletions use the same recovery journal.
                $entries[$path] = ['size' => 0, 'mode' => $oldFiles[$path]['mode'], 'delete' => true];
            }
            $files = array_filter($files, function ($path): bool { return !$this->protected((string) $path); }, ARRAY_FILTER_USE_KEY);
            $state['installed_files'] = Manifest::files($files, $this->limits);
            // Reject case-only renames and file/directory transitions instead of risking existing data.
            Manifest::files($files + $oldFiles, ['max_files' => $this->limits['max_files'] * 2,
                'max_expanded_bytes' => $this->limits['max_expanded_bytes'] * 2]);
            if ($targetFiles !== null) {
                $expected = array_filter($targetFiles, function ($path): bool { return !$this->protected((string) $path); }, ARRAY_FILTER_USE_KEY);
                if (count($expected) !== count($files) || array_diff_key($expected, $files)) {
                    throw new RuntimeException('Delta result does not match target manifest');
                }
                foreach ($expected as $path => $file) {
                    if ($file['size'] !== $files[$path]['size'] || $file['sha256'] !== $files[$path]['sha256'] || $file['mode'] !== $files[$path]['mode']) {
                        throw new RuntimeException('Delta result differs from target file: ' . $path);
                    }
                }
            }
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
                    if (!empty($entry['delete'])) {
                        if (is_file($target) && !unlink($target)) {
                            throw new RuntimeException('Cannot delete obsolete file: ' . $relative);
                        }
                    } else {
                        Files::copy($stage . '/' . $relative, $target, $entry['old_mode'] ?? $entry['mode']);
                    }
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
        return $release + ['backup_dir' => $backup, 'retained_files' => $retained];
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

    private function extract(string $archive, string $stage, ?array &$delta = null): array
    {
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('Package is not a valid ZIP archive');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > $this->limits['max_files'] + ($delta === null ? 0 : 1)) {
                throw new RuntimeException('Archive entry count exceeds limit or is empty');
            }
            if ($delta !== null) {
                $metadata = $zip->statName('update.json');
                if (!$metadata || $metadata['size'] > 16777216) {
                    throw new RuntimeException('Missing or oversized delta metadata');
                }
                $document = Json::decode($zip->getFromName('update.json'));
                if (!is_array($document) || ($document['schema'] ?? '') !== 'apps.delta.v1'
                    || ($document['base_package_id'] ?? null) !== $delta['base_package_id']
                    || ($document['target_package_id'] ?? null) !== $delta['target_package_id']
                    || !is_array($document['files'] ?? null) || !is_array($document['deleted'] ?? null)
                    || count($document['deleted']) > $this->limits['max_files']) {
                    throw new RuntimeException('Delta metadata does not match the selected packages');
                }
                foreach (['base_sha256', 'target_sha256', 'target_manifest_sha256'] as $field) {
                    if (($document[$field] ?? null) !== $delta[$field]) {
                        throw new RuntimeException('Delta package identity mismatch: ' . $field);
                    }
                }
                Manifest::files($document['files'], $this->limits);
                $seenDeleted = [];
                foreach ($document['deleted'] as $path) {
                    // PHP converts numeric filename keys to integers when generating array_keys().
                    if (!is_string($path) && !is_int($path)) {
                        throw new RuntimeException('Invalid deleted path');
                    }
                    $path = (string) $path;
                    Files::relative($path);
                    if (isset($document['files'][$path]) || isset($seenDeleted[strtolower($path)])) {
                        throw new RuntimeException('Conflicting delta deletion');
                    }
                    $seenDeleted[strtolower($path)] = true;
                }
                $delta += $document;
            }
            $entries = $seen = $spellings = [];
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $raw = $zip->getNameIndex($index, ZipArchive::FL_ENC_RAW);
                if (preg_match('//u', $stat['name']) !== 1 || (preg_match('/[^\x00-\x7f]/', $raw) && $raw !== $stat['name'])) {
                    throw new RuntimeException('Ambiguous ZIP filename encoding; use UTF-8');
                }
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
                $source = $relative;
                if ($delta !== null) {
                    if ($relative === 'update.json' && !$directory) {
                        continue;
                    }
                    if ($relative === 'files' && $directory) {
                        continue;
                    }
                    if (strpos($relative, 'files/') !== 0) {
                        throw new RuntimeException('Unexpected entry outside delta files directory');
                    }
                    $relative = substr($relative, 6);
                    if (!$directory && (!isset($delta['files'][$relative]) || $delta['files'][$relative]['size'] !== $stat['size'])) {
                        throw new RuntimeException('Delta archive does not match its file list');
                    }
                }
                if (!$directory && !$this->protected($relative)) {
                    $entries[$relative] = ['size' => $stat['size'], 'source' => $source, 'crc' => sprintf('%08x', $stat['crc']),
                        'mode' => $delta['files'][$relative]['mode'] ?? (0644 | (($attributes >> 16) & 0111))];
                }
            }
            foreach ($seen as $path => $directory) {
                for ($parent = dirname((string) $path); $parent !== '.'; $parent = dirname($parent)) {
                    if (isset($seen[$parent]) && !$seen[$parent]) {
                        throw new RuntimeException('Conflicting ZIP file and directory paths');
                    }
                }
            }
            if ($delta !== null) {
                foreach ($delta['files'] as $path => $file) {
                    if (!$this->protected((string) $path) && !isset($entries[$path])) {
                        throw new RuntimeException('Delta archive is missing a declared file');
                    }
                }
            }
            if (!$entries && $delta === null) {
                throw new RuntimeException('Archive contains no installable files');
            }
            $this->space($stage, $total);
            foreach ($entries as $relative => $entry) {
                $relative = (string) $relative;
                $target = $stage . '/' . $relative;
                Files::directory(dirname($target));
                $input = $zip->getStream($entry['source']);
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
                if ($delta !== null && !hash_equals($delta['files'][$relative]['sha256'], hash_file('sha256', $target))) {
                    throw new RuntimeException('Delta file SHA-256 mismatch');
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
