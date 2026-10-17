<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Action;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Tds\ApiGateway\Config\Service;
use Tds\ApiGateway\Config\ServiceRegistry;
use Tds\ApiGateway\Dispatch\DispatchException;
use Tds\ApiGateway\Dispatch\InProcessDispatcher;
use Tds\ApiGateway\Support\Logger;

/**
 * Catch-all for in-process mode: resolve the first path segment to a service
 * and run that service's Slim app inside this process (no loopback HTTP hop).
 * The in-process twin of {@see ProxyAction} — same routing/404/502 semantics,
 * but the upstream is the service app itself instead of `php -S`.
 */
final class DispatchAction
{
    public function __construct(
        private readonly ServiceRegistry $registry,
        private readonly InProcessDispatcher $dispatcher,
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
                    static fn (Service $s): string => '/' . $s->prefix,
                    $this->registry->all(),
                ),
            ]);
        }

        [$service, $remainder] = $match;
        $servicePath = $service->pathFor($remainder);
        $forwarded = $this->withForwardedHeaders($request, $service);

        $startedAt = microtime(true);
        try {
            $result = $this->dispatcher->dispatch($forwarded, $service->prefix, $servicePath);
        } catch (DispatchException $e) {
            $this->logger?->error('in-process dispatch failed', [
                'service' => '/' . $service->prefix,
                'method' => $request->getMethod(),
                'path' => $servicePath,
                'detail' => $e->getMessage(),
                'duration_ms' => self::elapsedMs($startedAt),
            ]);
            // error_log() too, so the failure is visible even if the file sink
            // is misconfigured.
            error_log('[gateway] ' . $e->getMessage());
            return $this->json($response->withStatus(502), [
                'error' => 'The service could not be dispatched.',
                'service' => '/' . $service->prefix,
            ]);
        }

        $this->logger?->info('dispatched', [
            'service' => '/' . $service->prefix,
            'method' => $request->getMethod(),
            'path' => $servicePath,
            'status' => $result->getStatusCode(),
            'duration_ms' => self::elapsedMs($startedAt),
        ]);

        return $result;
    }

    private function withForwardedHeaders(Request $request, Service $service): Request
    {
        $uri = $request->getUri();
        return $request
            ->withHeader('X-Forwarded-Host', $uri->getHost())
            ->withHeader('X-Forwarded-Proto', $uri->getScheme() !== '' ? $uri->getScheme() : 'https')
            ->withHeader('X-Forwarded-Prefix', '/' . $service->prefix)
            ->withHeader('X-Forwarded-For', $this->forwardedFor($request));
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

    private static function elapsedMs(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 1);
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
