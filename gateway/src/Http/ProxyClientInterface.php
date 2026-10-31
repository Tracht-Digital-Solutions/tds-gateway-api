<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Http;

interface ProxyClientInterface
{
    /**
     * Forward a request to an upstream and return its raw response.
     *
     * @param array<string, string[]> $headers name => list of values
     * @throws ProxyException on transport failure (connect/timeout/etc.)
     */
    public function send(string $method, string $url, array $headers, string $body): ProxyResponse;

    /**
     * Forward several requests concurrently and return their responses keyed
     * by the same ids. Used for the health fan-out so one slow/dead upstream
     * doesn't serialise the others. A per-request transport failure is
     * reported as a status-0 {@see ProxyResponse} (never thrown), so one dead
     * upstream can't abort the batch.
     *
     * @param array<string, array{method: string, url: string, headers: array<string, string[]>, body: string}> $requests
     * @return array<string, ProxyResponse> same keys as $requests
     */
    public function sendMany(array $requests): array;
}
