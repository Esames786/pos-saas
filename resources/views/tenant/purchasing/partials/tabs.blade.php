{{--
    PURCHASING-V2-2 — one row of tabs over every purchasing page (owner: "purchase ki screen k tamam modules k
    tab links"). A role sees only the tabs it can open. The small numbers say what is waiting.
    Included once from layouts/app on the six purchasing pages, only when "New purchase screens" is on.
--}}
@php
    // W3/W4 S5: "New purchase" comes first — only where the purchase workspace is switched on and the role may open it.
    $purTabs = array_values(array_filter([
        \App\Http\Controllers\Tenant\PurchaseWorkspaceController::on()
            ? ['perm' => 'tenant.purchases.workspace', 'url' => '/purchases/new', 'label' => 'New purchase', 'ur' => '', 'icon' => 'ti-circle-plus', 'on' => 'tenant.purchases.*']
            : null,
        ['perm' => 'tenant.suppliers.index',        'url' => '/suppliers',         'label' => 'Suppliers',     'ur' => '',   'icon' => 'ti-users',          'on' => 'tenant.suppliers.*'],
        ['perm' => 'tenant.purchase-orders.index',   'url' => '/purchase-orders',   'label' => 'Purchase Orders', 'ur' => '',     'icon' => 'ti-clipboard-list', 'on' => 'tenant.purchase-orders.*'],
        ['perm' => 'tenant.goods-receipts.index',    'url' => '/goods-receipts',    'label' => 'Goods Receipts', 'ur' => '', 'icon' => 'ti-truck-delivery', 'on' => 'tenant.goods-receipts.*'],
        ['perm' => 'tenant.purchase-bills.index',    'url' => '/purchase-bills',    'label' => 'Supplier Invoices', 'ur' => '',       'icon' => 'ti-file-invoice',   'on' => 'tenant.purchase-bills.*'],
        ['perm' => 'tenant.supplier-payments.index', 'url' => '/supplier-payments', 'label' => 'Payments',      'ur' => '',  'icon' => 'ti-cash',           'on' => 'tenant.supplier-payments.*'],
        ['perm' => 'tenant.purchase-returns.index',  'url' => '/purchase-returns',  'label' => 'Supplier Returns', 'ur' => '',    'icon' => 'ti-arrow-back-up',  'on' => 'tenant.purchase-returns.*'],
    ]));
    $purUser = auth('tenant')->user();
    $purWaiting = [
        'tenant.purchase-orders.index' => [\App\Models\Tenant\PurchaseOrder::whereIn('status', ['approved', 'partially_received'])->count(), 'to receive'],
        'tenant.purchase-bills.index'  => [\App\Models\Tenant\GoodsReceipt::where('status', 'posted')->whereDoesntHave('bill')->count(), 'to invoice'],
    ];
@endphp
<style>
    /* position: the hidden "to invoice" words inside a tab are absolute — without it they escape the scroller and widen the page on a phone */
    .pur-tabs { position:relative; display:flex; gap:6px; overflow-x:auto; padding:4px; margin:0 0 16px; background:#fff; border:1px solid #E6E9EF; border-radius:12px; }
    .pur-tab { display:flex; align-items:center; gap:8px; padding:8px 14px; border-radius:9px; color:#1B2A4A; font-weight:700; white-space:nowrap; text-decoration:none; }
    .pur-tab:hover { background:#F6F1E4; color:#1B2A4A; }
    .pur-tab:focus-visible { outline:3px solid #B8902E; outline-offset:1px; }
    .pur-tab.on { background:#1B2A4A; color:#fff; }
    .pur-tab .ur { font-weight:500; font-size:.8rem; opacity:.75; }
    .pur-tab .n { background:#B8902E; color:#fff; border-radius:999px; padding:1px 8px; font-size:.75rem; font-weight:800; }
    .pur-tab.on .n { background:#F2DFA8; color:#1B2A4A; }
</style>
<nav class="pur-tabs" aria-label="Purchasing pages">
    @foreach($purTabs as $t)
        @if($purUser && $purUser->can($t['perm']))
            @php [$n, $nWord] = $purWaiting[$t['perm']] ?? [0, '']; $isOn = request()->routeIs($t['on']); @endphp
            <a href="{{ url($t['url']) }}" class="pur-tab {{ $isOn ? 'on' : '' }}" @if($isOn) aria-current="page" @endif>
                <i class="ti {{ $t['icon'] }}" aria-hidden="true"></i>
                <span>{{ $t['label'] }}</span>
                @if($n > 0)<span class="n" title="{{ $n }} {{ $nWord }}">{{ $n }}<span class="visually-hidden"> {{ $nWord }}</span></span>@endif
            </a>
        @endif
    @endforeach
</nav>
