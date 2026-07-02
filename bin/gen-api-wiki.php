<?php
declare(strict_types=1);

/**
 * gen-api-wiki.php — generate the TDS API wiki from the live route
 * definitions.
 *
 * It parses each service's `src/Bootstrap.php` (the gateway + the four
 * micro-backends), extracts every Slim route registration — including
 * grouped routes and the auth middleware on them — and writes:
 *
 *   API-WIKI.md      a Markdown reference (one section per service)
 *   wiki/index.html  a self-contained, styled HTML wiki (design-system
 *                    type + colours)
 *   wiki/data.json   the same route map as structured JSON, consumed by the
 *                    in-panel admin wiki (tds-admin) via the gateway's
 *                    gated /wiki.json endpoint
 *
 * Because it reads the routes themselves, **new routes appear in the
 * wiki automatically** — wire it into CI (see .github/workflows/build.yml)
 * and the docs never drift from the code.
 *
 * Usage:
 *   php bin/gen-api-wiki.php [output-dir]
 *
 * output-dir defaults to the current working directory. The generator
 * auto-discovers each service's Bootstrap.php across the dev layout
 * (sibling repos), the assembled bundle (gateway/ + services/<name>/)
 * and the CI checkout (_src/<name>/).
 */

// name → [label, public path prefix, Bootstrap.php candidate locations].
// Public prefix mirrors the gateway routing: /auth and /customer strip
// their prefix upstream (so the upstream route is prefixed back here),
// while contact keeps its literal /contact and content mounts under
// /content. The gateway's own routes live at the API root.
$SERVICES = [
    'gateway' => [
        'label' => 'API-Gateway',
        'prefix' => '',
        'blurb' => 'Single public entry. Proxies every other path to the matching backend; only the two routes below are served by the gateway itself.',
        'candidates' => ['src/Bootstrap.php', 'gateway/src/Bootstrap.php', '_src/gateway/src/Bootstrap.php'],
    ],
    'auth' => [
        'label' => 'Auth-API',
        'prefix' => '/auth',
        'blurb' => 'RS256 JWT issuance + JWKS, admin + customer login, sessions.',
        'candidates' => ['../tds-auth-api/src/Bootstrap.php', 'services/auth/src/Bootstrap.php', '_src/auth/src/Bootstrap.php'],
    ],
    'contact' => [
        'label' => 'Contact-API',
        'prefix' => '',
        'blurb' => 'Contact form → Resend email, with per-IP rate limiting.',
        'candidates' => ['../tds-contact-api/src/Bootstrap.php', 'services/contact/src/Bootstrap.php', '_src/contact/src/Bootstrap.php'],
    ],
    'content' => [
        'label' => 'Content-API',
        'prefix' => '/content',
        'blurb' => 'Blog posts + media; admin deployment/version panel.',
        'candidates' => ['../tds-content-api/src/Bootstrap.php', 'services/content/src/Bootstrap.php', '_src/content/src/Bootstrap.php'],
    ],
    'customer' => [
        'label' => 'Customer-API',
        'prefix' => '/customer',
        'blurb' => 'Customers, projects, invoices (Stripe), documents, messages, time tracking, Lexware export.',
        'candidates' => ['../tds-customer-api/src/Bootstrap.php', 'services/customer/src/Bootstrap.php', '_src/customer/src/Bootstrap.php'],
    ],
];

/** Map an `->add(...)` middleware argument to a human auth label. */
function authLabel(string $addArg): ?string
{
    if (str_contains($addArg, '$admin') || str_contains($addArg, 'AdminAuthMiddleware')) {
        return 'Admin-Token';
    }
    if (str_contains($addArg, 'CustomerAuthMiddleware')) {
        return 'Customer-JWT';
    }
    if (str_contains($addArg, '$auth') || str_contains($addArg, 'JwksAuthMiddleware')) {
        return 'JWT';
    }
    return null; // $audit / body parsing / unknown → not an auth gate
}

/**
 * Parse one Bootstrap.php into a list of routes.
 *
 * @return array<int,array{methods:string,path:string,auth:string,handler:string}>
 */
