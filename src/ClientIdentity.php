<?php

declare(strict_types=1);

namespace BuTing\CliAppUpdater;

/** Internal helper. Raw OS identifiers stay local and are never sent to the platform. */
final class ClientIdentity
{
    public static function detect(): ?string
    {
        if (PHP_OS_FAMILY === 'Linux') {
            foreach (['/etc/machine-id', '/var/lib/dbus/machine-id'] as $path) {
                if (is_readable($path)) {
                    $value = trim((string) @file_get_contents($path, false, null, 0, 128));
                    if (preg_match('/^[a-f0-9]{32}$/iD', $value) && $value !== str_repeat('0', 32)) {
                        return strtolower($value);
                    }
                }
            }
        } elseif (PHP_OS_FAMILY === 'Windows') {
            $root = getenv('SystemRoot');
            if ($root) {
                $output = self::command([$root . '/System32/reg.exe', 'query',
                    'HKLM\SOFTWARE\Microsoft\Cryptography', '/v', 'MachineGuid', '/reg:64']);
                if (preg_match('/MachineGuid\s+REG_SZ\s+([a-f0-9-]{36})/i', $output, $match)) {
                    return strtolower($match[1]);
                }
            }
        }
        // No hostname, IP or MAC fallback: these can change or be shared by unrelated installations.
        return null;
    }

    public static function tag(string $machineId, string $installation): string
    {
        return hash_hmac('sha256', $machineId, 'buting.cli-app-updater.machine.v1:' . $installation);
    }

    private static function command(array $command): string
    {
        if (!is_callable('proc_open')) {
            return '';
        }
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            null, null, ['bypass_shell' => true, 'create_no_window' => true]);
        if (!is_resource($process)) {
            return '';
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $deadline = microtime(true) + 2;
        try {
            do {
                $output .= stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $output .= stream_get_contents($pipes[1]);
                    return $output;
                }
                usleep(10000);
            } while (microtime(true) < $deadline && strlen($output) < 4096);
            proc_terminate($process);
            return '';
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }
}
