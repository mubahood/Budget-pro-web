<?php

namespace App\Services\Sacco\Loans;

/** One instalment of a schedule, in whole minor units (the pure result of Schedule::build). */
final class ScheduleLine implements \JsonSerializable
{
    public function __construct(
        public readonly int $no,
        public readonly string $dueOn,
        public readonly int $openingPrincipal,
        public readonly int $principal,
        public readonly int $interest,
        public readonly int $fee = 0,
    ) {
    }

    public function instalment(): int
    {
        return $this->principal + $this->interest + $this->fee;
    }

    /** @return array{installment_no: int, due_on: string, opening_principal_minor: int, principal_due_minor: int, interest_due_minor: int, fee_due_minor: int, instalment_minor: int} */
    public function jsonSerialize(): array
    {
        return ['installment_no' => $this->no, 'due_on' => $this->dueOn, 'opening_principal_minor' => $this->openingPrincipal, 'principal_due_minor' => $this->principal,
            'interest_due_minor' => $this->interest, 'fee_due_minor' => $this->fee, 'instalment_minor' => $this->instalment()];
    }
}
