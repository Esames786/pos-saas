<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * UB-06 — this form was already posted. `$same` = the same contents (show the record that exists; nothing was
 * posted twice); otherwise the key came back with different contents and nothing was posted.
 */
final class AlreadySubmitted extends RuntimeException
{
    public function __construct(public readonly Model $record, public readonly bool $same)
    {
        parent::__construct($same
            ? 'This was already posted — nothing was posted twice.'
            : 'This form was already posted with different items. Open a new form to post another one.');
    }
}
