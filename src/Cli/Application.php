<?php

declare(strict_types=1);

namespace Tarjim\Cli;

use Tarjim\Client;
use Tarjim\Config;
use Tarjim\Export\ExportResult;
use Tarjim\RefreshResult;

final class Application
{
    public const OK = 0;
    public const FAILED = 1;
    public const MISCONFIGURED = 2;
    public const WAIT_RAN_OUT = 3;
    public const NO_COPY = 4;

    private const USAGE = "usage:\n"
        . "  tarjim-php refresh --config=<file> [--wait=<seconds>] [--require-copy]\n"
        . "  tarjim-php status --config=<file>\n"
        . "  tarjim-php export --config=<file> --project=<id> --bundle=<id> --out=<absolute folder>\n";

    /**
     * @param list<string> $argv without the program name
     * @param resource $out
     * @param resource $err
     */
    public function run(array $argv, $out, $err): int
    {
        $command = null;
        try {
            if (($argv[0] ?? null) === 'export') {
                return $this->export(array_slice($argv, 1), $out, $err);
            }
            $parsed = $this->parse($argv);
            if ($parsed === null) {
                fwrite($err, self::USAGE);

                return self::MISCONFIGURED;
            }
            [$command, $options] = $parsed;
            $config = $this->loadConfig($options['config']);
            if ($config === null) {
                // The exception text is dropped on purpose: it may quote the key.
                fwrite($err, "tarjim-php: the config file could not be loaded or did not return an array\n");

                return self::MISCONFIGURED;
            }

            // Problems are reported through exit codes: a thrown refusal would be a different one.
            $config['strict'] = false;

            return $command === 'refresh'
                ? $this->refresh($config, $options, $out, $err)
                : $this->status($config, $out, $err);
        } catch (\Throwable $e) {
            // Never the exception's message, for the same reason.
            if ($command === 'refresh') {
                fwrite($err, "tarjim-php: the refresh failed unexpectedly\n");

                return self::FAILED;
            }
            if (($argv[0] ?? null) === 'export') {
                fwrite($err, "tarjim-php: the export failed unexpectedly\n");

                return self::FAILED;
            }
            fwrite($err, "tarjim-php: the configuration could not be used\n");

            return self::MISCONFIGURED;
        }
    }

    /**
     * @param list<string> $args the arguments after the command name
     * @param resource $out
     * @param resource $err
     */
    private function export(array $args, $out, $err): int
    {
        $options = ['config' => null, 'project' => null, 'bundle' => null, 'out' => null];
        foreach ($args as $arg) {
            if (preg_match('/^--([a-z-]+)=(.*)$/s', $arg, $m) !== 1 || !array_key_exists($m[1], $options)) {
                return $this->usage($err, 'unknown or malformed argument: ' . (strpos($arg, '=') === false ? $arg : substr($arg, 0, (int) strpos($arg, '='))));
            }
            $options[$m[1]] = $m[2];
        }
        foreach (['project', 'bundle'] as $name) {
            $value = $options[$name];
            if ($value === null || preg_match('/^[0-9]{1,9}$/', $value) !== 1 || (int) $value < 1) {
                return $this->usage($err, '--' . $name . ' must be a positive integer');
            }
        }
        if ($options['out'] === null || preg_match('#^(/|[A-Za-z]:[\\\\/])#', $options['out']) !== 1) {
            return $this->usage($err, '--out must be an absolute path');
        }
        if ($options['config'] === null || $options['config'] === '') {
            return $this->usage($err, '--config is required');
        }

        $config = $this->loadConfig($options['config']);
        if ($config === null) {
            fwrite($err, "tarjim-php: the config file could not be loaded or did not return an array\n");

            return self::MISCONFIGURED;
        }
        $config['strict'] = false;
        $result = Client::create($config)->exports()->pull((int) $options['project'], (int) $options['bundle'], $options['out']);

        if ($result->status === ExportResult::UPDATED) {
            fwrite($out, $result->status . ': ' . $result->message . "\n");

            return self::OK;
        }
        fwrite($err, $result->status . ': ' . $result->message . "\n");

        return $result->status === ExportResult::MISCONFIGURED ? self::MISCONFIGURED : self::FAILED;
    }

    /** @param resource $err */
    private function usage($err, string $problem): int
    {
        fwrite($err, 'tarjim-php: ' . $problem . "\n" . self::USAGE);

        return self::MISCONFIGURED;
    }

