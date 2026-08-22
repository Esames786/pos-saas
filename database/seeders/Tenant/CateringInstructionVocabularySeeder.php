<?php

namespace Database\Seeders\Tenant;

use App\Models\Tenant\CateringInstruction;
use Illuminate\Database\Seeder;

/**
 * KASHIF-CATERING-INSTRUCTIONS-2 — the client's AUTHORITATIVE kitchen vocabulary.
 *
 * These 55 labels are transcribed from the client's legacy software exactly as
 * its operators have read them for years — spelling quirks included ("Tommoto",
 * "Gravi", "Bry Naam"). They are recognition anchors, not prose; correcting
 * them would break the very operators they exist for. The Owner can refine any
 * wording later on the Kitchen Instructions screen. ("Return" on the legacy
 * popup was a button, not an instruction — deliberately absent.)
 *
 * IDEMPOTENT AND ADDITIVE, keyed by the stable Roman label: rerunning updates
 * Urdu/ordering, never duplicates, never truncates, never deletes. An entry
 * the Owner has DEACTIVATED stays deactivated — is_active is set only when a
 * row is first created, because a seeder must never overrule a person.
 * Existing line selections and historical kitchen-sheet snapshots are pivot
 * rows and frozen text; nothing here touches them. No finance, no stock, no
 * quotation or event mutation — this is vocabulary only.
 */
class CateringInstructionVocabularySeeder extends Seeder
{
    /** label => Urdu script (transliteration of the legacy phrase, never a reinterpretation). */
    public const VOCABULARY = [
        'All Masaley Grind' => 'آل مصالحے گرائنڈ',
        'Aloo' => 'آلو',
        'Aloo Bukhara +' => 'آلو بخارا +',
        'Baghair Piyaz' => 'بغیر پیاز',
        'Bombay Style' => 'بمبئی اسٹائل',
        'Boti Choti' => 'بوٹی چھوٹی',
        'Chat Pati' => 'چٹ پٹی',
        'Chawal Dana Dana' => 'چاول دانہ دانہ',
        'Chawal Naram' => 'چاول نرم',
        'Counter Style' => 'کاؤنٹر اسٹائل',
        'Cuter Lal Mirch Kam' => 'کٹر لال مرچ کم',
        'Darmiyana Masala' => 'درمیانہ مصالحہ',
        'Darmiyani Chatpati' => 'درمیانی چٹپٹی',
        'Double Chat Pati' => 'ڈبل چٹ پٹی',
        'Extra Gravi' => 'ایکسٹرا گریوی',
        'Garam Masala +' => 'گرم مصالحہ +',
        'Garam Masala Darmiyana' => 'گرم مصالحہ درمیانہ',
        'Garam Masala Kam' => 'گرم مصالحہ کم',
        'Gosht Gala Huwa Ho' => 'گوشت گلا ہوا ہو',
        'Gosht Na Totey' => 'گوشت نہ ٹوٹے',
        'Grind Hari Mirch +' => 'گرائنڈ ہری مرچ +',
        'Hara Masala +' => 'ہرا مصالحہ +',
        'Hara Masala Darmiyana' => 'ہرا مصالحہ درمیانہ',
        'Hara Masala Grind' => 'ہرا مصالحہ گرائنڈ',
        'Hara Masala Kam' => 'ہرا مصالحہ کم',
        'Hari Mirch Chatpati' => 'ہری مرچ چٹپٹی',
        'Hari Mirch Kam' => 'ہری مرچ کم',
        'Kinary Kachey Na Hon' => 'کنارے کچے نہ ہوں',
        'Lal Mirch Bry Naam' => 'لال مرچ برائے نام',
        'Lal Mirch Kam' => 'لال مرچ کم',
        'Lal Mirch Na Dalain' => 'لال مرچ نہ ڈالیں',
        'Metha Darmiyana' => 'میٹھا درمیانہ',
        'Metha Tez' => 'میٹھا تیز',
        'Mirch Darmiyani' => 'مرچ درمیانی',
        'Mirch Kam' => 'مرچ کم',
        'Mirch Kam Masala +' => 'مرچ کم مصالحہ +',
        'Namak Kam' => 'نمک کم',
        'Namak Normal' => 'نمک نارمل',
        'Oil Kam' => 'آئل کم',
        'Oil Zayada' => 'آئل زیادہ',
        'Rang Kam' => 'رنگ کم',
        'Rang Na Dalain' => 'رنگ نہ ڈالیں',
        'Raseely' => 'رسیلی',
        'Roti Naram' => 'روٹی نرم',
        'Sabit Hari Mirch +' => 'ثابت ہری مرچ +',
        'Sadi Delhi Wali' => 'سادی دہلی والی',
        'Koyala' => 'کوئلہ',
        'Sindhi Style' => 'سندھی اسٹائل',
        'Spicy/Tez Masala' => 'اسپائسی/تیز مصالحہ',
        'Tommoto +' => 'ٹماٹر +',
        'Tommoto Kam' => 'ٹماٹر کم',
        'W/ Out Tommoto' => 'بغیر ٹماٹر',
        'Zafrani Delhi Wali' => 'زعفرانی دہلی والی',
        'Without Panjer' => 'بغیر پنجر',
        'Golden' => 'گولڈن',
    ];

    public function run(): void
    {
        $order = 0;

        foreach (self::VOCABULARY as $label => $labelUr) {
            $order++;

            $row = CateringInstruction::firstOrNew(['label' => $label]);

            if (! $row->exists) {
                // Active only on first creation: a rerun must never overrule an
                // Owner who deactivated an entry on the management screen.
                $row->is_active = true;
            }

            $row->label_ur = $labelUr;
            $row->sort_order = $order;
            $row->save();
        }
    }
}
