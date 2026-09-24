<?php

namespace App\Exceptions;

/**
 * A business-rule rejection (insufficient stock, closed period, duplicate name,
 * immutable ledger entry...). Rendered as HTTP 422 with a machine-readable
 * `errors.code` so clients can branch on it, and deliberately distinct from
 * a plain \Exception so infrastructure failures still surface as 500s.
 */
class BusinessRuleException extends \Exception
{
    public function __construct(
        string $message,
        private readonly string $errorCode = 'business_rule',
        private readonly array $meta = [],
    ) {
        parent::__construct($message);
    }

    public static function make(string $errorCode, string $message, array $meta = []): self
    {
        return new self($message, $errorCode, $meta);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function meta(): array
    {
        return $this->meta;
    }

    /** Shape used in the API envelope's `errors` key. */
    public function toErrors(): array
    {
        return ['code' => $this->errorCode] + $this->meta;
    }
}
