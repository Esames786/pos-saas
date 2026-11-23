<?php

namespace App\Console\Commands;

use App\Services\Edge\AutoUpdate\EdgeDeviceCloudClient;
use App\Services\Edge\EdgeBranchContext;
use App\Support\EdgeLocalDatabase;
use App\Support\EdgeRuntime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EDGE 0.9 (stream B) — the setup wizard's "Users & PINs" page data, and its bulk enrollment assertions (contract K3).
 *
 *   php artisan edge:local:users --json
 *       every user the Cloud bootstrapped for this branch: id, name, employee code, status, roles, whether an Edge
 *       credential is enrolled for the CURRENT activation epoch, and whether the user may use the Switch Offline/Online
 *       buttons (tenant.offline-edge.security). Read-only; no credential material, ever.
 *
 *   php artisan edge:local:users --assertions-dir=<dir> --setup-token-file=<file> (--ids=1,3,4 | --all-active) [--json]
 *       asks the Cloud (device-authenticated + X-Edge-Setup-Token) for one signed 15-minute enrollment assertion per user and
 *       writes each to <dir>\assertion-<user_id>.json for `edge:local:enroll <file> --type=pin --credential-file=…`.
 *       The setup token is read from the file and the file deleted at once (never argv, never logged). The wizard creates
 *       <dir> with a user-only ACL and deletes every assertion file after enrolment.
 */
class EdgeLocalUsersCommand extends Command
{
    protected $signature = 'edge:local:users
        {--json : Machine-readable output}
        {--assertions-dir= : Fetch K3 enrollment assertions into this directory}
        {--setup-token-file= : File holding the setup token from the wizard (read then deleted)}
        {--ids= : Comma-separated user ids for --assertions-dir}
        {--all-active : Ask for every active user of the branch instead of --ids}';

    protected $description = 'List the branch users bootstrapped on this Branch Server (enrolled or not) and fetch Cloud-signed enrollment assertions for the setup wizard.';

