{{-- CATERING-SIDEBAR-COLLAPSE-1 — catering ki kaam wali screenon par navigation
     band mil-ti hai, POS ki tarah, aur ye button usay wapas laata hai.

     Pehle ye sirf event ki screen par tha (KASHIF-LEGACY-ALIGN-6). 22 September
     ko malik ne kaha ke fehrist wali screen par bhi wohi chahiye. Do jagah do
     nakal rakhne ke bajaye wo hissa yahan aa gaya hai — is codebase me nakal
     hamesha waqt ke saath alag ho jati hai, aur yahan us nakal ke saath ek
     SABAQ bhi chipka hua hai jo kho nahi sakta:

     ⚠️ Ye faisla YAAD NAHI rakha jata. Ek revision ne kabhi operator ki aakhri
     pasand localStorage me rakhi thi, jis ka natija ye nikla ke mahine pehle ki
     ek hi click ne is screen par navigation hamesha ke liye khula chhod diya —
     9 September ko floor se report hui. Is liye har baar band, aur button sirf
     is safhe par khulta rakhta hai.

     Button yahan se aata hai, is liye har screen usay apne header me jahan
     munasib ho wahan rakh sakti hai; script sirf EK BAAR jati hai (@once). --}}
<button type="button" class="btn btn-light" id="catering-sidebar-toggle"
        title="Show navigation" aria-label="Show navigation">
    <i class="ti ti-layout-sidebar-left-expand"></i>
</button>

@once
@push('scripts')
<script>
// Har visit par band. Dekhein upar wala comment — yaad rakhna jaan-boojh kar
// nahi kiya ja raha.
document.body.classList.remove('mini-sidebar', 'expand-menu');
document.body.classList.add('nosidebar');

// Delegated: event screen ka header (aur us ka ye button) us workspace ke andar
// hai jo ajax se badalta rehta hai.
$(document).on('click', '#catering-sidebar-toggle', function () {
    const hidden = document.body.classList.toggle('nosidebar');
    $(this).find('i').attr('class', hidden ? 'ti ti-layout-sidebar-left-expand' : 'ti ti-layout-sidebar-left-collapse');
    $(this).attr('title', hidden ? 'Show navigation' : 'Hide navigation');
});
</script>
@endpush
@endonce
