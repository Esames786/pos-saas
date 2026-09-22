<?php

namespace App\Support\Catering;

use Illuminate\Support\Collection;

/**
 * CATERING-COURSE-ORDER-1 — khana jis tarteeb se parosa jata hai, kaghaz us
 * tarteeb me chhape.
 *
 * Client (21 Sep) ne quotation par laal daira laga kar sequence maangi:
 * starter, biryani, gravy, BBQ, fried, sideline, dessert, nan, raita, salad,
 * tea, pan. Ab tak lines us tarteeb me chhapti thin jis me operator ne punch
 * kiya tha (`sort_order`), is liye ek hi parche par BBQ, phir meetha, phir
 * chatni, phir wapas fried aa jata tha.
 *
 * **Ye tarteeb pehle se system me mojood thi.** `categories.sort_order` par
 * STARTERS=1, RICE=2, CURRIES=3 ... PAN=13 pehle se set hai aur client ki
 * farmaish se hu-ba-hu milti hai. Yahan koi nayi list hard-code NAHI ki gayi —
 * agar malik kal tarteeb badle, wo Categories screen par badlegi aur dono
 * documents khud-ba-khud us par chalenge. Ek hard-coded list is file me aur
 * ek data me — dono ka ek doosre se juda ho jana waqt ki baat hoti.
 *
 * Dono documents (customer ki quotation aur kitchen sheet) YEHI class istemaal
 * karte hain, is liye wo kabhi ek doosre se ikhtilaf nahi kar sakte — is
 * codebase me yehi sabaq baar baar mila hai (materialSummary + line-materials).
 */
final class CourseOrder
{
    /**
     * Jin lines ka product ya category na mile, wo sab se aakhir me jati hain.
     * Bara number is liye ke asli sort_order (1–99) kabhi is tak na pohnche.
     */
    private const UNPLACED = 9999;

    /**
     * Lines ko course ki tarteeb me lagao, aur har course ke andar operator ki
     * apni tarteeb qayam rakho.
     *
     * Operator ki tarteeb ko todna nahi chahiye: agar us ne teen tarah ki
     * biryani ek khaas tarteeb se likhi hai to RICE ke andar wohi tarteeb rahe.
     * Sirf courses aapas me tartib paate hain.
     *
     * @param  iterable<object>  $lines  CateringEstimateLine ya CateringProductionReleaseLine
     */
    public static function sort(iterable $lines): Collection
    {
        return self::hydrate($lines)
            ->sortBy([
                fn ($a, $b) => self::rank($a) <=> self::rank($b),
                fn ($a, $b) => ((int) ($a->sort_order ?? 0)) <=> ((int) ($b->sort_order ?? 0)),
                fn ($a, $b) => ((int) ($a->id ?? 0)) <=> ((int) ($b->id ?? 0)),
            ])
            ->values();
    }

    /**
     * Wohi tarteeb, magar course ke naam ke saath guchhon me — kitchen sheet
     * ke liye, jahan bawarchi course-dar-course kaam karta hai.
     *
     * @return Collection<int, array{name: string, lines: Collection}>
     */
    public static function groups(iterable $lines): Collection
    {
        return self::sort($lines)
            ->groupBy(fn ($line) => self::categoryName($line))
            ->map(fn (Collection $group, string $name) => [
                'name' => $name,
                'lines' => $group->values(),
            ])
            ->values();
    }

    /**
     * Ek line ka course ka naam — chhapne ke liye.
     */
    public static function categoryName(object $line): string
    {
        $category = $line->product?->category ?? null;

        return $category?->name ?: '—';
    }

    /**
     * Rishte ek hi baar load karo. Bina is ke har line par do query chalti
     * hain (product, phir category) aur satrah item ke parche par wo chautees
     * query ban jati hain.
     */
    private static function hydrate(iterable $lines): Collection
    {
        $collection = $lines instanceof Collection ? $lines : collect($lines);

        // loadMissing sirf Eloquent collections par chalta hai; agar koi test
        // saade objects de to bhi ye class kaam karti rahe.
        if ($collection->isNotEmpty() && method_exists($collection, 'loadMissing')) {
            $collection->loadMissing('product.category');
        }

        return $collection;
    }

    private static function rank(object $line): int
    {
        $category = $line->product?->category ?? null;

        if ($category === null) {
            return self::UNPLACED;
        }

        // sort_order khali bhi ho sakta hai (nullable column). Aisi category
        // ko aakhir me rakho, magar un lines se pehle jinki category hi nahi.
        return $category->sort_order === null
            ? self::UNPLACED - 1
            : (int) $category->sort_order;
    }
}
