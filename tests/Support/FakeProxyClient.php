<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Tests\Support;

use Tds\ApiGateway\Http\ProxyClientInterface;
use Tds\ApiGateway\Http\ProxyException;
use Tds\ApiGateway\Http\ProxyResponse;

/** Records the last call and returns a canned response (or throws). */
final class FakeProxyClient implements ProxyClientInterface
{
    public ?string $method = null;
    public ?string $url = null;
    /** @var array<string, string[]>|null */
    public ?array $headers = null;
    public ?string $body = null;
    public int $calls = 0;

    /** @var array<string, ProxyResponse> keyed by URL substring, optional */
    private array $byUrl = [];

    /** @var string[] URL substrings that should raise a transport failure */
    private array $throwNeedles = [];

    public function __construct(
        private ProxyResponse $response = new ProxyResponse(200, [], ''),
        private bool $throw = false,
    ) {
    }

    public function willReturn(ProxyResponse $response): void
    {
        $this->response = $response;
    }

    public function willThrow(): void
    {
        $this->throw = true;
    }

    /** Return a specific response when the URL contains $needle. */
    public function whenUrlContains(string $needle, ProxyResponse $response): void
    {
        $this->byUrl[$needle] = $response;
    }

    /** Simulate a transport failure when the URL contains $needle. */
    public function throwWhenUrlContains(string $needle): void
    {
        $this->throwNeedles[] = $needle;
    }

    public function send(string $method, string $url, array $headers, string $body): ProxyResponse
    {
        $this->calls++;
        $this->method = $method;
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;

        if ($this->throw) {
            throw new ProxyException('forced failure');
        }
        foreach ($this->throwNeedles as $needle) {
            if (str_contains($url, $needle)) {
                throw new ProxyException('forced failure for ' . $needle);
            }
        }
        foreach ($this->byUrl as $needle => $response) {
            if (str_contains($url, $needle)) {
                return $response;
            }
        }
        return $this->response;
    }

    public function sendMany(array $requests): array
    {
        $out = [];
        foreach ($requests as $key => $req) {
            try {
                $out[$key] = $this->send(
                    (string) ($req['method'] ?? 'GET'),
                    (string) ($req['url'] ?? ''),
                    (array) ($req['headers'] ?? []),
                    (string) ($req['body'] ?? ''),
                );
            } catch (ProxyException) {
                $out[$key] = new ProxyResponse(0, [], '');
            }
        }
        return $out;
    }
}