function parseRoutes(string $file, string $prefix): array
{
    $src = file_get_contents($file);
    if ($src === false) {
        return [];
    }
    $lines = preg_split('/\r\n|\r|\n/', $src) ?: [];

    $routes = [];
    /** @var array<int,array{prefix:string,depth:int,idx:int[]}> $stack */
    $stack = [];
    $depth = 0;

    $groupPrefix = static function () use (&$stack): string {
        $p = '';
        foreach ($stack as $g) {
            $p .= $g['prefix'];
        }
        return $p;
    };
    $pushIdx = static function (int $i) use (&$stack): void {
        if ($stack !== []) {
            $stack[count($stack) - 1]['idx'][] = $i;
        }
    };

    foreach ($lines as $line) {
        // A grouped block opening on this line: ->group('/prefix', function (...) {
        $pendingGroup = null;
        if (preg_match("/->group\\(\\s*'([^']*)'/", $line, $gm) === 1) {
            $pendingGroup = $gm[1];
        }

        // Single-method routes: ->get('/path', Handler::class) [->add(...)]*
        if (preg_match_all(
            "/->(get|post|put|patch|delete|options)\\(\\s*'([^']*)'\\s*,\\s*([A-Za-z0-9_\\\\]+)::class\\s*\\)((?:->add\\([^)]*\\))*)/",
            $line,
            $rm,
            PREG_SET_ORDER,
        ) === 1 || !empty($rm)) {
            foreach ($rm as $r) {
                $auth = 'öffentlich';
                if (preg_match_all("/->add\\(([^)]*)\\)/", $r[4], $am) >= 1) {
                    foreach ($am[1] as $arg) {
                        $label = authLabel($arg);
                        if ($label !== null) {
                            $auth = $label;
                        }
                    }
                }
                $routes[] = [
                    'methods' => strtoupper($r[1]),
                    'path' => $prefix . $groupPrefix() . $r[2],
                    'auth' => $auth,
                    'handler' => ltrim($r[3], '\\'),
                ];
                $pushIdx(count($routes) - 1);
            }
        }

        // Multi-method map(): ->map(['GET','POST'], '/path', Handler::class)
        if (preg_match(
            "/->map\\(\\s*\\[([^\\]]*)\\]\\s*,\\s*'([^']*)'\\s*,\\s*([A-Za-z0-9_\\\\]+)::class/",
            $line,
            $mm,
        ) === 1) {
            preg_match_all("/'([A-Za-z]+)'/", $mm[1], $methods);
            $routes[] = [
                'methods' => implode(', ', array_map('strtoupper', $methods[1])),
                'path' => $prefix . $groupPrefix() . $mm[2],
                'auth' => 'öffentlich',
                'handler' => ltrim($mm[3], '\\'),
            ];
            $pushIdx(count($routes) - 1);
        }

        // Brace bookkeeping for group scope.
        $opens = substr_count($line, '{');
        $closes = substr_count($line, '}');

        if ($pendingGroup !== null && $opens > 0) {
            $depth += $opens;
            $stack[] = ['prefix' => $pendingGroup, 'depth' => $depth, 'idx' => []];
            $depth -= $closes;
        } else {
            $depth += $opens - $closes;
        }

        // Pop any groups whose scope just closed; the closing line often
        // carries the group's auth middleware (`})->add($admin);`) — stamp
        // it onto the group's routes that are still public.
        while ($stack !== [] && $depth < $stack[count($stack) - 1]['depth']) {
            $closed = array_pop($stack);
            if (preg_match_all("/->add\\(([^)]*)\\)/", $line, $am) >= 1) {
                $label = null;
                foreach ($am[1] as $arg) {
                    $label = authLabel($arg) ?? $label;
                }
                if ($label !== null) {
                    foreach ($closed['idx'] as $i) {
                        if ($routes[$i]['auth'] === 'öffentlich') {
                            $routes[$i]['auth'] = $label;
                        }
                    }
                }
            }
        }
    }

    return $routes;
}

