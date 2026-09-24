<?php

namespace App\Services\Sync;

/** Internal signal: one op in a batch was rejected, so the whole batch's transaction rolls back. */
class BatchRejected extends \RuntimeException
{
    public function __construct(public readonly array $ops)
    {
        parent::__construct('Sync batch rejected');
    }
}
