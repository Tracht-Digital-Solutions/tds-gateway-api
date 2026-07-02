<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Action;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Tds\ApiGateway\Support\AdminSessionVerifier;

/**
 * Serves the auto-generated API wiki HTML at `/wiki` — but only to a logged-in
 * operator. The wiki is internal (route map of every backend), so it is gated
 * by {@see AdminSessionVerifier}: either the legacy shared ADMIN_TOKEN
 * (Bearer / `?token=` / `tds_wiki` cookie) or a valid admin `tds_session`
 * cookie. A correct `?token=` sets the cookie and redirects to a clean `/wiki`
 * URL so the token leaves the address bar. Without a valid credential a small
 * login form is shown.
 *
 * When no credential path is configured the wiki is disabled (404) rather than
 * open — "you must be logged in" must hold even if the gateway is misconfigured.
 *
 * The structured JSON the admin panel renders in-app is served separately by
 * {@see WikiDataAction} at `/wiki.json`.
 */
final class WikiAction
{
    public function __construct(
        private readonly AdminSessionVerifier $verifier,
        private readonly string $rootDir,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        if (!$this->verifier->canAuthenticate()) {
            return $response->withStatus(404);
        }

        if (!$this->verifier->isAdmin($request)) {
            $response->getBody()->write($this->loginPage());
            return $response->withStatus(401)->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        // Authed via a valid URL token → persist as a cookie and clean the URL.
        $legacy = $this->verifier->legacyToken($request);
        if (($request->getQueryParams()['token'] ?? null) !== null && $this->verifier->tokenMatches($legacy)) {
            return $response
                ->withStatus(303)
                ->withHeader('Location', '/wiki')
                ->withHeader(
                    'Set-Cookie',
                    'tds_wiki=' . rawurlencode((string) $legacy) . '; Path=/wiki; HttpOnly; SameSite=Lax; Max-Age=86400',
                );
        }

        $html = $this->wikiHtml();
        if ($html === null) {
            $response->getBody()->write('<h1>Wiki not generated</h1><p>Run <code>bin/gen-api-wiki.php</code>.</p>');
            return $response->withStatus(503)->withHeader('Content-Type', 'text/html; charset=utf-8');
        }
        $response->getBody()->write($html);
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex');
    }

    /** Read the generated wiki HTML from the dev or bundle layout. */
    private function wikiHtml(): ?string
    {
        foreach ([$this->rootDir . '/wiki/index.html', dirname($this->rootDir) . '/wiki/index.html'] as $path) {
            if (is_file($path)) {
                $html = file_get_contents($path);
                return $html === false ? null : $html;
            }
        }
        return null;
    }

    private function loginPage(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="de"><head><meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="robots" content="noindex" /><title>API-Wiki — Anmeldung</title>
<link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@500;800&family=Plus+Jakarta+Sans:wght@400;500&display=swap" rel="stylesheet" />
<style>
  body{margin:0;min-height:100vh;display:grid;place-items:center;background:#fafaf7;
    font-family:"Plus Jakarta Sans",system-ui,sans-serif;color:#1a1a17;}
  form{background:#fff;border:1px solid #e8e6df;border-radius:12px;padding:32px;width:min(92vw,380px);
    box-shadow:0 4px 8px rgba(5,15,104,.06),0 16px 32px rgba(5,15,104,.12);}
  .eyebrow{font-family:"Hanken Grotesk",sans-serif;font-weight:800;font-size:12px;letter-spacing:.14em;
    text-transform:uppercase;color:#820933;margin:0 0 6px;}
  h1{font-family:"Hanken Grotesk",sans-serif;font-weight:800;letter-spacing:-.02em;font-size:26px;margin:0 0 6px;}
  p{color:#6b6b66;font-size:14px;margin:0 0 20px;}
  input{width:100%;box-sizing:border-box;padding:11px 13px;border:1px solid #e8e6df;border-radius:8px;
    font-family:"JetBrains Mono",monospace;font-size:14px;margin-bottom:14px;}
  button{width:100%;padding:11px;border:0;border-radius:8px;background:#050f68;color:#fff;
    font-family:"Hanken Grotesk",sans-serif;font-weight:600;font-size:15px;cursor:pointer;}
  button:hover{background:#040b50;}
</style></head>
<body>
<form method="get" action="/wiki">
  <p class="eyebrow">Intern</p>
  <h1>API-Wiki</h1>
  <p>Bitte mit dem Admin-Token anmelden, um die API-Referenz zu sehen.</p>
  <input type="password" name="token" placeholder="Admin-Token" autocomplete="off" autofocus required />
  <button type="submit">Anmelden</button>
</form>
</body></html>
HTML;
    }
}
