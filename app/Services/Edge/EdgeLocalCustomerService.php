<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeLocalMeta;
use App\Models\Tenant\Customer;
use App\Models\Tenant\CustomerAddress;
use App\Services\Tenant\CustomerDirectory;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * EDGE PHASE 4 — OFFLINE ADD CUSTOMER on the Branch Server (the appliance half of docs/status/edge-phase4-offline-add-customer.md).
 *
 * Mirrors Online's CustomerController::quickStore / storeAddress rule for rule (a known phone REUSES that customer and only
 * tops up what is missing; an address identical in text is reused; the first address becomes the default), with the
 * cross-system identity the offline flow needs:
 *
 *   - canonical identity = `customer_uuid` (ULID, HasCanonicalIdentity mints it on create; never the local numeric id);
 *   - `phone_normalized` = App\Support\PhoneNormalizer (THE shared normaliser) — the local duplicate lookup and the Cloud's
 *     create-or-match compare on the same key;
 *   - `edge_origin = 'local'` on a customer / address born here, until a config refresh brings the same identity back from
 *     the Cloud (EdgeLocalConfigRefreshApplier re-keys it to 'cloud'); the sale envelope carries a 'local' customer as a
 *     `new_customer` intent;
 *   - LOCAL NUMERIC IDS live in a reserved high range (LOCAL_ID_FLOOR …) so a refresh inserting Cloud rows by their Cloud id
 *     can never collide with a customer/address created here (ids are local-only; identity travels by UUID).
 *
 * Authority: the caller (EdgeLocalCustomerController) enforces the Online route permission, the Cloud capability
 * advertisement and the branch-authority lease; this service adds the transactional rules only.
 */
class EdgeLocalCustomerService
{
    /** Local ids for rows created at the till start here — far above any Cloud id a refresh could bring. */
    public const LOCAL_ID_FLOOR = 1_000_000_000;

    public const ORIGIN_LOCAL = 'local';
    public const ORIGIN_CLOUD = 'cloud';

    public function __construct(private readonly CustomerDirectory $directory)
    {
    }

    /**
     * Online quickStore semantics. Returns [Customer, reused].
     *
     * @param  array{name:string, phone?:?string, email?:?string, address?:?string}  $data
     * @return array{0: Customer, 1: bool}
     */
    public function quickStore(array $data): array
    {
        $phone = trim((string) ($data['phone'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' && $phone === '') {
            throw new RuntimeException('Enter a name or a phone number.');
        }

        return DB::connection('tenant')->transaction(function () use ($data, $phone, $name) {
            $this->lockLocalIdAllocator();

            $customer = $phone !== '' ? $this->findByPhone($phone, true) : null;
            $reused = $customer !== null;

            if ($customer) {
                // Online: a known phone reuses the customer and only tops up what is missing — never renames.
                $customer->fill(array_filter([
                    'name' => $customer->name ?: ($name !== '' ? $name : $phone),
                    'email' => $customer->email ?: ($data['email'] ?? null),
                ]))->save();
            } else {
                $customer = new Customer([
                    'code' => null,
                    'name' => $name !== '' ? $name : $phone,
                    'phone' => $phone !== '' ? $this->directory->normalizePhone($phone) : null,
                    'email' => $data['email'] ?? null,
                    'status' => 'active',
                ]);
                $customer->id = $this->nextLocalId('customers');
                $customer->edge_origin = self::ORIGIN_LOCAL;
                $customer->edge_created_at = now();
                $customer->save();   // HasCanonicalIdentity mints customer_uuid; the model hook derives phone_normalized
            }

            $address = trim((string) ($data['address'] ?? ''));
            if ($address !== '') {
                $this->storeAddressOnce($customer, $address);
            }

            return [$customer->fresh(), $reused];
        });
    }

    /** Online storeAddress semantics (label / is_default / first-address-default), with the local identity rules. */
    public function storeAddress(Customer $customer, string $address, ?string $label = null, bool $isDefault = false): CustomerAddress
    {
        return DB::connection('tenant')->transaction(function () use ($customer, $address, $label, $isDefault) {
            $this->lockLocalIdAllocator();
            $makeDefault = $isDefault || $customer->addresses()->count() === 0;
            if ($makeDefault) {
                $customer->addresses()->update(['is_default' => false]);
            }

            return $this->storeAddressOnce($customer, $address, $label, $makeDefault);
        });
    }

    /**
     * The local duplicate lookup: the shared match key first (indexed), then CustomerDirectory's legacy spellings so a
     * synced row written before `phone_normalized` existed is still found. Any status — Online reuses an inactive row too.
     */
    public function findByPhone(string $phone, bool $lock = false): ?Customer
    {
        $key = PhoneNormalizer::normalize($phone);
        if ($key === null) {
            return null;
        }
        $q = Customer::on('tenant')->where('phone_normalized', $key)->orderBy('id');
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->first() ?? $this->directory->findByPhone($phone, $lock);
    }

    /** Online storeAddressOnce: identical text (whitespace-folded, case-insensitive) reuses the row. */
    private function storeAddressOnce(Customer $customer, string $address, ?string $label = null, ?bool $makeDefault = null): CustomerAddress
    {
        $address = trim(preg_replace('/\s+/', ' ', $address) ?? $address);
        $existing = $customer->addresses()->lockForUpdate()->get()->first(
            fn ($row) => mb_strtolower(trim(preg_replace('/\s+/', ' ', $row->address) ?? $row->address)) === mb_strtolower($address)
        );
        if ($existing) {
            if ($makeDefault && ! $existing->is_default) {
                $existing->update(['is_default' => true, 'label' => $label ?: $existing->label]);
            }

            return $existing;
        }

        $row = new CustomerAddress([
            'customer_id' => (int) $customer->id,
            'label' => $label !== null && $label !== '' ? mb_substr($label, 0, 50) : null,
            'address' => mb_substr($address, 0, 500),
            'is_default' => $makeDefault ?? $customer->addresses()->count() === 0,
        ]);
        $row->id = $this->nextLocalId('customer_addresses');
        $row->edge_origin = self::ORIGIN_LOCAL;
        $row->save();   // HasCanonicalIdentity mints address_uuid

        return $row;
    }

    /**
     * Serialise local id allocation on the appliance's singleton binding row (the same lock the config refresh holds while
     * it inserts Cloud rows): two tills adding customers at once get distinct ids, and a refresh never interleaves.
     */
    private function lockLocalIdAllocator(): void
    {
        $meta = EdgeLocalMeta::query()->where('singleton_guard', EdgeLocalMeta::SINGLETON)->lockForUpdate()->first();
        if (! $meta || ! $meta->isBootstrapped()) {
            throw new RuntimeException('This Branch Server is not bootstrapped — customers cannot be added yet.');
        }
    }

    /** The next local-range id for a table: max(LOCAL_ID_FLOOR, max(id) + 1). Callers hold lockLocalIdAllocator(). */
    public function nextLocalId(string $table): int
    {
        $max = (int) DB::connection('tenant')->table($table)->max('id');

        return max(self::LOCAL_ID_FLOOR, $max + 1);
    }

    public static function isLocalId(int $id): bool
    {
        return $id >= self::LOCAL_ID_FLOOR;
    }
}
