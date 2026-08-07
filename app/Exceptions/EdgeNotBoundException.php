<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * EDGE-LOCAL-RUNTIME-1 (fix 4) — thrown by the STRICT branch-context path when a Branch Server has no
 * imported branch binding yet (the appliance is uninitialised). It is deliberately DISTINCT from a
 * database/schema error: "not bound" must never be confused with "the local database failed", so the
 * strict path throws this for the former and lets genuine DB exceptions propagate for the latter.
 */
class EdgeNotBoundException extends RuntimeException
{
}
