<?php

namespace App\Services\Finance;

use RuntimeException;

/**
 * VOID-PURGE-1 (owner, 23 Oct): an entry voided on a different day may be deleted too, but only after the person has
 * been told which days' cash closing will change, and said yes. Nothing has been changed when this is thrown.
 */
final class VoidPurgeNeedsConfirmation extends RuntimeException
{
}
