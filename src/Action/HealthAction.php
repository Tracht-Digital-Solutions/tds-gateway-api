<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Action;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Tds\ApiGateway\Config\ServiceRegistry;
use Tds\ApiGateway\Http\ProxyClientInterface;
use Tds\ApiGateway\Support\Logger;

/**
 * Aggregated health: pings every upstream's /healthz with a short timeout.
 * 200 when all are healthy, 503 otherwise — so a load balancer can gate on
 * the whole surface from one endpoint.
 */
final class HealthAction
{
    public function __construct(
        private readonly ServiceRegistry $registry,
        private readonly ProxyClientInterface $client,
        private readonly ?Logger $logger = null,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $requests = [];
        foreach ($this->registry->all() as $service) {
            $requests['/' . $service->prefix] = [
                'method' => 'GET',
                'url' => $service->healthUrl(),
                'headers' => [],
                'body' => '',
            ];
        }

        // Ping every upstream concurrently so /healthz can't serialise on a
        // slow/dead one (a transport failure comes back as status 0).
        $results = $this->client->sendMany($requests);

        $services = [];
        $allOk = true;
        $down = [];
        foreach (array_keys($requests) as $key) {
            $status = isset($results[$key]) ? $results[$key]->status : 0;
            $ok = $status >= 200 && $status < 300;
            $services[$key] = ['ok' => $ok, 'status' => $status];
            $allOk = $allOk && $ok;
            if (!$ok) {
                // status 0 = transport failure (connect refused / DNS / timeout).
                $down[$key] = $status;
            }
        }

        if ($down !== []) {
            $this->logger?->warning('health check: upstream(s) down', [
                'down' => $down,
            ]);
        }

        $response->getBody()->write((string) json_encode([
            'ok' => $allOk,
            'gateway' => 'tds-api-gateway',
            'services' => $services,
        ], JSON_UNESCAPED_SLASHES));

        return $response
            ->withStatus($allOk ? 200 : 503)
            ->withHeader('Content-Type', 'application/json');
    }
}
