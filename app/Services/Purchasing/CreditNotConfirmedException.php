<?php

namespace App\Services\Purchasing;

/**
 * SUPPLIER-CREDIT-CLARITY-1 — a new invoice would use the supplier's advance and the person did not tick that on the
 * review. Thrown inside the invoice's transaction, so nothing is saved; the screen shows the message by the tick.
 */
class CreditNotConfirmedException extends \RuntimeException
{
}
