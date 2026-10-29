<?php

namespace App\Services\Saas;

use App\Models\Master\CompanyAccount;
use App\Models\Master\CompanyEntry;
use App\Models\Master\CompanyEntryLine;
use App\Models\Master\CompanyEntryLog;
use App\Models\Master\CompanyPartner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MASTER-HISAB-1 — Bingoo ka apna hisab. Plan: docs/plans/master-hisab-2026-10-29.md.
 *
 * Qaide (owner, 29 Oct 2026):
 *  - khate ka balance = aaya − gaya − bheja + mila (transfer);
 *  - nafa = aaya − kharcha (transfer nafa nahi banata);
 *  - har mahine ka nafa owners me un ke % se (50/50); "mila" = owner ke khaton ka balance;
 *  - jis ke paas hisse se zyada hai wo doosre ko bheje — barabari shuru se ab tak ki hai, to jo is mahine
 *    barabar na hua wo agle mahine me jud jata hai.
 *
 * Hisab chhota hai (mahine me chand entries), to ginti PHP me hoti hai — SQL ke jor tor se zyada saaf.
 */
class CompanyHisab
{
    public function __construct(private BillingRates $rates)
    {
    }

    /**
     * Nayi entry, ya purani ko badalna (Add = Edit). Ghalat cheez par ValidationException — form ke box ke naam se.
     *
     * @param  array<string, mixed>  $data  type, entry_date, tenant_id, note, usd_amount, bill_amount, bank_fee,
     *                                     message_count, lines[] {account_id, to_account_id, category, amount}, _was
     * @return array{entry: CompanyEntry, rate_updated: ?float}
     */
    public function save(array $data, ?CompanyEntry $entry, ?int $userId): array
    {
        $type = (string) ($data['type'] ?? '');
        if (! in_array($type, CompanyEntry::TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'Entry ki qism chunein.']);
        }

        if ($entry && $entry->deleted_at) {
            throw ValidationException::withMessages(['entry' => 'Ye entry hata di gayi hai — badli nahi ja sakti.']);
        }
        // Do log ek hi entry khol kar badlein to doosre ka kaam chup chaap na mite. Nishaan entry ki apni shakl hai
        // (waqt nahi), taake aik hi second ke andar do badlav bhi pakRe jayein.
        if ($entry && (string) ($data['_was'] ?? '') !== $this->fingerprint($entry)) {
            throw ValidationException::withMessages(['entry' => 'Ye entry kisi aur ne abhi badli hai. Page dobara kholein.']);
        }

        $lines = $this->cleanLines($type, (array) ($data['lines'] ?? []));
        $isCard = $type === CompanyEntry::OUT && collect($lines)->contains('category', CompanyEntry::WHATSAPP_COST);

        $header = [
            'entry_date'    => (string) $data['entry_date'],
            'type'          => $type,
            'tenant_id'     => $type === CompanyEntry::IN && ! empty($data['tenant_id']) ? (int) $data['tenant_id'] : null,
            'note'          => trim((string) ($data['note'] ?? '')) ?: null,
            'usd_amount'    => null,
            'bank_fee'      => null,
            'message_count' => null,
        ];

        if ($isCard) {
            // WhatsApp ka card wala kharcha ek hi hissa hota hai: raqam = bank ne bill ka jo kata + tax/fee.
            if (count($lines) !== 1) {
                throw ValidationException::withMessages(['lines' => 'WhatsApp (Meta) ka kharcha akela daalein — doosri cheez alag entry mein.']);
            }
            $usd = (float) ($data['usd_amount'] ?? 0);
            $bill = round((float) ($data['bill_amount'] ?? 0), 2);
            $fee = round((float) ($data['bank_fee'] ?? 0), 2);
            if ($usd <= 0) {
                throw ValidationException::withMessages(['usd_amount' => 'Meta ka bill dollar mein likhein (jaise 1.35).']);
            }
            if ($bill <= 0) {
                throw ValidationException::withMessages(['bill_amount' => 'Bank ne bill ka kitna kata (rupay) likhein.']);
            }
            if ($fee < 0) {
                throw ValidationException::withMessages(['bank_fee' => 'Tax / fee manfi nahi ho sakta.']);
            }
            $lines[0]['amount'] = round($bill + $fee, 2);
            $header['usd_amount'] = $usd;
            $header['bank_fee'] = $fee;
            $header['message_count'] = ! empty($data['message_count']) ? (int) $data['message_count'] : null;
        }

