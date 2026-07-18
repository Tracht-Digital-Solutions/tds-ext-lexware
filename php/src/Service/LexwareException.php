<?php
declare(strict_types=1);

namespace Tds\Ext\Lexware\Service;

use RuntimeException;

/**
 * Raised when the Lexware Office API rejects a request or is unreachable.
 * Carries the upstream HTTP status (0 for a transport error) so the route can
 * map it to a sensible response (502 on rejection, etc.).
 */
final class LexwareException extends RuntimeException
{
    public function __construct(string $message, private readonly int $httpStatus = 0)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
