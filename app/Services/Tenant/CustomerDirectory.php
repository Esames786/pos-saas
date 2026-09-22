<?php

namespace App\Services\Tenant;

use App\Models\Tenant\Customer;
use Illuminate\Support\Facades\DB;

/**
 * CUSTOMER-DIRECTORY-1 — one answer to "which customer is this phone?".
 *
 * The counter already learned this the hard way: creating customers mid-rush
 * added the same phone again and again, and a real book carried five
 * "tabish 0333…" rows. CustomerController grew a normalise-and-reuse rule to
 * stop it.
 *
 * Catering needs the SAME question answered, and a second copy of the rule is
 * how two copies drift: POS would match "0300-1234567" to an existing row and
 * catering would mint a new one, and nobody would notice until the book had two
 * of everybody. So the rule lives here once, and both callers ask it.
 *
 * Deliberately narrow: it finds, or it creates, a customer. It does not touch
 * sales, ledgers, addresses or GL.
 */
class CustomerDirectory
{
    /** Digits only — the one spelling of a phone number this system compares on. */
    public function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    /**
     * The customer who owns this phone, or null.
     *
     * Two passes on purpose. The first is an exact match on the normalised
     * value, which uses the phone index. The second catches rows written before
     * normalisation existed, whose phone still carries spaces, dashes or
     * brackets — a full scan, but only reached when the indexed lookup missed.
     */
    public function findByPhone(string $phone, bool $lock = false): ?Customer
    {
        $normalized = $this->normalizePhone($phone);
        if ($normalized === '') {
            return null;
        }

        // Under InnoDB's repeatable-read, lockForUpdate also locks the missing
        // key gap, so two simultaneous requests cannot both insert the same
        // newly-normalised phone.
        $exact = Customer::where('phone', $normalized);
        if ($lock) {
            $exact->lockForUpdate();
        }
        if ($customer = $exact->first()) {
            return $customer;
        }

        $legacy = Customer::whereNotNull('phone')->whereRaw(
            "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') = ?",
            [$normalized]
        );
        if ($lock) {
            $legacy->lockForUpdate();
        }

        return $legacy->first();
    }

    /**
     * The customer who owns this phone, created if nobody does.
     *
     * Returns null when there is no phone to go on: a name alone cannot
     * identify anybody, and guessing from one is what fills a book with
     * near-duplicates. The caller keeps its own copy of the name either way.
     */
    public function findOrCreateByPhone(string $phone, string $name): ?Customer
    {
        $normalized = $this->normalizePhone($phone);
        if ($normalized === '' || trim($name) === '') {
            return null;
        }

        return DB::connection('tenant')->transaction(function () use ($normalized, $name) {
            if ($existing = $this->findByPhone($normalized, true)) {
                // An existing row is never renamed from here. Whoever is in the
                // book was put there deliberately; a booking's spelling of the
                // same person does not get to overwrite it.
                return $existing;
            }

            // `code` stays null, exactly as the counter's quick-create leaves it.
            // The legacy import's C-<phone> codes came from the old system; a
            // customer born here is identified by phone, and inventing a second
            // code convention would only give the book two.
            return Customer::create([
                'code' => null,
                'name' => trim($name),
                'phone' => $normalized,
                'status' => 'active',
            ]);
        });
    }
}
