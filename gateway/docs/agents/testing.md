# Testing

PHPUnit 10, no DB, no network. `composer test` runs the suite.

| Area | Tests |
|---|---|
| Proxy mode | `tests/Support/FakeProxyClient` fakes `Http\ProxyClientInterface`; `ProxyActionTest` |
| In-process mode | `tests/Dispatch/InProcessDispatcherTest` (env isolation, path rewrite, 404, 502) with a fake app resolver and temp `.env`; `DispatchActionTest`, `InProcessHealthActionTest` |
| Default routing | `ServiceRegistryTest`, `ProxyActionTest`, `DispatchActionTest`: unmatched paths go to `frontend` verbatim; 404 only when `GATEWAY_DEFAULT_SERVICE` is disabled |
| Routing arithmetic | `tests/Config/ServiceTest.php`: prefixed services strip the segment, the catch-all forwards verbatim, `pathFor('')` → `/`, `healthUrl()` ignores the rewrite, dotted paths (`/.well-known/jwks.json`) survive |
| Health parsing | `tests/Support/HealthBodyTest.php`: `no-schema` / `down` gate; `null` ("nothing to gate on") stays distinct from `down`, asserted from both sides |
| CORS | `tests/Http/GatewayCorsTest.php`: own routes carry the header, the catch-all doesn't |
| Installer env helpers | `tests/Support/InstallEnvFileTest.php` |
| Audit policy | `tests/Support/ComposerAuditPolicyTest.php` |

A mistake in the routing arithmetic sends one backend's request to another (`/auth/admin/login` arriving at
the customer API) or produces an empty path the upstream 404s.

Mutation check on routing and health parsing: 17 breakages, 17 caught.

The installer script has no unit tests beyond its env helpers; validate with `php -l` and a built-in-server
smoke test.
