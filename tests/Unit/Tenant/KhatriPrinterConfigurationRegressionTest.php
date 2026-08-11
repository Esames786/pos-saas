<?php

namespace Tests\Unit\Tenant;

use Tests\TestCase;

class KhatriPrinterConfigurationRegressionTest extends TestCase
{
    public function test_onboarding_records_the_live_printer_models_and_safe_width(): void
    {
        $command = file_get_contents(app_path('Console/Commands/OnboardKhatriBiryaniCommand.php'));

        $this->assertStringContainsString('BlackCopper BC97AC - Delivery Receipt + KOT', $command);
        $this->assertStringContainsString('XPrinter - Beverages / Desserts / Extras KOT', $command);
        $this->assertStringContainsString("'paper_size' => '80mm', 'characters_per_line' => 42", $command);
    }

    public function test_onboarding_preserves_the_shop_ip_addresses(): void
    {
        $command = file_get_contents(app_path('Console/Commands/OnboardKhatriBiryaniCommand.php'));

        $this->assertStringContainsString('if (! $existing) {', $command);
        $this->assertStringContainsString("\$attributes['ip_address'] = \$ip;", $command);
    }
}
