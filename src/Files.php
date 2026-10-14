<?php

declare(strict_types=1);

namespace BuTing\CliAppUpdater;

use RuntimeException;

/** Internal filesystem operations; all update paths are relative to a trusted directory. */
final class Files
{
    public static function directory(string $path, int $mode = 0700): string
    {
        if (!is_dir($path) && !mkdir($path, $mode, true) && !is_dir($path)) {
            throw new RuntimeException('Cannot create directory: ' . $path);
        }
        $resolved = realpath($path);
        if ($resolved === false) {
            throw new RuntimeException('Cannot resolve directory: ' . $path);
        }
        return str_replace('\\', '/', $resolved);
    }

    public static function relative(string $path): string
    {
        // Deliberately portable: reject Windows aliases, ADS, traversal and backslashes on every OS.
        if ($path === '' || strlen($path) > 4096 || preg_match('//u', $path) !== 1 || preg_match('/[\\\\:\x00-\x1f\x7f]/', $path)) {
            throw new RuntimeException('Unsafe relative path');
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || preg_match('/[. ]$/', $part)
                || preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $part)) {
                throw new RuntimeException('Unsafe relative path: ' . $path);
            }
        }
        return $path;
    }

    public static function target(string $root, string $relative): string
    {
        self::relative($relative);
        $path = $root;
        foreach (explode('/', $relative) as $part) {
            $path .= '/' . $part;
            if (is_link($path)) {
                throw new RuntimeException('Symbolic links are not update targets: ' . $relative);
            }
            if (file_exists($path)) {
                $actual = str_replace('\\', '/', (string) realpath($path));
                if (!self::within($actual, $root)) {
                    throw new RuntimeException('Target escapes project directory: ' . $relative);
                }
            }
        }
        return $path;
    }

    public static function within(string $path, string $root): bool
    {
        return strcasecmp($path, $root) === 0 || stripos($path, rtrim($root, '/') . '/') === 0;
    }

    public static function json(string $path): array
    {
        $value = Json::decode((string) file_get_contents($path));
        if (!is_array($value)) {
            throw new RuntimeException('Expected a JSON object: ' . $path);
        }
        return $value;
    }

    public static function writeJson(string $path, array $value): void
    {
        self::write($path, Json::encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    public static function write(string $path, string $content, int $mode = 0600): void
    {
        self::replace($path, static function ($output) use ($content): void {
            $length = strlen($content);
            $offset = 0;
            while ($offset < $length) {
                $written = fwrite($output, substr($content, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Cannot write file');
                }
                $offset += $written;
            }
        }, $mode);
    }

    public static function copy(string $source, string $target, int $mode = 0644): void
    {
        $input = fopen($source, 'rb');
        if ($input === false) {
            throw new RuntimeException('Cannot open source file');
        }
        try {
            self::replace($target, static function ($output) use ($input, $source): void {
                $size = stream_copy_to_stream($input, $output);
                if ($size === false || $size !== filesize($source)) {
                    throw new RuntimeException('Incomplete file copy');
                }
            }, $mode);
        } finally {
            fclose($input);
        }
    }

    private static function replace(string $path, callable $write, int $mode): void
    {
        self::directory(dirname($path), 0755);
        $temp = $path . '.updater-' . bin2hex(random_bytes(8));
        $output = fopen($temp, 'xb');
        if ($output === false) {
            throw new RuntimeException('Cannot create temporary file');
        }
        try {
            if (!chmod($temp, $mode)) {
                throw new RuntimeException('Cannot set file permissions');
            }
            $write($output);
            if (!fflush($output)) {
                throw new RuntimeException('Cannot flush file');
            }
            fclose($output);
            $output = null;
            if (!rename($temp, $path)) {
                throw new RuntimeException('Cannot replace file: ' . $path);
            }
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    public static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!unlink($path)) {
                throw new RuntimeException('Cannot remove temporary file');
            }
        } elseif (is_dir($path)) {
            foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
                self::removeTree($entry->getPathname());
            }
            if (!rmdir($path)) {
                throw new RuntimeException('Cannot remove temporary directory');
            }
        }
    }
}
