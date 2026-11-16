<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Master\ContactEnquiry;
use Illuminate\Http\Request;

/**
 * CONTACT-FORM-1 — the enquiries the public form has collected.
 *
 * Ek hi safha, do kaam: parhna aur "ho gaya" ka nishaan lagana. Alag "show" safha nahi banaya —
 * enquiry chhoti cheez hai, poora message isi list me nazar aata hai, aur ek aur screen ka matlab
 * ek aur jagah jahan kuch chhoot jaye.
 */
class ContactEnquiryController extends Controller
{
    public function index(Request $request)
    {
        $status = (string) $request->query('status', '');
        $q = trim((string) $request->query('q', ''));

        $enquiries = ContactEnquiry::query()
            ->when(in_array($status, [ContactEnquiry::STATUS_NEW, ContactEnquiry::STATUS_HANDLED], true),
                fn ($query) => $query->where('status', $status))
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    foreach (['name', 'email', 'phone', 'business_name', 'message'] as $column) {
                        $w->orWhere($column, 'like', '%' . $q . '%');
                    }
                });
            })
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('central.contact-enquiries.index', [
            'enquiries' => $enquiries,
            'status'    => $status,
            'q'         => $q,
            'newCount'  => ContactEnquiry::where('status', ContactEnquiry::STATUS_NEW)->count(),
            // Jo mehfooz to hui magar bheji na ja saki — yehi wo soorat hai jis ke liye table bani.
            'unsentCount' => ContactEnquiry::whereNull('notified_at')->count(),
        ]);
    }

    public function handled(Request $request, ContactEnquiry $contactEnquiry)
    {
        // Dobara dabana ek toggle hai: ghalti se lagaya hua nishaan wapas bhi hona chahiye.
        $makeHandled = ! $contactEnquiry->isHandled();

        $contactEnquiry->forceFill([
            'status'     => $makeHandled ? ContactEnquiry::STATUS_HANDLED : ContactEnquiry::STATUS_NEW,
            'handled_at' => $makeHandled ? now() : null,
            'handled_by' => $makeHandled ? (string) (auth('central')->user()?->name ?? '') : null,
        ])->save();

        return back()->with('success', $makeHandled
            ? 'Enquiry marked handled.'
            : 'Enquiry put back in the new list.');
    }
}
