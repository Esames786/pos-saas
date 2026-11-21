<?php

namespace App\Console\Commands;

use App\Models\Master\Tenant;
use App\Services\Edge\EdgeBackupRecoveryAuthority;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;

/**
 * P5B §3 — Cloud ADMIN authority over a branch's backup recovery key (never available on an appliance).
 *
 *   php artisan edge:recovery-key status --tenant=<tenant_code> --branch=<branch_id>
 *   php artisan edge:recovery-key rotate --tenant=<tenant_code> --branch=<branch_id> --reason="operator left"
 *
 * Prints key ids and the audit trail — never key material. Rotation retires the active key (older backups stay
 * recoverable) and issues a fresh one; the appliance picks it up on its next edge:local:recovery-key run.
 */
class EdgeRecoveryKeyCommand extends Command
{
    protected $signature = 'edge:recovery-key
        {action : status|rotate}
        {--tenant= : tenant code}
        {--branch= : tenant-DB branch id}
        {--reason= : rotation reason (audited)}
        {--json : Emit JSON}';

    protected $description = 'Cloud admin authority: show or rotate a branch backup recovery key (ids + audit only, never material).';

    public function handle(EdgeBackupRecoveryAuthority $authority): int
    {
        if (EdgeRuntime::isBranchServer()) {
            $this->error('edge:recovery-key is a Cloud command — it never runs on a Branch Server.');

            return self::FAILURE;
        }
        $action = (string) $this->argument('action');
        $code = (string) ($this->option('tenant') ?? '');
        $branch = (int) ($this->option('branch') ?? 0);
        $tenant = $code !== '' ? Tenant::where('tenant_code', $code)->first() : null;
        if (! $tenant || $branch <= 0) {
            $this->error('--tenant=<tenant_code> and --branch=<id> are required (tenant not found or branch missing).');

            return self::FAILURE;
        }
        $actor = 'admin:cli:' . (get_current_user() ?: 'unknown');
        try {
            if ($action === 'rotate') {
                $reason = trim((string) ($this->option('reason') ?? ''));
                if ($reason === '') {
                    $this->error('--reason is required for a rotation (it is audited).');

                    return self::FAILURE;
                }
                $rotated = $authority->rotate((int) $tenant->id, $branch, $actor, 'admin_rotate:' . $reason);
                $this->info('Recovery key rotated: new active key id ' . $rotated['key_id'] . ' (the previous key is retired, not deleted).');
            } elseif ($action !== 'status') {
                $this->error('Unknown action [' . $action . ']: use status|rotate.');

                return self::FAILURE;
            }
            $status = $authority->status((int) $tenant->id, $branch);
        } catch (\Throwable $e) {
            $this->error('Refused: ' . $e->getMessage());

            return self::FAILURE;
        }
        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $this->line('tenant ' . $tenant->tenant_code . ' branch ' . $branch);
        $this->line('  active key id : ' . ($status['active_key_id'] ?? '(none issued yet)'));
        $this->line('  retired keys  : ' . ($status['retired_key_ids'] !== [] ? implode(', ', $status['retired_key_ids']) : '(none)'));
        $this->table(['at', 'action', 'outcome', 'key_id', 'actor', 'detail'], array_map(fn ($a) => [$a['at'], $a['action'], $a['outcome'], $a['key_id'], $a['actor'], $a['detail']], $status['audits']));

        return self::SUCCESS;
    }
}
