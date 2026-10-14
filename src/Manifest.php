<?php

declare(strict_types=1);

namespace BuTing\CliAppUpdater;

use RuntimeException;

/** Internal validation shared by stored baselines and server-generated delta metadata. */
final class Manifest
{
    public const LIMITS = ['max_files' => 50000, 'max_expanded_bytes' => 10737418240];

    public static function files(array $files, array $limits = []): array
    {
        $limits += self::LIMITS;
        if (count($files) > $limits['max_files']) {
            throw new RuntimeException('Manifest contains too many files');
        }
        $total = 0;
        $spellings = [];
        foreach ($files as $path => $file) {
            $path = Files::relative((string) $path);
            if (!is_array($file) || !is_int($file['size'] ?? null) || $file['size'] < 0
                || !is_string($file['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $file['sha256'])
                || !in_array($file['mode'] ?? null, [0644, 0755], true)) {
                throw new RuntimeException('Invalid manifest file: ' . $path);
            }
            $total += $file['size'];
            if ($total > $limits['max_expanded_bytes']) {
                throw new RuntimeException('Manifest exceeds expanded size limit');
            }
            $prefix = '';
            foreach (explode('/', $path) as $segment) {
                $prefix = $prefix === '' ? $segment : $prefix . '/' . $segment;
                $folded = strtolower($prefix);
                if (isset($spellings[$folded]) && $spellings[$folded] !== $prefix) {
                    throw new RuntimeException('Case-colliding manifest paths');
                }
                $spellings[$folded] = $prefix;
            }
            for ($parent = dirname($path); $parent !== '.'; $parent = dirname($parent)) {
                if (isset($files[$parent])) {
                    throw new RuntimeException('Conflicting manifest file and directory');
                }
            }
        }
        return $files;
    }
}
