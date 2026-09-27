<?php

namespace App\Services\Fiscal;

/** What an adapter reports back (supermarket plan F2). `raw` must never hold keys, passwords or signatures. */
final class FiscalResult
{
    /** @param array{request?: mixed, response?: mixed} $raw */
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $fiscal_number = null,
        public readonly ?string $verification_code = null,
        public readonly ?string $qr_payload = null,
        public readonly array $raw = [],
        public readonly ?string $error = null,
        /** true = do not retry (the data itself is wrong, e.g. a missing goods code); still flagged to the owner */
        public readonly bool $permanent = false,
        /** true = nothing was sent: the adapter waits for someone (manual entry) */
        public readonly bool $deferred = false,
    ) {
    }

    public static function ok(string $number, ?string $code = null, ?string $qr = null, array $raw = []): self
    {
        return new self(true, $number, $code, $qr, $raw);
    }

    public static function fail(string $error, array $raw = [], bool $permanent = false): self
    {
        return new self(false, null, null, null, $raw, $error, $permanent);
    }

    public static function deferred(string $message): self
    {
        return new self(false, error: $message, deferred: true);
    }
}
