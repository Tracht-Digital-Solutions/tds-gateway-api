<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Dispatch;

/**
 * Thrown when an in-process dispatch can't be carried out — an unknown prefix,
 * a missing service autoloader/bootstrap, or the service app throwing during
 * construction/handling. {@see \Tds\ApiGateway\Action\DispatchAction} maps it
 * to a 502, mirroring the proxy mode's "upstream unavailable".
 */
final class DispatchException extends \RuntimeException
{
}
