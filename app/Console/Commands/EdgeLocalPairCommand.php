<?php

namespace App\Console\Commands;

use App\Services\Edge\EdgeCloudPairingClient;
use App\Support\EdgeApplianceEnvFile;
use App\Support\EdgeApplianceLayout;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * P4 FIRST-INSTALL — bind this appliance to its tenant + branch by exchanging a one-time pairing code.
 *
 *   php artisan edge:local:pair --cloud-url=https://pos.example.com [--code-file=C:\...\pairing-code.txt] [--device-name="Gulberg-01 box"]
 *
 * The pairing code is read from --code-file or an interactive hidden prompt — never from argv. The appliance
 * generates its device secret locally and PERSISTS the identity (EDGE_SYNC_DEVICE_ID / EDGE_SYNC_DEVICE_SECRET) and
 * the Cloud endpoint family (EDGE_SYNC_*_URL, EDGE_STANDBY_*_URL, EDGE_AUTHORITY_*_URL derived from --cloud-url) into
 * the appliance env file atomically. It prints the public device id only. Re-running with an already-bound identity
 * refuses (revoke/replace the device from the Cloud first) unless --force.
 */
class EdgeLocalPairCommand extends Command
{
    protected $signature = 'edge:local:pair
        {--cloud-url= : Cloud base URL (https://…); the Edge API family is derived from it}
        {--code-file= : File containing the one-time pairing code (read then deleted); otherwise a hidden prompt}
        {--device-name= : Friendly device name shown in the Cloud}
        {--env-file= : Appliance env file to update (default: the loaded appliance.env / .env)}
        {--force : Replace an existing device identity in the env file}';

    protected $description = 'Exchange a one-time pairing code for this appliance\'s device identity and persist it (secret never on argv).';

    public function handle(EdgeCloudPairingClient $client): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:pair only runs on a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }
        $cloud = rtrim((string) ($this->option('cloud-url') ?: env('EDGE_CLOUD_BASE_URL', '')), '/');
        if ($cloud === '' || ! preg_match('#^https?://#i', $cloud)) {
            $this->error('A Cloud base URL is required (--cloud-url=https://…).');

            return self::FAILURE;
        }
        if (! str_starts_with(strtolower($cloud), 'https://') && ! filter_var(env('EDGE_CLOUD_ALLOW_HTTP', false), FILTER_VALIDATE_BOOL)) {
            $this->error('Refusing a plain-HTTP Cloud URL. Set EDGE_CLOUD_ALLOW_HTTP=true only for a lab.');

            return self::FAILURE;
        }
        $envFile = (string) ($this->option('env-file') ?: EdgeApplianceLayout::envFilePath());
        $existing = EdgeApplianceEnvFile::get($envFile, 'EDGE_SYNC_DEVICE_ID');
        if ($existing !== null && $existing !== '' && ! $this->option('force')) {
            $this->error("This appliance already holds a device identity ({$existing}). Revoke/replace it from the Cloud, then re-run with --force.");

            return self::FAILURE;
        }

        $code = $this->readCode();
        if ($code === '') {
            $this->error('No pairing code provided.');

            return self::FAILURE;
        }
        $installationUuid = EdgeApplianceEnvFile::get($envFile, 'EDGE_INSTALLATION_UUID') ?: (string) Str::uuid();
        try {
            $result = $client->pair($cloud, $code, $installationUuid, $this->option('device-name') ?: (gethostname() ?: null));
        } catch (Throwable $e) {
            Log::warning('[edge-pairing-audit] appliance_pair_failed', ['cloud' => $cloud, 'error' => mb_substr($e->getMessage(), 0, 300)]);
            $this->error('Pairing failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $api = $cloud . '/api/edge';
        EdgeApplianceEnvFile::set($envFile, [
            'EDGE_CLOUD_BASE_URL' => $cloud,
            'EDGE_INSTALLATION_UUID' => $installationUuid,
            'EDGE_SYNC_DEVICE_ID' => $result['device_id'],
            'EDGE_SYNC_DEVICE_SECRET' => $result['device_secret'],
            'EDGE_SYNC_URL' => $api . '/sync/sales',
            'EDGE_SYNC_RETURNS_URL' => $api . '/sync/returns',
            'EDGE_SYNC_SUPPLIER_FINANCE_URL' => $api . '/sync/supplier-finance',
            'EDGE_SYNC_PURCHASE_RETURNS_URL' => $api . '/sync/purchase-returns',
            'EDGE_SYNC_RECONCILE_URL' => $api . '/sync/reconcile',
            'EDGE_SYNC_BASELINE_URL' => $api . '/sync/baseline',
            'EDGE_STANDBY_CONFIG_REFRESH_URL' => $api . '/config/refresh',
            'EDGE_STANDBY_RETURNABLE_REFRESH_URL' => $api . '/returnable/refresh',
            'EDGE_STANDBY_SUPPLIER_FINANCE_REFRESH_URL' => $api . '/supplier-finance/refresh',
            'EDGE_STANDBY_PURCHASE_RETURN_REFRESH_URL' => $api . '/purchase-returns/refresh',
            'EDGE_AUTHORITY_HEARTBEAT_URL' => $api . '/authority/heartbeat',
            'EDGE_AUTHORITY_HANDBACK_URL' => $api . '/authority/handback',
        ]);
        // Prove the persisted identity authenticates before declaring success (the secret stays in memory only).
        try {
            $me = $client->me($cloud, $result['device_id'], $result['device_secret']);
        } catch (Throwable $e) {
            $this->error('Identity persisted but verification failed: ' . $e->getMessage());

            return self::FAILURE;
        }
        Log::info('[edge-pairing-audit] appliance_paired', ['device_id' => $result['device_id'], 'branch_id' => $me['branch_id'] ?? null, 'tenant_code' => $me['tenant_code'] ?? null]);
        $this->info('Paired. Device identity persisted to ' . $envFile . ' (secret not shown).');
        $this->line('  device_id : ' . $result['device_id']);
        $this->line('  tenant    : ' . (string) ($me['tenant_code'] ?? '?'));
        $this->line('  branch_id : ' . (string) ($me['branch_id'] ?? '?'));
        $this->line('  status    : ' . (string) ($me['status'] ?? '?'));

        return self::SUCCESS;
    }

    private function readCode(): string
    {
        $file = (string) ($this->option('code-file') ?? '');
        if ($file !== '') {
            if (! is_file($file)) {
                return '';
            }
            $code = trim((string) file_get_contents($file));
            @unlink($file); // one-time: never leave the code lying around

            return $code;
        }

        return trim((string) $this->secret('Enter the one-time pairing code from the Cloud'));
    }
}
