<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Models\Tenant\SharePartner;
use App\Models\Tenant\ShareRate;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PARTNER-SHARE-1 — load an agreed rate list from a CSV, so 22 rows are not typed by hand.
 *
 * CSV columns (header row required): type, name, payer, amount, effective_from, notes
 *   type   = deal | product
 *   name   = the EXACT name in the system (a deal's name, or a product's)
 *   payer  = partner (the partner pays us) | us (we pay the partner)
 *
 * Dry run by default: prints what it would write and every name it cannot find. Nothing is written
 * unless --yes, and then only if EVERY row resolved — half a rate list is worse than none. A row
 * whose version already exists is skipped, so a re-run writes nothing twice.
 */
class ShareRatesImportCommand extends Command
{
    protected $signature = 'share-rates:import {tenant_code} {--file=} {--partner=} {--yes}';

    protected $description = 'PARTNER-SHARE-1: import a partner share rate list from a CSV (dry run unless --yes)';

    public function handle(TenancyManager $tenancy): int
    {
        $tenant = Tenant::where('tenant_code', (string) $this->argument('tenant_code'))->first();
        if (! $tenant) {
            $this->error('No such tenant.');

            return self::FAILURE;
        }
        $file = (string) $this->option('file');
        $partnerName = trim((string) $this->option('partner'));
        if ($file === '' || ! is_readable($file) || $partnerName === '') {
            $this->error('Give --file=<readable csv> and --partner="<partner name>".');

            return self::FAILURE;
        }

        $tenancy->activate($tenant);
        $conn = DB::connection('tenant');

        $handle = fopen($file, 'r');
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), fgetcsv($handle) ?: []);
        $need = ['type', 'name', 'payer', 'amount', 'effective_from'];
        if (array_diff($need, $header)) {
            $this->error('CSV header must include: ' . implode(', ', $need));

            return self::FAILURE;
        }

        $partner = SharePartner::where('name', $partnerName)->first();
        $plan = [];
        $problems = [];
        $line = 1;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $row = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
            $type = strtolower(trim($row['type']));
            $name = trim($row['name']);
            $payer = strtolower(trim($row['payer']));
            $amount = trim($row['amount']);

            $id = match ($type) {
                'deal' => $conn->table('combos')->where('name', $name)->value('id'),
                'product' => $conn->table('products')->where('name', $name)->value('id'),
                default => null,
            };
            $date = null;
            try {
                $date = Carbon::parse(trim($row['effective_from']))->toDateString();
            } catch (\Throwable) {
            }

            $why = match (true) {
                ! in_array($type, ['deal', 'product'], true) => "type must be deal or product",
                ! $id => "no {$type} named \"{$name}\"",
                ! in_array($payer, [ShareRate::PAYER_PARTNER, ShareRate::PAYER_US], true) => 'payer must be partner or us',
                ! is_numeric($amount) || (float) $amount < 0 => 'amount must be a number 0 or more',
                ! $date => 'effective_from is not a date',
                default => null,
            };
            if ($why) {
                $problems[] = "line {$line}: {$why}";

                continue;
            }

            $productId = $type === 'product' ? (int) $id : null;
            $comboId = $type === 'deal' ? (int) $id : null;
            $plan[] = [
                'item_key' => ShareRate::itemKey($productId, $comboId),
                'product_id' => $productId, 'combo_id' => $comboId, 'name' => $name,
                'payer' => $payer, 'amount' => round((float) $amount, 2), 'effective_from' => $date,
                'notes' => trim((string) ($row['notes'] ?? '')) ?: null,
            ];
        }
        fclose($handle);

        $exists = fn (array $p) => $partner && ShareRate::where('share_partner_id', $partner->id)
            ->where('item_key', $p['item_key'])->whereDate('effective_from', $p['effective_from'])->exists();

        $this->table(['Item', 'Type', 'Who pays', 'Amount', 'From', 'Status'], array_map(fn ($p) => [
            $p['name'], $p['combo_id'] ? 'deal' : 'product',
            $p['payer'] === 'partner' ? "{$partnerName} pays us" : "we pay {$partnerName}",
            number_format($p['amount'], 2), $p['effective_from'], $exists($p) ? 'already there — skip' : 'new',
        ], $plan));
        foreach ($problems as $problem) {
            $this->error($problem);
        }
        $this->line(($partner ? "Partner \"{$partnerName}\" exists." : "Partner \"{$partnerName}\" will be created.")
            . ' Rows: ' . count($plan) . ' ok, ' . count($problems) . ' problem(s).');

        if (! $this->option('yes')) {
            $this->info('Dry run — nothing written. Re-run with --yes to write.');

            return $problems ? self::FAILURE : self::SUCCESS;
        }
        if ($problems) {
            $this->error('Not written: fix every problem first. Half a rate list is worse than none.');

            return self::FAILURE;
        }

        $written = $conn->transaction(function () use ($partner, $partnerName, $plan, $exists) {
            $partner ??= SharePartner::create(['name' => $partnerName, 'is_active' => true, 'count_free_lines' => false]);
            $n = 0;
            foreach ($plan as $p) {
                if ($exists($p)) {
                    continue;
                }
                ShareRate::create([
                    'share_partner_id' => $partner->id, 'item_key' => $p['item_key'],
                    'product_id' => $p['product_id'], 'combo_id' => $p['combo_id'],
                    'payer' => $p['payer'], 'amount' => $p['amount'],
                    'effective_from' => $p['effective_from'], 'notes' => $p['notes'],
                ]);
                $n++;
            }

            return $n;
        });

        $this->info("Written: {$written} rate row(s).");

        return self::SUCCESS;
    }
}
