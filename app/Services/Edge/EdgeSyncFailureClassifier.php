<?php

namespace App\Services\Edge;

/**
 * OFFLINE-SYNC-ENGINE-1E — translate a raw outbox last_error into a BUSINESS failure class (§F).
 *
 * The sender records machine codes ("HTTP 503", "INSUFFICIENT_STOCK", "ENVELOPE_CONFLICT: ...") in
 * last_error. The operator surface must speak in business terms, and the supervisor requeue must know which
 * classes are safe to re-send unchanged. One place owns that mapping so the status surface and the requeue
 * authority never disagree. No developer noise (SQLSTATE, stack traces) is ever surfaced from here.
 */
class EdgeSyncFailureClassifier
{
    public const CLASS_NONE = 'none';
    public const CLASS_TRANSIENT = 'transient';
    public const CLASS_INSUFFICIENT_STOCK = 'insufficient_stock';
    public const CLASS_CONFLICT = 'conflict';
    public const CLASS_IDENTITY_SECURITY = 'identity_security';
    public const CLASS_UNSUPPORTED = 'unsupported';
    public const CLASS_INVALID_PAYLOAD = 'invalid_payload';
    public const CLASS_UNKNOWN = 'unknown';

    /**
     * The classes a supervisor may requeue by re-sending the SAME immutable envelope (§H): an operational
     * condition that can be restored (connectivity/temporary Cloud fault) or an authorized stock correction.
     * Every terminal-verdict class is deliberately absent — those need reconciliation, never a blind requeue.
     */
    public const REQUEUEABLE_CLASSES = [self::CLASS_TRANSIENT, self::CLASS_INSUFFICIENT_STOCK];

    /** Codes -> class. Matched case-insensitively as whole tokens inside last_error. */
    private const CODE_MAP = [
        'INSUFFICIENT_STOCK' => self::CLASS_INSUFFICIENT_STOCK,
        'ENVELOPE_CONFLICT' => self::CLASS_CONFLICT,
        'WRONG_TENANT' => self::CLASS_IDENTITY_SECURITY,
        'WRONG_BRANCH' => self::CLASS_IDENTITY_SECURITY,
        'DEVICE_UNKNOWN' => self::CLASS_IDENTITY_SECURITY,
        'DEVICE_REVOKED' => self::CLASS_IDENTITY_SECURITY,
        'DEVICE_MISMATCH' => self::CLASS_IDENTITY_SECURITY,
        'STALE_ACTIVATION' => self::CLASS_IDENTITY_SECURITY,
        'SCHEMA_UNSUPPORTED' => self::CLASS_UNSUPPORTED,
        'ORDER_TYPE_UNSUPPORTED' => self::CLASS_UNSUPPORTED,
        'PAYMENT_UNSUPPORTED' => self::CLASS_UNSUPPORTED,
        'SALE_UUID_INVALID' => self::CLASS_INVALID_PAYLOAD,
        'HASH_INVALID' => self::CLASS_INVALID_PAYLOAD,
        'ENVELOPE_INVALID' => self::CLASS_INVALID_PAYLOAD,
        'CUSTOMER_INVALID' => self::CLASS_INVALID_PAYLOAD,
        'CUSTOMER_UNKNOWN' => self::CLASS_INVALID_PAYLOAD,
        'PRODUCT_UNRESOLVED' => self::CLASS_INVALID_PAYLOAD,
    ];

    /** Business labels + operator guidance per class (§F, §S — plain language, no internals). */
    private const PRESENTATION = [
        self::CLASS_NONE => ['label' => 'No error', 'action' => 'None.'],
        self::CLASS_TRANSIENT => ['label' => 'Temporary connection or Cloud problem', 'action' => 'Retries automatically; you can also Retry now.'],
        self::CLASS_INSUFFICIENT_STOCK => ['label' => 'Cloud refused: not enough official stock', 'action' => 'Correct the stock with authorization, then requeue.'],
        self::CLASS_CONFLICT => ['label' => 'This sale already exists in the Cloud with different content', 'action' => 'Do not resend. Needs supervisor reconciliation.'],
        self::CLASS_IDENTITY_SECURITY => ['label' => 'Device or branch is not authorized for this sale', 'action' => 'Do not resend. Needs administrator remediation.'],
        self::CLASS_UNSUPPORTED => ['label' => 'The Cloud cannot process this sale type yet', 'action' => 'Do not resend. Needs a software update.'],
        self::CLASS_INVALID_PAYLOAD => ['label' => 'The sale data could not be accepted by the Cloud', 'action' => 'Do not resend. Needs supervisor review.'],
        self::CLASS_UNKNOWN => ['label' => 'Unclassified sync problem', 'action' => 'Needs support review.'],
    ];

    /**
     * @return array{class:string,label:string,action:string,requeueable:bool}
     */
    public static function classify(?string $lastError): array
    {
        $class = self::classCode($lastError);
        $p = self::PRESENTATION[$class] ?? self::PRESENTATION[self::CLASS_UNKNOWN];

        return [
            'class' => $class,
            'label' => $p['label'],
            'action' => $p['action'],
            'requeueable' => in_array($class, self::REQUEUEABLE_CLASSES, true),
        ];
    }

    private static function classCode(?string $lastError): string
    {
        $err = trim((string) $lastError);
        if ($err === '') {
            return self::CLASS_NONE;
        }
        $upper = strtoupper($err);

        foreach (self::CODE_MAP as $code => $class) {
            if (str_contains($upper, $code)) {
                return $class;
            }
        }

        // Transient network/HTTP shapes the sender records for retryable failures.
        if (preg_match('/HTTP 5\d\d/', $upper) === 1 || str_contains($upper, 'HTTP 429')
            || str_contains($upper, 'CONNECT') || str_contains($upper, 'TIMEOUT')
            || str_contains($upper, 'CURL') || str_contains($upper, 'CONNECTION')) {
            return self::CLASS_TRANSIENT;
        }

        return self::CLASS_UNKNOWN;
    }
}
