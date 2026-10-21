<?php

namespace App\Support\Sacco;

/**
 * How a computed amount meets the minor unit (ADR 0009 §2, AK-09). The one rule is HALF_UP: half a minor unit
 * or more goes away from zero, applied once per computed amount. DOWN (towards zero) and UP (away from zero)
 * exist for caps and floors that must never round past a legal limit.
 */
enum Rounding: string
{
    case HALF_UP = 'half_up';
    case DOWN = 'down';
    case UP = 'up';
}