        $saved = DB::connection('master')->transaction(function () use ($entry, $header, $lines, $userId) {
            $before = $entry ? $this->snapshot($entry->load('lines')) : null;

            if ($entry) {
                $entry->fill($header + ['updated_by' => $userId])->save();
                $entry->lines()->delete();
            } else {
                $entry = CompanyEntry::create($header + [
                    'entry_no'   => 'NEW-'.bin2hex(random_bytes(6)),
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);
                $entry->entry_no = 'HSB-'.str_pad((string) $entry->id, 4, '0', STR_PAD_LEFT);
                $entry->save();
            }

            foreach ($lines as $line) {
                $entry->lines()->create($line);
            }

            $entry->load('lines');
            CompanyEntryLog::create([
                'company_entry_id' => $entry->id,
                'action'           => $before ? 'updated' : 'created',
                'before'           => $before,
                'after'            => $this->snapshot($entry),
                'central_user_id'  => $userId,
            ]);

            return $entry;
        });

        return ['entry' => $saved, 'rate_updated' => $this->followCardCost($saved, $userId)];
    }

    /** Entry hatana = chhupana, wajah ke saath. Hisab se nikal jati hai, record rehta hai. */
    public function remove(CompanyEntry $entry, string $reason, ?int $userId): void
    {
        if ($entry->deleted_at) {
            return;
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Hatane ki wajah likhein.']);
        }

        DB::connection('master')->transaction(function () use ($entry, $reason, $userId) {
            $before = $this->snapshot($entry->load('lines'));
            $entry->forceFill(['deleted_at' => now(), 'deleted_by' => $userId, 'delete_reason' => mb_substr($reason, 0, 255)])->save();
            CompanyEntryLog::create([
                'company_entry_id' => $entry->id,
                'action'           => 'deleted',
                'before'           => $before,
                'after'            => ['reason' => $reason],
                'central_user_id'  => $userId,
            ]);
        });
    }

    /**
     * Owners aur khate ek saath. Active owners ke hisse mil kar 100. Jis khate me paisa para ho wo band nahi hota.
     *
     * @param  list<array{key: string, id?: int|null, name: string, share_percent: float|string, is_active?: bool}>  $partners
     * @param  list<array{id?: int|null, name: string, partner_key?: string|null, is_active?: bool}>  $accounts
     */
    public function saveSetup(array $partners, array $accounts): void
    {
        $active = collect($partners)->filter(fn ($p) => ! empty($p['is_active']));
        if ($active->isNotEmpty() && abs($active->sum(fn ($p) => (float) $p['share_percent']) - 100) > 0.001) {
            throw ValidationException::withMessages(['partners' => 'Owners ke hisse mil kar 100% hone chahiye (abhi '.
                rtrim(rtrim(number_format($active->sum(fn ($p) => (float) $p['share_percent']), 2), '0'), '.').'%).']);
        }

        $balances = $this->balances();

        DB::connection('master')->transaction(function () use ($partners, $accounts, $balances) {
            $idByKey = [];
            foreach ($partners as $p) {
                $partner = ! empty($p['id']) ? CompanyPartner::findOrFail($p['id']) : new CompanyPartner;
                $partner->fill([
                    'name'          => trim($p['name']),
                    'share_percent' => (float) $p['share_percent'],
                    'is_active'     => ! empty($p['is_active']),
                ])->save();
                $idByKey[$p['key']] = $partner->id;
            }

            foreach ($accounts as $i => $a) {
                $account = ! empty($a['id']) ? CompanyAccount::findOrFail($a['id']) : new CompanyAccount;
                $wantsActive = ! empty($a['is_active']);
                if ($account->exists && $account->is_active && ! $wantsActive && abs($balances[$account->id] ?? 0) > 0.004) {
                    throw ValidationException::withMessages(['accounts' => "\"{$account->name}\" mein abhi Rs ".
                        number_format($balances[$account->id], 2).' hain — pehle ye paisa doosre khate mein bhejein, phir band karein.']);
                }
                $key = (string) ($a['partner_key'] ?? '');
                $account->fill([
                    'name'       => trim($a['name']),
                    'partner_id' => $key !== '' ? ($idByKey[$key] ?? null) : null,
                    'is_active'  => $wantsActive,
                    'sort_order' => $i,
                ])->save();
            }
        });
    }

