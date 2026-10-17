<?php

use App\Support\PhoneNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * EDGE PHASE 4 — OFFLINE ADD CUSTOMER: the cross-system customer identity columns (tenant migration: runs on the Cloud
 * tenant database AND on every Branch Server, which applies the tenant migrations through the appliance schema upgrader).
 *
 *   customers.phone_normalized      the ONE match key (App\Support\PhoneNormalizer) the Cloud's create-or-match and the
 *                                   appliance's duplicate lookup compare on; indexed; backfilled from `phone`.
 *   customer_addresses.address_uuid the canonical cross-system identity of an address (ULID, unique) so an address typed
 *                                   offline is created exactly once on the Cloud and matched by identity on refresh.
 *   customer_uuid_aliases           alias_uuid → canonical customer: when an offline-created customer's phone matches an
 *                                   EXISTING Cloud customer, the offline customer_uuid is recorded here instead of
 *                                   minting a duplicate; every later envelope / reservation carrying the alias resolves.
 *
 * Additive, idempotent (hasColumn / hasTable / whereNull guards), memory-bounded backfill — APPLIANCE-UPDATE-SAFE like
 * 2026_08_08_000010 (never drops, truncates or re-imports; re-runnable after an interruption).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('tenant');
        $conn = DB::connection('tenant');

        if ($schema->hasTable('customers') && ! $schema->hasColumn('customers', 'phone_normalized')) {
            $schema->table('customers', function (Blueprint $t) {
                $t->string('phone_normalized', 32)->nullable()->after('phone')->index('customers_phone_normalized_idx');
            });
        }
        if ($schema->hasTable('customers')) {
            // Backfill: the shared key of the stored phone, rows still NULL only (generate-once), walked by an id cursor so a
            // phone without digits (no key) is skipped rather than re-selected forever. updated_at is NOT touched here (the
            // bootstrap exporter's own repair moves the watermark where that matters).
            $cursor = 0;
            do {
                $rows = $conn->table('customers')->whereNull('phone_normalized')->whereNotNull('phone')->where('phone', '!=', '')
                    ->where('id', '>', $cursor)->orderBy('id')->limit(1000)->get(['id', 'phone']);
                foreach ($rows as $row) {
                    $cursor = (int) $row->id;
                    $normalized = PhoneNormalizer::normalize((string) $row->phone);
                    if ($normalized === null) {
                        continue;
                    }
                    $conn->table('customers')->where('id', $row->id)->whereNull('phone_normalized')->update(['phone_normalized' => $normalized]);
                }
            } while ($rows->count() > 0);
        }

        if ($schema->hasTable('customer_addresses')) {
            if (! $schema->hasColumn('customer_addresses', 'address_uuid')) {
                $schema->table('customer_addresses', function (Blueprint $t) {
                    $t->char('address_uuid', 26)->nullable()->after('id');
                });
            }
            do {
                $ids = $conn->table('customer_addresses')->whereNull('address_uuid')->orderBy('id')->limit(1000)->pluck('id');
                foreach ($ids as $id) {
                    $conn->table('customer_addresses')->where('id', $id)->whereNull('address_uuid')->update(['address_uuid' => (string) Str::ulid()]);
                }
            } while ($ids->count() > 0);
            if (! in_array('customer_addresses_address_uuid_unique', $this->indexNames($conn, 'customer_addresses'), true)) {
                $schema->table('customer_addresses', function (Blueprint $t) {
                    $t->unique('address_uuid', 'customer_addresses_address_uuid_unique');
                });
            }
        }

        if (! $schema->hasTable('customer_uuid_aliases')) {
            $schema->create('customer_uuid_aliases', function (Blueprint $t) {
                $t->char('alias_uuid', 26)->primary();                 // the offline-minted customer_uuid that was MATCHED, not created
                $t->unsignedBigInteger('customer_id');                 // the canonical Cloud customer (this database's id)
                $t->char('customer_uuid', 26);                         // the canonical customer_uuid (cross-system; what an appliance maps to)
                $t->string('source_device_uuid', 64)->nullable();      // the appliance whose envelope produced the match
                $t->string('source', 32)->default('phone_match');      // how the alias was established
                $t->timestamps();
                $t->index('customer_id', 'customer_uuid_aliases_customer_idx');
                $t->index('customer_uuid', 'customer_uuid_aliases_canonical_idx');
                // Deliberately NO foreign key on customer_id: the same table is shipped to the appliance, where the canonical
                // customer may live under a different local id or not at all (inactive on the Cloud) — the applier maps the id
                // when it can; every reader re-resolves the customer (an alias of a deleted customer simply does not resolve).
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('tenant');
        $schema->dropIfExists('customer_uuid_aliases');
        if ($schema->hasColumn('customer_addresses', 'address_uuid')) {
            $schema->table('customer_addresses', function (Blueprint $t) {
                $t->dropUnique('customer_addresses_address_uuid_unique');
                $t->dropColumn('address_uuid');
            });
        }
        if ($schema->hasColumn('customers', 'phone_normalized')) {
            $schema->table('customers', function (Blueprint $t) {
                $t->dropIndex('customers_phone_normalized_idx');
                $t->dropColumn('phone_normalized');
            });
        }
    }

    /** Existing index names for a table (lower-cased), portable across MySQL/MariaDB. */
    private function indexNames($conn, string $table): array
    {
        $rows = $conn->select("SHOW INDEX FROM `{$table}`");

        return array_values(array_unique(array_map(fn ($r) => strtolower($r->Key_name), $rows)));
    }
};
