<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Action;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Tds\ApiGateway\Config\ServiceRegistry;
use Tds\ApiGateway\Http\ProxyClientInterface;
use Tds\ApiGateway\Http\ProxyException;

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
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $services = [];
        $allOk = true;

        foreach ($this->registry->all() as $service) {
            try {
                $result = $this->client->send('GET', $service->healthUrl(), [], '');
                $ok = $result->status >= 200 && $result->status < 300;
                $status = $result->status;
            } catch (ProxyException) {
                $ok = false;
                $status = 0;
            }
            $services['/' . $service->prefix] = ['ok' => $ok, 'status' => $status];
            $allOk = $allOk && $ok;
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
