<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Support;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Decides whether a request to the internal wiki comes from an admin.
 *
 * Two accepted credentials, checked in order:
 *   1. The legacy shared ADMIN_TOKEN, presented as `Authorization: Bearer`,
 *      a `?token=` query param, or the `tds_wiki` cookie. Kept as a
 *      broken-glass fallback and for the standalone HTML login form.
 *   2. The Phase-4 admin session — the `tds_session` cookie tds-auth-api sets
 *      across `.tracht-digital.de`. We verify it by asking auth-api
 *      `GET /me` and requiring `isAdmin === true`; the gateway never sees the
 *      signing key, mirroring how the backends trust auth-api. This is the
 *      path the admin panel actually uses (it holds no ADMIN_TOKEN anymore).
 *
 * The `/me` call is injectable so unit tests never hit the network.
 */
final class AdminSessionVerifier
{
    public const SESSION_COOKIE = 'tds_session';
    public const LEGACY_COOKIE = 'tds_wiki';

    /** @var callable(string):(array<string,mixed>|null) */
    private $meFetcher;

    /**
     * @param callable(string):(array<string,mixed>|null)|null $meFetcher
     *        Given a `Cookie:` header value, returns auth-api's decoded /me
     *        body, or null on any failure. Defaults to a cURL call.
     */
    public function __construct(
        private readonly string $adminToken,
        private readonly string $authApiUrl,
        ?callable $meFetcher = null,
    ) {
        $this->meFetcher = $meFetcher ?? fn (string $cookie): ?array => $this->fetchMe($cookie);
    }

    /**
     * The wiki is only reachable when at least one credential path is
     * configured — otherwise nobody could ever authenticate, so the routes
     * 404 rather than sit open or error.
     */
    public function canAuthenticate(): bool
    {
        return $this->adminToken !== '' || $this->authApiUrl !== '';
    }

    /** Legacy shared token from the Bearer header, `?token=`, or tds_wiki cookie. */
    public function legacyToken(Request $request): ?string
    {
        $auth = $request->getHeaderLine('Authorization');
        if ($auth !== '' && preg_match('/^Bearer\s+(.+)$/i', $auth, $m) === 1) {
            return $m[1];
        }
        $q = $request->getQueryParams()['token'] ?? null;
        if (is_string($q) && $q !== '') {
            return $q;
        }
        $cookie = $request->getCookieParams()[self::LEGACY_COOKIE] ?? null;
        return is_string($cookie) && $cookie !== '' ? rawurldecode($cookie) : null;
    }

    public function tokenMatches(?string $token): bool
    {
        return $this->adminToken !== '' && $token !== null && hash_equals($this->adminToken, $token);
    }

    /** True when the request carries a valid admin credential (token or session). */
    public function isAdmin(Request $request): bool
    {
        if ($this->tokenMatches($this->legacyToken($request))) {
            return true;
        }
        $session = $request->getCookieParams()[self::SESSION_COOKIE] ?? null;
        if (!is_string($session) || $session === '' || $this->authApiUrl === '') {
            return false;
        }
        $me = ($this->meFetcher)(self::SESSION_COOKIE . '=' . $session);
        return is_array($me) && (($me['isAdmin'] ?? false) === true);
    }

    /** @return array<string,mixed>|null */
    private function fetchMe(string $cookieHeader): ?array
    {
        $ch = curl_init(rtrim($this->authApiUrl, '/') . '/me');
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Cookie: ' . $cookieHeader, 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $status !== 200) {
            return null;
        }
        $decoded = json_decode((string) $body, true);
        return is_array($decoded) ? $decoded : null;
    }
}
