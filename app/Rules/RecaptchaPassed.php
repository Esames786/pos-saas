<?php

namespace App\Rules;

use App\Services\Saas\Recaptcha;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** RECAPTCHA-TRIAL-1: the Start Trial form's "I'm not a robot" box was ticked and Google agrees. */
class RecaptchaPassed implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! app(Recaptcha::class)->passes(is_string($value) ? $value : null, request()->ip())) {
            $fail(__('Please tick “I’m not a robot” and try again.'));
        }
    }
}
