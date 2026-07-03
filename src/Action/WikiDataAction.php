<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Action;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Tds\ApiGateway\Support\AdminSessionVerifier;

/**
 * Serves the structured API route map as JSON at `/wiki.json`, for the
 * in-panel admin wiki (tds-admin) to render natively. Same gate as the HTML
 * {@see WikiAction} — legacy ADMIN_TOKEN or an admin `tds_session` cookie via
 * {@see AdminSessionVerifier}.
 *
 * Because the admin panel is a different origin (`management.tracht-digital.de`)
 * and authenticates with the session cookie, this route emits its own CORS
 * headers for the configured origins. CORS is deliberately kept OFF the
 * proxied catch-all (each upstream owns its CORS); it lives here only because
 * `/wiki.json` is a gateway-owned route with no upstream to duplicate.
 */
final class WikiDataAction
{
    /** @param string[] $allowedOrigins */
    public function __construct(
        private readonly AdminSessionVerifier $verifier,
        private readonly string $rootDir,
        private readonly array $allowedOrigins,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $response = $this->withCors($request, $response);

        // Preflight — answer before any auth so the browser can send the
        // credentialed GET that follows.
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return $response->withStatus(204);
        }

        if (!$this->verifier->canAuthenticate()) {
            return $response->withStatus(404);
        }

        if (!$this->verifier->isAdmin($request)) {
            $response->getBody()->write((string) json_encode(['error' => 'Unauthorized']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $json = $this->wikiJson();
        if ($json === null) {
            $response->getBody()->write((string) json_encode([
                'error' => 'Wiki not generated',
                'hint' => 'Run bin/gen-api-wiki.php',
            ]));
            return $response->withStatus(503)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write($json);
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex');
    }

    private function withCors(Request $request, Response $response): Response
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin === '' || !in_array($origin, $this->allowedOrigins, true)) {
            return $response;
        }
        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Vary', 'Origin')
            ->withHeader('Access-Control-Allow-Methods', 'GET, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type')
            ->withHeader('Access-Control-Max-Age', '600');
    }

    /** Read the generated wiki JSON from the dev or bundle layout. */
    private function wikiJson(): ?string
    {
        foreach ([$this->rootDir . '/wiki/data.json', dirname($this->rootDir) . '/wiki/data.json'] as $path) {
            if (is_file($path)) {
                $raw = file_get_contents($path);
                return $raw === false ? null : $raw;
            }
        }
        return null;
    }
}