// ---- Collect ---------------------------------------------------------------
$outDir = rtrim($argv[1] ?? getcwd(), '/\\');
$collected = [];
$sources = [];
foreach ($SERVICES as $key => $svc) {
    $file = null;
    foreach ($svc['candidates'] as $cand) {
        if (is_file($cand)) {
            $file = $cand;
            break;
        }
    }
    if ($file === null) {
        fwrite(STDERR, "[gen-api-wiki] WARN: no Bootstrap.php found for {$key} — skipping.\n");
        continue;
    }
    $sources[$key] = $file;
    $collected[$key] = parseRoutes($file, $svc['prefix']);
}

$generatedAt = gmdate('Y-m-d H:i') . ' UTC';
$totalRoutes = array_sum(array_map('count', $collected));

// ---- Markdown --------------------------------------------------------------
$md = "# TDS API — Wiki\n\n";
$md .= "> **Auto-generiert** von `tds-api-gateway/bin/gen-api-wiki.php` aus den\n";
$md .= "> Slim-Routendefinitionen der Services. Nicht von Hand bearbeiten — neue\n";
$md .= "> Routen erscheinen beim nächsten Build automatisch.\n\n";
$md .= "Öffentliche Basis-URL: `https://api.tracht-digital.de` · Stand: {$generatedAt} · {$totalRoutes} Routen\n\n";
$md .= "Auth-Spalte: **öffentlich** (kein Gate) · **Admin-Token** (Bearer `ADMIN_TOKEN`) · ";
$md .= "**JWT** (Customer-Cookie/Bearer, JWKS-verifiziert) · **Customer-JWT** (Login-Session).\n\n";

$md .= "## Inhalt\n\n";
foreach ($SERVICES as $key => $svc) {
    if (!isset($collected[$key])) {
        continue;
    }
    $anchor = strtolower(str_replace([' ', '-'], '-', $svc['label']));
    $md .= "- [{$svc['label']}](#" . $anchor . ") — " . count($collected[$key]) . " Routen\n";
}
$md .= "\n";

foreach ($SERVICES as $key => $svc) {
    if (!isset($collected[$key])) {
        continue;
    }
    $md .= "## {$svc['label']}\n\n";
    $md .= $svc['blurb'] . "\n\n";
    $md .= "_Quelle: `{$sources[$key]}`_\n\n";
    if ($collected[$key] === []) {
        $md .= "_Keine Routen erkannt._\n\n";
        continue;
    }
    $md .= "| Methode | Pfad | Auth | Handler |\n|---|---|---|---|\n";
    foreach ($collected[$key] as $r) {
        $path = $r['path'] === '' ? '/' : $r['path'];
        $md .= "| `{$r['methods']}` | `{$path}` | {$r['auth']} | `{$r['handler']}` |\n";
    }
    $md .= "\n";
    if ($key === 'gateway') {
        $md .= "> Alle übrigen Pfade werden anhand des ersten Segments an den passenden\n";
        $md .= "> Backend-Service weitergeleitet (`/auth/*`, `/content/*`, `/customer/*`,\n";
        $md .= "> `/contact`). Siehe die Service-Abschnitte für die effektiven Pfade.\n\n";
    }
}

file_put_contents($outDir . '/API-WIKI.md', $md);

