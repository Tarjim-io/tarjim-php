<?php

declare(strict_types=1);

namespace Tarjim\Tests\Support;

use Tarjim\Wire\Request;
use Tarjim\Wire\Response;
use Tarjim\Wire\Transport;

/**
 * Answers by exact URL, or by URL without its query when no exact answer is set. Unknown URLs get
 * status 0. Each answer is a Response or a list of Responses served in turn (the last repeats).
 * Bodies over the request's limit come back as tooLarge, as the curl transport does.
 */
final class FakeTransport implements Transport
{
    /** @var array<string, list<Response>> */
    private $answers = [];

    /** @var list<Request> */
    public $requests = [];

    /** @param Response|list<Response> $answer */
    public function on(string $url, $answer): self
    {
        $this->answers[$url] = is_array($answer) ? $answer : [$answer];

        return $this;
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;
        $key = isset($this->answers[$request->url]) ? $request->url : strtok($request->url, '?');
        if (!is_string($key) || !isset($this->answers[$key])) {
            return Response::none();
        }
        $queue = &$this->answers[$key];
        $response = count($queue) > 1 ? array_shift($queue) : $queue[0];
        if ($response->body !== null && strlen($response->body) > $request->limit) {
            return new Response($response->status, $response->headers, null, true);
        }

        return $response;
    }

    /** @return list<string> */
    public function urls(): array
    {
        return array_map(static function (Request $r): string {
            return $r->url;
        }, $this->requests);
    }
}
