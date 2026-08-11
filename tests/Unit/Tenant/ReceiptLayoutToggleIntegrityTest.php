<?php

namespace Tests\Unit\Tenant;

use App\Models\Tenant\ReceiptLayoutSetting;
use Tests\TestCase;

/**
 * Every layout switch must flow through ONE list — ReceiptLayoutSetting::TOGGLE_FIELDS.
 *
 * The edit modal's JS blob, its live-preview URL, the save handler and the preview controller
 * each carried a hardcoded copy of the toggle list. Four newer columns (delivery details,
 * vehicle number, order type, branding) were missing from the modal's copy, so those switches
 * always displayed OFF regardless of the saved value, the live preview hid them, and saving ANY
 * other change posted the falsely-unchecked boxes back — silently wiping the stored values.
 */
class ReceiptLayoutToggleIntegrityTest extends TestCase
{
    public function test_the_constant_covers_every_toggle_column_on_the_model(): void
    {
        $fillableToggles = array_values(array_filter(
            (new ReceiptLayoutSetting())->getFillable(),
            fn ($f) => str_starts_with($f, 'show_')
        ));

        $this->assertEqualsCanonicalizing(
            $fillableToggles,
            ReceiptLayoutSetting::TOGGLE_FIELDS,
            'A show_* column exists that TOGGLE_FIELDS does not carry (or vice versa) — the modal will silently wipe it on save.'
        );
    }

    public function test_the_modal_blob_and_controller_derive_from_the_constant(): void
    {
        $blade = file_get_contents(base_path('resources/views/tenant/printing/layouts/index.blade.php'));
        $controller = file_get_contents(base_path('app/Http/Controllers/Tenant/ReceiptLayoutController.php'));

        $this->assertStringContainsString('ReceiptLayoutSetting::TOGGLE_FIELDS', $blade,
            'The modal JS blob must be built from the shared constant, never a hardcoded list.');
        $this->assertSame(2, substr_count($controller, 'ReceiptLayoutSetting::TOGGLE_FIELDS'),
            'Both store() and preview() must derive their boolean lists from the shared constant.');
    }

    public function test_the_form_renders_every_toggle(): void
    {
        $form = file_get_contents(base_path('resources/views/tenant/printing/layouts/_form.blade.php'));

        foreach (ReceiptLayoutSetting::TOGGLE_FIELDS as $field) {
            $this->assertStringContainsString("'{$field}'", $form,
                "The form is missing the {$field} switch — its stored value could never be edited.");
        }
    }

    /**
     * The per-document supported lists shown in the modal must only name real toggles, and each
     * document's list must be a subset of what its templates actually consult.
     */
    public function test_supported_lists_only_name_real_toggles_their_templates_consume(): void
    {
        $blade = file_get_contents(base_path('resources/views/tenant/printing/layouts/index.blade.php'));
        preg_match('/const supported = \{(.+?)\n    \};/s', $blade, $m);
        $this->assertNotEmpty($m, 'syncLayoutOptions must declare its per-document supported lists.');
        preg_match_all("/'(show_[a-z_]+)'/", $m[1], $fields);

        foreach (array_unique($fields[1]) as $field) {
            $this->assertContains($field, ReceiptLayoutSetting::TOGGLE_FIELDS,
                "syncLayoutOptions names {$field}, which is not a real toggle column.");
        }

        // Each list ⊆ what the document's templates consult — a switch the paper ignores is a lie.
        $sources = [
            'kot' => file_get_contents(base_path('resources/views/tenant/printing/documents/kot.blade.php'))
                . file_get_contents(base_path('app/Services/Printing/EscPosPayloadService.php')),
            'reminder' => file_get_contents(base_path('resources/views/tenant/printing/documents/reminder.blade.php'))
                . file_get_contents(base_path('app/Services/Printing/PrintJobService.php')),
            'receipt' => file_get_contents(base_path('resources/views/tenant/printing/documents/receipt.blade.php')),
        ];
        preg_match('/receipt: \[(.+?)\]/s', $m[1], $r);
        preg_match('/kot:\s+\[(.+?)\]/s', $m[1], $k);
        preg_match('/reminder: \[(.+?)\]/s', $m[1], $re);
        foreach (['receipt' => $r[1], 'kot' => $k[1], 'reminder' => $re[1]] as $doc => $list) {
            preg_match_all("/'(show_[a-z_]+)'/", $list, $docFields);
            foreach ($docFields[1] as $field) {
                $this->assertStringContainsString($field, $sources[$doc],
                    "The {$doc} form offers {$field}, but no {$doc} template consults it — the switch would do nothing.");
            }
        }
    }
}
