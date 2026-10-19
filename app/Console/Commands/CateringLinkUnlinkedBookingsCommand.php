<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Models\Tenant\CateringEvent;
use App\Services\Tenancy\TenancyManager;
use App\Services\Tenant\CustomerDirectory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * CATERING-PHONE-2-1 (data) — purani bookings ko graahak se jorna.
 *
 * 22 September ko wo bug theek hua tha jis ki wajah se likha hua naam kabhi
 * customer banta hi nahi tha (CATERING-CUSTOMER-ENROL-1). Us ke baad ki 45
 * bookings me se 40 par link lag gaya, aur baqi 5 sirf wo hain jin par phone
 * daala hi nahi gaya. Yani fix chal raha hai.
 *
 * Magar us se PEHLE ki bookings waise hi pari hain: prod par 12 aisi hain jin
 * par phone mojood hai aur link nahi. Ye command wohi purana kaam poora karti
 * hai — us waqt jo enrolment nahi chali, ab chala deti hai.
 *
 * WOHI RAASTA, koi doosra nahi: `CustomerDirectory::findOrCreateByPhone`, jo
 * booking banate waqt bhi chalta hai. Yahan alag se customer banane ka apna
 * tareeqa likhna un dono ko waqt ke saath alag kar deta — aur phir ek hi
 * shaks book me do baar aa jata.
 *
 * DO NUMBER EK HI KHAANE ME: prod par ek booking ka phone
 * "0312-0080000  0312-0090000" hai. Aise khaane ko poora ka poora normalise
 * karne par 22 adad ka ek farzi number banta hai jo kisi ka nahi. Is liye
 * aisi surat me pehla number phone 1 me rehta hai, doosra `customer_phone_2`
 * me chala jata hai, aur pehchan pehle par hoti hai. (Yehi kharabi legacy
 * import me 165 customers par darj hai — wahan ka ilaj alag command karti
 * hai.)
 *
 * Default DRY RUN. `--yes` ke baghair ek byte nahi likhti.
 */
class CateringLinkUnlinkedBookingsCommand extends Command
{
    protected $signature = 'catering:link-unlinked-bookings {tenant_code} {--yes}';

    protected $description = 'Attach bookings that carry a phone but no customer link. Dry run unless --yes.';

    public function handle(TenancyManager $tenancy, CustomerDirectory $directory): int
    {
        $tenant = Tenant::where('tenant_code', (string) $this->argument('tenant_code'))->first();
        if (! $tenant) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }

        $tenancy->activate($tenant);
        $apply = (bool) $this->option('yes');
        $this->line($apply ? '<comment>APPLYING</comment>' : '<info>DRY RUN</info> — pass --yes to write.');
        $this->newLine();

        $rows = [];
        $skipped = [];

        foreach (CateringEvent::whereNull('customer_id')->orderBy('id')->get() as $event) {
            $raw = trim((string) $event->customer_phone);
            $name = trim((string) $event->customer_name);

            if ($raw === '' || $name === '') {
                // Prod par teen aisi bookings mili jahan kisi ne PHONE ko NAAM
                // ke khaane me likh diya aur phone ka khaana khali chhod
                // diya. "phone nahi" kehna sach to hai magar bekaar — aur
                // in ko khud theek karna bhi ghalat hoga: phir booking par
                // naam hi na bachta. Is liye naam le kar batao, taake koi
                // us booking ko khol kar do sekand me durust kar de.
                $looksLikePhone = $name !== '' && preg_match('/^[\d\s\-+()]+$/', $name) === 1;
                $reason = $looksLikePhone
                    ? 'phone NAAM ke khaane me likha hai — booking khol kar theek karein'
                    : 'phone nahi — link nahi ban sakta';

                $skipped[] = [$event->event_no, mb_substr($name ?: '(naam nahi)', 0, 24), $reason];

                continue;
            }

            [$first, $second] = $this->split($raw);

            // CATERING-PHONE-11-1: wohi hadd jo form lagata hai. Do jagah do
            // alag hadden rakhne se ye command wo number jorne lagti jo form
            // qubool hi nahi karta.
            $digits = strlen(preg_replace('/\D+/', '', $first) ?? '');
            if ($digits !== 11) {
                $skipped[] = [$event->event_no, mb_substr($name, 0, 24), "phone me {$digits} adad — theek 11 chahiyen, haath se dekhna parega"];

                continue;
            }

            $rows[] = [$event, $first, $second, $name];
        }

        if ($rows) {
            $this->table(['event', 'customer', 'phone 1', 'phone 2'], array_map(
                fn ($r) => [$r[0]->event_no, mb_substr($r[3], 0, 26), $r[1], $r[2] ?: '—'],
                $rows
            ));
        } else {
            $this->info('Jorne ko koi booking nahi.');
        }

        if ($apply && $rows) {
            $linked = 0;
            DB::connection('tenant')->transaction(function () use ($rows, $directory, &$linked) {
                foreach ($rows as [$event, $first, $second, $name]) {
                    $customer = $directory->findOrCreateByPhone($first, $name);
                    if (! $customer) {
                        continue;
                    }

                    $patch = ['customer_id' => $customer->id];
                    // Do number ek khaane me thay — ab alag alag.
                    if ($second !== null) {
                        $patch['customer_phone'] = $first;
                        $patch['customer_phone_2'] = $event->customer_phone_2 ?: $second;
                    }
                    $event->forceFill($patch)->save();
                    $linked++;
                }
            });
            $this->newLine();
            $this->info("{$linked} bookings graahak se jud gayin.");
        } elseif ($rows) {
            $this->newLine();
            $this->info('Kuch likha nahi gaya. --yes de kar chalayein.');
        }

        if ($skipped) {
            $this->newLine();
            $this->line('<comment>Ye nahi juri — inhe haath se dekhna parega:</comment>');
            $this->table(['event', 'customer', 'wajah'], $skipped);
        }

        return self::SUCCESS;
    }

    /**
     * Ek khaane me do number pare hon to unhe alag karo.
     *
     * Shart jaan-boojh kar TANG hai: adad theek 22 hon aur dono aadhe 11-11 ke
     * hon. Ek 12 adad wala number jis par ek hindsa zaid hai wo TYPO hai,
     * do number nahi — usay kaat dena ek asli number ko ghalat number bana
     * deta. Aisi surat me command usay chhod deti hai aur naam le kar batati
     * hai.
     *
     * @return array{0: string, 1: ?string}
     */
    private function split(string $raw): array
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (strlen($digits) === 22 && str_starts_with($digits, '0') && str_starts_with(substr($digits, 11), '0')) {
            return [substr($digits, 0, 11), substr($digits, 11)];
        }

        return [$raw, null];
    }
}
