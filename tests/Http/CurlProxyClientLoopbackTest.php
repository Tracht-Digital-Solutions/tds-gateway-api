<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Tests\Http;

use PHPUnit\Framework\TestCase;
use Tds\ApiGateway\Http\CurlProxyClient;
use Tds\ApiGateway\Http\ProxyException;

/**
 * Exercises the real {@see CurlProxyClient} against a throwaway `php -S`
 * server, so the actual cURL transport (header parsing, body forwarding,
 * repeated Set-Cookie, curl_multi fan-out, connect failure) is covered — the
 * fakes can't reach it. Skips cleanly when a server can't be spawned, so it
 * never reds CI on a constrained runner.
 */
final class CurlProxyClientLoopbackTest extends TestCase
{
    /** @var resource|null */
    private $proc = null;
    private int $port = 0;
    private int $deadPort = 0;
    private string $router = '';
    private string $log = '';

    protected function setUp(): void
    {
        $this->port = $this->freePort();
        // A second free port we deliberately leave unbound for the
        // connection-refused cases.
        $this->deadPort = $this->freePort();

        $this->router = sys_get_temp_dir() . '/tds-router-' . uniqid() . '.php';
        $this->log = sys_get_temp_dir() . '/tds-router-' . uniqid() . '.log';
        file_put_contents($this->router, $this->routerSource());

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $this->log, 'a'],
            2 => ['file', $this->log, 'a'],
        ];
        $proc = @proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, $this->router],
            $descriptors,
            $pipes,
        );
        if (!is_resource($proc)) {
            self::markTestSkipped('Could not spawn php -S for the loopback test.');
        }
        $this->proc = $proc;
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        if (!$this->waitUntilUp()) {
            $this->stopServer();
            self::markTestSkipped('Loopback php -S server did not come up in time.');
        }
    }

    protected function tearDown(): void
    {
        $this->stopServer();
        @unlink($this->router);
        @unlink($this->log);
    }

    public function testForwardsMethodQueryBodyAndHeaders(): void
    {
        $client = new CurlProxyClient(connectTimeout: 1, timeout: 5);
        $res = $client->send(
            'POST',
            $this->url('/echo?x=1'),
            ['X-Test' => ['hello']],
            '{"a":1}',
        );

        self::assertSame(200, $res->status);
        $decoded = json_decode($res->body, true);
        self::assertSame('POST', $decoded['method']);
        self::assertSame('/echo', $decoded['path']);
        self::assertSame('x=1', $decoded['query']);
        self::assertSame('{"a":1}', $decoded['body']);
        self::assertSame('hello', $decoded['xtest']);
    }

    public function testGetCarriesNoBody(): void
    {
        $client = new CurlProxyClient(connectTimeout: 1, timeout: 5);
        $res = $client->send('GET', $this->url('/echo'), [], 'should-be-ignored');

        self::assertSame(200, $res->status);
        $decoded = json_decode($res->body, true);
        self::assertSame('', $decoded['body']);
    }

    public function testPreservesMultipleSetCookieHeaders(): void
    {
        $client = new CurlProxyClient(connectTimeout: 1, timeout: 5);
        $res = $client->send('GET', $this->url('/cookies'), [], '');

        self::assertSame(200, $res->status);
        self::assertArrayHasKey('Set-Cookie', $res->headers);
        self::assertSame(['a=1', 'b=2'], $res->headers['Set-Cookie']);
    }

    public function testConnectionRefusedThrows(): void
    {
        $client = new CurlProxyClient(connectTimeout: 1, timeout: 2);
        $this->expectException(ProxyException::class);
        $client->send('GET', 'http://127.0.0.1:' . $this->deadPort . '/x', [], '');
    }

    public function testSendManyReturnsStatusZeroForDeadUpstream(): void
    {
        $client = new CurlProxyClient(connectTimeout: 1, timeout: 5);
        $results = $client->sendMany([
            'live' => ['method' => 'GET', 'url' => $this->url('/echo'), 'headers' => [], 'body' => ''],
            'dead' => ['method' => 'GET', 'url' => 'http://127.0.0.1:' . $this->deadPort . '/x', 'headers' => [], 'body' => ''],
        ]);

        self::assertCount(2, $results);
        self::assertSame(200, $results['live']->status);
        self::assertSame(0, $results['dead']->status);
    }

    private function url(string $path): string
    {
        return 'http://127.0.0.1:' . $this->port . $path;
    }

    private function freePort(): int
    {
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            self::markTestSkipped('Could not allocate a loopback port.');
        }
        $name = (string) stream_socket_get_name($sock, false);
        fclose($sock);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function waitUntilUp(): bool
    {
        for ($i = 0; $i < 50; $i++) {
            $conn = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1);
            if (is_resource($conn)) {
                fclose($conn);
                return true;
            }
            usleep(100_000); // 100ms — up to ~5s total
        }
        return false;
    }

    private function stopServer(): void
    {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
            $this->proc = null;
        }
    }

    private function routerSource(): string
    {
        return <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/cookies') {
    header('Set-Cookie: a=1', false);
    header('Set-Cookie: b=2', false);
    echo 'cookies';
    return true;
}
header('Content-Type: application/json');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'path'   => $path,
    'query'  => $_SERVER['QUERY_STRING'] ?? '',
    'body'   => file_get_contents('php://input'),
    'xtest'  => $_SERVER['HTTP_X_TEST'] ?? '',
]);
return true;
PHP;
    }
}
