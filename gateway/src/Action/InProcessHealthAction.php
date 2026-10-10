<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Action;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Tds\ApiGateway\Config\ServiceRegistry;
use Tds\ApiGateway\Dispatch\InProcessDispatcher;
use Tds\ApiGateway\Support\HealthBody;
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
        /** @var array<string, string> service key => why it could not be dispatched */
        $reasons = [];

        foreach ($this->registry->all() as $service) {
            $key = '/' . $service->prefix;
            try {
                $probe = $request
                    ->withMethod('GET')
                    ->withUri($request->getUri()->withQuery(''));
                $result = $this->dispatcher->dispatch($probe, $service->prefix, '/healthz');
                $status = $result->getStatusCode();
                $db = HealthBody::dbState((string) $result->getBody());
            } catch (\Throwable $e) {
                // status 0 = the service couldn't be booted/dispatched at all.
                $status = 0;
                $db = null;
                // Capture WHY. This used to be swallowed entirely, so a `status: 0`
                // in the payload said "it failed" and nothing more — and unlike a
                // real request, this path never reaches DispatchAction, which is
                // the only place that logged the detail. Diagnosing a service that
                // was down here meant guessing at "missing directory / unreadable
                // vendor / fatal during boot" with no way to tell them apart.
                // The reason is LOGGED, never returned: /healthz is public and an
                // exception message carries absolute paths.
                $reasons[$key] = $e->getMessage();
            }

            // Gate on the self-reported db state too: each backend /healthz
            // answers 200 by contract, so a reachable-but-un-migrated service
            // would look green without inspecting `db` (tds-api-gateway#4).
            $ok = $status >= 200 && $status < 300 && $db !== 'down' && $db !== 'no-schema';
            $services[$key] = ['ok' => $ok, 'status' => $status];
            if ($db !== null) {
                $services[$key]['db'] = $db;
            }
            $allOk = $allOk && $ok;
            if (!$ok) {
                $down[$key] = $db !== null && $db !== 'ok' ? "db:{$db}" : $status;
            }
        }

        if ($down !== []) {
            $context = ['down' => $down];
            if ($reasons !== []) {
                $context['reasons'] = $reasons;
            }
            $this->logger?->warning('health check: service(s) down', $context);
            // error_log() as well, mirroring DispatchAction: on the Plesk host the
            // file sink is the thing most likely to be misconfigured, and this is
            // exactly the moment you need the message.
            foreach ($reasons as $key => $reason) {
                error_log("[gateway] health: {$key} could not be dispatched: {$reason}");
            }
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
