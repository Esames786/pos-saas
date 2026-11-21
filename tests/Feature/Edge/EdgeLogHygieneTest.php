<?php

namespace Tests\Feature\Edge;

use Tests\TestCase;

/**
 * P4 §12 — logging hygiene: the appliance log channel exists, and NO Edge log statement carries a secret.
 *
 * Static scan of every Log:: call in the Edge runtime + the appliance scripts: a context key or interpolated value
 * named like a password / device secret / recovery key / bearer token / app key / card secret fails the test. The
 * allowed shapes are hashes, ids, uuids, counts, codes and states. The PowerShell scripts must never echo a secret
 * either, and the supervision plan's command lines are proven secret-free by EdgeSupervisionMySqlTest.
 */
class EdgeLogHygieneTest extends TestCase
{
    private const SECRET_PATTERN = '/\'(device_secret|secret|password|passwd|recovery_key|wrapping_key|app_key|token|bearer|card_number|pan|cvv|credential|pfx_password)\'\s*=>/i';

    public function test_the_edge_log_channel_is_configured_for_the_appliance(): void
    {
        $channel = config('logging.channels.edge');
        $this->assertIsArray($channel, 'config/logging.php must define the edge channel');
        $this->assertSame('daily', $channel['driver']);
        $this->assertStringEndsWith('edge.log', (string) $channel['path']);
        $this->assertGreaterThanOrEqual(14, (int) $channel['days'], 'keep at least two weeks of appliance logs');
    }

    public function test_no_edge_log_statement_carries_a_secret_context_key(): void
    {
        $files = array_merge(
            glob(app_path('Services/Edge') . '/*.php') ?: [],
            glob(app_path('Console/Commands') . '/Edge*.php') ?: [],
            glob(app_path('Http/Controllers/Edge') . '/*.php') ?: [],
            glob(app_path('Http/Middleware') . '/*Edge*.php') ?: [],
            glob(app_path('Support') . '/Edge*.php') ?: [],
        );
        $this->assertNotEmpty($files);
        $offenders = [];
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            // Every Log::<level>( ... ) statement, greedy to the closing ');' of that statement.
            if (! preg_match_all('/Log::(emergency|alert|critical|error|warning|notice|info|debug|log)\s*\((.*?)\);/s', $src, $m)) {
                continue;
            }
            foreach ($m[2] as $i => $args) {
                if (preg_match(self::SECRET_PATTERN, $args)) {
                    $offenders[] = basename($file) . ': Log::' . $m[1][$i] . '(' . mb_substr(preg_replace('/\s+/', ' ', $args), 0, 120) . '…)';
                }
                // Interpolating the raw config secret into a message is a leak too.
                if (preg_match('/config\(\'edge\.(sync\.device_secret|backup\.recovery_key|update\.signing_key|enrollment\.signing_key)\'\)/', $args)) {
                    $offenders[] = basename($file) . ': Log::' . $m[1][$i] . ' interpolates a configured secret';
                }
            }
        }
        $this->assertSame([], $offenders, "Edge log statements must never carry a secret:\n" . implode("\n", $offenders));
    }

    public function test_the_appliance_scripts_never_echo_a_secret_and_never_take_one_on_argv(): void
    {
        foreach (glob(base_path('scripts/edge') . '/*.ps1') ?: [] as $script) {
            $body = (string) file_get_contents($script);
            $this->assertDoesNotMatchRegularExpression('/Write-(Host|Output)[^\n]*\$(dbPassword|recoveryKey|pairingCode|credential|pfxPassword|secret)\b/i', $body, basename($script) . ' must not print a secret');
            $this->assertDoesNotMatchRegularExpression('/-Password\s+["\']\S/', $body, basename($script) . ' must not embed a password literal');
            $this->assertDoesNotMatchRegularExpression('/--credential=\$|--device-secret|--password=\$/i', $body, basename($script) . ' must not pass a secret on a command line');
        }
        // The env template is secret-free by construction: every secret-shaped key is present and EMPTY.
        foreach (preg_split('/\r?\n/', (string) file_get_contents(base_path('scripts/edge/appliance/appliance.env.template'))) as $line) {
            if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $m) && preg_match('/SECRET|PASSWORD|_KEY$|KEY_ID$|TOKEN/', $m[1]) && ! in_array($m[1], ['EDGE_BACKUP_RECOVERY_KEY_ID'], true)) {
                $this->assertSame('', trim($m[2]), "template key {$m[1]} must be empty");
            }
        }
    }
}
