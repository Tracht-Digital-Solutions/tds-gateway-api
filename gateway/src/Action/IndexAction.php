<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Action;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Tds\ApiGateway\Config\ServiceRegistry;

/**
 * Root navigation: lists the public service prefixes the gateway routes to.
 * Internal upstream addresses are intentionally not exposed.
 */
final class IndexAction
{
    public function __construct(private readonly ServiceRegistry $registry)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $response->getBody()->write((string) json_encode([
            'name' => 'tds-api-gateway',
            'description' => 'Single entry point for the TDS micro-backends.',
            'services' => array_map(
                static fn ($s) => ['prefix' => '/' . $s->prefix],
                $this->registry->all(),
            ),
            'health' => '/healthz',
        ], JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
