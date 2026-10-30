<?php

namespace App\Services\Saas;

use App\Models\Master\CompanyAccount;
use App\Models\Master\CompanyEntry;
use App\Support\SimpleXlsx as X;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * MASTER-HISAB-EXCEL-1 — Hisab ki Excel file (Master → Hisab → Excel). Plan: docs/plans/master-hisab-2026-10-29.md.
 *
 * Har figure CompanyHisab se aata hai — wohi jo safha dikhata hai — taake file aur safha kabhi alag baat na karein.
 * Sheets: Khulasa · Entries · har khate ki aik · Mahine-war · WhatsApp.
 */
class CompanyHisabExport
{
    public function __construct(private CompanyHisab $hisab)
    {
    }

    public function build(string $from, string $to, string $label): X
    {
        $x = new X;
        $accounts = CompanyAccount::query()->with('partner')->orderBy('sort_order')->orderBy('id')->get();
        $names = $accounts->pluck('name', 'id');
        $period = $this->hisab->period($from, $to);
        $balances = $this->hisab->balances($to);
        $settlement = $this->hisab->settlement($to);
        $whatsapp = $this->hisab->whatsapp($from, $to);
        $users = DB::connection('master')->table('central_users')->pluck('name', 'id');
        $d = fn (string $date) => CarbonImmutable::parse($date);
        $money = fn ($v) => ['v' => round((float) $v, 2), 's' => X::MONEY];
        $head = fn (array $titles) => array_map(fn ($t) => ['v' => $t, 's' => X::HEADER], $titles);

        // 1. Khulasa
        $s = $x->sheet('Khulasa', [42, 14, 16, 16, 16, 30]);
        $x->row($s, [['v' => 'Bingoo — Hisab', 's' => X::TITLE]]);
        $x->row($s, [['v' => $label.' · '.$d($from)->format('d M Y').' se '.$d($to)->format('d M Y').' tak · banayi '.now()->format('d M Y H:i')
            .' · sab raqam Rs mein', 's' => X::NOTE]]);
        $x->row($s);
        $x->row($s, $head(['Is muddat mein', 'Rs']));
        $x->row($s, ['Aamdani', $money($period['income'])]);
        $x->row($s, ['Kharcha', $money($period['expense'])]);
        $x->row($s, [['v' => 'Nafa', 's' => X::BOLD], ['v' => $period['profit'], 's' => X::MONEY_BOLD]]);
        $x->row($s, ['Company ka kul paisa ('.$d($to)->format('d M Y').' tak, sab khaton mein)', $money(array_sum($balances))]);
        $x->row($s);
        $x->row($s, $head(['Barabari — shuru se '.$d($to)->format('d M Y').' tak', 'Hissa', 'Banta tha', 'Mil gaya', 'Farq', 'Haal']));
        foreach ($settlement['partners'] as $p) {
            $state = abs($p['diff']) <= 0.004 ? 'Barabar' : ($p['diff'] > 0 ? 'Zyada mila' : 'Kam mila');
            $x->row($s, [$p['name'], ['v' => $p['percent'] / 100, 's' => X::PERCENT], $money($p['share']), $money($p['got']), $money($p['diff']), $state]);
        }
        foreach ($settlement['moves'] as $m) {
            $x->row($s, [['v' => $m['from'].' → '.$m['to'], 's' => X::BOLD], '', ['v' => $m['amount'], 's' => X::MONEY_BOLD], 'bhej dein, to hisab barabar']);
        }
        if (! $settlement['moves'] && $settlement['partners']) {
            $x->row($s, [['v' => 'Hisab barabar hai — kisi ko kuch nahi bhejna.', 's' => X::BOLD]]);
        }
        $x->row($s, [['v' => 'Mil gaya = owner ke khaton ka balance: jo paisa us ke paas aaya, minus jo company ke kharche us ne diye, '
            .'plus/minus jo owners ne aapas mein bheja. Nafa shuru se ginta hai, is liye jo pichhle mahine barabar na hua wo yahan jud jata hai.', 's' => X::NOTE]]);
        $x->row($s);
        $x->row($s, $head(['Khata', 'Kis ka', 'Balance']));
        foreach ($accounts as $a) {
            if ($a->is_active || abs($balances[$a->id] ?? 0) > 0.004) {
                $x->row($s, [$a->name, $a->partner?->name ?? 'Company', $money($balances[$a->id] ?? 0)]);
            }
        }
        $x->row($s, [['v' => 'Sab khate mila kar', 's' => X::BOLD], '', ['v' => array_sum($balances), 's' => X::MONEY_BOLD]]);
        $x->row($s);
        $x->row($s, $head(['WhatsApp', 'Rs']));
        $x->row($s, ['Clients se liya', $money($whatsapp['billed'])]);
        $x->row($s, ['Card se gaya (Meta bill + bank tax/fee)', $money($whatsapp['cost'])]);
        $x->row($s, [['v' => 'WhatsApp ka nafa', 's' => X::BOLD], ['v' => $whatsapp['profit'], 's' => X::MONEY_BOLD]]);
        $x->row($s);
        $x->row($s, $head(['Kis client se kitna aaya', 'Rs']));
        foreach ($period['by_client'] as $c) {
            $x->row($s, [$c['name'], $money($c['amount'])]);
        }
        $x->row($s);
        $x->row($s, $head(['Kharcha kis cheez par', 'Rs']));
        foreach ($period['out_category'] as $c) {
            $x->row($s, [$c['label'], $money($c['amount'])]);
        }

        // 2. Entries — har hissa aik row (Kashif Kitchen ke 1,50,000 do rows: 75,000 + 75,000).
        $entries = CompanyEntry::query()->live()->with(['lines', 'tenant', 'logs'])
            ->whereBetween('entry_date', [$from, $to])->orderBy('entry_date')->orderBy('id')->get();
        $kind = ['in' => 'Paisa aaya', 'out' => 'Kharcha', 'transfer' => 'Khate se khate'];
        $s = $x->sheet('Entries', [13, 11, 15, 22, 24, 22, 22, 14, 14, 14, 36, 16, 8], 1);
        $x->row($s, $head(['Tareekh', 'No.', 'Qism', 'Kis cheez ka', 'Client', 'Khata', 'Kis khate mein', 'Aaya (+)', 'Gaya (−)', 'Khate se khate', 'Note', 'Kis ne', 'Badli']));
        $sum = ['in' => 0.0, 'out' => 0.0, 'transfer' => 0.0];
        foreach ($entries as $e) {
            $edits = $e->logs->where('action', 'updated')->count();
            foreach ($e->lines as $l) {
                $amount = (float) $l->amount;
                $sum[$e->type] += $amount;
                $x->row($s, [
                    $e->entry_date, $e->entry_no, $kind[$e->type] ?? $e->type, CompanyEntry::categoryLabel($l->category),
                    $e->tenant?->business_name ?? '', $names[$l->account_id] ?? '', $l->to_account_id ? ($names[$l->to_account_id] ?? '') : '',
                    $e->type === 'in' ? $money($amount) : '', $e->type === 'out' ? $money($amount) : '', $e->type === 'transfer' ? $money($amount) : '',
                    (string) $e->note, $users[$e->created_by] ?? '', $edits ?: '',
                ]);
            }
        }
        $x->row($s, [['v' => 'Total', 's' => X::BOLD], '', '', '', '', '', '',
            ['v' => $sum['in'], 's' => X::MONEY_BOLD], ['v' => $sum['out'], 's' => X::MONEY_BOLD], ['v' => $sum['transfer'], 's' => X::MONEY_BOLD]]);

        // 3. Har khate ki sheet — bank statement jesi.
        $byId = $entries->keyBy('id');
        foreach ($accounts as $a) {
            $st = $this->hisab->statement($a->id, $from, $to);
            if (! $a->is_active && ! $st['rows'] && abs($st['opening']) <= 0.004) {
                continue;
            }
            $s = $x->sheet('Khata - '.$a->name, [13, 11, 26, 40, 14, 14, 16], 3);
            $x->row($s, [['v' => $a->name.($a->partner ? ' — owner: '.$a->partner->name : ' — company ka apna khata'), 's' => X::TITLE]]);
            $x->row($s, [['v' => $d($from)->format('d M Y').' se '.$d($to)->format('d M Y').' tak', 's' => X::NOTE]]);
            $x->row($s, $head(['Tareekh', 'No.', 'Kya', 'Client / note', 'Aaya (+)', 'Gaya (−)', 'Balance']));
            $x->row($s, [$d($from), '', ['v' => 'Shuru ka balance', 's' => X::BOLD], '', '', '', ['v' => $st['opening'], 's' => X::MONEY_BOLD]]);
            foreach ($st['rows'] as $r) {
                $e = $byId[$r['entry_id']] ?? null;
                if (! $e) {
                    continue;
                }
                $what = $e->lines->pluck('category')->unique()->map(fn ($c) => CompanyEntry::categoryLabel($c))->implode(', ');
                $x->row($s, [$e->entry_date, $e->entry_no, $what, trim(($e->tenant?->business_name ?? '').($e->tenant && $e->note ? ' · ' : '').($e->note ?? '')),
                    $r['in'] > 0 ? $money($r['in']) : '', $r['out'] > 0 ? $money($r['out']) : '', $money($r['balance'])]);
            }
            $x->row($s, [$d($to), '', ['v' => 'Akhir ka balance', 's' => X::BOLD], '',
                ['v' => array_sum(array_column($st['rows'], 'in')), 's' => X::MONEY_BOLD], ['v' => array_sum(array_column($st['rows'], 'out')), 's' => X::MONEY_BOLD],
                ['v' => $st['closing'], 's' => X::MONEY_BOLD]]);
        }

        // 4. Mahine-war — pehli entry se is muddat ke akhri mahine tak.
        $s = $x->sheet('Mahine-war', [14, 16, 16, 16], 1);
        $x->row($s, $head(['Mahina', 'Aamdani', 'Kharcha', 'Nafa']));
        foreach ($this->hisab->trend($d($to)->startOfMonth(), 60) as $t) {
            $x->row($s, [$t['label'], $money($t['income']), $money($t['expense']), ['v' => $t['profit'], 's' => X::MONEY_BOLD]]);
        }

        // 5. WhatsApp ke card wale bill.
        $s = $x->sheet('WhatsApp', [13, 11, 12, 15, 13, 10, 13, 11, 13, 14], 1);
        $x->row($s, $head(['Tareekh', 'No.', 'Meta bill ($)', 'Bank ne kata', 'Tax / fee', 'Tax %', 'Dollar rate', 'Messages', 'Fi message', 'Kul']));
        foreach ($whatsapp['cards'] as $c) {
            $x->row($s, [$d($c['day']), $c['entry_no'], ['v' => $c['usd'], 's' => X::MONEY],
                $money($c['bill']), $money($c['fee']), $c['tax_percent'] !== null ? ['v' => $c['tax_percent'] / 100, 's' => X::PERCENT] : '',
                $c['rate'] !== null ? $money($c['rate']) : '', $c['messages'] ?: '', $c['per_message'] !== null ? $money($c['per_message']) : '',
                ['v' => $c['total'], 's' => X::MONEY_BOLD]]);
        }
        if (! $whatsapp['cards']) {
            $x->row($s, [['v' => 'Is muddat mein card se WhatsApp ka koi bill nahi.', 's' => X::NOTE]]);
        }

        return $x;
    }
}
