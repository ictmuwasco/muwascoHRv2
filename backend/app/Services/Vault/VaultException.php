<?php

declare(strict_types=1);

namespace App\Services\Vault;

/**
 * VaultException - typed failure for vault operations.
 *
 * The HTTP-shaped constants let the controller translate a service failure into
 * a response without the service knowing anything about HTTP. `NOT_FOUND` is
 * used deliberately for "exists but you may not see it" so the response does
 * not confirm that a vault exists at all.
 */
final class VaultException extends \RuntimeException
{
    public const VALIDATION = 'VAULT_VALIDATION';
    public const FORBIDDEN  = 'VAULT_FORBIDDEN';
    public const NOT_FOUND  = 'VAULT_NOT_FOUND';
    public const CONFLICT   = 'VAULT_CONFLICT';
    public const EXPIRED    = 'VAULT_EXPIRED';
    public const SERVER     = 'VAULT_SERVER_ERROR';

    private string $reason;

    public function __construct(string $message, string $reason = self::SERVER, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->reason = $reason;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * HTTP status for this failure.
     *
     * NOT_FOUND maps to 404 rather than 403 on purpose: a 403 on someone
     * else's vault tells an unauthorised caller that a vault exists, which is
     * itself a disclosure. 404 is indistinguishable from "no such employee".
     */
    public function getHttpStatus(): int
    {
        switch ($this->reason) {
            case self::VALIDATION:
                return 400;
            case self::FORBIDDEN:
                return 403;
            case self::NOT_FOUND:
            case self::EXPIRED:
                return 404;
            case self::CONFLICT:
                return 409;
            default:
                return 500;
        }
    }
}
