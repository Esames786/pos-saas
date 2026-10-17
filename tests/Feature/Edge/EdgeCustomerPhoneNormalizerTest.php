<?php

namespace Tests\Feature\Edge;

use App\Support\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * EDGE PHASE 4 — THE shared phone normaliser (App\Support\PhoneNormalizer): the Cloud create-or-match and the appliance
 * duplicate lookup compare `phone_normalized`, so the rule must be total, deterministic and identical on both sides.
 */
class EdgeCustomerPhoneNormalizerTest extends TestCase
{
    public function test_pakistani_spellings_collapse_to_one_national_key(): void
    {
        foreach (['0300-1234567', '0300 1234567', '(0300) 1234567', '+92 300 1234567', '+923001234567', '0092 300 1234567', '00923001234567', '923001234567', '3001234567', '03001234567'] as $spelling) {
            $this->assertSame('03001234567', PhoneNormalizer::normalize($spelling), "spelling [{$spelling}]");
        }
    }

    public function test_other_numbers_keep_their_digits_and_no_digits_is_null(): void
    {
        $this->assertSame('0421234567', PhoneNormalizer::normalize('042-1234567'), 'a landline keeps its digits');
        $this->assertSame('447700900123', PhoneNormalizer::normalize('+44 7700 900123'), 'a foreign number is not re-prefixed');
        $this->assertSame('111', PhoneNormalizer::normalize('111'));
        $this->assertNull(PhoneNormalizer::normalize(''));
        $this->assertNull(PhoneNormalizer::normalize(null));
        $this->assertNull(PhoneNormalizer::normalize('n/a'));
        $this->assertSame(32, strlen((string) PhoneNormalizer::normalize(str_repeat('9', 40))), 'bounded to the column width');
    }

    public function test_same_compares_through_the_key_and_never_matches_nothing(): void
    {
        $this->assertTrue(PhoneNormalizer::same('+92 300 1234567', '0300-1234567'));
        $this->assertFalse(PhoneNormalizer::same('03001234567', '03001234568'));
        $this->assertFalse(PhoneNormalizer::same('', ''));
        $this->assertFalse(PhoneNormalizer::same(null, null));
    }

    public function test_normalising_twice_is_stable(): void
    {
        foreach (['+92 300 1234567', '042-1234567', '3001234567'] as $spelling) {
            $once = PhoneNormalizer::normalize($spelling);
            $this->assertSame($once, PhoneNormalizer::normalize($once));
        }
    }
}
