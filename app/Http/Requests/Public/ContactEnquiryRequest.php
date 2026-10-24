<?php

namespace App\Http\Requests\Public;

use App\Models\Master\ContactEnquiry;
use App\Rules\RecaptchaPassed;
use App\Services\Saas\Recaptcha;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * CONTACT-FORM-1 — what a public "Contact Sales" enquiry must contain.
 *
 * Teen pardey, teen alag kaam ke liye:
 *   - `throttle` middleware — aik IP se bohat saari koshishein (route par, controller se pehle)
 *   - honeypot `website` — bot jo har khana bhar deta hai (asli visitor ko ye dikhta hi nahi)
 *   - reCAPTCHA — tabhi jab dono keys lagi hon, bilkul Start Trial ki tarah
 *
 * Teenon ki zaroorat hai: throttle gintee rokta hai niyat nahi, honeypot sasta hai magar behtar
 * bot usay chhoR dete hain, aur reCAPTCHA mehnga hai magar insaan maangta hai.
 */
class ContactEnquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => Str::of((string) $this->input('email'))->lower()->trim()->toString(),
            'name'  => trim((string) $this->input('name')),
        ]);
    }

    public function rules(): array
    {
        return [
            // RECAPTCHA-TRIAL-1 ka wohi rule — sirf tab jab dono keys configured hon.
            ...(Recaptcha::enabled() ? ['g-recaptcha-response' => ['bail', 'required', 'string', new RecaptchaPassed]] : []),

            'name'          => ['required', 'string', 'max:255'],
            'email'         => ['required', 'email', 'max:255'],
            'phone'         => ['nullable', 'string', 'max:50'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'topic'         => ['nullable', Rule::in(array_keys(ContactEnquiry::TOPICS))],
            // Chhota sa "hi" koi enquiry nahi hoti, aur 5000 harf se zyada koi likhta nahi —
            // us se aage sirf spam hota hai.
            'message'       => ['required', 'string', 'min:10', 'max:5000'],
            // Jis plan ke link se aaya (contact?plan=enterprise) — sirf nishaani, koi faisla nahi.
            'plan_hint'     => ['nullable', 'string', 'max:100'],

            // Honeypot: asli visitor ko ye khana dikhta hi nahi, bot aksar bhar dete hain.
            'website'       => ['nullable', 'size:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'message.min' => __('Please tell us a little more so we can help.'),
            // Bot ko ye batana ke wo pakRa gaya us ko agli baar behtar bana deta hai.
            'website.size' => __('Your message could not be sent. Please try again.'),
            'g-recaptcha-response.required' => __('Please tick “I’m not a robot” and try again.'),
        ];
    }

    /** Jo enquiry me jayega — token aur honeypot yahin khatam. */
    public function enquiryData(): array
    {
        return Arr::except($this->validated(), ['g-recaptcha-response', 'website']);
    }
}
