<?php

declare(strict_types=1);

namespace BuTing\CliAppUpdater;

use InvalidArgumentException;

/** Ordered, project-relative ignore patterns; built-in protections are applied separately. */
final class IgnoreRules
{
    /** @var array */
    private $rules = [];

    public function __construct($patterns)
    {
        if (is_string($patterns)) {
            $patterns = preg_split('/\r\n|\r|\n/', $patterns);
        }
        if (!is_array($patterns)) {
            throw new InvalidArgumentException('exclude must be a string or an array of strings');
        }
        foreach ($patterns as $pattern) {
            if (!is_string($pattern)) {
                throw new InvalidArgumentException('Each exclude pattern must be a string');
            }
            $pattern = trim($pattern);
            if ($pattern === '' || $pattern[0] === '#') {
                continue;
            }
            $excluded = $pattern[0] !== '!';
            if (!$excluded) {
                $pattern = substr($pattern, 1);
            }
            if ($pattern === '' || strpos($pattern, '\\') !== false || preg_match('~(^|/)\.\.(/|$)|[\x00-\x1f]~', $pattern)) {
                throw new InvalidArgumentException('Invalid exclude pattern');
            }
            $anchored = $pattern[0] === '/' || strpos(rtrim($pattern, '/'), '/') !== false;
            $directory = substr($pattern, -1) === '/';
            $pattern = trim($pattern, '/');
            if ($pattern === '') {
                throw new InvalidArgumentException('Exclude pattern cannot be the project root');
            }
            $regex = '';
            for ($i = 0, $length = strlen($pattern); $i < $length; $i++) {
                if (substr($pattern, $i, 3) === '**/') {
                    $regex .= '(?:[^/]+/)*';
                    $i += 2;
                } elseif (substr($pattern, $i, 2) === '**') {
                    $regex .= '.*';
                    $i++;
                } elseif ($pattern[$i] === '*') {
                    $regex .= '[^/]*';
                } elseif ($pattern[$i] === '?') {
                    $regex .= '[^/]';
                } else {
                    $regex .= preg_quote($pattern[$i], '~');
                }
            }
            $this->rules[] = ['~' . ($anchored ? '^' : '(?:^|/)') . $regex . ($directory ? '/' : '(?:/|$)') . '~iD', $excluded];
        }
    }

    public function matches(string $path): bool
    {
        $excluded = false;
        foreach ($this->rules as $rule) {
            if (preg_match($rule[0], $path)) {
                $excluded = $rule[1];
            }
        }
        return $excluded;
    }
}
