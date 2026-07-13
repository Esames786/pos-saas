<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\DeliveryChannel;
use Illuminate\Http\Request;

class DeliveryChannelController extends Controller
{
    public function index(Request $request)
    {
        $query = DeliveryChannel::orderBy('sort_order')->orderBy('name');

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->is_active === '1');
        }

        $channels = $query->paginate(25)->withQueryString();

        return view('tenant.delivery.channels.index', compact('channels'));
    }

    public function store(Request $request)
    {
        $data = $this->validateChannel($request);

        DeliveryChannel::create($data);

        return redirect(url('/delivery/channels'))->with('status', 'Delivery channel created.');
    }

    public function update(Request $request, DeliveryChannel $deliveryChannel)
    {
        $data = $this->validateChannel($request);

        $deliveryChannel->update($data);

        return redirect(url('/delivery/channels'))->with('status', 'Delivery channel updated.');
    }

    public function destroy(DeliveryChannel $deliveryChannel)
    {
        if ($deliveryChannel->sales()->exists()) {
            return redirect(url('/delivery/channels'))
                ->withErrors(['channel' => 'This channel has sales recorded against it. Mark it inactive instead of deleting.']);
        }

        $deliveryChannel->delete();

        return redirect(url('/delivery/channels'))->with('status', 'Delivery channel deleted.');
    }

    private function validateChannel(Request $request): array
    {
        $data = $request->validate([
            'name'               => 'required|string|max:100',
            'type'               => 'required|in:aggregator,own',
            'commission_percent' => 'nullable|numeric|min:0|max:100',
            'is_active'          => 'nullable|boolean',
            'sort_order'         => 'nullable|integer|min:0',
        ]);

        $data['commission_percent'] = $data['commission_percent'] ?? 0;
        $data['is_active']          = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;
        $data['sort_order']         = $data['sort_order'] ?? 0;

        return $data;
    }
}
