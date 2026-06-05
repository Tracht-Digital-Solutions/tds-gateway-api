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
}
