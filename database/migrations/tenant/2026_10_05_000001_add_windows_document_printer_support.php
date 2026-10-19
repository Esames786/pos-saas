<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CATERING-SEND-TO-PRINTER-1 — A4/A5 document printer ke khaane.
 *
 * Malik (4 Oct): "client confuse ho raha hai, bar bar A4 / A5 select karna
 * parta hai." Hal ye hai ke kagaz ka size APP tay kare aur job ke saath bheje,
 * taake operator sirf printer chune.
 *
 * Aaj tak `printers` sirf thermal ke liye bana tha:
 *     printer_type  network | usb | browser
 *     print_role    receipt | kot | both
 *     paper_size    58mm | 80mm | A4
 *
 * Teen ezafe, teenon additive:
 *
 * 1. `printer_type` me **windows** — yani agent ke PC par laga hua printer,
 *    NAAM se, IP:port se nahi.
 *
 *    Seedha port 9100 kyun nahi: office ka HP M127fn host-based printer hai —
 *    wo rendering driver se karwata hai aur us ke 9100 par kaccha PCL/PostScript
 *    bhejna bharosemand nahi. Windows ke spooler se sab theek chhapta hai aur
 *    A4/A5 ka chunao driver khud sambhal leta hai.
 *
 * 2. `paper_size` me **A5** — kitchen sheet ka kagaz
 *    (`catering_settings.kitchen_sheet_paper = a5_portrait`).
 *
 * 3. `print_role` me **document** — taake ye printers POS ke receipt/KOT wale
 *    dropdowns me na ghusen. Abhi POS ki fehristein `print_role` se chhanti
 *    hain; ek naya role rakhna un sab ko chheray baghair unhein bahar rakhta
 *    hai. Isi tarah mapping wali screen par bhi ye nazar nahi aayenge —
 *    mapping thermal/station ka kaam hai, document printing ka us se taluq
 *    nahi.
 *
 * ENUM chaura karna raw ALTER se hi hota hai (wohi tareeqa jo
 * 2026_08_03_000002 me print_jobs.document_type par istemaal hua tha).
 *
 * `print_jobs.document_type` ko haath lagane ki ZARURAT NAHI — wo pehle se
 * VARCHAR(30) hai, is liye naya type bina migration ke chal jata hai.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection('tenant');

        $db->statement(
            "ALTER TABLE printers MODIFY printer_type ENUM('network','usb','browser','windows') NOT NULL DEFAULT 'browser'"
        );
        $db->statement(
            "ALTER TABLE printers MODIFY print_role ENUM('receipt','kot','both','document') NOT NULL DEFAULT 'receipt'"
        );
        $db->statement(
            "ALTER TABLE printers MODIFY paper_size ENUM('58mm','80mm','A4','A5') NOT NULL DEFAULT '80mm'"
        );

        if (! Schema::connection('tenant')->hasColumn('printers', 'windows_printer_name')) {
            Schema::connection('tenant')->table('printers', function (Blueprint $table) {
                // Windows me jo naam likha hai, bilkul waisa hi — agent isi se
                // spooler ko pehchanta hai. Nullable, kyunke thermal printers
                // ke liye ye bemani hai.
                $table->string('windows_printer_name')->nullable()->after('port');
            });
        }
    }

    public function down(): void
    {
        $db = DB::connection('tenant');

        // Pehle wo rows nikal do jo purane ENUM me fit hi nahi hotin, warna
        // MODIFY unhe khamoshi se khali kar deta hai.
        $db->table('printers')->where('printer_type', 'windows')->delete();
        $db->table('printers')->where('print_role', 'document')->update(['print_role' => 'receipt']);
        $db->table('printers')->where('paper_size', 'A5')->update(['paper_size' => 'A4']);

        if (Schema::connection('tenant')->hasColumn('printers', 'windows_printer_name')) {
            Schema::connection('tenant')->table('printers', function (Blueprint $table) {
                $table->dropColumn('windows_printer_name');
            });
        }

        $db->statement(
            "ALTER TABLE printers MODIFY paper_size ENUM('58mm','80mm','A4') NOT NULL DEFAULT '80mm'"
        );
        $db->statement(
            "ALTER TABLE printers MODIFY print_role ENUM('receipt','kot','both') NOT NULL DEFAULT 'receipt'"
        );
        $db->statement(
            "ALTER TABLE printers MODIFY printer_type ENUM('network','usb','browser') NOT NULL DEFAULT 'browser'"
        );
    }
};
