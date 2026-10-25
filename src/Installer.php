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
    /** @var IgnoreRules */
    private $exclude;
    /** @var array<string, float> Durations for this installation only. */
    private $timings = [];
    /** @var float */
    private $clock;

    public function __construct(string $root, string $work, array $preserve = [], array $limits = [], $exclude = [])
    {
        $this->root = $root;
        $this->work = $work;
        $this->preserve = array_merge(['.updater', 'updater.php', '.git', '.env', '.user.ini', 'runtime', 'storage', 'upload', 'uploads', 'public/upload', 'public/uploads', 'public/storage'], $preserve);
        $this->exclude = new IgnoreRules($exclude);
        // 工作目录可放在项目内；状态、锁、下载和备份永远不参与覆盖或清理。
        if (Files::within($work, $root)) {
            $this->preserve[] = substr($work, strlen(rtrim($root, '/')) + 1);
        }
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

    /** Rebuild missing metadata from the journal; never infer that an unfinished apply succeeded. */
    public function recoverState(): array
    {
        $transaction = $this->transaction();
        if (!is_array($transaction['previous_state'] ?? null)) {
            throw new RuntimeException('Recovery manifest has no previous installation state');
        }
        $state = $transaction['previous_state'];
        if ($transaction['status'] === 'complete') {
            $release = $transaction['release'] ?? [];
            if (!is_string($release['version'] ?? null) || $release['version'] === ''
                || !is_int($release['version_code'] ?? null)) {
                throw new RuntimeException('Recovery manifest has no committed version');
            }
            $state['version'] = $release['version'];
            $state['version_code'] = $release['version_code'];
            // The journal tracks changed files only, so it cannot recreate the new package baseline.
            unset($state['installed_files'], $state['package_id'], $state['package_sha256'],
                $state['package_origin'], $state['updated_at']);
        }
        return $state;
    }

    private function transaction(): array
    {
        $transaction = Files::json($this->work . '/transaction.json');
        if (($transaction['project_dir'] ?? '') !== $this->root
            || !is_string($transaction['id'] ?? null)
            || !preg_match('/^[0-9]{14}-[a-f0-9]{12}$/D', $transaction['id'])
            || !in_array($transaction['status'] ?? '', ['applying', 'complete', 'rolling_back', 'rolled_back'], true)) {
            throw new RuntimeException('Recovery manifest does not match this project or has an invalid status');
        }
        return $transaction;
    }

    /** A delta is usable only when the recorded base is still present on disk. */
    public function matchesBase(array $state, int $packageId): bool
    {
        if ($packageId < 1 || ($state['package_id'] ?? 0) !== $packageId || !isset($state['installed_files'])
            || !preg_match('/^[a-f0-9]{64}$/D', $state['package_sha256'] ?? '')) {
            return false;
        }
        $parents = [];
        foreach (Manifest::files($state['installed_files'], $this->limits) as $path => $file) {
            if ($this->protected((string) $path)) {
                continue;
            }
            $target = Files::target($this->root, (string) $path, $parents);
            if (!is_file($target) || filesize($target) !== $file['size'] || !hash_equals($file['sha256'], hash_file('sha256', $target))) {
                return false;
            }
        }
        return true;
    }

    public function install(string $archive, array $release, array $state, array $hooks = [], ?array $delta = null, ?array $targetFiles = null): array
    {
        $this->timings = [];
        $this->clock = microtime(true);
        $this->assertReady();
        $id = gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
        $stage = Files::directory($this->work . '/stage-' . $id);
        $backup = Files::directory($this->work . '/backups/' . $id);
        $recorded = false;
        try {
            $previous = Files::json($this->work . '/state.json');
            if ($delta !== null && !$this->matchesBase($previous, $delta['base_package_id'])) {
                throw new DeltaUnavailable('Delta baseline changed before installation');
            }
            $oldFiles = Manifest::files($previous['installed_files'] ?? [], $this->limits);
            if ($targetFiles !== null) {
                Manifest::files($targetFiles, $this->limits);
            }
            $this->checkpoint('baseline');
            [$entries, $packageFiles, $unchanged] = $this->extract($archive, $stage, $delta, $oldFiles, $backup, $targetFiles);
            $extracted = count($entries);
            $files = $delta === null ? [] : $oldFiles;
            foreach ($packageFiles as $path => $file) {
                $files[$path] = $file;
            }
            unset($packageFiles);
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
                    throw new RuntimeException('Installation result does not match target manifest');
                }
                foreach ($expected as $path => $file) {
                    if ($file['size'] !== $files[$path]['size'] || $file['sha256'] !== $files[$path]['sha256'] || $file['mode'] !== $files[$path]['mode']) {
                        throw new RuntimeException('Installation result differs from target file: ' . $path);
                    }
                }
            }
            // Without a manifest the hashes become available only after extraction.
            if ($delta === null && $targetFiles === null) {
                foreach ($entries as $path => $entry) {
                    $target = Files::target($this->root, (string) $path);
                    // Existing permissions are intentionally preserved, including for unchanged files.
                    if (empty($entry['delete']) && is_file($target) && filesize($target) === $entry['size']
                        && hash_equals($entry['sha256'], hash_file('sha256', $target))) {
                        unset($entries[$path]);
                        $unchanged++;
                    }
                }
            }
            unset($expected, $targetFiles, $files, $oldFiles);
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
            $this->checkpoint('plan_backup');
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
                        Files::install($stage . '/' . $entry['source'], $target, $entry['old_mode'] ?? $entry['mode']);
                    }
                }
                self::hook($hooks, 'after_apply', $release);
                $this->checkpoint('apply');
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
        $this->checkpoint('commit_cleanup');
        // A reload failure does not invalidate the already committed version or undo database work.
        try {
            self::hook($hooks, 'after_commit', $release);
        } catch (\Throwable $error) {
            throw new RuntimeException('Update is installed, but after_commit failed; finish cache/process/maintenance handling manually', 0, $error);
        }
        $this->checkpoint('after_commit');
        $deletedCount = count(array_filter($entries, static function (array $entry): bool { return !empty($entry['delete']); }));
        return $release + ['backup_dir' => $backup, 'retained_files' => $retained, 'unchanged_files' => $unchanged,
            'updated_files' => count($entries) - $deletedCount,
            'deleted_files' => $deletedCount, 'extracted_files' => $extracted, 'timings' => $this->timings];
    }

    public function rollback(array $hooks = []): void
    {
        $path = $this->work . '/transaction.json';
        if (!is_file($path)) {
            throw new RuntimeException('No update is available for rollback');
        }
        $transaction = $this->transaction();
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

    private function extract(
        string $archive,
        string $stage,
        ?array &$delta,
        array $oldFiles,
        string $backup,
        ?array $targetFiles = null
    ): array {
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
                        if ($field === 'base_sha256') {
                            throw new DeltaUnavailable('Delta package uses a different baseline');
                        }
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
            // Audit every ZIP entry even when its payload will not be extracted.
            $packageFiles = [];
            $unchanged = 0;
            $declared = $delta['files'] ?? $targetFiles;
            if ($declared !== null) {
                foreach ($declared as $path => $file) {
                    if ($this->protected((string) $path)) {
                        continue;
                    }
                    if (!isset($entries[$path]) || $entries[$path]['size'] !== $file['size']
                        || (($entries[$path]['mode'] & 0111) ? 0755 : 0644) !== $file['mode']) {
                        throw new RuntimeException('Archive differs from declared file: ' . $path);
                    }
                    $packageFiles[$path] = $file;
                }
                if (count($packageFiles) !== count($entries)) {
                    throw new RuntimeException('Archive contains undeclared files');
                }
            }
            unset($seen, $spellings);
            $this->checkpoint('archive_scan');
            $deleted = $delta === null ? array_keys(array_diff_key($oldFiles, $entries)) : $delta['deleted'];
            if ($declared !== null) {
                $parents = [];
                foreach ($entries as $path => $entry) {
                    $target = Files::target($this->root, (string) $path, $parents);
                    $file = $packageFiles[$path];
                    if (is_file($target) && filesize($target) === $file['size']
                        && hash_equals($file['sha256'], hash_file('sha256', $target))) {
                        unset($entries[$path]);
                        $unchanged++;
                    }
                }
            }
            $this->checkpoint('local_compare');
            $this->preflight($entries, $oldFiles, $deleted, $backup, array_sum(array_column($entries, 'size')));
            $this->checkpoint('preflight');
            // The whitelist contains files only. extractTo keeps one archive handle open.
            // Bound each batch and verify its actual output before extracting the next batch.
            foreach (array_chunk(array_keys($entries), 256) as $paths) {
                $sources = [];
                foreach ($paths as $path) {
                    $source = $entries[$path]['source'];
                    if (PHP_OS_FAMILY === 'Windows' && preg_match('/[^\x00-\x7f]/', $source)) {
                        $this->extractUnicode($zip, $stage, $source);
                    } else {
                        $sources[] = $source;
                    }
                }
                if ($sources && !@$zip->extractTo($stage, $sources)) {
                    throw new RuntimeException('Cannot extract ZIP batch: ' . $zip->getStatusString());
                }
                foreach ($paths as $path) {
                    $entry = $entries[$path];
                    $target = $stage . '/' . $entry['source'];
                    if (!is_file($target) || filesize($target) !== $entry['size']) {
                        throw new RuntimeException('ZIP entry failed size validation: ' . $path);
                    }
                    $hashes = $this->hashes($target);
                    if ($hashes['crc32b'] !== $entry['crc']) {
                        throw new RuntimeException('ZIP entry failed CRC validation: ' . $path);
                    }
                    if ($declared !== null && !hash_equals($packageFiles[$path]['sha256'], $hashes['sha256'])) {
                        throw new RuntimeException('Manifest file SHA-256 mismatch: ' . $path);
                    }
                    $entries[$path]['sha256'] = $hashes['sha256'];
                    if ($declared === null) {
                        $packageFiles[$path] = ['size' => $entry['size'], 'sha256' => $hashes['sha256'],
                            'mode' => ($entry['mode'] & 0111) ? 0755 : 0644];
                    }
                }
            }
            $this->checkpoint('extract_verify');
            return [$entries, $packageFiles, $unchanged];
        } finally {
            $zip->close();
        }
    }

    /** Some Windows builds flatten UTF-8 directory names. Isolate native output before moving it. */
    private function extractUnicode(ZipArchive $zip, string $stage, string $source): void
    {
        $scratch = Files::directory($stage . '/.entry-' . bin2hex(random_bytes(8)));
        try {
            if (!@$zip->extractTo($scratch, [$source])) {
                throw new RuntimeException('Cannot extract UTF-8 ZIP entry: ' . $source);
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($scratch, \FilesystemIterator::SKIP_DOTS));
            $extracted = null;
            foreach ($files as $file) {
                if ($extracted !== null || !$file->isFile() || $file->isLink()) {
                    throw new RuntimeException('Unexpected native ZIP extraction result: ' . $source);
                }
                $extracted = $file->getPathname();
            }
            $target = $stage . '/' . $source;
            Files::directory(dirname($target));
            if ($extracted === null || !rename($extracted, $target)) {
                throw new RuntimeException('Cannot stage UTF-8 ZIP entry: ' . $source);
            }
        } finally {
            Files::removeTree($scratch);
        }
    }

    /** Check conservative space and write/read prerequisites before expanding any application file. */
    private function preflight(array $entries, array $oldFiles, array $deleted, string $backup, int $expandedBytes): void
    {
        $paths = array_fill_keys(array_keys($entries), true);
        foreach ($deleted as $path) {
            if (isset($oldFiles[$path]) && !$this->protected((string) $path)) {
                $paths[$path] = true;
            }
        }
        $checked = [];
        $backupBytes = 0;
        $this->probeDirectory($this->work);
        $this->probeDirectory($backup);
        foreach ($paths as $relative => $unused) {
            $target = Files::target($this->root, (string) $relative);
            if (!isset($entries[$relative]) && !is_file($target)) {
                continue; // Locally replaced obsolete paths are retained, not deletion targets.
            }
            if (file_exists($target)) {
                if (!is_file($target)) {
                    throw new RuntimeException('A directory conflicts with an update file: ' . $target);
                }
                if (!is_readable($target) || (PHP_OS_FAMILY === 'Windows' && !is_writable($target))) {
                    throw new RuntimeException('Cannot read or replace update target: ' . $target);
                }
                $backupBytes += filesize($target);
            }
            $parent = dirname($target);
            while (!is_dir($parent)) {
                if (file_exists($parent)) {
                    throw new RuntimeException('A file conflicts with an update directory: ' . $parent);
                }
                $parent = dirname($parent);
            }
            if (!isset($checked[$parent])) {
                $this->probeDirectory($parent);
                $checked[$parent] = true;
            }
        }
        $this->space($this->work, $expandedBytes + $backupBytes);
        $this->space($this->root, $expandedBytes);
    }

    private function probeDirectory(string $path): void
    {
        if (!is_writable($path)) {
            throw new RuntimeException('Update directory is not writable: ' . $path);
        }
        $probe = $path . '/.updater-probe-' . bin2hex(random_bytes(8));
        $handle = @fopen($probe, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Cannot create update files in directory: ' . $path);
        }
        fclose($handle);
        if (!@unlink($probe)) {
            throw new RuntimeException('Cannot remove update files in directory: ' . $path);
        }
    }

    private function checkpoint(string $phase): void
    {
        $now = microtime(true);
        $this->timings[$phase] = round($now - ($this->clock ?? $now), 6);
        $this->clock = $now;
    }

    /** One disk read feeds both checks; reuse SHA-256 for manifest and unchanged-file detection. */
    private function hashes(string $path): array
    {
        $input = fopen($path, 'rb');
        if ($input === false) {
            throw new RuntimeException('Cannot verify extracted file: ' . $path);
        }
        $sha = hash_init('sha256');
        $crc = hash_init('crc32b');
        try {
            while (!feof($input)) {
                $data = fread($input, 1048576);
                if ($data === false || ($data === '' && !feof($input))) {
                    throw new RuntimeException('Cannot read extracted file: ' . $path);
                }
                hash_update($sha, $data);
                hash_update($crc, $data);
            }
        } finally {
            fclose($input);
        }
        return ['sha256' => hash_final($sha), 'crc32b' => hash_final($crc)];
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
        return $this->exclude->matches($relative);
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
