<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Http;

/** Immutable snapshot of an upstream response. */
final class ProxyResponse
{
    /** @param array<string, string[]> $headers name => list of values */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }
}
