<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Action;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Tds\ApiGateway\Config\ServiceRegistry;
use Tds\ApiGateway\Dispatch\InProcessDispatcher;
use Tds\ApiGateway\Support\Logger;

/**
 * Aggregated health for in-process mode: runs each service's `/healthz` inside
 * this process and reports 200 when all are healthy, 503 otherwise. Same JSON
 * shape as {@see HealthAction} so a load balancer can gate on the whole surface
 * from one endpoint regardless of the gateway's run mode. A service that throws
 * while booting/handling is reported as status 0 (never aborts the batch).
 */
final class InProcessHealthAction
{
    public function __construct(
        private readonly ServiceRegistry $registry,
        private readonly InProcessDispatcher $dispatcher,
        private readonly ?Logger $logger = null,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $services = [];
        $allOk = true;
        $down = [];

        foreach ($this->registry->all() as $service) {
            $key = '/' . $service->prefix;
            try {
                $probe = $request
                    ->withMethod('GET')
                    ->withUri($request->getUri()->withQuery(''));
                $result = $this->dispatcher->dispatch($probe, $service->prefix, '/healthz');
                $status = $result->getStatusCode();
            } catch (\Throwable) {
                // status 0 = the service couldn't be booted/dispatched at all.
                $status = 0;
            }

            $ok = $status >= 200 && $status < 300;
            $services[$key] = ['ok' => $ok, 'status' => $status];
            $allOk = $allOk && $ok;
            if (!$ok) {
                $down[$key] = $status;
            }
        }

        if ($down !== []) {
            $this->logger?->warning('health check: service(s) down', ['down' => $down]);
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
