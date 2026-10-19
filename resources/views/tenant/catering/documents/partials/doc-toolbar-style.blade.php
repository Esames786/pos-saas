{{-- DOC-TOOLBAR-OFF-PAPER-1 (19 Oct) — screen ke button KAGHAZ SE BAHAR.

     Malik ne estimate ka safha nishan laga kar bheja: Print / A4 / printer ka
     khana / Send to network — chaaron parche ke UPAR tair rahe thay aur
     "DRAFT ESTIMATE" ko dhaanp rahe thay. Phir kaha: "ye tamam jo pages
     recent kam kiya hai — invoices, pre invoice, kitchen sheet, address —
     har jagah ye masla nahi aana chahiye."

     WAJAH: teenon stylesheet me ek hi satar thi —
         .print-bar { position: fixed; top: 10px; right: 10px; z-index: 10 }
     `fixed` ka matlab hai "safhe se laa-taalluq, hamesha screen ke us kone
     par" — aur un safhon par `body` KHUD kaghaz hai (A4/A5 chaurai, safed,
     mez par saaya). Is liye wo kona hamesha kaghaz ke upar hi parta tha.

     HAL: button ab kaghaz ke UPAR WALI MEZ par baithte hain. `bottom: 100%`
     unhe hamesha kaghaz ke bilkul upar rakhta hai — chahe bar ek satar ki ho
     ya do — aur `body` ke sar par utni jagah bana di gayi hai. Ye safhe ke
     saath scroll hote hain, is liye neeche jate hi nazron se hat jate hain
     aur kisi haal me matn nahi dhaanpte.

     QEEMAT (likh deni chahiye): Print ka button ab hamesha nazar ke samne
     nahi rehta. Lambe document par chhapne ke liye upar aana parega. Malik
     ka maangna yehi tha, aur bulk safhe pehle se isi tarah chal rahe hain.

     EK HI JAGAH: ye partial saare document safhon me lagti hai, is liye
     dobara kisi ek safhe par alag soorat nahi ban sakti. Har document ki
     apni stylesheet ke BAAD lagni chahiye, warna purani satar is par haawi
     ho jati hai. --}}
@php
    $tbRtl = ($isUr ?? false) === true;
@endphp
<style>
    @media screen {
        /* Kaghaz ke sar par mez ki patti — bar isi me baithta hai.
           `position: relative` is liye ke bar ka langar KAGHAZ ho, screen
           nahi; wagarna hum wapas wahin pahunch jate hain jahan se chale the. */
        body { position: relative; margin-top: 84px; }

        .print-bar, .bulk-toolbar, .toolbar {
            position: absolute;
            bottom: 100%;        /* hamesha kaghaz ke bilkul upar */
            margin-bottom: 10px;
            left: 0; right: 0;
            display: flex; flex-wrap: wrap; align-items: center; gap: 8px;
            justify-content: {{ $tbRtl ? 'flex-start' : 'flex-end' }};
            font-family: Arial, Helvetica, sans-serif;
            text-align: initial;
        }
    }

    /* TANG SCREEN (phone): mez bachti hi nahi — kaghaz poori chaurai le leta
       hai. Wahan bar kaghaz ke sar par ek BHARI PATTI banta hai: matn ke upar
       phir bhi kabhi nahi aata, bas use neeche dhakel deta hai. */
    @media screen and (max-width: 230mm) {
        body { margin-top: 0; }
        .print-bar, .bulk-toolbar, .toolbar {
            position: static; margin: 0 0 10px;
            padding: 8px 10px; background: #e5e7eb;
            border-radius: 6px;
        }
    }

    @media print {
        .print-bar, .bulk-toolbar, .toolbar { display: none; }
    }
</style>
