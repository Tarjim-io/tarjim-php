<?php

declare(strict_types=1);

namespace Tarjim;

use Tarjim\Store\Disk;
use Tarjim\Store\LocalDisk;
use Tarjim\Wire\CurlTransport;
use Tarjim\Wire\DeliveryUrl;
use Tarjim\Wire\Transport;

final class Config
{
    /** @var array<string, mixed> */
    private $values;

    /** @var list<string> */
    private $problems;

    /** @var list<string> */
    private $refreshProblems;

    /**
     * @param array<string, mixed> $values
     * @param list<string> $problems
     * @param list<string> $refreshProblems
     */
    private function __construct(array $values, array $problems, array $refreshProblems)
    {
        $this->values = $values;
        $this->problems = $problems;
        $this->refreshProblems = $refreshProblems;
    }

    /**
     * Never throws; whatever is wrong is listed by problems(). A bad optional value falls back to
     * its default. Problem texts name the option and never repeat its value: the key, and a URL
     * that may carry one, must not reach a log or an exception.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $problems = [];
        $refreshProblems = [];

        $dir = $config['dir'] ?? null;
        if (!is_string($dir) || $dir === '') {
            $dir = null;
            $refreshProblems[] = 'dir must be a non-empty string';
        } elseif (preg_match('#^(/|[A-Za-z]:[\\\\/])#', $dir) !== 1) {
            // The command line and the web server would otherwise resolve it against different folders.
            $dir = null;
            $refreshProblems[] = 'dir must be an absolute path';
        }

        $deliveryUrl = $config['deliveryUrl'] ?? null;
        if (!is_string($deliveryUrl)) {
            $deliveryUrl = null;
            $refreshProblems[] = 'deliveryUrl must be a string';
        } elseif (($why = DeliveryUrl::problem($deliveryUrl)) !== null) {
            $deliveryUrl = null;
            $refreshProblems[] = $why;
        }

        $key = $config['key'] ?? null;
        if (!is_string($key) || $key === '') {
            $key = null;
            $refreshProblems[] = 'key is required';
        } elseif (preg_match('/[\x00-\x20\x7f]/', $key) === 1) {
            // Whitespace or a control character would let the key add a header line to the request.
            $key = null;
            $refreshProblems[] = 'key is malformed';
        }

        $caBundle = self::optionalString($config, 'caBundle', $problems);
        $transport = $config['transport'] ?? null;
        if ($transport !== null && !$transport instanceof Transport) {
            $problems[] = 'transport must implement Tarjim\\Wire\\Transport';
            $transport = null;
        }

        $logger = $config['logger'] ?? null;
        if ($logger !== null && !is_callable($logger)) {
            $problems[] = 'logger must be callable';
            $logger = null;
        }

        // Test seams: the clock, the sleep and the disk the refresh cycle works through.
        $now = $config['now'] ?? null;
        if ($now !== null && !is_callable($now)) {
            $problems[] = 'now must be callable';
            $now = null;
        }
        $sleep = $config['sleep'] ?? null;
        if ($sleep !== null && !is_callable($sleep)) {
            $problems[] = 'sleep must be callable';
            $sleep = null;
        }
        $disk = $config['disk'] ?? null;
        if ($disk !== null && !$disk instanceof Disk) {
            $problems[] = 'disk must implement Tarjim\\Store\\Disk';
            $disk = null;
        }

        $keyCase = $config['keyCase'] ?? 'exact';
        if ($keyCase !== 'exact' && $keyCase !== 'lower') {
            $problems[] = "keyCase must be 'exact' or 'lower'";
            $keyCase = 'exact';
        }

        $staleAfter = $config['staleAfter'] ?? 86400;
        if (!is_int($staleAfter) || $staleAfter <= 0) {
            $problems[] = 'staleAfter must be a positive number of seconds';
            $staleAfter = 86400;
        }

        $bundle = $config['bundle'] ?? 'default';
        if (!is_string($bundle) || $bundle === '') {
            $problems[] = 'bundle must be a non-empty string';
            $bundle = 'default';
        }

        $values = [
            'dir' => $dir,
            'deliveryUrl' => $deliveryUrl,
            'key' => $key,
            'bundle' => $bundle,
            'locale' => self::optionalString($config, 'locale', $problems),
            'fallbackLocale' => self::optionalString($config, 'fallbackLocale', $problems),
            'keyCase' => $keyCase,
            'legacyPercentPlaceholders' => self::flag($config, 'legacyPercentPlaceholders', $problems),
            'staleAfter' => $staleAfter,
            'logger' => $logger,
            'caBundle' => $caBundle,
            'strict' => self::flag($config, 'strict', $problems),
            'transport' => $transport ?? new CurlTransport(5, 20, $caBundle),
            'now' => $now,
            'sleep' => $sleep,
            'disk' => $disk ?? new LocalDisk(),
            'apiUrl' => self::optionalString($config, 'apiUrl', $problems),
            'apiKey' => self::optionalString($config, 'apiKey', $problems),
        ];

        return new self($values, array_merge($refreshProblems, $problems), $refreshProblems);
    }

    /**
     * Which options are set, never their values: the key and a delivery URL that may carry one
     * must stay out of print_r() and var_dump().
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'set' => array_keys(array_filter($this->values, static function ($value): bool {
                return $value !== null;
            })),
            'problems' => count($this->problems),
        ];
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * The problems that stop a refresh: a bad dir, deliveryUrl or key.
     *
     * @return list<string>
     */
    public function refreshProblems(): array
    {
        return $this->refreshProblems;
    }

    /** @return mixed */
    public function get(string $name)
    {
        return $this->values[$name] ?? null;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $problems
     */
    private static function optionalString(array $config, string $name, array &$problems): ?string
    {
        $value = $config[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '') {
            $problems[] = $name . ' must be a non-empty string';

            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $problems
     */
    private static function flag(array $config, string $name, array &$problems): bool
    {
        $value = $config[$name] ?? false;
        if (!is_bool($value)) {
            $problems[] = $name . ' must be true or false';

            return false;
        }

        return $value;
    }
}
