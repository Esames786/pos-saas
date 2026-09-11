<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeLocalMeta;

/**
 * OFFLINE EDGE — F2 SUPPLIER FINANCE PARITY: the two immutable supplier-finance events.
 *
 *   SUPPLIER_PAYMENT              (edge-supplier-payment-envelope-v1)   — Online "Record Supplier Payment": Dr Accounts
 *                                 Payable / Cr the chosen Cash/Bank account, Purchase Bill OPTIONAL, Cash/Bank REQUIRED.
 *   SUPPLIER_AP_JOURNAL_ADJUSTMENT (edge-supplier-ap-journal-envelope-v1) — Online "General Journal" entry whose AP lines
 *                                 name a real supplier (the subledger mirrors the AP movement).
 *
 * Same transport contract as sales and F1 returns: content_hash = sha256(canonicalJson(envelope − hash)); the outbox
 * row's `sale_uuid` carries the event_uuid; the schema version routes the sender to the Cloud supplier-finance
 * ingestion. The appliance never posts AP / GL / cash-bank itself — the Cloud posts the OFFICIAL transaction exactly
 * once through its existing supplier-finance authority.
 */
class EdgeSupplierFinanceEnvelopeBuilder
{
    public const SCHEMA_PAYMENT = 'edge-supplier-payment-envelope-v1';
    public const SCHEMA_AP_JOURNAL = 'edge-supplier-ap-journal-envelope-v1';
    public const SCHEMAS = [self::SCHEMA_PAYMENT, self::SCHEMA_AP_JOURNAL];

    public const EVENT_PAYMENT = 'supplier_payment';
    public const EVENT_AP_JOURNAL = 'supplier_ap_journal_adjustment';

    public function __construct(private readonly EdgeBootstrapService $canonical)
    {
    }

    public static function isFinanceSchema(?string $schema): bool
    {
        return in_array((string) $schema, self::SCHEMAS, true);
    }

    /**
     * @param array $payment cloud_supplier_id, supplier_code, supplier_name, payment_date (Y-m-d), amount,
     *                       cloud_cash_bank_account_id, cash_bank_code, payment_method, reference_no, bank_name,
     *                       account_no, transaction_ref, cheque_no, cheque_date, cloud_bill_id, bill_no, notes
     */
    public function buildPayment(EdgeLocalMeta $meta, string $eventUuid, array $payment, array $actor, array $freshness): array
    {
        $envelope = $this->header($meta, self::SCHEMA_PAYMENT, self::EVENT_PAYMENT, $eventUuid) + [
            'supplier' => [
                'cloud_supplier_id' => (int) $payment['cloud_supplier_id'],
                'code' => (string) ($payment['supplier_code'] ?? ''),
                'name' => (string) ($payment['supplier_name'] ?? ''),
            ],
            'payment_date' => (string) $payment['payment_date'],
            'business_date' => (string) $payment['payment_date'],
            'amount' => round((float) $payment['amount'], 2),
            'cash_bank_account' => [
                'cloud_cash_bank_account_id' => (int) $payment['cloud_cash_bank_account_id'],
                'code' => (string) ($payment['cash_bank_code'] ?? ''),
            ],
            'payment_method' => (string) $payment['payment_method'],
            'reference_no' => self::str($payment['reference_no'] ?? null),
            'bank_name' => self::str($payment['bank_name'] ?? null),
            'account_no' => self::str($payment['account_no'] ?? null),
            'transaction_ref' => self::str($payment['transaction_ref'] ?? null),
            'cheque_no' => self::str($payment['cheque_no'] ?? null),
            'cheque_date' => self::str($payment['cheque_date'] ?? null),
            'purchase_bill' => ! empty($payment['cloud_bill_id']) ? [
                'cloud_bill_id' => (int) $payment['cloud_bill_id'],
                'bill_no' => (string) ($payment['bill_no'] ?? ''),
            ] : null,
            'notes' => self::str($payment['notes'] ?? null),
            'actor' => $this->actor($actor),
            'freshness' => $this->freshness($freshness),
            'created_at' => now()->toIso8601String(),
        ];
        $envelope['content_hash'] = hash('sha256', $this->canonical->canonicalJson($envelope));

        return $envelope;
    }

    /**
     * @param array $journal entry_date (Y-m-d), description, reference_no, lines[] {line_uuid, cloud_account_id, account_code,
     *                       cloud_cash_bank_account_id, counterparty_type, cloud_supplier_id, description, debit, credit}
     */
    public function buildApJournal(EdgeLocalMeta $meta, string $eventUuid, array $journal, array $actor, array $freshness): array
    {
        $lines = array_values(array_map(fn ($l) => [
            'line_uuid' => (string) $l['line_uuid'],
            'cloud_account_id' => (int) $l['cloud_account_id'],
            'account_code' => (string) $l['account_code'],
            'cloud_cash_bank_account_id' => ! empty($l['cloud_cash_bank_account_id']) ? (int) $l['cloud_cash_bank_account_id'] : null,
            'counterparty_type' => ! empty($l['cloud_supplier_id']) ? 'supplier' : null,
            'cloud_supplier_id' => ! empty($l['cloud_supplier_id']) ? (int) $l['cloud_supplier_id'] : null,
            'description' => self::str($l['description'] ?? null),
            'debit' => round((float) ($l['debit'] ?? 0), 4),
            'credit' => round((float) ($l['credit'] ?? 0), 4),
        ], $journal['lines']));

        $envelope = $this->header($meta, self::SCHEMA_AP_JOURNAL, self::EVENT_AP_JOURNAL, $eventUuid) + [
            'entry_date' => (string) $journal['entry_date'],
            'business_date' => (string) $journal['entry_date'],
            'description' => (string) $journal['description'],
            'reference_no' => self::str($journal['reference_no'] ?? null),
            'lines' => $lines,
            'totals' => [
                'debit' => round(array_sum(array_column($lines, 'debit')), 4),
                'credit' => round(array_sum(array_column($lines, 'credit')), 4),
            ],
            'actor' => $this->actor($actor),
            'freshness' => $this->freshness($freshness),
            'created_at' => now()->toIso8601String(),
        ];
        $envelope['content_hash'] = hash('sha256', $this->canonical->canonicalJson($envelope));

        return $envelope;
    }

    public function canonicalEnvelopeJson(array $envelope): string
    {
        return $this->canonical->canonicalJson($envelope);
    }

    private function header(EdgeLocalMeta $meta, string $schema, string $eventType, string $eventUuid): array
    {
        return [
            'envelope_schema_version' => $schema,
            'event_type' => $eventType,
            'event_uuid' => $eventUuid,
            'tenant_id' => (int) $meta->tenant_id,
            'tenant_code' => (string) $meta->tenant_code,
            'branch_id' => (int) $meta->branch_id,
            'device_public_uuid' => (string) $meta->device_uuid,
            'activation_epoch' => (int) $meta->activation_epoch,
            'config_revision' => (int) ($meta->last_applied_config_revision ?? 0),
        ];
    }

    private function actor(array $actor): array
    {
        return [
            'user_id' => (int) $actor['user_id'],
            'employee_code' => $actor['employee_code'] ?? null,
            'terminal_id' => isset($actor['terminal_id']) ? (int) $actor['terminal_id'] : null,
        ];
    }

    private function freshness(array $freshness): array
    {
        return [
            'supplier_finance_watermark' => $freshness['watermark'] ?? null,
            'supplier_finance_as_of' => $freshness['as_of'] ?? null,
        ];
    }

    private static function str(mixed $v): ?string
    {
        $s = $v === null ? '' : trim((string) $v);

        return $s === '' ? null : $s;
    }
}