    public function handle(EdgeBranchContext $context, EdgeDeviceCloudClient $cloud): int
    {
        if (! EdgeRuntime::isBranchServer()) {
            $this->error('edge:local:users only runs on a Branch Server (APP_ROLE=branch_server).');

            return self::FAILURE;
        }
        if (($reason = EdgeLocalDatabase::unsafeReason()) !== null) {
            $this->error("Refusing: {$reason}.");

            return self::FAILURE;
        }
        EdgeLocalDatabase::useAsTenantConnection();

        if ((string) $this->option('assertions-dir') !== '') {
            return $this->fetchAssertions($cloud);
        }

        $users = $this->users($context);
        if ($this->option('json')) {
            $this->line(json_encode(['branch_id' => $context->boundBranchId(), 'bound' => $context->isBound(), 'count' => count($users), 'users' => $users], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['id', 'name', 'employee code', 'status', 'roles', 'enrolled', 'offline control'], array_map(fn ($u) => [
                $u['id'], $u['name'], $u['employee_code'] ?? '—', $u['status'] ?? '—', implode(', ', $u['roles']), $u['enrolled'] ? 'yes (' . $u['credential_type'] . ')' : 'no', $u['offline_edge_security'] ? 'yes' : 'no',
            ], $users));
        }

        return self::SUCCESS;
    }

    /** @return array<int,array<string,mixed>> */
    private function users(EdgeBranchContext $context): array
    {
        $conn = DB::connection('tenant');
        if (! Schema::connection('tenant')->hasTable('users')) {
            return [];
        }
        $epoch = $context->activationEpoch();
        $rows = $conn->table('users')->orderBy('id')->get(['id', 'name', 'employee_code', 'status']);
        $ids = $rows->pluck('id')->all();
        $roles = [];
        $security = [];
        if ($ids !== []) {
            foreach ($conn->table('model_has_roles as mhr')->join('roles as r', 'r.id', '=', 'mhr.role_id')
                ->where('mhr.model_type', \App\Models\Tenant\User::class)->whereIn('mhr.model_id', $ids)
                ->orderBy('r.name')->get(['mhr.model_id as uid', 'r.name']) as $r) {
                $roles[(int) $r->uid][] = (string) $r->name;
            }
            foreach ($conn->table('model_has_permissions as mhp')->join('permissions as p', 'p.id', '=', 'mhp.permission_id')
                ->where('mhp.model_type', \App\Models\Tenant\User::class)->whereIn('mhp.model_id', $ids)
                ->where('p.name', 'tenant.offline-edge.security')->get(['mhp.model_id as uid']) as $p) {
                $security[(int) $p->uid] = true;
            }
        }
        $creds = Schema::connection('tenant')->hasTable('edge_local_user_credentials')
            ? $conn->table('edge_local_user_credentials')->whereIn('user_id', $ids ?: [0])->where('status', 'active')
                ->when($epoch !== null, fn ($q) => $q->where('activation_epoch', $epoch))
                ->get(['user_id', 'credential_type'])->keyBy('user_id')
            : collect();

        return $rows->map(fn ($u) => [
            'id' => (int) $u->id,
            'name' => (string) $u->name,
            'employee_code' => $u->employee_code !== null && $u->employee_code !== '' ? (string) $u->employee_code : null,
            'status' => $u->status !== null ? (string) $u->status : null,
            'roles' => $roles[(int) $u->id] ?? [],
            'enrolled' => isset($creds[$u->id]),
            'credential_type' => isset($creds[$u->id]) ? (string) $creds[$u->id]->credential_type : null,
            'offline_edge_security' => isset($security[(int) $u->id]),
        ])->values()->all();
    }

    private function fetchAssertions(EdgeDeviceCloudClient $cloud): int
    {
        $dir = rtrim((string) $this->option('assertions-dir'), "/\\");
        $tokenFile = (string) $this->option('setup-token-file');
        if (! is_dir($dir)) {
            return $this->refuse('the assertions directory does not exist (the wizard creates it with a user-only ACL)');
        }
        if ($tokenFile === '' || ! is_file($tokenFile)) {
            return $this->refuse('--setup-token-file is required and must exist');
        }
        $token = trim((string) file_get_contents($tokenFile));
        @unlink($tokenFile); // one-time: the token file never lingers
        if ($token === '') {
            return $this->refuse('the setup token file is empty');
        }
        if ($this->option('all-active')) {
            $selection = ['all_active' => true];
            $wanted = null;
        } else {
            $wanted = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $this->option('ids'))), fn ($i) => $i > 0)));
            if ($wanted === []) {
                return $this->refuse('give --ids=<comma list> or --all-active');
            }
            $selection = ['user_ids' => $wanted];
        }
        try {
            $r = $cloud->enrollmentAssertions($token, $selection);
        } catch (\Throwable $e) {
            return $this->refuse($e->getMessage());
        } finally {
            unset($token);
        }
        $written = [];
        $skipped = $r['skipped'];
        foreach ($r['assertions'] as $entry) {
            $uid = (int) ($entry['user_id'] ?? 0);
            $assertion = $entry['assertion'] ?? null;
            if ($uid <= 0 || ! is_array($assertion) || ! isset($assertion['payload'], $assertion['signature']) || (int) ($assertion['payload']['user_id'] ?? 0) !== $uid
                || ($wanted !== null && ! in_array($uid, $wanted, true))) {
                $skipped[] = ['user_id' => $uid, 'reason' => 'invalid_assertion_from_cloud'];
                continue;
            }
            $file = $dir . DIRECTORY_SEPARATOR . 'assertion-' . $uid . '.json';
            file_put_contents($file, json_encode($assertion, JSON_UNESCAPED_SLASHES));
            if (DIRECTORY_SEPARATOR === '/') {
                @chmod($file, 0600);
            }
            $written[] = ['user_id' => $uid, 'employee_code' => $entry['employee_code'] ?? null, 'name' => $entry['name'] ?? null, 'file' => $file, 'expires_at' => $assertion['payload']['expires_at'] ?? null];
        }
        $doc = ['result' => 'ok', 'assertions' => $written, 'skipped' => array_values($skipped)];
        if ($this->option('json')) {
            $this->line(json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info(count($written) . ' enrollment assertion(s) written to ' . $dir . '; ' . count($skipped) . ' skipped.');
            foreach ($skipped as $s) {
                $this->line('  skipped user #' . ($s['user_id'] ?? '?') . ': ' . ($s['reason'] ?? ''));
            }
        }

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        if ($this->option('json')) {
            $this->line(json_encode(['result' => 'failed', 'error' => $message], JSON_UNESCAPED_SLASHES));
        }
        $this->error('edge:local:users: ' . $message);

        return self::FAILURE;
    }
}
