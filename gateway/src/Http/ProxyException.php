<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Http;

/** Thrown when an upstream is unreachable or the transport fails. */
final class ProxyException extends \RuntimeException
{
}