    /**
     * @param list<string> $argv
     *
     * @return array{string, array{config: string, wait: int, requireCopy: bool}}|null null on bad usage
     */
    private function parse(array $argv): ?array
    {
        $command = array_shift($argv);
        if ($command !== 'refresh' && $command !== 'status') {
            return null;
        }
        $options = ['config' => '', 'wait' => 0, 'requireCopy' => false];
        foreach ($argv as $arg) {
            if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m) !== 1) {
                return null;
            }
            $value = $m[2] ?? null;
            if ($m[1] === 'config' && $value !== null && $value !== '') {
                $options['config'] = $value;
            } elseif ($m[1] === 'wait' && $command === 'refresh' && $value !== null && preg_match('/^[0-9]{1,9}$/', $value) === 1) {
                $options['wait'] = (int) $value;
            } elseif ($m[1] === 'require-copy' && $command === 'refresh' && $value === null) {
                $options['requireCopy'] = true;
            } else {
                return null;
            }
        }

        return $options['config'] === '' ? null : [$command, $options];
    }

    /** @return array<string, mixed>|null */
    private function loadConfig(string $file): ?array
    {
        $level = ob_get_level();
        ob_start();
        try {
            if (!is_file($file) || !is_readable($file)) {
                return null;
            }
            $config = (static function (string $__file) {
                return include $__file;
            })($file);

            return is_array($config) ? $config : null;
        } catch (\Throwable $e) {
            return null;
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     * @param array{config: string, wait: int, requireCopy: bool} $options
     * @param resource $out
     * @param resource $err
     */
    private function refresh(array $config, array $options, $out, $err): int
    {
        // The same answer as a config file that cannot be loaded: there is no store to look in.
        if ($options['requireCopy'] && Config::fromArray($config)->get('dir') === null) {
            fwrite($err, "tarjim-php: dir must be set to an absolute path\n");

            return self::MISCONFIGURED;
        }
        $client = Client::create($config);
        $result = $client->refresh(['wait' => $options['wait']]);
        $held = $client->status()->releaseId;

        $ranOut = $options['wait'] > 0 && in_array($result->status, [RefreshResult::NOT_DUE, RefreshResult::BUSY, RefreshResult::UNRELEASED], true);
        $problem = $result->status === RefreshResult::FAILED || $result->status === RefreshResult::MISCONFIGURED;

        if ($problem) {
            fwrite($err, $result->status . ': ' . $result->message . "\n");
        } else {
            fwrite($out, $result->status . ($held !== null ? ' release ' . $held : ' no release held') . "\n");
            if ($ranOut) {
                fwrite($err, 'still ' . $result->status . ' after waiting' . ($result->message !== '' ? ': ' . $result->message : '') . "\n");
            }
        }

        if ($options['requireCopy']) {
            if ($result->copyHeld) {
                return self::OK;
            }
            fwrite($err, "no usable copy is held\n");

            return self::NO_COPY;
        }
        if ($result->status === RefreshResult::FAILED) {
            return self::FAILED;
        }
        if ($result->status === RefreshResult::MISCONFIGURED) {
            return self::MISCONFIGURED;
        }

        return $ranOut ? self::WAIT_RAN_OUT : self::OK;
    }

    /**
     * @param array<string, mixed> $config
     * @param resource $out
     * @param resource $err
     */
    private function status(array $config, $out, $err): int
    {
        // Only the folder matters here: a missing key or delivery URL does not stop a read.
        if (Config::fromArray($config)->get('dir') === null) {
            fwrite($err, "tarjim-php: dir must be set to an absolute path\n");

            return self::MISCONFIGURED;
        }
        $status = Client::create($config)->status();
        $time = static function (?int $at): string {
            return $at === null ? 'never' : gmdate('Y-m-d\TH:i:s\Z', $at);
        };
        $lines = [
            'releaseId' => $status->releaseId ?? 'none',
            'distribution' => $status->distribution ?? 'none',
            'stage' => $status->stage ?? 'none',
            'installedAt' => $time($status->installedAt),
            'lastCheckAt' => $time($status->lastCheckAt),
            'lastSuccessAt' => $time($status->lastSuccessAt),
            'stale' => $status->stale ? 'yes' : 'no',
        ];
        foreach ($lines as $name => $value) {
            fwrite($out, $name . ': ' . $value . "\n");
        }

        if ($status->releaseId === null) {
            return self::NO_COPY;
        }

        return $status->stale ? self::FAILED : self::OK;
    }
}
