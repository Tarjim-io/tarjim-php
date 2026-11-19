<?php

declare(strict_types=1);

namespace Tarjim\Wire;

final class CurlTransport implements Transport
{
    /** @var int */
    private $connectTimeout;

    /** @var int */
    private $stallTimeout;

    /** @var string|null */
    private $caBundle;

    public function __construct(int $connectTimeout = 5, int $stallTimeout = 20, ?string $caBundle = null)
    {
        $this->connectTimeout = $connectTimeout;
        $this->stallTimeout = $stallTimeout;
        $this->caBundle = $caBundle;
    }

    public function send(Request $request): Response
    {
        try {
            return $this->perform($request);
        } catch (\Throwable $e) {
            return Response::none();
        }
    }

    private function perform(Request $request): Response
    {
        $caBundle = $this->caBundle;
        // An empty bundle path would make curl fall back to its default roots; fail closed instead.
        if ($request->url === '' || $caBundle === '') {
            return Response::none();
        }
        $lines = [];
        foreach ($request->headers as $name => $value) {
            $name = (string) $name;
            $value = (string) $value;
            // Refused before curl sees them: a line break would smuggle a second header.
            if (preg_match('/[\r\n\0]/', $name . $value) === 1 || $name === '') {
                return Response::none();
            }
            $lines[] = $name . ': ' . $value;
        }

        $handle = curl_init();
        if ($handle === false) {
            return Response::none();
        }

        $headers = [];
        $body = '';
        $size = 0;
        $tooLarge = false;
        $bodyStarted = false;
        $interim = false;
        $limit = $request->limit;
        // A create call is held open by the server while it works, so it may ask for longer silence.
        $stallTimeout = $request->stallTimeout ?? $this->stallTimeout;
        $seen = 0;
        $lastProgress = microtime(true);

        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            // No total timeout: a large body on a slow but steady link is fine, only silence is not.
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => $stallTimeout,
            CURLOPT_NOSIGNAL => true,
            // libcurl's own low-speed check averages over a window of several seconds, so a few bytes
            // sent just before the server goes quiet postpone it well past the timeout. This watchdog
            // (called about once a second) counts the time since the last byte instead.
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static function ($ch, $dlTotal, $dlNow) use (&$seen, &$lastProgress, $stallTimeout): int {
                $now = microtime(true);
                if ((int) $dlNow !== $seen) {
                    $seen = (int) $dlNow;
                    $lastProgress = $now;
                }

                return $now - $lastProgress >= $stallTimeout ? 1 : 0;
            },
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers, &$bodyStarted, &$interim): int {
                $length = strlen($line);
                // The final header block is over at its blank line (or at the first body byte): anything
                // after that is a chunked trailer and must not overwrite the real headers.
                if ($bodyStarted) {
                    return $length;
                }
                $trimmed = trim($line);
                if ($trimmed === '') {
                    $bodyStarted = !$interim;
                } elseif (stripos($trimmed, 'HTTP/') === 0) {
                    // A new status line starts a new block (1xx, proxy CONNECT): earlier headers are stale.
                    $headers = [];
                    $interim = preg_match('~^HTTP/[0-9.]+ 1[0-9]{2}(\s|\z)~i', $trimmed) === 1;
                } elseif (($colon = strpos($trimmed, ':')) !== false && $colon > 0) {
                    $headers[strtolower(trim(substr($trimmed, 0, $colon)))] = trim(substr($trimmed, $colon + 1));
                }

                return $length;
            },
            // Counts decoded bytes: curl decodes before calling this, so a compression bomb trips the limit too.
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$size, &$tooLarge, &$bodyStarted, $limit): int {
                $bodyStarted = true;
                $size += strlen($chunk);
                if ($size > $limit) {
                    $tooLarge = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ];
        if ($request->method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = (string) $request->body;
        } else {
            $options[CURLOPT_HTTPGET] = true;
        }
        curl_setopt_array($handle, $options);
        if ($caBundle !== null) {
            curl_setopt($handle, CURLOPT_CAINFO, $caBundle);
        }

        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        unset($handle);

        // Decided before the size: a redirect is never an answer, however large its body.
        if ($status >= 300 && $status < 400 && $status !== 304) {
            return Response::none();
        }
        if ($tooLarge) {
            return new Response($status, $headers, null, true);
        }
        if ($ok === false || $status === 0) {
            return Response::none();
        }

        return new Response($status, $headers, $body);
    }
}
