<?php

namespace Tests\MySql;

use App\Mail\ContactEnquiryMail;
use App\Mail\ContactEnquiryReceivedMail;
use App\Models\Master\ContactEnquiry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * CONTACT-FORM-1 — the public "Contact Sales" form.
 *
 * Jo is form ka waada hai, wohi yahan sabit hota hai:
 *   - enquiry sales@ par jati hai, aur bhejne wale ko "mil gaya" ka jawab
 *   - enquiry PEHLE mehfooz hoti hai, email BAAD me — mail server ka bura din lead nahi kha sakta
 *   - teen pardey (throttle, honeypot, reCAPTCHA) waqai ROKTE hain, sirf mojood nahi hain
 *   - Master panel me wo list nazar aati hai, aur "ho gaya" ka nishaan lagta hai
 *
 * Har test asli raasta chalata hai: safha controller se render hota hai aur form HTTP se post hota
 * hai — koi service seedha nahi bulai jati.
 */
class ContactFormMySqlTest extends MySqlTenantTestCase
{
    private const SITE = 'test-site-key-1234';

    private const SECRET = 'test-secret-key-5678';

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('master');
        config([
            'saas.public_site_mode'     => 'live',
            'saas.recaptcha.site_key'   => null,
            'saas.recaptcha.secret_key' => null,
            'saas.contact.sales_email'  => 'sales@bingoopos.com',
        ]);
        // CACHE_STORE=array (phpunit.xml), is liye throttle ki gintee har test me nayi hoti hai.
        Mail::fake();
        $this->wipe();
    }

    protected function tearDown(): void
    {
        $this->wipe();
        parent::tearDown();
    }

    private function wipe(): void
    {
        DB::connection('master')->table('contact_enquiries')->where('email', 'like', '%@contact.test')->delete();
    }

    private function central(string $path): string
    {
        return 'http://' . config('tenancy.central_domain') . $path;
    }

    /** A filled-in form. Every test starts from a valid one and breaks exactly one thing. */
    private function form(array $override = []): array
    {
        return array_merge([
            'name'          => 'Imran Sheikh',
            'email'         => 'imran' . Str::lower(Str::random(5)) . '@contact.test',
            'phone'         => '0300 1234567',
            'business_name' => 'Sheikh Biryani House',
            'topic'         => 'demo',
            'message'       => 'We run three branches and want a demo for the restaurant counter.',
        ], $override);
    }

    // ── the page ────────────────────────────────────────────────────────────────────────────

    public function test_the_contact_page_shows_a_real_form_that_posts_to_the_contact_route(): void
    {
        $html = $this->get($this->central('/contact'))->assertOk()->getContent();

        $this->assertStringContainsString('id="contact-form"', $html);
        $this->assertStringContainsString('action="' . $this->central('/contact') . '"', $html);
        foreach (['name="name"', 'name="email"', 'name="message"', 'name="topic"', 'name="website"'] as $field) {
            $this->assertStringContainsString($field, $html, "the form is missing {$field}");
        }

        // Wo purana jumla jo safhe par likha tha ke form "baad me" aayega — ab wo jhoot hota.
        $this->assertStringNotContainsString('An automated contact form will be added later', $html);
        // Aur wo placeholder number jo live site par "replace before production" keh raha tha.
        $this->assertStringNotContainsString('Phone is a placeholder', $html);

        // 🔴 Blade ka wo nuqs jo `php -l` kabhi nahi pakRta: `@php(...)` chhota form apne baad
        // aane wale kisi bhi `@endphp` se jurr jata hai, aur darmiyan ka sab kuch LITERAL TEXT
        // ban jata hai. Compiled file tab bhi sahi PHP hoti hai — wo bas directives chhapti hai.
        // Is liye safhe par unka mojood hona hi saboot hai ke view toot chuki hai.
        foreach (['@php', '@endphp', '@if', '@error', '{{'] as $leaked) {
            $this->assertStringNotContainsString($leaked, $html, "Blade leaked [{$leaked}] as text — a directive is unpaired");
        }
    }

    public function test_a_fake_phone_number_is_hidden_rather_than_shown(): void
    {
        config(['saas.contact.phone' => '+92 XXX XXXXXXX']);
        $this->assertStringNotContainsString('XXX XXXXXXX', $this->get($this->central('/contact'))->getContent());

        config(['saas.contact.phone' => '0332 825 2838']);
        $this->assertStringContainsString('0332 825 2838', $this->get($this->central('/contact'))->getContent());
    }

    // ── the happy path ──────────────────────────────────────────────────────────────────────

    public function test_an_enquiry_is_saved_and_emailed_to_sales_with_a_confirmation_to_the_sender(): void
    {
        $form = $this->form();

        $this->post($this->central('/contact'), $form)
            ->assertRedirect($this->central('/contact') . '#contact-form')
            ->assertSessionHas('contact_sent', true);

        $enquiry = ContactEnquiry::where('email', $form['email'])->firstOrFail();
        $this->assertSame('Imran Sheikh', $enquiry->name);
        $this->assertSame('Sheikh Biryani House', $enquiry->business_name);
        $this->assertSame('demo', $enquiry->topic);
        $this->assertSame(ContactEnquiry::STATUS_NEW, $enquiry->status);
        $this->assertNotNull($enquiry->notified_at, 'the mail was queued, so the row must say so');
        $this->assertNotNull($enquiry->ip_address);

        // Sales ko enquiry, aur bhejne wale ko "mil gaya".
        Mail::assertQueued(ContactEnquiryMail::class, function ($mail) use ($enquiry) {
            return $mail->enquiry->is($enquiry)
                && $mail->hasTo('sales@bingoopos.com')
                // Reply dabate hi jawab usi bande ko jaye, kisi khaali inbox me nahi.
                && $mail->hasReplyTo($enquiry->email);
        });
        Mail::assertQueued(ContactEnquiryReceivedMail::class, fn ($mail) => $mail->hasTo($enquiry->email));
    }

    public function test_the_enquiry_survives_even_when_the_mail_server_refuses(): void
    {
        // Yehi wo wajah hai jis ke liye enquiry pehle DB me likhi jati hai aur phir bheji jati hai.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP is down'));

        $form = $this->form();
        $this->post($this->central('/contact'), $form)->assertSessionHas('contact_sent', true);

        $enquiry = ContactEnquiry::where('email', $form['email'])->firstOrFail();
        $this->assertNull($enquiry->notified_at, 'mail failed, so the row must show it was never sent');
        $this->assertSame($form['message'], $enquiry->message, 'the lead itself is not lost');
    }

    // ── the three gates, each proven to BITE ────────────────────────────────────────────────

    public function test_the_honeypot_refuses_a_bot_and_writes_nothing(): void
    {
        $form = $this->form(['website' => 'http://spam.example']);

        $this->from($this->central('/contact'))
            ->post($this->central('/contact'), $form)
            ->assertSessionHasErrors('website');

        $this->assertSame(0, ContactEnquiry::where('email', $form['email'])->count());
        Mail::assertNothingQueued();
    }

    public function test_a_short_or_missing_message_is_refused(): void
    {
        foreach ([['message' => ''], ['message' => 'hi'], ['email' => 'not-an-email'], ['name' => '']] as $broken) {
            $form = $this->form($broken);
            $this->from($this->central('/contact'))->post($this->central('/contact'), $form)->assertSessionHasErrors();
        }

        $this->assertSame(0, ContactEnquiry::where('email', 'like', '%@contact.test')->count());
        Mail::assertNothingQueued();
    }

    public function test_the_rate_limiter_stops_a_flood_from_one_address(): void
    {
        // Aath guzarte hain (ek hi IP, har baar naya email, warna email wala taala pehle lagta).
        for ($i = 0; $i < 8; $i++) {
            $this->post($this->central('/contact'), $this->form())->assertRedirect();
        }
        $this->assertSame(8, ContactEnquiry::where('email', 'like', '%@contact.test')->count());

        // Nauwan ruk jata hai — aur rukne ka matlab hai ke DB tak pohancha hi nahi.
        $blocked = $this->form();
        $this->post($this->central('/contact'), $blocked)->assertStatus(429);

        $this->assertSame(0, ContactEnquiry::where('email', $blocked['email'])->count());
        $this->assertSame(8, ContactEnquiry::where('email', 'like', '%@contact.test')->count());
    }

    public function test_the_same_email_is_stopped_sooner_than_the_address_allows(): void
    {
        // Ek hi email baar baar: paanchwein ke baad band, jabke IP ka taala aath par hai.
        $email = 'repeat' . Str::lower(Str::random(5)) . '@contact.test';
        for ($i = 0; $i < 5; $i++) {
            $this->post($this->central('/contact'), $this->form(['email' => $email]))->assertRedirect();
        }

        $this->post($this->central('/contact'), $this->form(['email' => $email]))->assertStatus(429);
        $this->assertSame(5, ContactEnquiry::where('email', $email)->count());
    }

    public function test_recaptcha_is_off_until_both_keys_are_set_then_it_refuses_an_unticked_form(): void
    {
        Http::fake();

        // Dono keys ke baghair: na box, na Google se baat.
        $this->assertStringNotContainsString('g-recaptcha', $this->get($this->central('/contact'))->getContent());
        $this->post($this->central('/contact'), $this->form())->assertSessionHasNoErrors();

        // Dono keys ke saath: box safhe par, aur bina tick ke form wapas.
        config(['saas.recaptcha.site_key' => self::SITE, 'saas.recaptcha.secret_key' => self::SECRET]);
        $this->assertStringContainsString('g-recaptcha', $this->get($this->central('/contact'))->getContent());

        $form = $this->form();
        $this->from($this->central('/contact'))
            ->post($this->central('/contact'), $form)
            ->assertSessionHasErrors('g-recaptcha-response');
        $this->assertSame(0, ContactEnquiry::where('email', $form['email'])->count());
    }

    // ── Master panel ────────────────────────────────────────────────────────────────────────

    public function test_master_panel_lists_the_enquiry_and_can_mark_it_handled(): void
    {
        $form = $this->form();
        $this->post($this->central('/contact'), $form)->assertRedirect();
        $enquiry = ContactEnquiry::where('email', $form['email'])->firstOrFail();

        $admin = $this->superAdmin();

        $html = $this->actingAs($admin, 'central')->get($this->central('/contact-enquiries'))->assertOk()->getContent();
        $this->assertStringContainsString('Sheikh Biryani House', $html);
        // Poora message isi list me hai — isi liye koi alag "show" safha nahi banaya gaya.
        $this->assertStringContainsString('want a demo for the restaurant counter', $html);

        $this->actingAs($admin, 'central')
            ->post($this->central('/contact-enquiries/' . $enquiry->id . '/handled'))
            ->assertRedirect();

        $enquiry->refresh();
        $this->assertSame(ContactEnquiry::STATUS_HANDLED, $enquiry->status);
        $this->assertNotNull($enquiry->handled_at);

        // Dobara dabana wapas "new" kar deta hai — ghalti se lagaya nishaan hata bhi sakein.
        $this->actingAs($admin, 'central')
            ->post($this->central('/contact-enquiries/' . $enquiry->id . '/handled'))
            ->assertRedirect();
        $this->assertSame(ContactEnquiry::STATUS_NEW, $enquiry->refresh()->status);
        $this->assertNull($enquiry->handled_at);
    }

    public function test_the_master_list_is_behind_a_login(): void
    {
        $this->get($this->central('/contact-enquiries'))->assertRedirect();
    }

    /**
     * 🚨 "NEW ROUTE = CHECK THE PERMISSION." EnsureRoutePermission har central route par usi naam
     * ka permission maangta hai, aur MasterSeeder ki fehrist me jo naam NAHI hai wo har deploy par
     * `syncPermissions()` se Super Admin se CHHIN jata hai. Yani route banane ke baad seeder me
     * naam na daalna = safha 403, aur wo sirf deploy ke baad pata chalta hai.
     *
     * Is liye fehrist khud parhi jati hai: ye test tab tootta hai jab koi ye do routes to rakhe
     * magar seeder me naam daalna bhool jaye.
     */
    public function test_the_master_seeder_grants_the_permissions_these_routes_need(): void
    {
        $seeder = file_get_contents(database_path('seeders/MasterSeeder.php'));

        foreach (['central.contact-enquiries.index', 'central.contact-enquiries.handled'] as $permission) {
            $this->assertStringContainsString(
                "'" . $permission . "'",
                $seeder,
                "MasterSeeder does not grant {$permission} — the screen will 403 after the next deploy",
            );
        }
    }

    /** The Super Admin, with the two permissions this feature's routes require. */
    private function superAdmin(): \App\Models\Master\CentralUser
    {
        $admin = \App\Models\Master\CentralUser::firstOrCreate(
            ['email' => 'superadmin@mywebsite.com'],
            ['name' => 'Super Admin', 'password' => bcrypt('password'), 'status' => 'active'],
        );

        // Master test DB deploy ki tarah `migrate` par chalti hai (migrate:fresh nahi), is liye
        // ye permissions wahan tab tak nahi hotin jab tak seeder dobara na chale. Yahan wohi kiya
        // jata hai jo MasterSeeder deploy par karta hai — aur seeder ki apni tasdeeq upar hai.
        $role = Role::findOrCreate('Super Admin', 'central');
        foreach (['central.contact-enquiries.index', 'central.contact-enquiries.handled'] as $name) {
            $role->givePermissionTo(Permission::findOrCreate($name, 'central'));
        }
        $admin->syncRoles([$role]);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return $admin;
    }
}