// ---- HTML ------------------------------------------------------------------
$esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$navItems = '';
$sections = '';
foreach ($SERVICES as $key => $svc) {
    if (!isset($collected[$key])) {
        continue;
    }
    $id = 'svc-' . $key;
    $navItems .= "<a href=\"#{$id}\">" . $esc($svc['label'])
        . "<span class=\"count\">" . count($collected[$key]) . "</span></a>";

    $rows = '';
    foreach ($collected[$key] as $r) {
        $path = $r['path'] === '' ? '/' : $r['path'];
        $authClass = match ($r['auth']) {
            'Admin-Token' => 'auth-admin',
            'JWT', 'Customer-JWT' => 'auth-jwt',
            default => 'auth-public',
        };
        $methods = '';
        foreach (explode(', ', $r['methods']) as $m) {
            $methods .= "<span class=\"method m-" . strtolower($m) . "\">" . $esc($m) . "</span> ";
        }
        $rows .= "<tr><td class=\"methods\">{$methods}</td>"
            . "<td><code>" . $esc($path) . "</code></td>"
            . "<td><span class=\"badge {$authClass}\">" . $esc($r['auth']) . "</span></td>"
            . "<td class=\"handler\"><code>" . $esc($r['handler']) . "</code></td></tr>";
    }
    $body = $rows === ''
        ? '<p class="empty">Keine Routen erkannt.</p>'
        : "<table><thead><tr><th>Methode</th><th>Pfad</th><th>Auth</th><th>Handler</th></tr></thead><tbody>{$rows}</tbody></table>";

    $sections .= "<section id=\"{$id}\"><h2 class=\"t-h2\">" . $esc($svc['label']) . "</h2>"
        . "<p class=\"blurb\">" . $esc($svc['blurb']) . "</p>"
        . "<p class=\"src\">Quelle: <code>" . $esc($sources[$key]) . "</code></p>"
        . $body . "</section>";
}

