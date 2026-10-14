<?php

declare(strict_types=1);

namespace BuTing\CliAppUpdater;

use RuntimeException;

/** Checked JSON operations for PHP 7.2+, without global polyfills. */
final class Json
{
    public static function decode(string $json, int $depth = 64)
    {
        $value = json_decode($json, true, $depth);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid JSON: ' . json_last_error_msg());
        }
        return $value;
    }

    public static function encode(array $value, int $flags = 0): string
    {
        $json = json_encode($value, $flags);
        if ($json === false) {
            throw new RuntimeException('Cannot encode JSON: ' . json_last_error_msg());
        }
        return $json;
    }
}
