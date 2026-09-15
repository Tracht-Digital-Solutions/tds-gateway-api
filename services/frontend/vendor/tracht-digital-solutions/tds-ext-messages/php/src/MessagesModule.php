<?php
declare(strict_types=1);

namespace Tds\Ext\Messages;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Tds\Ext\Messages\Domain\MessageRepository;
use Tds\Frontend\Contract\AbstractModule;
use Tds\Frontend\Contract\ApiDocSource;
use Tds\Frontend\Contract\PermissionDef;
use Tds\Frontend\Contract\UserContext;

/**
 * Backend Module for the customer↔owner message thread, ported from
 * tds-customer-api's Message actions onto the frontend platform.
 *
 * Auth comes entirely from the core {@see UserContext} — routes require
 * `messages:read`/`messages:write` (admins bypass) and scope by the active
 * company. `customer_id` = the JWT's active company id; an admin with no active
 * company sees every company's thread. Data via the core shared PDO.
 */
final class MessagesModule extends AbstractModule implements ApiDocSource
{
    public function id(): string
    {
        return 'messages';
    }

    /** @return PermissionDef[] */
    public function permissions(): array
    {
        return [
            new PermissionDef('messages:read', 'Nachrichten ansehen', 'messages'),
            new PermissionDef('messages:write', 'Nachrichten schreiben', 'messages'),
        ];
    }

    /** @return string[] */
    public function migrations(): array
    {
        return [__DIR__ . '/../db/migrations'];
    }

    public function register(App $app): void
    {
        $c = $app->getContainer();
        if ($c !== null && !$c->has(MessageRepository::class)) {
            $c->set(MessageRepository::class, static fn ($c) => new MessageRepository($c->get(PDO::class)));
        }

        // GET /messages/summary — unread count for the dashboard widget.
        $app->get('/messages/summary', function (Request $req, Response $res) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (($deny = self::require($user, 'messages:read', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(MessageRepository::class);
            $cid = self::scopeCompanyId($user);
            return self::json($res, ['unread' => $repo->unreadCount($cid, $user->isAdmin())]);
        });

        // GET /messages?projectId= — the thread (marks counterpart msgs read).
        $app->get('/messages', function (Request $req, Response $res) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (($deny = self::require($user, 'messages:read', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(MessageRepository::class);
            $cid = self::scopeCompanyId($user);
            $pid = self::intParam($req->getQueryParams()['projectId'] ?? null);
            $rows = $repo->listForCustomer($cid, $pid);
            $repo->markRead($cid, $user->isAdmin());
            return self::json($res, ['messages' => $rows]);
        });

        // POST /messages — { body, projectId? }. author_type derived from role.
        $app->post('/messages', function (Request $req, Response $res) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (($deny = self::require($user, 'messages:write', $res)) !== null) {
                return $deny;
            }
            $body = $req->getParsedBody();
            $text = is_array($body) ? trim((string) ($body['body'] ?? '')) : '';
            if ($text === '' || mb_strlen($text) > 10000) {
                return self::json($res, ['error' => 'body must be 1-10000 chars'], 422);
            }
            $cid = $user->activeCompanyId() !== null ? (int) $user->activeCompanyId() : null;
            if ($cid === null && !$user->isAdmin()) {
                return self::json($res, ['error' => 'No active company'], 422);
            }
            $pid = is_array($body) ? self::intParam($body['projectId'] ?? null) : null;
            $repo = $c->get(MessageRepository::class);
            $id = $repo->create($cid, $pid, $user->isAdmin() ? 'owner' : 'customer', $text);
            return self::json($res, ['id' => $id], 201);
        });

        // PATCH /messages/{id} — edit body in place (admin any; customer own).
        $app->patch('/messages/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (($deny = self::require($user, 'messages:write', $res)) !== null) {
                return $deny;
            }
            $body = $req->getParsedBody();
            if (!is_array($body) || !isset($body['body'])) {
                return self::json($res, ['error' => 'body required'], 400);
            }
            $text = trim((string) $body['body']);
            if ($text === '' || mb_strlen($text) > 10000) {
                return self::json($res, ['error' => 'body must be 1-10000 chars'], 422);
            }
            $cid = $user->activeCompanyId() !== null ? (int) $user->activeCompanyId() : null;
            $repo = $c->get(MessageRepository::class);
            $ok = $repo->update((int) $args['id'], $text, $cid, $user->isAdmin());
            return $ok ? self::json($res, ['id' => (int) $args['id']]) : self::json($res, ['error' => 'Not found'], 404);
        });
    }

    /** Active company scope: admin w/o active company → null (all); else the company id. */
    private static function scopeCompanyId(UserContext $user): ?int
    {
        if ($user->isAdmin() && $user->activeCompanyId() === null) {
            return null;
        }
        return $user->activeCompanyId() !== null ? (int) $user->activeCompanyId() : null;
    }

    private static function intParam(mixed $v): ?int
    {
        return $v !== null && ctype_digit((string) $v) ? (int) $v : null;
    }

    private static function require(UserContext $user, string $permission, Response $res): ?Response
    {
        if (!$user->isAuthenticated()) {
            return self::json($res, ['error' => 'Unauthorized'], 401);
        }
        if (!$user->has($permission)) {
            return self::json($res, ['error' => 'Forbidden'], 403);
        }
        return null;
    }

    private static function json(Response $res, mixed $data, int $status = 200): Response
    {
        $res->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $res->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Route documentation for the admin frontend's API reference. Kept in its
     * own file so the prose does not sit in the middle of the wiring.
     *
     * @return list<array<string, mixed>>
     */
    public function apiDocs(): array
    {
        return require __DIR__ . '/../docs/api.php';
    }
}
