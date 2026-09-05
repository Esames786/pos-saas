<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * KASHIF-LEGACY-PHONE-SPLIT-1 — customers whose phone holds TWO numbers.
 *
 * The old software kept a second number in the same field. The import stripped
 * every non-digit, which fused them into one 22-digit string — and that string
 * became the customer's phone. Nobody searches by a 22-digit number, so 161
 * customers are effectively unreachable on the live tenant today.
 *
 * The rule here is deliberately narrow: EXACTLY 22 digits, both halves starting
 * with 0. That is the only shape the evidence supports. A 12-digit number with
 * one digit left over is a typo, not a second phone, and splitting it would
 * turn a real number into a wrong one — those nine rows are reported and left
 * untouched for a person to look at.
 *
 * It repairs, it never merges. Sixty-one of these, once repaired, turn out to
 * share a phone with a customer already in the book (`MR,MUBASHSHIR` twice, and
 * so on). Deciding which row is the real one — which name, which address, and
 * what happens to anything attached — is the owner's call, not a script's. This
 * command makes the duplicates VISIBLE and stops there.
 *
 * No row is ever deleted. The second number is kept on the address rather than
 * dropped, because it is a way to reach the customer.
 */
class CateringRepairLegacyCustomerPhonesCommand extends Command
{
    protected $signature = 'catering:repair-legacy-customer-phones {tenant_code} {--yes} {--dry-run}';

    protected $description = 'Split legacy customer phones that fused two numbers into one field. Repairs only; never merges or deletes.';

    private const ALLOWED = ['kashifkitchen'];

    public function handle(TenancyManager $tenancy): int
    {
        $code = (string) $this->argument('tenant_code');

        if (! in_array($code, self::ALLOWED, true)) {
            $this->error("Refusing: '{$code}' is not in the allowlist for this client's legacy data.");

            return self::FAILURE;
        }
        if (! $this->option('yes') && ! $this->option('dry-run')) {
            $this->error('Refusing: pass --dry-run to preview, or --yes to write.');

            return self::FAILURE;
        }

        $tenant = Tenant::where('tenant_code', $code)->first();
        if (! $tenant) {
            $this->error("Tenant '{$code}' not found.");

            return self::FAILURE;
        }
        $tenancy->activate($tenant);

        $db = DB::connection('tenant');
        $totalBefore = $db->table('customers')->count();

        $rows = $db->table('customers')->get(['id', 'code', 'name', 'phone', 'address']);
        $digits = fn ($p) => preg_replace('/\D/', '', (string) $p);

        $repairable = [];
        $leftAlone = [];
        foreach ($rows as $c) {
            $d = $digits($c->phone);
            if (strlen($d) <= 11) {
                continue;
            }
            if (strlen($d) === 22 && $d[0] === '0' && $d[11] === '0') {
                $repairable[] = [$c, substr($d, 0, 11), substr($d, 11)];
            } else {
                $leftAlone[] = [$c, $d];
            }
        }

        // Who would end up sharing a phone once this is done.
        $phoneOwners = [];
        foreach ($rows as $c) {
            $phoneOwners[$digits($c->phone)][] = $c;
        }

        $duplicates = [];
        foreach ($repairable as [$c, $primary]) {
            foreach ($phoneOwners[$primary] ?? [] as $other) {
                if ($other->id !== $c->id) {
                    $duplicates[] = [$c, $other, $primary];
                }
            }
        }

        $this->info('customers: '.$totalBefore);
        $this->info('repairable (22 digits = 11 + 11): '.count($repairable));
        $this->info('left untouched for a human: '.count($leftAlone));

        $repaired = 0;
        if (! $this->option('dry-run')) {
            $db->transaction(function () use ($db, $repairable, &$repaired) {
                foreach ($repairable as [$c, $primary, $alt]) {
                    $address = trim((string) $c->address);
                    $note = "Alt phone: {$alt}";
                    // Idempotent: running twice must not stack the same note.
                    if (! str_contains($address, $note)) {
                        $address = trim($address === '' ? $note : $address."\n".$note);
                    }

                    $db->table('customers')->where('id', $c->id)->update([
                        'phone' => $primary,
                        'address' => $address !== '' ? $address : null,
                        'updated_at' => now(),
                    ]);
                    $repaired++;
                }
            });
        }

        $totalAfter = $db->table('customers')->count();

        $this->newLine();
        $this->info(($this->option('dry-run') ? '[DRY RUN] would repair ' : 'repaired ')
            .($this->option('dry-run') ? count($repairable) : $repaired).' phone(s)');
        $this->line("  customers before={$totalBefore} after={$totalAfter}");

        if ($totalBefore !== $totalAfter) {
            $this->error('INTEGRITY: the customer count changed — this command must never add or remove a row.');

            return self::FAILURE;
        }

        if ($leftAlone !== []) {
            $this->newLine();
            $this->warn('LEFT UNTOUCHED — not a clean two-number split, needs a human:');
            foreach ($leftAlone as [$c, $d]) {
                $this->line('    #'.str_pad((string) $c->id, 7).str_pad(mb_substr($c->name, 0, 30), 32)
                    .$d.'  ('.strlen($d).' digits)');
            }
        }

        if ($duplicates !== []) {
            $this->newLine();
            $this->warn('NOW VISIBLY DUPLICATED — '.count($duplicates).' repaired customers share a phone with an');
            $this->warn('existing one. NOT merged: which row is the real one is the owner\'s decision.');
            foreach (array_slice($duplicates, 0, 25) as [$c, $other, $primary]) {
                $this->line('    '.$primary.'  #'.$c->id.' '.mb_substr($c->name, 0, 26)
                    .'   <->   #'.$other->id.' '.mb_substr($other->name, 0, 26));
            }
            if (count($duplicates) > 25) {
                $this->line('    … and '.(count($duplicates) - 25).' more');
            }
        }

        return self::SUCCESS;
    }
}