    /** @return array<int, float> khata id => balance (tareekh tak, us din samet) */
    public function balances(?string $asOf = null): array
    {
        $out = [];
        foreach ($this->lineRows(null, $asOf) as $l) {
            $amount = (float) $l->amount;
            if ($l->type === CompanyEntry::IN) {
                $out[$l->account_id] = ($out[$l->account_id] ?? 0) + $amount;
            } elseif ($l->type === CompanyEntry::OUT) {
                $out[$l->account_id] = ($out[$l->account_id] ?? 0) - $amount;
            } else {
                $out[$l->account_id] = ($out[$l->account_id] ?? 0) - $amount;
                $out[$l->to_account_id] = ($out[$l->to_account_id] ?? 0) + $amount;
            }
        }

        return array_map(fn ($v) => round($v, 2), $out);
    }

    /** Ek mahine (ya kisi bhi muddat) ka khulasa: aamdani, kharcha, nafa, cheez-wise aur client-wise. */
    public function period(string $from, string $to): array
    {
        $rows = $this->lineRows($from, $to);
        $in = $rows->where('type', CompanyEntry::IN);
        $out = $rows->where('type', CompanyEntry::OUT);

        $byCategory = fn (Collection $set) => $set->groupBy('category')
            ->map(fn ($g, $c) => ['category' => $c, 'label' => CompanyEntry::categoryLabel($c), 'amount' => round($g->sum('amount'), 2)])
            ->sortByDesc('amount')->values()->all();

        $tenantNames = DB::connection('master')->table('tenants')->pluck('business_name', 'id');
        $byClient = $in->groupBy(fn ($l) => (string) ($l->tenant_id ?? ''))
            ->map(fn ($g, $t) => [
                'tenant_id' => $t === '' ? null : (int) $t,
                'name'      => $t === '' ? 'Baghair client' : ($tenantNames[(int) $t] ?? 'Client #'.$t),
                'amount'    => round($g->sum('amount'), 2),
            ])->sortByDesc('amount')->values()->all();

        $income = round($in->sum('amount'), 2);
        $expense = round($out->sum('amount'), 2);

        return [
            'income'       => $income,
            'expense'      => $expense,
            'profit'       => round($income - $expense, 2),
            'in_category'  => $byCategory($in),
            'out_category' => $byCategory($out),
            'by_client'    => $byClient,
        ];
    }

