<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by ShiftService for controlled shift outcomes (already-open, no-open-shift-required,
 * cannot-close-with-open-work). Callers translate it to a business response — never a 500.
 */
class ShiftException extends RuntimeException
{
}
