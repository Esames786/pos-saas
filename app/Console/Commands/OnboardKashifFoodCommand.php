<?php

namespace App\Console\Commands;

use App\Models\Master\Module;
use App\Models\Master\Plan;
use App\Models\Master\PlanFeature;
use App\Models\Master\PlanModule;
use App\Models\Master\Subscription;
use App\Models\Master\Tenant;
use App\Models\Master\TenantDomain;
use App\Services\Tenancy\TenancyManager;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * KASHIF FOOD ONBOARDING — a BRAND-NEW restaurant tenant (tenant_code `kashiffood`).
 *
 * This is data provisioning only; it NEVER touches any other tenant (khatribiryani/kashifkitchen
 * are separate). Mirrors the proven Khatri onboarding pattern:
 *  - master: custom plan `kashif_restaurant` (restaurant_pro module set, 1 branch / 4 terminals / 10 users);
 *  - master: tenant + domain + ACTIVE subscription;
 *  - provision (existing TenantProvisioner — DB, migrations, base seed, Owner);
 *  - tenant: 4 terminals (T1 Delivery, T2/T3/T4 DTQ), the full Kashif menu (ALL SERVICE-BASED —
 *    is_stock_tracked=0, consumption 'none'), size/portion variants as separate products, deals &
 *    platters as combos (per-component KOT routing), 6 printers + 3-station routing
 *    (Counter=each terminal's own printer / BBQ .87 / Fastfood .54), and the operator roles/users.
 *
 * Routing (owner-confirmed 2026-08-24):
 *  - COUNTER (terminal's own printer): Beverages, Singaporean/Biryani/rice dishes, Chatnies, Salad,
 *    Raita, Extras — plus receipts, previews and reminders.
 *  - BBQ/GRILL (.87): Paratha, all Rolls, all Bar-B-Que.
 *  - FASTFOOD (.54): everything else cooked.
 *  Reminders print at the COUNTER (per-terminal) for cooked (BBQ/FF) categories.
 */
class OnboardKashifFoodCommand extends Command
{
    protected $signature = 'onboard:kashif-food
        {--owner-password= : Owner password (required on first provision)}
        {--owner-email= : Tenant owner/default report email}
        {--counter-password= : Password for the counter/delivery users (generated when omitted)}
        {--manager-pin= : Manager PIN for the counter users (defaults to the agreed value)}
        {--yes : Confirm execution}';

    protected $description = 'Onboard the Kashif Food tenant (brand-new; idempotent; touches no other tenant).';

    private const PLAN_CODE = 'kashif_restaurant';
    private const TENANT_CODE = 'kashiffood';
    private const OWNER_EMAIL = 'owner_kf@bingoopos.com';

    private const PLAN_MODULES = [
        'pos', 'catalog', 'restaurant', 'kitchen_display', 'kitchen_inventory', 'inventory',
        'purchasing', 'stock_count', 'printing', 'reports', 'sales_controls', 'multi_branch',
        'users_roles', 'finance',
    ];

    private const PLAN_FEATURES = ['branch_limit' => 1, 'terminal_limit' => 4, 'user_limit' => 10, 'product_limit' => null];

    /**
     * The catalogue. Each entry: [category, station, parentCategory|null, [[productName, price, hidden?], ...]].
     * station ∈ counter|bbq|ff. Prices are EXACTLY the 04-19-26 card. Size/portion options are separate
     * products (proven Khatri pattern), suffixed in the name so the slug stays unique. Products flagged
     * `hidden` are combo-only fillers (is_pos_visible=0) — the card gives them no standalone price so they
     * carry a documented placeholder (⚠ verify).
     */
    private const CATALOGUE = [
        // ── FASTFOOD ──────────────────────────────────────────────────────────
        ['Starter', 'ff', null, [
            ['Chicken Hot Wings (Spicy) 8 Pcs', 990], ['Dynamite Chicken', 990], ['Dhaka Chicken', 990],
            ['Supreme Nachos', 990], ['Korean Chicken', 990], ['Fish And Chips 5 Pcs', 1490], ['Dhaka Fish 5 Pcs', 1490],
        ]],
        ['Soup', 'ff', null, [
            ['Chicken Corn Soup (Single)', 350], ['Chicken Corn Soup (Family Bowl)', 1100],
            ['Hot n Sour Soup (Single)', 400], ['Hot n Sour Soup (Family Bowl)', 1200],
        ]],
        ['Crispy Fried Chicken', 'ff', null, [
            ['2 Pcs Crispy Fried Chicken', 650], ['2 Pcs Crispy Fried Chicken (With Fries)', 800],
            ['3 Pcs Crispy Fried Chicken', 900], ['3 Pcs Crispy Fried Chicken (With Fries)', 1050],
            ['2 Pcs Crispy Fried Chicken (Spicy)', 700], ['2 Pcs Crispy Fried Chicken (Spicy, With Fries)', 850],
            ['3 Pcs Crispy Fried Chicken (Spicy)', 950], ['3 Pcs Crispy Fried Chicken (Spicy, With Fries)', 1100],
            ['2 Pcs Crispy Fried Chicken (Garlic)', 700], ['2 Pcs Crispy Fried Chicken (Garlic, With Fries)', 850],
        ]],
        ['Kids House', 'ff', null, [
            ['Nuggets 6 Pcs', 700], ['Nuggets 6 Pcs (With Fries)', 850],
            ['Hot Shot 9 Pcs', 650], ['Hot Shot 9 Pcs (With Fries)', 800],
            ['Crispy Fried Chicken 1 Pcs', 450], ['Crispy Fried Chicken 1 Pcs (With Fries)', 600],
        ]],
        ['Burgers', 'ff', null, [
            ['Chicken Crunch Burger', 500], ['Chicken Crunch Burger (With Fries)', 650],
            ['Chicken Zinger Burger', 650], ['Chicken Zinger Burger (With Fries)', 800],
            ['Thrill Zinger Burger (Spicy)', 700], ['Thrill Zinger Burger (Spicy, With Fries)', 850],
            ['Chicken Burger', 600], ['Chicken Burger (With Fries)', 750],
            ['Beef Burger', 650], ['Beef Burger (With Fries)', 800],
        ]],
        ['Smash Burgers', 'ff', null, [
            ['American Vintage (Beef)', 1050], ['Mushroom Melt (Beef)', 1050], ['Smoky Bar-B-Que (Beef)', 1150],
            ['Crunchy Jalapeno (Beef)', 1550], ['Smoke House (Chicken)', 900], ['Grilled Mushroom (Chicken)', 900],
            ['Cheesy Blaze (Chicken)', 1000], ['Dual Combo Crispy Burger (Chicken)', 1250],
        ]],
        ['Sandwiches', 'ff', null, [
            ['Chicken Sandwich', 700], ['Chicken Sandwich (With Fries)', 850],
            ['Club Sandwich', 750], ['Club Sandwich (With Fries)', 900],
            ['Chicken Bar-B-Que Sandwich', 800], ['Chicken Bar-B-Que Sandwich (With Fries)', 950],
            ['Chicken Malai Boti Sandwich', 800], ['Chicken Malai Boti Sandwich (With Fries)', 950],
        ]],
        ['Fries', 'ff', null, [
            ['Fries (Regular)', 350], ['Fries (Large)', 450], ['Fries Masala (Regular)', 370], ['Fries Masala (Large)', 470],
            ['Mayo Garlic Fries (Regular)', 450], ['Mayo Garlic Fries (Large)', 550],
        ]],
        ['Pizza Fries', 'ff', null, [
            ['Miami Supreme (Small)', 800], ['Miami Supreme (Large)', 1300], ['Tikka Pizza Fries (Small)', 800], ['Tikka Pizza Fries (Large)', 1300],
            ['Supreme Pizza Fries (Small)', 800], ['Supreme Pizza Fries (Large)', 1300], ['Pepperoni Pizza Fries (Small)', 800], ['Pepperoni Pizza Fries (Large)', 1300],
        ]],
        ['Chinese', 'ff', null, [
            ['Garlic Rice With Shashlik Bar-B-Que', 1650],
            ['Manchurian With Fried Rice (1 Person)', 1350], ['Manchurian With Fried Rice (2 Person)', 2450],
            ['Shashlik With Fried Rice (1 Person)', 1350], ['Shashlik With Fried Rice (2 Person)', 2450],
            ['Schezwan With Fried Rice (1 Person)', 1350], ['Schezwan With Fried Rice (2 Person)', 2450],
            ['Chicken Chilli Dry With Fried Rice (1 Person)', 1350], ['Chicken Chilli Dry With Fried Rice (2 Person)', 2450],
            ['Kung Pao Chicken With Fried Rice (1 Person)', 1350], ['Kung Pao Chicken With Fried Rice (2 Person)', 2450],
            ['Dragon Chicken With Fried Rice (1 Person)', 1350], ['Dragon Chicken With Fried Rice (2 Person)', 2450],
            ['Beef Chilli Dry With Fried Rice (1 Person)', 1450], ['Beef Chilli Dry With Fried Rice (2 Person)', 2650],
            ['Dragon Beef With Fried Rice (1 Person)', 1450], ['Dragon Beef With Fried Rice (2 Person)', 2650],
            ['Chicken Fried Rice (1 Person)', 1200], ['Chicken Fried Rice (2 Person)', 1950],
            ['Chicken Chow Mein (1 Person)', 1200], ['Chicken Chow Mein (2 Person)', 1900],
            ['Vegetable Chow Mein (1 Person)', 1050], ['Vegetable Chow Mein (2 Person)', 2050],
            ['Vegetable Fried Rice (1 Person)', 950], ['Vegetable Fried Rice (2 Person)', 1750],
        ]],
        ['Steaks', 'ff', 'Continental', [
            ['Tarragon Steak (Chicken)', 2050], ['Tarragon Steak (Beef)', 2650],
            ['Mushroom Steak (Chicken)', 2050], ['Mushroom Steak (Beef)', 2650],
            ['Spicy Mexican Steak (Chicken)', 2050], ['Spicy Mexican Steak (Beef)', 2650],
            ['French Fries (Steak Side)', 300], ['Vegetable Rice (Steak Side)', 400],
        ]],
        ['Pasta', 'ff', 'Continental', [
            ['Chicken Alfredo Pasta (1 Person)', 1250], ['Chicken Alfredo Pasta (2 Person)', 2150],
            ['Chicken Mac n Cheese Pasta (1 Person)', 1250], ['Chicken Mac n Cheese Pasta (2 Person)', 2150],
            ['Chicken Red Sauce Pasta (1 Person)', 1250], ['Chicken Red Sauce Pasta (2 Person)', 2150],
            ['Chicken Parmesan Cheese Pasta (1 Person)', 1250], ['Chicken Parmesan Cheese Pasta (2 Person)', 2150],
        ]],

        // ── BBQ / GRILL ───────────────────────────────────────────────────────
        ['Bar-B-Que', 'bbq', null, [
            ['Chicken Tikka (Chest)', 650], ['Chicken Tikka (Leg)', 600],
            ['Chicken Malai Tikka (Chest)', 750], ['Chicken Malai Tikka (Leg)', 700],
            ['Chicken Malai Boti', 1150], ['Chicken Boti Boneless', 1150], ['Chicken Reshmi Kebab', 1150],
            ['Chicken Shahi Chattakh', 1250], ['Chicken Baluchi Boti', 1250], ['Beef Behari Boti', 1250],
            ['Beef Seekh Kebab', 1250], ['Beef Dhaga Kebab', 1250], ['Beef Dhaga Kebab (Fry)', 1350],
            ['Beef Afghani Boti', 1550], ['Bar B Q Fish Grill (Half)', 2250], ['Bar B Q Fish Grill (Full)', 4500],
        ]],
        ['Bar-B-Que New Arrivals', 'bbq', null, [
            ['Cajun Chicken (Spicy)', 850], ['Chicken Namkeen Boti', 850], ['Adana Kebab Chicken', 1250],
            ['Tandoori Chicken', 1250], ['Adana Kebab Beef', 1450], ['Morocon Kebab', 1450],
        ]],
        ['Paratha', 'bbq', null, [
            ['Parhata Small', 80], ['Parhata Large', 150], ['Pori Paratha', 80],
        ]],
        ['Beef Boti Rolls', 'bbq', 'Rolls', [
            ['Beef Boti Chatni Roll', 370], ['Beef Boti Garlic Mayo Roll', 400], ['Beef Boti Mayo Cheese Roll', 410],
        ]],
        ['Beef Kebab Rolls', 'bbq', 'Rolls', [
            ['Beef Seekh Kebab Chatni Roll', 370], ['Beef Kebab Garlic Mayo Roll', 400], ['Beef Kebab Mayo Cheese Roll', 410],
        ]],
        ['Chicken Roll', 'bbq', 'Rolls', [
            ['Chicken Balochi Roll', 400], ['Chicken Balochi Mayo Roll', 440], ['Chicken Balochi Cheese Roll', 450],
            ['Chicken Chatni Roll', 370], ['Chicken Garlic Mayo Roll', 400], ['Chicken Mayo Cheese Roll', 410],
        ]],
        ['Chicken Crispy Rolls', 'bbq', 'Rolls', [
            ['Chicken Crispy Chatni Roll', 500], ['Chicken Crispy Mayo Garlic Roll', 500], ['Chicken Crispy Mayo Cheese Roll', 510],
        ]],
        ['Chicken Malai Boti Roll', 'bbq', 'Rolls', [
            ['Chicken Malai Chatni Roll', 390], ['Chicken Malai Garlic Mayo Roll', 420], ['Chicken Malai Mayo Cheese Roll', 440],
        ]],
        ['Chicken Reshmi Kebab Roll', 'bbq', 'Rolls', [
            ['Chicken Reshmi Kebab Chatni Roll', 380], ['Chicken Reshmi Kebab Garlic Mayo Roll', 400], ['Chicken Reshmi Kebab Mayo Cheese Roll', 420],
        ]],
        ['Ustad Roll', 'bbq', 'Rolls', [
            ['Ustad Special Roll', 550], ['Ustad Special Mayo', 600], ['Ustad Special Chipotle Roll', 600],
        ]],

        // ── COUNTER (terminal's own printer) ───────────────────────────────────
        ['Singaporean Rice', 'counter', null, [
            ['Singaporean Rice (Regular)', 600], ['Singaporean Rice (Large)', 1050], ['Singaporean Rice (Platter)', 1580],
            ['Singaporean Rice (Khass)', 2900], ['Singaporean Rice Family Pack (Small)', 2750], ['Singaporean Rice Family Pack (Large)', 4100],
        ]],
        ['Chicken Biryani', 'counter', null, [
            ['Chicken Biryani (Sadi)', 250], ['Chicken Biryani (Small)', 400], ['Chicken Biryani (Large)', 750],
            ['Chicken Biryani (Platter)', 2000], ['Chicken Biryani Family Pack 6 Pcs', 2270], ['Extra Chicken Pcs', 200],
        ]],
        ['Beverages', 'counter', null, [
            ['Mineral Water (Small)', 80], ['Mineral Water (Large)', 160], ['Cold Drink (Can)', 160], ['Soft Drink Sting (Can)', 160],
            ['Soft Drink (300 ml)', 100], ['Soft Drink (345 ml)', 120], ['Soft Drink (500 ml)', 140], ['Soft Drink Sting (500 ml)', 150],
            ['Soft Drink (1.5 Ltr)', 220], ['Soft Drink (Jumbo)', 320],
            ['Regular Drink', 120, true],   // ⚠ combo filler for "1 Drink Regular" in deals
        ]],
        ['Raita & Salad', 'counter', null, [
            ['Raita', 100], ['Fresh Salad', 200],
        ]],
        ['Extras', 'counter', null, [
            ['Singaporean Sauce', 100], ['Butter', 200], ['Garlic Fried', 100], ['Cheese', 100],
            ['Bun', 80], ['Dinner Roll', 80], ['Coleslaw', 80], ['Mayo Sauce', 80],
            ['Extra Sauce', 100], ['Sizzling Charge', 200], ['Extra Skewer', 625],
            // ⚠ combo fillers (no standalone card price):
            ['Arabic Rice', 300, true], ['3 Different Chatnies', 0, true],
        ]],
        ['Al-Faham Components', 'bbq', null, [
            ['Grilled Chicken Al-Faham', 1600, true],   // ⚠ combo filler (whole platter priced as combo)
            ['BBQ Vegetable', 0, true], ['BBQ Tomato', 0, true], ['Green Chilli', 0, true],
        ]],
    ];

    /**
     * Combos. Each: [code, name, price, [[componentProductName, qty], ...]]. The combo carries the
     * MENU price; component lines are priced 0 (bundled). Each component references a product whose
     * CATEGORY drives its KOT station — that is how one deal fans out to Counter/Grill/Fastfood.
     */
    private const COMBOS = [
        // Platters (New Arrivals)
        ['KF-PLAT-ALFAHAM-H', 'Grill Chicken Al-Faham (Half)', 1600, [['Grilled Chicken Al-Faham', 1], ['Arabic Rice', 1], ['3 Different Chatnies', 1], ['Fresh Salad', 1]]],
        ['KF-PLAT-ALFAHAM-F', 'Grill Chicken Al-Faham (Full)', 3100, [['Grilled Chicken Al-Faham', 2], ['Arabic Rice', 1], ['3 Different Chatnies', 1], ['Fresh Salad', 1]]],
        ['KF-PLAT-CLASSIC-1', 'Classic Platter 1 (6 Persons)', 5500, [['Arabic Rice', 1], ['Tandoori Chicken', 1], ['Chicken Malai Boti', 1], ['Chicken Boti Boneless', 1], ['Chicken Shahi Chattakh', 1], ['Chicken Reshmi Kebab', 1], ['Chicken Baluchi Boti', 1], ['Beef Seekh Kebab', 1], ['Chicken Namkeen Boti', 1], ['BBQ Vegetable', 1], ['Dhaka Chicken', 1]]],
        ['KF-PLAT-CLASSIC-2', 'Classic Platter 2 (4 Persons)', 4400, [['Arabic Rice', 1], ['Tandoori Chicken', 1], ['Chicken Malai Boti', 1], ['Chicken Boti Boneless', 1], ['Chicken Reshmi Kebab', 1], ['Chicken Baluchi Boti', 1], ['Chicken Namkeen Boti', 1], ['BBQ Vegetable', 1], ['Dhaka Chicken', 1]]],
        ['KF-PLAT-CLASSIC-3', 'Classic Platter 3 (3 Persons)', 3300, [['Arabic Rice', 1], ['Chicken Malai Boti', 1], ['Beef Seekh Kebab', 1], ['Chicken Baluchi Boti', 1], ['Chicken Reshmi Kebab', 1], ['Chicken Namkeen Boti', 1], ['BBQ Vegetable', 1], ['Dhaka Chicken', 1]]],
        // Rice & Kebab Platters
        ['KF-RKP-SINGKHASS', 'Singaporean Rice Khass (2-3 Persons)', 2900, [['Singaporean Rice (Regular)', 1], ['Chicken Baluchi Boti', 1], ['Chicken Shahi Chattakh', 1], ['Chicken Boti Boneless', 1]]],
        ['KF-RKP-CHULLU-BEEF', 'Chullu Kebab Beef (1-2 Persons)', 2000, [['Arabic Rice', 1], ['Beef Seekh Kebab', 2], ['BBQ Tomato', 1], ['Green Chilli', 1], ['Fries (Regular)', 1], ['Butter', 1]]],
        ['KF-RKP-CHULLU-CHK', 'Chullu Kebab Chicken (1-2 Persons)', 1900, [['Arabic Rice', 1], ['Chicken Reshmi Kebab', 2], ['BBQ Tomato', 1], ['Green Chilli', 1], ['Fries (Regular)', 1], ['Butter', 1]]],
        ['KF-RKP-BALOCHI', 'Balochi Boti Rice (1-2 Persons)', 1900, [['Arabic Rice', 1], ['Chicken Baluchi Boti', 2], ['BBQ Tomato', 1], ['Green Chilli', 1]]],
        ['KF-RKP-TURKIYA', 'Turkiya Kebab (1-2 Persons)', 1800, [['Arabic Rice', 1], ['Adana Kebab Chicken', 1], ['Adana Kebab Beef', 1], ['BBQ Tomato', 1], ['Green Chilli', 1], ['Fries (Regular)', 1]]],
        ['KF-RKP-BBQ-PLAT', 'Bar-B-Que Platter (4-5 Persons)', 5500, [['Tandoori Chicken', 2], ['Chicken Malai Boti', 1], ['Chicken Baluchi Boti', 1], ['Chicken Boti Boneless', 1], ['Chicken Shahi Chattakh', 1], ['Beef Behari Boti', 1], ['Beef Seekh Kebab', 1], ['Chicken Reshmi Kebab', 1], ['Beef Afghani Boti', 6], ['BBQ Vegetable', 1], ['3 Different Chatnies', 1]]],
        // Deals 1-20 (component product picks a representative item in the correct routing category)
        ['KF-DEAL-01', 'Deal 1 (Serve 1)', 875, [['Chicken Biryani (Small)', 1], ['Chicken Chatni Roll', 1], ['Regular Drink', 1]]],
        ['KF-DEAL-02', 'Deal 2 (Serve 2)', 1190, [['Singaporean Rice (Regular)', 1], ['Chicken Chatni Roll', 1], ['Regular Drink', 2]]],
        ['KF-DEAL-03', 'Deal 3 (Serve 2)', 1355, [['Chicken Biryani (Small)', 1], ['Chicken Chatni Roll', 2], ['Regular Drink', 2]]],
        ['KF-DEAL-04', 'Deal 4 (Serve 2)', 1420, [['Chicken Biryani (Small)', 1], ['Chicken Zinger Burger (With Fries)', 1], ['Regular Drink', 2]]],
        ['KF-DEAL-05', 'Deal 5 (Serve 2)', 1620, [['Singaporean Rice (Regular)', 1], ['Chicken Zinger Burger (With Fries)', 1], ['Regular Drink', 2]]],
        ['KF-DEAL-06', 'Deal 6 (Serve 2)', 1555, [['Singaporean Rice (Regular)', 1], ['Chicken Chatni Roll', 2], ['Regular Drink', 2]]],
        ['KF-DEAL-07', 'Deal 7 (Serve 2)', 1985, [['Singaporean Rice (Regular)', 1], ['Chicken Zinger Burger (With Fries)', 1], ['Chicken Chatni Roll', 1], ['Regular Drink', 2]]],
        ['KF-DEAL-08', 'Deal 8 (Serve 2)', 2005, [['Singaporean Rice (Large)', 1], ['Chicken Chatni Roll', 2], ['Regular Drink', 2]]],
        ['KF-DEAL-09', 'Deal 9 (Serve 2)', 1755, [['Chicken Zinger Burger (With Fries)', 1], ['Chicken Chatni Roll', 2], ['Regular Drink', 2]]],
        ['KF-DEAL-10', 'Deal 10 (Serve 2)', 1905, [['Chicken Bar-B-Que Sandwich (With Fries)', 1], ['Chicken Chatni Roll', 2], ['Regular Drink', 2]]],
        ['KF-DEAL-11', 'Deal 11 (Serve 2)', 1490, [['Club Sandwich (With Fries)', 1], ['Chicken Chatni Roll', 1], ['Regular Drink', 2]]],
        ['KF-DEAL-12', 'Deal 12 (Serve 2)', 1620, [['Singaporean Rice (Regular)', 1], ['2 Pcs Crispy Fried Chicken (With Fries)', 1], ['Regular Drink', 2]]],
        ['KF-DEAL-13', 'Deal 13 (Serve 2)', 2045, [['Singaporean Rice (Regular)', 1], ['Chicken Malai Boti', 1], ['Pori Paratha', 1], ['Regular Drink', 2]]],
        ['KF-DEAL-14', 'Deal 14 (Serve 2)', 1390, [['Chicken Zinger Burger (With Fries)', 1], ['Chicken Chatni Roll', 1], ['Regular Drink', 2]]],
        ['KF-DEAL-15', 'Deal 15 (Serve 2)', 2120, [['Chicken Tikka (Leg)', 1], ['Chicken Malai Boti', 1], ['Pori Paratha', 2], ['Regular Drink', 2]]],
        ['KF-DEAL-16', 'Deal 16 (Serve 4)', 4270, [['Chicken Biryani (Large)', 2], ['Chicken Zinger Burger (With Fries)', 2], ['Chicken Chatni Roll', 2], ['Regular Drink', 4]]],
        ['KF-DEAL-17', 'Deal 17 (Serve 4)', 4355, [['Singaporean Rice (Platter)', 1], ['Chicken Zinger Burger (With Fries)', 2], ['Chicken Chatni Roll', 2], ['Regular Drink', 4]]],
        ['KF-DEAL-18', 'Deal 18 (Serve 4)', 3510, [['Chicken Zinger Burger (With Fries)', 2], ['Chicken Chatni Roll', 4], ['Regular Drink', 4]]],
        ['KF-DEAL-19', 'Deal 19 (Serve 4)', 5540, [['Chicken Malai Boti', 1], ['Chicken Reshmi Kebab', 1], ['Beef Seekh Kebab', 1], ['Beef Behari Boti', 1], ['Pori Paratha', 4], ['Regular Drink', 4]]],
        ['KF-DEAL-20', 'Deal 20 (Serve 6)', 5625, [['Singaporean Rice Family Pack (Small)', 1], ['Chicken Chatni Roll', 6], ['Regular Drink', 6]]],
        // Midnight Deal (combo-priced items; singles are already standalone products)
        ['KF-MID-TIKKAPIZZA', 'Midnight — Tikka Pizza Fries + 2 Drinks', 950, [['Tikka Pizza Fries (Small)', 1], ['Regular Drink', 2]]],
        ['KF-MID-MIAMIPIZZA', 'Midnight — Miami Supreme Pizza Fries + 2 Drinks', 950, [['Miami Supreme (Small)', 1], ['Regular Drink', 2]]],
        ['KF-MID-SINGKHAAS', 'Midnight — Singaporean Rice Khaas + 2 Drinks', 2900, [['Singaporean Rice (Khass)', 1], ['Regular Drink', 2]]],
        ['KF-MID-MALAIBOTI', 'Midnight — Chicken Malai Boti + Drink', 1150, [['Chicken Malai Boti', 1], ['Regular Drink', 1]]],
        ['KF-MID-SHAHI', 'Midnight — Chicken Shahi Chattakh + Drink', 1250, [['Chicken Shahi Chattakh', 1], ['Regular Drink', 1]]],
        ['KF-MID-BALOCHI', 'Midnight — Chicken Balochi Boti + Drink', 1250, [['Chicken Baluchi Boti', 1], ['Regular Drink', 1]]],
        ['KF-MID-RESHAM', 'Midnight — Chicken Resham Kebab + Drink', 1150, [['Chicken Reshmi Kebab', 1], ['Regular Drink', 1]]],
        ['KF-MID-BEEFSEEKH', 'Midnight — Beef Seekh Kebab + Drink', 1350, [['Beef Seekh Kebab', 1], ['Regular Drink', 1]]],
        ['KF-MID-MANCHURIAN', 'Midnight — Manchurian With Fried Rice + Drink', 1350, [['Manchurian With Fried Rice (1 Person)', 1], ['Regular Drink', 1]]],
        ['KF-MID-CHOWMEIN', 'Midnight — Chicken Chow Mein + Drink', 1200, [['Chicken Chow Mein (1 Person)', 1], ['Regular Drink', 1]]],
    ];

    /** Terminals: T1 Delivery (solo, delivery), T2/T3 DTQ (see T2/T3/T4), T4 DTQ floor (dine-in punch only). */
    private const TERMINALS = [
        ['T1', 'Delivery'], ['T2', 'DTQ 1'], ['T3', 'DTQ 2'], ['T4', 'DTQ Floor'],
    ];

    public function handle(TenantProvisioner $provisioner, TenancyManager $tenancy): int
    {
        if (! $this->option('yes')) {
            $this->error('Refusing without --yes (creates a NEW tenant + master plan + tenant data).');

            return self::FAILURE;
        }

        // ── 1. master: custom plan ──
        $plan = Plan::updateOrCreate(['code' => self::PLAN_CODE], [
            'name' => 'Kashif Restaurant (Custom)', 'price' => 0, 'currency_code' => 'PKR',
            'billing_period' => 'monthly', 'is_public' => false, 'is_custom' => true,
        ]);
        $moduleIds = Module::whereIn('key', self::PLAN_MODULES)->pluck('id', 'key');
        foreach ($moduleIds as $id) {
            PlanModule::updateOrCreate(['plan_id' => $plan->id, 'module_id' => $id], ['is_enabled' => true]);
        }
        foreach (Module::whereNotIn('key', self::PLAN_MODULES)->get() as $off) {
            PlanModule::updateOrCreate(['plan_id' => $plan->id, 'module_id' => $off->id], ['is_enabled' => false]);
        }
        foreach (self::PLAN_FEATURES as $key => $value) {
            PlanFeature::updateOrCreate(['plan_id' => $plan->id, 'feature_key' => $key], ['feature_value' => $value]);
        }
        $this->info('plan kashif_restaurant ready (branch 1 / terminal 4 / user 10).');

        // ── 2. master: tenant + domain + subscription ──
        $tenant = Tenant::updateOrCreate(['tenant_code' => self::TENANT_CODE], [
            'business_name' => 'Kashif Food', 'owner_name' => 'Kashif Food',
            'owner_email' => $this->option('owner-email') ?: self::OWNER_EMAIL,
            'currency_code' => 'PKR', 'status' => 'pending',
        ]);
        TenantDomain::updateOrCreate(
            ['domain' => self::TENANT_CODE . '.' . config('tenancy.tenant_base_domain')],
            ['tenant_id' => $tenant->id, 'is_primary' => true, 'status' => 'active']
        );
        Subscription::updateOrCreate(['tenant_id' => $tenant->id], [
            'plan_id' => $plan->id, 'status' => 'active', 'current_period_ends_at' => now()->addYear(),
        ]);

        // ── 3. provision (DB + migrations + base seed + Owner) ──
        $alreadyProvisioned = DB::connection('master')->table('tenant_databases')
            ->where('tenant_id', $tenant->id)->where('migration_status', 'completed')->exists();
        $password = $this->option('owner-password');
        if (! $alreadyProvisioned && ! $password) {
            $this->error('First provision requires --owner-password.');

            return self::FAILURE;
        }
        // Preserve an existing Owner password on re-run (provision updateOrCreate's the Owner with a hash).
        $ownerEmail = $tenant->owner_email ?: self::OWNER_EMAIL;
        $existingHash = null;
        if ($alreadyProvisioned && ! $password) {
            $tenancy->activate($tenant->fresh());
            $existingHash = DB::connection('tenant')->table('users')->where('email', $ownerEmail)->value('password');
        }
        $provisioner->provisionTenant($tenant->fresh(), $password ?: Str::random(24));
        if ($existingHash !== null) {
            DB::connection('tenant')->table('users')->where('email', $ownerEmail)->update(['password' => $existingHash]);
        }
        $this->info('tenant provisioned: ' . self::TENANT_CODE . '.' . config('tenancy.tenant_base_domain'));

        // ── 4. tenant data ──
        $tenancy->activate($tenant->fresh());
        $branchId = (int) DB::connection('tenant')->table('branches')->orderBy('id')->value('id');
        DB::connection('tenant')->table('branches')->where('id', $branchId)->update(['name' => 'Kashif Food', 'updated_at' => now()]);

        // Terminals T1..T4 all active.
        foreach (self::TERMINALS as [$code, $name]) {
            DB::connection('tenant')->table('terminals')->updateOrInsert(
                ['code' => $code],
                ['branch_id' => $branchId, 'name' => $name, 'requires_shift' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]
            );
        }
        DB::connection('tenant')->table('terminals')->whereNotIn('code', ['T1', 'T2', 'T3', 'T4'])->update(['status' => 'inactive', 'updated_at' => now()]);
        $terminalId = fn (string $code) => (int) DB::connection('tenant')->table('terminals')->where('code', $code)->value('id');
        $this->info('terminals: T1 Delivery / T2 DTQ 1 / T3 DTQ 2 / T4 DTQ Floor (all active).');

        // Menu: categories (+parents) and SERVICE products.
        $unitId = DB::connection('tenant')->table('units')->where('code', 'EA')->value('id')
            ?: DB::connection('tenant')->table('units')->insertGetId([
                'code' => 'EA', 'name' => 'Each', 'unit_type' => 'quantity', 'base_factor' => 1,
                'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);

        $catId = [];       // category name → id
        $catStation = [];  // category id → station
        $sort = 0;
        $ensureCategory = function (string $name, ?int $parentId, int $sortOrder) use (&$catId): int {
            $slug = Str::slug($name);
            $existing = DB::connection('tenant')->table('categories')->where('slug', $slug)->value('id');
            if ($existing) {
                DB::connection('tenant')->table('categories')->where('id', $existing)
                    ->update(['name' => $name, 'parent_id' => $parentId, 'sort_order' => $sortOrder, 'is_active' => 1, 'updated_at' => now()]);

                return (int) $existing;
            }

            return (int) DB::connection('tenant')->table('categories')->insertGetId([
                'name' => $name, 'slug' => $slug, 'code' => strtoupper(Str::slug($slug, '_')),
                'parent_id' => $parentId, 'sort_order' => $sortOrder, 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        };
        $productCount = 0;
        $seedProduct = function (int $categoryId, string $name, float $price, bool $hidden) use (&$productCount, $unitId): void {
            DB::connection('tenant')->table('products')->updateOrInsert(
                ['slug' => Str::slug($name)],
                [
                    'category_id' => $categoryId, 'unit_id' => $unitId, 'sku' => strtoupper(Str::slug($name, '-')),
                    'name' => $name, 'product_type' => 'service', 'product_kind' => 'service', 'item_kind' => 'finished_good',
                    'is_stock_tracked' => 0, 'inventory_consumption_method' => 'none',
                    'is_sellable' => 1, 'is_purchasable' => 0, 'is_pos_visible' => $hidden ? 0 : 1,
                    'can_be_bom_component' => 0, 'can_be_bom_output' => 0, 'is_manufactured_finished_good' => 0,
                    'default_selling_price' => $price, 'status' => 'active',
                    'updated_at' => now(), 'created_at' => now(),
                ]
            );
            $productCount++;
        };

        foreach (self::CATALOGUE as [$category, $station, $parent, $items]) {
            $parentId = null;
            if ($parent) {
                if (! isset($catId[$parent])) {
                    $catId[$parent] = $ensureCategory($parent, null, ++$sort);
                }
                $parentId = $catId[$parent];
            }
            $cid = $ensureCategory($category, $parentId, ++$sort);
            $catId[$category] = $cid;
            $catStation[$cid] = $station;
            $pSort = 0;
            foreach ($items as $item) {
                $seedProduct($cid, $item[0], (float) $item[1], (bool) ($item[2] ?? false));
                $pSort++;
            }
        }
        // parent categories inherit their children's station (all children of one parent share a station here).
        foreach ($catId as $name => $cid) {
            if (! isset($catStation[$cid])) {
                $childStation = DB::connection('tenant')->table('categories')->where('parent_id', $cid)->value('id');
                $catStation[$cid] = $childStation ? ($catStation[(int) $childStation] ?? 'ff') : 'ff';
            }
        }
        $this->info("menu: " . count($catId) . " categories, {$productCount} service products.");

        // Combos.
        $productIdBySlug = fn (string $name) => DB::connection('tenant')->table('products')->where('slug', Str::slug($name))->value('id');
        $comboCount = 0;
        $missing = [];
        foreach (self::COMBOS as [$code, $name, $price, $components]) {
            $comboId = DB::connection('tenant')->table('combos')->where('code', $code)->value('id');
            if ($comboId) {
                DB::connection('tenant')->table('combos')->where('id', $comboId)->update([
                    'name' => $name, 'price' => $price, 'status' => 'active', 'branch_id' => $branchId, 'updated_at' => now(),
                ]);
            } else {
                $comboId = (int) DB::connection('tenant')->table('combos')->insertGetId([
                    'branch_id' => $branchId, 'code' => $code, 'name' => $name, 'price' => $price,
                    'status' => 'active', 'sort_order' => ++$comboCount, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::connection('tenant')->table('combo_components')->where('combo_id', $comboId)->delete();
            $cSort = 0;
            foreach ($components as [$compName, $qty]) {
                $pid = $productIdBySlug($compName);
                if (! $pid) { $missing[$compName] = true; continue; }
                DB::connection('tenant')->table('combo_components')->insert([
                    'combo_id' => $comboId, 'product_id' => $pid, 'product_variant_id' => null,
                    'quantity' => $qty, 'sort_order' => ++$cSort, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        if ($missing) {
            $this->warn('combo components with NO matching product (skipped): ' . implode(', ', array_keys($missing)));
        }
        $this->info('combos: ' . count(self::COMBOS) . ' deals/platters seeded.');

        // ── Printers: 4 counter (receipt+counter-KOT) + BBQ + Fastfood ──
        $printers = [
            'KF-P-T1'  => ['T1 Counter Printer', '192.168.1.100', 'both'],
            'KF-P-T2'  => ['T2 Counter Printer', '192.168.1.101', 'both'],
            'KF-P-T3'  => ['T3 Counter Printer', '192.168.1.102', 'both'],
            'KF-P-T4'  => ['T4 Counter Printer', '192.168.1.103', 'both'],
            'KF-P-BBQ' => ['BBQ / Grill KOT', '192.168.1.87', 'kot'],
            'KF-P-FF'  => ['Fastfood KOT', '192.168.1.54', 'kot'],
        ];
        $printerId = [];
        foreach ($printers as $code => [$name, $ip, $role]) {
            $existing = DB::connection('tenant')->table('printers')->where('code', $code)->first();
            $attrs = ['branch_id' => $branchId, 'name' => $name, 'printer_type' => 'network', 'print_role' => $role,
                'supports_reminder' => 1, 'port' => $existing->port ?? 9100, 'paper_size' => '80mm',
                'characters_per_line' => 42, 'is_default' => $code === 'KF-P-T1' ? 1 : 0, 'is_active' => 1, 'updated_at' => now()];
            if (! $existing) { $attrs['ip_address'] = $ip; $attrs['created_at'] = now(); }   // never overwrite an on-site IP
            DB::connection('tenant')->table('printers')->updateOrInsert(['code' => $code], $attrs);
            $printerId[$code] = (int) DB::connection('tenant')->table('printers')->where('code', $code)->value('id');
        }
        // retire any stray printers + their mappings
        $keep = array_values($printerId);
        $retired = DB::connection('tenant')->table('printers')->whereNotIn('id', $keep)->pluck('id');
        if ($retired->isNotEmpty()) {
            DB::connection('tenant')->table('category_printer_mappings')->whereIn('printer_id', $retired)->delete();
            DB::connection('tenant')->table('printers')->whereIn('id', $retired)->update(['is_active' => 0, 'is_default' => 0, 'updated_at' => now()]);
        }

        // terminal_printer_settings: each terminal → its own counter printer for receipts + fallback KOT.
        $terminalPrinter = ['T1' => 'KF-P-T1', 'T2' => 'KF-P-T2', 'T3' => 'KF-P-T3', 'T4' => 'KF-P-T4'];
        foreach ($terminalPrinter as $tcode => $pcode) {
            DB::connection('tenant')->table('terminal_printer_settings')->updateOrInsert(
                ['terminal_id' => $terminalId($tcode)],
                ['receipt_printer_id' => $printerId[$pcode], 'kot_printer_id' => $printerId[$pcode],
                 'auto_print_receipt' => 1, 'auto_print_kot' => 1, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        // Category → printer mappings (KOT + reminder). Fresh rebuild so a re-run reflects the routing exactly.
        DB::connection('tenant')->table('category_printer_mappings')->delete();
        $mapRow = function (?int $terminalIdVal, int $categoryId, int $printerIdVal, string $role) use ($branchId): void {
            DB::connection('tenant')->table('category_printer_mappings')->updateOrInsert(
                ['branch_id' => $branchId, 'terminal_id' => $terminalIdVal, 'category_id' => $categoryId, 'printer_id' => $printerIdVal, 'print_role' => $role, 'order_type' => 'all'],
                ['reminder_confirm_on_addition' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
            );
        };
        $termIds = ['T1' => $terminalId('T1'), 'T2' => $terminalId('T2'), 'T3' => $terminalId('T3'), 'T4' => $terminalId('T4')];
        $mapped = 0;
        foreach ($catStation as $cid => $station) {
            if ($station === 'counter') {
                // KOT for a counter item prints on the ORDER's own terminal printer (terminal-pinned rule wins).
                foreach ($terminalPrinter as $tcode => $pcode) {
                    $mapRow($termIds[$tcode], $cid, $printerId[$pcode], 'kot');
                    $mapped++;
                }
            } elseif ($station === 'bbq') {
                $mapRow(null, $cid, $printerId['KF-P-BBQ'], 'kot');
                foreach ($terminalPrinter as $tcode => $pcode) { $mapRow($termIds[$tcode], $cid, $printerId[$pcode], 'reminder'); }
                $mapped++;
            } else { // ff
                $mapRow(null, $cid, $printerId['KF-P-FF'], 'kot');
                foreach ($terminalPrinter as $tcode => $pcode) { $mapRow($termIds[$tcode], $cid, $printerId[$pcode], 'reminder'); }
                $mapped++;
            }
        }
        $this->info("printers: 4 counter + BBQ(.87) + Fastfood(.54); {$mapped} category KOT routes + per-terminal reminders.");

        // Owner reaches every terminal.
        $ownerUser = \App\Models\Tenant\User::where('email', self::OWNER_EMAIL)->first();
        if ($ownerUser) {
            $ownerUser->terminals()->sync(DB::connection('tenant')->table('terminals')->pluck('id')->all());
        }

        // ── Roles + users ──
        $this->seedRolesAndUsers($branchId, $termIds);

        $this->info('DONE. Kashif Food ready at ' . self::TENANT_CODE . '.' . config('tenancy.tenant_base_domain') . ' — verify ⚠ combo-filler prices before go-live.');

        return self::SUCCESS;
    }

    /**
     * Delivery + Dine-In roles carry the SAME operator permission set (mirrors the Khatri delivery/dine-in
     * roles); the Restricted role is the Dine-In set MINUS Review&Pay (`tenant.pos.store`) and Returns
     * (`tenant.sales-returns`). Users: T1 delivery (solo), T2/T3 DTQ (see T2/T3/T4), T4 floor (dine-in punch).
     */
    private function seedRolesAndUsers(int $branchId, array $termIds): void
    {
        $allow = [
            'tenant.dashboard', 'tenant.pos', 'tenant.api.pos', 'tenant.api.catalog',
            'tenant.held-sales', 'tenant.sales-orders', 'tenant.sales-returns', 'tenant.sales-ledger',
            'tenant.customers', 'tenant.delivery-channels', 'tenant.delivery-riders', 'tenant.payment-methods',
            'tenant.restaurant', 'tenant.shifts',
            'tenant.products', 'tenant.product-variants', 'tenant.product-barcodes', 'tenant.product-branch-prices',
            'tenant.categories', 'tenant.units', 'tenant.unit-conversions', 'tenant.modifier-groups', 'tenant.combos',
            'tenant.ajax.products', 'tenant.ajax.customers', 'tenant.ajax.sales',
            'tenant.printing.documents', 'tenant.printing.jobs',
            'tenant.reports.center.index', 'tenant.reports.center.print', 'tenant.reports.center.export',
        ];
        $deny = [
            'tenant.branches', 'tenant.terminals', 'tenant.users', 'tenant.roles', 'tenant.permissions',
            'tenant.billing', 'tenant.settings', 'tenant.system-reset', 'tenant.currencies',
            'tenant.finance', 'tenant.inventory', 'tenant.stock', 'tenant.purchas', 'tenant.suppliers',
            'tenant.goods-receipts', 'tenant.departments', 'tenant.manufacturing', 'tenant.offline-edge',
            'tenant.quotations', 'tenant.kitchen', 'tenant.promotions', 'tenant.daily-closing',
            'tenant.reports.center.schedules', 'tenant.reports.center.email',
        ];
        $sections = ['categories', 'items', 'waiters', 'order-types', 'order-type-combos', 'cancellations'];

        $names = \Spatie\Permission\Models\Permission::where('guard_name', 'tenant')->pluck('name')
            ->filter(function (string $name) use ($allow, $deny) {
                if (str_ends_with($name, '.destroy') || str_contains($name, '.delete')) {
                    return false;
                }
                foreach ($deny as $d) {
                    if (str_starts_with($name, $d)) {
                        return false;
                    }
                }
                foreach ($allow as $a) {
                    if (str_starts_with($name, $a)) {
                        return true;
                    }
                }

                return false;
            })->values()->all();
        foreach ($sections as $section) {
            $key = 'tenant.reports.center.sections.' . $section;
            \Spatie\Permission\Models\Permission::findOrCreate($key, 'tenant');
            $names[] = $key;
        }
        $perms = array_values(array_unique($names));

        // Each user type gets its OWN named role (never shared) cloned from the same base set.
        $deliveryRole = \Spatie\Permission\Models\Role::findOrCreate('Delivery', 'tenant');
        $deliveryRole->syncPermissions($perms);
        $dineInRole = \Spatie\Permission\Models\Role::findOrCreate('Dine In', 'tenant');
        $dineInRole->syncPermissions($perms);
        // Restricted = Dine-In minus Review&Pay (tenant.pos.store) and Returns (tenant.sales-returns).
        $restrictedPerms = array_values(array_filter($perms, function (string $n) {
            return $n !== 'tenant.pos.store' && ! str_starts_with($n, 'tenant.sales-returns');
        }));
        $restrictedRole = \Spatie\Permission\Models\Role::findOrCreate('Dine In (Restricted)', 'tenant');
        $restrictedRole->syncPermissions($restrictedPerms);

        // Users. [email, name, role, allowed order types, default order type, default terminal code, bound terminal codes]
        $users = [
            ['delivery_kf@bingoopos.com', 'Delivery Desk', $deliveryRole, ['delivery'], 'delivery', 'T1', ['T1']],
            ['counter2_kf@bingoopos.com', 'Counter T2', $dineInRole, ['dine_in', 'takeaway', 'quick_sale'], 'dine_in', 'T2', ['T2', 'T3', 'T4']],
            ['counter3_kf@bingoopos.com', 'Counter T3', $dineInRole, ['dine_in', 'takeaway', 'quick_sale'], 'dine_in', 'T3', ['T2', 'T3', 'T4']],
            ['floor4_kf@bingoopos.com', 'Floor T4', $restrictedRole, ['dine_in'], 'dine_in', 'T4', ['T4']],
        ];
        foreach ($users as [$email, $name, $role, $orderTypes, $defaultType, $defaultTermCode, $boundCodes]) {
            $this->seedUser($branchId, $email, $name, $role, $orderTypes, $defaultType, $termIds[$defaultTermCode], array_map(fn ($c) => $termIds[$c], $boundCodes), count($role->permissions));
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seedUser(int $branchId, string $email, string $name, $role, array $orderTypes, string $defaultType, int $defaultTerminalId, array $boundTerminalIds, int $permCount): void
    {
        $existing = \App\Models\Tenant\User::where('email', $email)->first();
        // Owner-confirmed: all counter/delivery users use the password `password`.
        $password = $existing ? null : ($this->option('counter-password') ?: 'password');

        $user = \App\Models\Tenant\User::updateOrCreate(
            ['email' => $email],
            array_merge([
                'name' => $name, 'status' => 'active', 'locale' => 'en',
                'default_branch_id' => $branchId, 'default_terminal_id' => $defaultTerminalId,
                'allowed_order_types' => $orderTypes, 'default_order_type' => $defaultType,
            ], $password ? ['password' => Hash::make($password)] : [])
        );
        $user->syncRoles([$role]);
        $user->branches()->syncWithoutDetaching([$branchId]);
        $user->terminals()->sync($boundTerminalIds);

        $pin = $this->option('manager-pin') ?: 'password@';
        DB::connection('tenant')->table('manager_pins')->updateOrInsert(
            ['user_id' => $user->id],
            ['pin_hash' => Hash::make($pin), 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
        );

        $this->info("user {$email}: role {$role->name} ({$permCount} perms), terminals [" . implode(',', $boundTerminalIds) . "], types [" . implode(',', $orderTypes) . '].');
        if ($password) {
            $this->warn(ucfirst($name) . " password (shown once): {$password}");
        }
    }
}