    /**
     * Barabari — shuru se tareekh tak. Har owner: hissa (nafa × %), mila (us ke khaton ka balance), farq.
     * Company ke khaton ka paisa abhi kisi ka nahi. Aakhir me "kaun kis ko kitna bheje" ki seedhi list.
     */
    public function settlement(string $asOf): array
    {
        $totals = $this->period('1900-01-01', $asOf);
        $profit = $totals['profit'];
        $balances = $this->balances($asOf);
        $accounts = CompanyAccount::query()->orderBy('sort_order')->orderBy('id')->get();

        $partners = CompanyPartner::query()->where('is_active', true)->orderBy('id')->get()->map(function ($p) use ($profit, $balances, $accounts) {
            $own = $accounts->where('partner_id', $p->id);
            $got = round($own->sum(fn ($a) => $balances[$a->id] ?? 0), 2);
            $share = round($profit * (float) $p->share_percent / 100, 2);
            // Bhejne / lene ke liye khata: jis me sab se zyada paisa ho (warna pehla).
            $main = $own->sortByDesc(fn ($a) => $balances[$a->id] ?? 0)->first();

            return [
                'id' => $p->id, 'name' => $p->name, 'percent' => (float) $p->share_percent,
                'share' => $share, 'got' => $got, 'diff' => round($got - $share, 2),
                'account_id' => $main?->id,
            ];
        })->values();

        $company = $accounts->whereNull('partner_id')->map(fn ($a) => [
            'id' => $a->id, 'name' => $a->name, 'balance' => $balances[$a->id] ?? 0.0,
        ])->values();

        // Jin ke paas zyada hai (owner ya company khata) → jin ka kam hai. Bari raqam pehle, taake bhejna kam se kam ho.
        $givers = $partners->filter(fn ($p) => $p['diff'] > 0.004)
            ->map(fn ($p) => ['name' => $p['name'], 'account_id' => $p['account_id'], 'left' => $p['diff']])
            ->concat($company->filter(fn ($c) => $c['balance'] > 0.004)
                ->map(fn ($c) => ['name' => $c['name'], 'account_id' => $c['id'], 'left' => $c['balance']]))
            ->sortByDesc('left')->values()->all();
        $takers = $partners->filter(fn ($p) => $p['diff'] < -0.004)
            ->map(fn ($p) => ['name' => $p['name'], 'account_id' => $p['account_id'], 'left' => -$p['diff']])
            ->sortByDesc('left')->values()->all();

        $moves = [];
        $g = 0;
        $t = 0;
        while ($g < count($givers) && $t < count($takers)) {
            $amount = round(min($givers[$g]['left'], $takers[$t]['left']), 2);
            if ($amount > 0.004 && $givers[$g]['account_id'] && $takers[$t]['account_id']) {
                $moves[] = [
                    'from' => $givers[$g]['name'], 'from_account_id' => $givers[$g]['account_id'],
                    'to' => $takers[$t]['name'], 'to_account_id' => $takers[$t]['account_id'],
                    'amount' => $amount,
                ];
            }
            $givers[$g]['left'] -= $amount;
            $takers[$t]['left'] -= $amount;
            if ($givers[$g]['left'] <= 0.004) {
                $g++;
            }
            if ($takers[$t]['left'] <= 0.004) {
                $t++;
            }
        }

        return [
            'profit'   => $profit,
            'partners' => $partners->all(),
            'company'  => $company->all(),
            'moves'    => $moves,
        ];
    }

    /** Mahine-war aamdani / kharcha / nafa — pehli entry ke mahine se, zyada se zyada 12 mahine. */
    public function trend(CarbonImmutable $month, int $months = 12): array
    {
        $first = CompanyEntry::query()->live()->min('entry_date');
        if (! $first) {
            return [];
        }
        $month = $month->startOfMonth();
        $start = CarbonImmutable::parse($first)->startOfMonth();
        $earliest = $month->subMonths($months - 1);
        if ($start->lessThan($earliest)) {
            $start = $earliest;
        }
        $rows = $this->lineRows($start->toDateString(), $month->endOfMonth()->toDateString());

        $out = [];
        for ($m = $start; $m <= $month; $m = $m->addMonth()) {
            $key = $m->format('Y-m');
            $set = $rows->filter(fn ($l) => substr((string) $l->entry_date, 0, 7) === $key);
            $income = round($set->where('type', CompanyEntry::IN)->sum('amount'), 2);
            $expense = round($set->where('type', CompanyEntry::OUT)->sum('amount'), 2);
            $out[] = ['month' => $key, 'label' => $m->format('M Y'), 'income' => $income, 'expense' => $expense, 'profit' => round($income - $expense, 2)];
        }

        return $out;
    }

