<?php

namespace App\Services\Edge;

/**
 * OFFLINE EDGE — the ONE shared list of TERMINAL ingestion verdicts, used by the Cloud ingestions (to answer a
 * business refusal as `refused` with the envelope identity) and by the appliance sender (to park the outbox row as
 * `failed_permanent` instead of retrying an immutable envelope the Cloud can never accept).
 *
 * A code is terminal when re-sending the IDENTICAL immutable envelope can never succeed. Codes that may clear on a
 * later attempt (a transport fault, a DB error, INSUFFICIENT_STOCK gated by 1E baseline cutover, an original sale
 * still in flight, a FINANCE_* verifier refusal after a rollback) are deliberately NOT terminal.
 */
final class EdgeIngestionVerdicts
{
    public const TERMINAL_FAILURE_CODES = [
        // transport / identity / authority
        'ENVELOPE_CONFLICT', 'WRONG_TENANT', 'WRONG_BRANCH', 'DEVICE_UNKNOWN', 'DEVICE_REVOKED', 'DEVICE_MISMATCH',
        'STALE_ACTIVATION', 'SCHEMA_UNSUPPORTED', 'HASH_INVALID', 'ENVELOPE_INVALID', 'BRANCH_UNKNOWN',
        // sales (1C)
        'SALE_UUID_INVALID', 'ORDER_TYPE_UNSUPPORTED', 'PAYMENT_UNSUPPORTED', 'CUSTOMER_INVALID', 'CUSTOMER_UNKNOWN', 'PRODUCT_UNRESOLVED',
        // F1 sales returns — ORIGINAL_SALE_NOT_INGESTED is deliberately NOT here: the sale may still be in flight → retry.
        'RETURN_UUID_INVALID', 'RETURN_INVALID', 'ORIGINAL_SALE_UNKNOWN', 'RETURN_LINE_UNKNOWN', 'RETURN_REFUSED',
        'REFUND_METHOD_UNSUPPORTED', 'ACTOR_UNKNOWN', 'APPROVAL_REQUIRED', 'APPROVER_UNAUTHORIZED',
        // F2 supplier finance — FINANCE_* verifier refusals are NOT here: the transaction rolled back, the next attempt may complete.
        'EVENT_UUID_INVALID', 'EVENT_INVALID', 'SUPPLIER_UNKNOWN', 'SUPPLIER_INACTIVE', 'CASH_BANK_REQUIRED', 'CASH_BANK_UNKNOWN',
        'CASH_BANK_INACTIVE', 'CASH_BANK_UNMAPPED', 'BILL_UNKNOWN', 'BILL_MISMATCH', 'PAYMENT_INVALID', 'PAYMENT_REFUSED',
        'JOURNAL_INVALID', 'JOURNAL_REFUSED', 'ACCOUNT_UNKNOWN', 'ACCOUNT_MISMATCH', 'ACCOUNT_INACTIVE', 'AP_SUPPLIER_REQUIRED', 'ACTOR_UNAUTHORIZED',
        // F3 purchase returns — the canonical authority refused (over-return / official stock no longer covers it) or an identity does not resolve.
        'GRN_UNKNOWN', 'GRN_MISMATCH', 'GRN_LINE_UNKNOWN', 'PURCHASE_RETURN_INVALID', 'PURCHASE_RETURN_REFUSED',
    ];

    public static function isTerminal(?string $code): bool
    {
        return in_array((string) $code, self::TERMINAL_FAILURE_CODES, true);
    }
}
