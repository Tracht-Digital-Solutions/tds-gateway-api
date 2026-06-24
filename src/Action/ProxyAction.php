<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Action;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Tds\ApiGateway\Config\ServiceRegistry;
use Tds\ApiGateway\Http\HeaderFilter;
use Tds\ApiGateway\Http\ProxyClientInterface;
use Tds\ApiGateway\Http\ProxyException;
use Tds\ApiGateway\Support\Logger;

/**
 * Catch-all: resolve the first path segment to an upstream and relay the
 * request transparently (method, query, headers, body, cookies), then mirror
 * the upstream status/headers/body back to the client.
 */
final class ProxyAction
{
    public function __construct(
        private readonly ServiceRegistry $registry,
        private readonly ProxyClientInterface $client,
        private readonly ?Logger $logger = null,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $path = $request->getUri()->getPath();
        $match = $this->registry->match($path);
        if ($match === null) {
            return $this->json($response->withStatus(404), [
                'error' => 'No service is registered for this path.',
                'path' => $path,
                'services' => array_map(
                    static fn ($s) => '/' . $s->prefix,
                    $this->registry->all(),
                ),
            ]);
        }

        [$service, $remainder] = $match;
        $uri = $request->getUri();
        $url = $service->targetFor($remainder, $uri->getQuery());

        $headers = HeaderFilter::forRequest($request->getHeaders());
        $headers['X-Forwarded-Host'] = [$uri->getHost()];
        $headers['X-Forwarded-Proto'] = [$uri->getScheme() !== '' ? $uri->getScheme() : 'https'];
        $headers['X-Forwarded-Prefix'] = ['/' . $service->prefix];
        $headers['X-Forwarded-For'] = [$this->forwardedFor($request)];

        $startedAt = microtime(true);
        try {
            $upstream = $this->client->send(
                $request->getMethod(),
                $url,
                $headers,
                (string) $request->getBody(),
            );
        } catch (ProxyException $e) {
            // The exception code carries the cURL errno (7 = connection
            // refused, 6 = couldn't resolve host, 28 = timeout, …) — the single
            // most useful datum for diagnosing an "all services down" outage.
            $this->logger?->error('upstream request failed', [
                'service' => '/' . $service->prefix,
                'method' => $request->getMethod(),
                'target' => $url,
                'curl_errno' => $e->getCode(),
                'detail' => $e->getMessage(),
                'duration_ms' => self::elapsedMs($startedAt),
            ]);
            // error_log() too, so the failure is visible even if the file sink
            // is misconfigured.
            error_log('[gateway] ' . $e->getMessage());
            return $this->json($response->withStatus(502), [
                'error' => 'The upstream service is unavailable.',
                'service' => '/' . $service->prefix,
            ]);
        }

        $this->logger?->info('proxied', [
            'service' => '/' . $service->prefix,
            'method' => $request->getMethod(),
            'target' => $url,
            'status' => $upstream->status,
            'duration_ms' => self::elapsedMs($startedAt),
        ]);

        $result = $response->withStatus($upstream->status);
        foreach (HeaderFilter::forResponse($upstream->headers) as $name => $values) {
            foreach ($values as $index => $value) {
                $result = $index === 0
                    ? $result->withHeader($name, $value)
                    : $result->withAddedHeader($name, $value);
            }
        }
        $result->getBody()->write($upstream->body);
        return $result;
    }

    private static function elapsedMs(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 1);
    }

    private function forwardedFor(Request $request): string
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        $existing = $request->getHeaderLine('X-Forwarded-For');
        if ($existing !== '' && $remote !== '') {
            return $existing . ', ' . $remote;
        }
        return $existing !== '' ? $existing : (string) $remote;
    }

    /** @param array<string, mixed> $data */
    private function json(Response $response, array $data): Response
    {
        $response->getBody()->write(
            (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
        return $response->withHeader('Content-Type', 'application/json');
    }
}