$html = <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="robots" content="noindex" />
<title>TDS API — Wiki</title>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet" />
<style>
  :root {
    --haupt:#050f68; --akzent:#820933; --paper:#fafaf7; --soft:#f1efe8;
    --line:#e8e6df; --ink:#1a1a17; --muted:#6b6b66; --card:#fff;
    --font-display:"Hanken Grotesk",system-ui,sans-serif;
    --font-body:"Plus Jakarta Sans",system-ui,sans-serif;
    --font-mono:"JetBrains Mono",ui-monospace,monospace;
  }
  * { box-sizing:border-box; }
  body { margin:0; font-family:var(--font-body); color:var(--ink); background:var(--paper); line-height:1.5; }
  code { font-family:var(--font-mono); font-size:.85em; }
  .layout { display:grid; grid-template-columns:260px 1fr; gap:0; min-height:100vh; }
  nav.side { position:sticky; top:0; align-self:start; height:100vh; overflow-y:auto;
    border-right:1px solid var(--line); padding:28px 20px; background:var(--soft); }
  nav.side .brand { font-family:var(--font-display); font-weight:800; letter-spacing:-.02em;
    font-size:18px; color:var(--haupt); margin:0 0 4px; }
  nav.side .sub { font-size:12px; color:var(--muted); margin:0 0 24px; }
  nav.side a { display:flex; justify-content:space-between; align-items:center; gap:8px;
    text-decoration:none; color:var(--ink); padding:8px 12px; border-radius:8px;
    font-weight:500; font-size:14px; transition:background .15s; }
  nav.side a:hover { background:var(--card); }
  nav.side a .count { font-family:var(--font-mono); font-size:11px; color:var(--muted); }
  main { padding:48px clamp(24px,5vw,72px); max-width:980px; }
  .t-eyebrow { font-family:var(--font-display); font-weight:700; font-size:12px;
    letter-spacing:.14em; text-transform:uppercase; color:var(--akzent); margin:0 0 10px; }
  h1.t-h1 { font-family:var(--font-display); font-weight:800; letter-spacing:-.03em;
    font-size:clamp(34px,5vw,52px); line-height:1.02; margin:0 0 14px; }
  .lead { font-size:18px; color:var(--muted); max-width:62ch; margin:0 0 14px; }
  .legend { font-size:13px; color:var(--muted); margin:0 0 40px; }
  .t-h2 { font-family:var(--font-display); font-weight:800; letter-spacing:-.02em;
    font-size:30px; margin:48px 0 8px; padding-top:24px; border-top:1px solid var(--line); }
  section:first-of-type .t-h2 { border-top:0; padding-top:0; }
  .blurb { color:var(--muted); margin:0 0 4px; max-width:64ch; }
  .src { font-size:12px; color:var(--muted); margin:0 0 18px; }
  table { width:100%; border-collapse:collapse; font-size:14px; }
  thead th { text-align:left; font-family:var(--font-display); font-weight:600; font-size:12px;
    letter-spacing:.04em; text-transform:uppercase; color:var(--muted);
    padding:8px 12px; border-bottom:1px solid var(--line); }
  tbody td { padding:10px 12px; border-bottom:1px solid var(--line); vertical-align:top; }
  td.methods { white-space:nowrap; }
  td.handler { color:var(--muted); }
  .method { font-family:var(--font-mono); font-size:11px; font-weight:600; padding:2px 6px;
    border-radius:4px; color:#fff; display:inline-block; }
  .m-get{background:#146c43;} .m-post{background:var(--haupt);} .m-put{background:#8a5a00;}
  .m-patch{background:#9c1c44;} .m-delete{background:#a51d1d;} .m-options{background:var(--muted);}
  .badge { font-size:11px; font-weight:600; padding:2px 8px; border-radius:999px; white-space:nowrap; }
  .auth-public { background:var(--soft); color:var(--muted); }
  .auth-admin { background:#fff4d6; color:#8a5a00; }
  .auth-jwt { background:var(--info-bg,#eef0fa); color:var(--haupt); }
  .empty { color:var(--muted); font-style:italic; }
  footer { color:var(--muted); font-size:12px; margin-top:56px; padding-top:20px; border-top:1px solid var(--line); }
  @media (max-width:820px){ .layout{grid-template-columns:1fr;} nav.side{position:static;height:auto;} }
</style>
</head>
<body>
<div class="layout">
  <nav class="side">
    <p class="brand">Tracht <span style="color:var(--akzent)">API</span></p>
    <p class="sub">Wiki · auto-generiert</p>
    {$navItems}
  </nav>
  <main>
    <p class="t-eyebrow">Referenz</p>
    <h1 class="t-h1">TDS API — Wiki</h1>
    <p class="lead">Vollständige Routenübersicht aller Services hinter <code>api.tracht-digital.de</code>. Generiert direkt aus den Routendefinitionen — neue Routen erscheinen beim nächsten Build automatisch.</p>
    <p class="legend">Auth: <span class="badge auth-public">öffentlich</span> kein Gate · <span class="badge auth-admin">Admin-Token</span> Bearer ADMIN_TOKEN · <span class="badge auth-jwt">JWT</span> JWKS-verifiziert · Stand {$generatedAt} · {$totalRoutes} Routen</p>
    {$sections}
    <footer>Auto-generiert von <code>tds-api-gateway/bin/gen-api-wiki.php</code>. Quelle der Wahrheit sind die <code>src/Bootstrap.php</code> der Services.</footer>
  </main>
</div>
</body>
</html>
HTML;

@mkdir($outDir . '/wiki', 0775, true);
file_put_contents($outDir . '/wiki/index.html', $html);

// ---- JSON ------------------------------------------------------------------
// Structured route map for the in-panel admin wiki (tds-admin). Served gated
// by the gateway's /wiki.json endpoint; the same $collected data as the HTML.
$servicesJson = [];
foreach ($SERVICES as $key => $svc) {
    if (!isset($collected[$key])) {
        continue;
    }
    $servicesJson[] = [
        'key' => $key,
        'label' => $svc['label'],
        'prefix' => $svc['prefix'],
        'blurb' => $svc['blurb'],
        'source' => $sources[$key],
        'routes' => array_map(static fn (array $r): array => [
            'methods' => $r['methods'],
            'path' => $r['path'] === '' ? '/' : $r['path'],
            'auth' => $r['auth'],
            'handler' => $r['handler'],
        ], $collected[$key]),
    ];
}
file_put_contents($outDir . '/wiki/data.json', json_encode([
    'generatedAt' => $generatedAt,
    'baseUrl' => 'https://api.tracht-digital.de',
    'totalRoutes' => $totalRoutes,
    'services' => $servicesJson,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

echo "[gen-api-wiki] wrote {$outDir}/API-WIKI.md, {$outDir}/wiki/index.html and {$outDir}/wiki/data.json ({$totalRoutes} routes across " . count($collected) . " services).\n";