    /** WhatsApp is muddat me: clients se kitna aaya, card se kitna gaya, nafa — aur card ke bill ka hisaab. */
    public function whatsapp(string $from, string $to): array
    {
        $rows = $this->lineRows($from, $to);
        $billed = round($rows->where('type', CompanyEntry::IN)->where('category', CompanyEntry::WHATSAPP_INCOME)->sum('amount'), 2);
        $cost = round($rows->where('type', CompanyEntry::OUT)->where('category', CompanyEntry::WHATSAPP_COST)->sum('amount'), 2);

        $cards = CompanyEntry::query()->live()->with('lines')
            ->where('type', CompanyEntry::OUT)->whereNotNull('usd_amount')
            ->whereBetween('entry_date', [$from, $to])->orderBy('entry_date')->orderBy('id')->get()
            ->map(fn ($e) => ['entry_no' => $e->entry_no, 'date' => $e->entry_date->format('d M Y')] + $e->cardFigures())
            ->all();

        return ['billed' => $billed, 'cost' => $cost, 'profit' => round($billed - $cost, 2), 'cards' => $cards];
    }

    /**
     * Ek khate ka bank statement jesa hisaab: shuru ka balance, phir har hissa aaya / gaya aur chalta balance.
     *
     * @return array{opening: float, rows: list<array<string, mixed>>, closing: float}
     */
    public function statement(int $accountId, string $from, string $to): array
    {
        $opening = $this->balances(CarbonImmutable::parse($from)->subDay()->toDateString())[$accountId] ?? 0.0;
        $balance = $opening;
        $rows = [];
        // Aik entry = aik line (Khatri ke 25,886 aik hi, chahe andar mahana + WhatsApp ho).
        foreach ($this->lineRows($from, $to)->groupBy('entry_id') as $entryId => $parts) {
            $in = 0.0;
            $out = 0.0;
            foreach ($parts as $l) {
                if ($l->type === CompanyEntry::IN && (int) $l->account_id === $accountId) {
                    $in += (float) $l->amount;
                } elseif ($l->type !== CompanyEntry::IN && (int) $l->account_id === $accountId) {
                    $out += (float) $l->amount;
                } elseif ($l->type === CompanyEntry::TRANSFER && (int) $l->to_account_id === $accountId) {
                    $in += (float) $l->amount;
                }
            }
            if ($in == 0.0 && $out == 0.0) {
                continue;
            }
            $balance = round($balance + $in - $out, 2);
            $rows[] = ['entry_id' => (int) $entryId, 'in' => round($in, 2), 'out' => round($out, 2), 'balance' => $balance];
        }

        return ['opening' => round($opening, 2), 'rows' => $rows, 'closing' => round($balance, 2)];
    }

