<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Tests\Http;

use PHPUnit\Framework\TestCase;
use Tds\ApiGateway\Http\HeaderFilter;

final class HeaderFilterTest extends TestCase
{
    public function testRequestDropsHostHopByHopAndLength(): void
    {
        $filtered = HeaderFilter::forRequest([
            'Host' => ['api.tracht-digital.de'],
            'Connection' => ['keep-alive'],
            'Content-Length' => ['42'],
            'Authorization' => ['Bearer t'],
            'Cookie' => ['tds_session=abc'],
        ]);

        self::assertArrayNotHasKey('Host', $filtered);
        self::assertArrayNotHasKey('Connection', $filtered);
        self::assertArrayNotHasKey('Content-Length', $filtered);
        self::assertSame(['Bearer t'], $filtered['Authorization']);
        self::assertSame(['tds_session=abc'], $filtered['Cookie']);
    }

    public function testResponseDropsLengthAndTransferEncodingKeepsCookies(): void
    {
        $filtered = HeaderFilter::forResponse([
            'Content-Type' => ['application/json'],
            'Content-Length' => ['5'],
            'Transfer-Encoding' => ['chunked'],
            'Set-Cookie' => ['a=1', 'b=2'],
        ]);

        self::assertSame(['application/json'], $filtered['Content-Type']);
        self::assertArrayNotHasKey('Content-Length', $filtered);
        self::assertArrayNotHasKey('Transfer-Encoding', $filtered);
        self::assertSame(['a=1', 'b=2'], $filtered['Set-Cookie']);
    }

    public function testDropIsCaseInsensitive(): void
    {
        $filtered = HeaderFilter::forResponse(['content-length' => ['9']]);
        self::assertArrayNotHasKey('content-length', $filtered);
    }

    public function testDropsEveryHopByHopHeader(): void
    {
        $filtered = HeaderFilter::forResponse([
            'Proxy-Authenticate' => ['Basic'],
            'TE' => ['trailers'],
            'Trailer' => ['Expires'],
            'Upgrade' => ['h2c'],
            'Keep-Alive' => ['timeout=5'],
            'Content-Type' => ['text/plain'],
        ]);

        self::assertArrayNotHasKey('Proxy-Authenticate', $filtered);
        self::assertArrayNotHasKey('TE', $filtered);
        self::assertArrayNotHasKey('Trailer', $filtered);
        self::assertArrayNotHasKey('Upgrade', $filtered);
        self::assertArrayNotHasKey('Keep-Alive', $filtered);
        self::assertSame(['text/plain'], $filtered['Content-Type']);
    }
}