    /**
     * Sab zinda (na hataye gaye) hisse, entry ki tareekh aur qism ke saath, purane pehle.
     *
     * @return Collection<int, object>
     */
    private function lineRows(?string $from, ?string $to): Collection
    {
        return DB::connection('master')->table('company_entry_lines as l')
            ->join('company_entries as e', 'e.id', '=', 'l.company_entry_id')
            ->whereNull('e.deleted_at')
            ->when($from, fn ($q) => $q->where('e.entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('e.entry_date', '<=', $to))
            ->orderBy('e.entry_date')->orderBy('e.id')->orderBy('l.id')
            ->get(['l.id', 'l.company_entry_id as entry_id', 'l.account_id', 'l.to_account_id', 'l.category', 'l.amount',
                'e.entry_date', 'e.type', 'e.tenant_id'])
            ->map(function ($l) {
                $l->amount = (float) $l->amount;

                return $l;
            });
    }

    /**
     * @param  array<int, mixed>  $lines
     * @return list<array{account_id: int, to_account_id: ?int, category: string, amount: float}>
     */
    private function cleanLines(string $type, array $lines): array
    {
        $lines = array_values(array_filter($lines, fn ($l) => is_array($l) && (($l['amount'] ?? '') !== '' || ! empty($l['account_id']))));
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Kam se kam ek raqam likhein.']);
        }
        if ($type === CompanyEntry::TRANSFER && count($lines) !== 1) {
            throw ValidationException::withMessages(['lines' => 'Ek khate se doosre mein ek hi raqam jati hai.']);
        }

        $active = CompanyAccount::query()->where('is_active', true)->pluck('name', 'id');
        $out = [];
        foreach ($lines as $i => $l) {
            $n = $i + 1;
            $category = $type === CompanyEntry::TRANSFER ? 'transfer' : (string) ($l['category'] ?? '');
            if (! isset(CompanyEntry::CATEGORIES[$type][$category])) {
                throw ValidationException::withMessages(["lines.$i.category" => "Hissa $n: kis cheez ka paisa hai, chunein."]);
            }
            $accountId = (int) ($l['account_id'] ?? 0);
            if (! isset($active[$accountId])) {
                throw ValidationException::withMessages(["lines.$i.account_id" => "Hissa $n: khata chunein."]);
            }
            $toId = null;
            if ($type === CompanyEntry::TRANSFER) {
                $toId = (int) ($l['to_account_id'] ?? 0);
                if (! isset($active[$toId])) {
                    throw ValidationException::withMessages(["lines.$i.to_account_id" => 'Kis khate mein gaya, chunein.']);
                }
                if ($toId === $accountId) {
                    throw ValidationException::withMessages(["lines.$i.to_account_id" => 'Dono khate ek hi hain — alag khata chunein.']);
                }
            }
            $amount = round((float) ($l['amount'] ?? 0), 2);
            // Card wale WhatsApp ki raqam bill + fee se banti hai — form use khud bharta hai.
            if ($amount <= 0 && $category !== CompanyEntry::WHATSAPP_COST) {
                throw ValidationException::withMessages(["lines.$i.amount" => "Hissa $n: raqam likhein."]);
            }
            $out[] = ['account_id' => $accountId, 'to_account_id' => $toId, 'category' => $category, 'amount' => $amount];
        }

        return $out;
    }

    /**
     * Sab se nayi card wali WhatsApp entry me messages likhe hon to "hamara kharcha fi message" (Billing rates) wohi ho
     * jata hai — owner ki baat (29 Oct): andaza asal ke peeche chale. Client ka rate kabhi nahi chhuta.
     */
    private function followCardCost(CompanyEntry $entry, ?int $userId): ?float
    {
        $newest = CompanyEntry::query()->live()->with('lines')
            ->where('type', CompanyEntry::OUT)->whereNotNull('usd_amount')->where('message_count', '>', 0)
            ->orderByDesc('entry_date')->orderByDesc('id')->first();
        if (! $newest || $newest->id !== $entry->id) {
            return null;
        }
        $perMessage = round($newest->total() / $newest->message_count, 4);
        $this->rates->put([BillingRates::WHATSAPP_COST => $perMessage], $userId);

        return $perMessage;
    }

    /** Entry ki shakl ka nishaan — popup ise saath bhejta hai, save par mila kar dekha jata hai. */
    public function fingerprint(CompanyEntry $entry): string
    {
        return md5(json_encode($this->snapshot($entry->loadMissing('lines'))));
    }

    /** Log ke liye entry ki tasveer. */
    private function snapshot(CompanyEntry $entry): array
    {
        return [
            'entry_date'    => $entry->entry_date?->toDateString(),
            'type'          => $entry->type,
            'tenant_id'     => $entry->tenant_id,
            'note'          => $entry->note,
            'usd_amount'    => $entry->usd_amount !== null ? (float) $entry->usd_amount : null,
            'bank_fee'      => $entry->bank_fee !== null ? (float) $entry->bank_fee : null,
            'message_count' => $entry->message_count,
            'lines'         => $entry->lines->map(fn (CompanyEntryLine $l) => [
                'account_id' => $l->account_id, 'to_account_id' => $l->to_account_id,
                'category' => $l->category, 'amount' => (float) $l->amount,
            ])->all(),
        ];
    }
}
