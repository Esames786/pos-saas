<?php

namespace App\Services\Edge;

use RuntimeException;

/**
 * OWNER DECISION D7 — the EXPLICIT Edge tenant-migration manifest.
 *
 * The appliance MUST NOT blindly execute every canonical tenant migration. Every tenant migration file the packaged
 * runtime can reach is classified in database/migrations/edge-tenant-manifest.php as exactly ONE of:
 *
 *   EDGE_REQUIRED     Edge uses the schema directly; ships, runs, needs an Edge migration + upgrade test;
 *   EDGE_SCHEMA_SAFE  shared table/schema change the appliance may safely carry (proven additive, forward-safe,
 *                     dependency-safe) even if not immediately used; ships and runs;
 *   CLOUD_ONLY        Cloud/back-office feature not part of the Branch Server; EXCLUDED from the package by the
 *                     artifact builder and, defensively, SKIPPED-WITH-RECORD if ever present on an appliance.
 *
 * Rules enforced here and by the callers (EdgeLocalSchemaUpgrader, EdgeLocalDbInitCommand, EdgeArtifactBuilder,
 * EdgeUpdateInstaller, EdgeTenantMigrationManifestTest):
 *   - an UNCLASSIFIED tenant migration present in the runtime is a RELEASE BLOCKER (plan verdict RELEASE_BLOCKER,
 *     upgrade/db-init abort before touching the DB, artifact plan refused);
 *   - a destructive / drop / rename / data-rewrite migration carries OWNER_REVIEW_REQUIRED=true;
 *   - CLOUD_ONLY never becomes a package dependency: PACKAGE_DECISION is EXCLUDE and UPGRADE_TEST is "n/a: CLOUD_ONLY";
 *   - the schema upgrader operates ONLY from the approved (RUN) set this manifest yields;
 *   - the dry-run plan is stable and hashable (planHash) so the installed LAB update can stop when the actual plan
 *     differs from the pre-install report.
 *
 * Only database/migrations/tenant is governed. database/migrations/edge is Edge-owned by definition (every file there
 * IS the appliance schema) and master migrations never run on an appliance.
 */
final class EdgeTenantMigrationManifest
{
    public const FILE = 'database/migrations/edge-tenant-manifest.php';

    public const PLAN_FORMAT = 'edge-migration-plan-v1';

    public const EDGE_REQUIRED = 'EDGE_REQUIRED';
    public const EDGE_SCHEMA_SAFE = 'EDGE_SCHEMA_SAFE';
    public const CLOUD_ONLY = 'CLOUD_ONLY';
    public const CLASSES = [self::EDGE_REQUIRED, self::EDGE_SCHEMA_SAFE, self::CLOUD_ONLY];

    /** Plan classification labels for files the manifest does not govern / does not know. */
    public const EDGE_OWNED = 'EDGE_OWNED';
    public const UNCLASSIFIED = 'UNCLASSIFIED';

    public const DECISION_RUN = 'RUN';
    public const DECISION_SKIP_CLOUD_ONLY = 'SKIP_CLOUD_ONLY';
    public const DECISION_ABORT_UNCLASSIFIED = 'ABORT_UNCLASSIFIED';

    public const PACKAGE_SHIP_AND_RUN = 'SHIP_AND_RUN';
    public const PACKAGE_EXCLUDE = 'EXCLUDE';

    public const LOCK_RISKS = ['none', 'low', 'medium', 'high'];

    /** The owner's ten record fields — every entry must carry all of them. */
    public const FIELDS = [
        'MIGRATION', 'CLASSIFICATION', 'TABLES_CHANGED', 'EDGE_CODE_REFERENCES', 'ADDITIVE_ONLY',
        'DATA_REWRITE', 'LOCK_RISK', 'CLOUD_ONLY_CLASS_DEPENDENCY', 'PACKAGE_DECISION', 'UPGRADE_TEST',
    ];

    public const VERDICT_OK = 'OK';
    public const VERDICT_RELEASE_BLOCKER = 'RELEASE_BLOCKER';

    /** @param array<string,array<string,mixed>> $migrations */
    private function __construct(
        private readonly string $version,
        private readonly array $meta,
        private readonly array $migrations,
        private readonly string $source,
    ) {
    }

    /** Load + validate the manifest of a source tree (default: this application). Throws on an invalid manifest. */
    public static function load(?string $root = null): self
    {
        $path = rtrim(str_replace('\\', '/', $root ?? base_path()), '/') . '/' . self::FILE;
        if (! is_file($path)) {
            throw new RuntimeException('EDGE_MIGRATION_MANIFEST_MISSING: ' . self::FILE . ' is not present at ' . $path);
        }
        $data = include $path;
        if (! is_array($data)) {
            throw new RuntimeException('EDGE_MIGRATION_MANIFEST_INVALID: ' . self::FILE . ' must return an array');
        }

        return self::fromArray($data, $path);
    }

    /** True when the tree at $root carries a manifest file at all. */
    public static function existsIn(string $root): bool
    {
        return is_file(rtrim(str_replace('\\', '/', $root), '/') . '/' . self::FILE);
    }

    public static function fromArray(array $data, string $source = 'array'): self
    {
        $problems = self::problems($data);
        if ($problems !== []) {
            throw new RuntimeException('EDGE_MIGRATION_MANIFEST_INVALID: ' . implode('; ', array_slice($problems, 0, 10))
                . (count($problems) > 10 ? '; … (' . (count($problems) - 10) . ' more)' : ''));
        }
        $migrations = $data['migrations'];
        ksort($migrations);

        return new self((string) $data['manifest_version'], (array) ($data['meta'] ?? []), $migrations, $source);
    }

    /**
     * Structural validation — every problem as a human line (empty = valid). Pure, so the CI gate can list everything.
     *
     * @return array<int,string>
     */
    public static function problems(array $data): array
    {
        $p = [];
        if (! isset($data['manifest_version']) || ! is_string($data['manifest_version']) || $data['manifest_version'] === '') {
            $p[] = 'manifest_version missing';
        }
        if (! isset($data['migrations']) || ! is_array($data['migrations'])) {
            return array_merge($p, ['migrations map missing']);
        }
        foreach ($data['migrations'] as $name => $entry) {
            if (! is_string($name) || ! preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+$/', $name)) {
                $p[] = "[{$name}] is not a tenant migration name";
                continue;
            }
            if (! is_array($entry)) {
                $p[] = "[{$name}] entry is not an array";
                continue;
            }
            foreach (self::FIELDS as $field) {
                if (! array_key_exists($field, $entry)) {
                    $p[] = "[{$name}] field {$field} missing";
                }
            }
            if (($entry['MIGRATION'] ?? null) !== $name) {
                $p[] = "[{$name}] MIGRATION must equal the key";
            }
            $class = $entry['CLASSIFICATION'] ?? null;
            if (! in_array($class, self::CLASSES, true)) {
                $p[] = "[{$name}] CLASSIFICATION must be one of " . implode('|', self::CLASSES);
            }
            if (! is_array($entry['TABLES_CHANGED'] ?? null)) {
                $p[] = "[{$name}] TABLES_CHANGED must be a list of table names";
            }
            if (! is_array($entry['EDGE_CODE_REFERENCES'] ?? null)) {
                $p[] = "[{$name}] EDGE_CODE_REFERENCES must be a list (['none'] when there are none)";
            }
            foreach (['ADDITIVE_ONLY', 'DATA_REWRITE'] as $bool) {
                if (! is_bool($entry[$bool] ?? null)) {
                    $p[] = "[{$name}] {$bool} must be a boolean";
                }
            }
            if (! in_array($entry['LOCK_RISK'] ?? null, self::LOCK_RISKS, true)) {
                $p[] = "[{$name}] LOCK_RISK must be one of " . implode('|', self::LOCK_RISKS);
            }
            if (! is_string($entry['CLOUD_ONLY_CLASS_DEPENDENCY'] ?? null) || ($entry['CLOUD_ONLY_CLASS_DEPENDENCY'] ?? '') === '') {
                $p[] = "[{$name}] CLOUD_ONLY_CLASS_DEPENDENCY must be 'none' or the class/seeder it references";
            }
            $decision = $entry['PACKAGE_DECISION'] ?? null;
            if ($class === self::CLOUD_ONLY) {
                if ($decision !== self::PACKAGE_EXCLUDE) {
                    $p[] = "[{$name}] CLOUD_ONLY must be PACKAGE_DECISION=EXCLUDE (SHIP-NO-RUN is not allowed)";
                }
                if (($entry['UPGRADE_TEST'] ?? null) !== 'n/a: CLOUD_ONLY') {
                    $p[] = "[{$name}] CLOUD_ONLY must name UPGRADE_TEST 'n/a: CLOUD_ONLY'";
                }
            } elseif (in_array($class, self::CLASSES, true)) {
                if ($decision !== self::PACKAGE_SHIP_AND_RUN) {
                    $p[] = "[{$name}] {$class} must be PACKAGE_DECISION=SHIP_AND_RUN";
                }
                $tests = $entry['UPGRADE_TEST'] ?? null;
                $tests = is_array($tests) ? $tests : [$tests];
                foreach ($tests as $t) {
                    if (! is_string($t) || ! preg_match('/^Tests\\\\[A-Za-z0-9_\\\\]+(::test_[a-z0-9_]+)?$/', $t)) {
                        $p[] = "[{$name}] UPGRADE_TEST must name a test class (Tests\\…) or class::test_method";
                    }
                }
            }
            if (array_key_exists('OWNER_REVIEW_REQUIRED', $entry) && ! is_bool($entry['OWNER_REVIEW_REQUIRED'])) {
                $p[] = "[{$name}] OWNER_REVIEW_REQUIRED must be a boolean";
            }
            $destructive = (($entry['ADDITIVE_ONLY'] ?? true) === false) || (($entry['DATA_REWRITE'] ?? false) === true);
            if ($destructive && ($entry['OWNER_REVIEW_REQUIRED'] ?? false) !== true) {
                $p[] = "[{$name}] destructive/data-rewrite migrations must carry OWNER_REVIEW_REQUIRED=true";
            }
            if (array_key_exists('PENDING_MERGE_FROM', $entry) && (! is_string($entry['PENDING_MERGE_FROM']) || ! preg_match('/^[0-9a-f]{7,40}$/', $entry['PENDING_MERGE_FROM']))) {
                $p[] = "[{$name}] PENDING_MERGE_FROM must be a canonical commit hash";
            }
        }

        return $p;
    }

    // ── accessors ────────────────────────────────────────────────────────────

    public function version(): string
    {
        return $this->version;
    }

    public function meta(): array
    {
        return $this->meta;
    }

    public function source(): string
    {
        return $this->source;
    }

    /** @return array<string,array<string,mixed>> name => entry (sorted by name) */
    public function entries(): array
    {
        return $this->migrations;
    }

    public function has(string $migration): bool
    {
        return isset($this->migrations[$migration]);
    }

    public function entry(string $migration): ?array
    {
        return $this->migrations[$migration] ?? null;
    }

    public function classification(string $migration): string
    {
        return (string) ($this->migrations[$migration]['CLASSIFICATION'] ?? self::UNCLASSIFIED);
    }

    /** The canonical commit a not-yet-merged entry waits for, or null once the file is in the tree. */
    public function pendingMergeFrom(string $migration): ?string
    {
        $v = $this->migrations[$migration]['PENDING_MERGE_FROM'] ?? null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    /** @return array<int,string> migration names of a class */
    public function ofClass(string $class): array
    {
        return array_keys(array_filter($this->migrations, fn ($e) => $e['CLASSIFICATION'] === $class));
    }

    /** @return array<string,int> class => count */
    public function counts(): array
    {
        $c = array_fill_keys(self::CLASSES, 0);
        foreach ($this->migrations as $e) {
            $c[$e['CLASSIFICATION']]++;
        }

        return $c;
    }

    /** @return array<int,string> migration names the owner must review (destructive / rewrite) */
    public function ownerReviewRequired(): array
    {
        return array_keys(array_filter($this->migrations, fn ($e) => ($e['OWNER_REVIEW_REQUIRED'] ?? false) === true));
    }

    /** Artifact-relative paths the package must NOT contain (CLOUD_ONLY tenant migrations). */
    public function packageExcludedPaths(): array
    {
        return array_map(fn ($n) => 'database/migrations/tenant/' . $n . '.php', $this->ofClass(self::CLOUD_ONLY));
    }

    // ── decisions / plan ─────────────────────────────────────────────────────

    /** RUN | SKIP_CLOUD_ONLY | ABORT_UNCLASSIFIED for one tenant migration name. */
    public function decisionFor(string $migration): string
    {
        return match ($this->classification($migration)) {
            self::EDGE_REQUIRED, self::EDGE_SCHEMA_SAFE => self::DECISION_RUN,
            self::CLOUD_ONLY => self::DECISION_SKIP_CLOUD_ONLY,
            default => self::DECISION_ABORT_UNCLASSIFIED,
        };
    }

    /**
     * Plan entry for one pending migration (stable field order — this is what gets hashed and printed).
     *
     * @return array{migration:string,group:string,classification:string,decision:string,tables:array<int,string>,owner_review_required:bool}
     */
    public function planEntry(string $migration, string $group): array
    {
        if ($group !== 'tenant') {
            // migrations/edge (and test-only extra paths) are Edge-owned by definition.
            return ['migration' => $migration, 'group' => $group, 'classification' => self::EDGE_OWNED, 'decision' => self::DECISION_RUN, 'tables' => [], 'owner_review_required' => false];
        }
        $e = $this->entry($migration);

        return [
            'migration' => $migration,
            'group' => $group,
            'classification' => $this->classification($migration),
            'decision' => $this->decisionFor($migration),
            'tables' => array_values((array) ($e['TABLES_CHANGED'] ?? [])),
            'owner_review_required' => (bool) ($e['OWNER_REVIEW_REQUIRED'] ?? false),
        ];
    }

    /**
     * The exact migration plan for a set of pending names per group — machine-readable, deterministic, hashable.
     *
     * @param  array<string,array<int,string>>  $pendingByGroup  e.g. ['tenant' => [...], 'edge' => [...]]
     * @return array{format:string,manifest_version:string,entries:array<int,array>,run:array<int,string>,skip:array<int,string>,blockers:array<int,string>,plan_hash:string,verdict:string}
     */
    public function plan(array $pendingByGroup): array
    {
        $entries = [];
        foreach ($pendingByGroup as $group => $names) {
            $names = array_values((array) $names);
            sort($names);
            foreach ($names as $name) {
                $entries[] = $this->planEntry((string) $name, (string) $group);
            }
        }
        $run = $skip = $blockers = [];
        foreach ($entries as $e) {
            match ($e['decision']) {
                self::DECISION_RUN => $run[] = $e['migration'],
                self::DECISION_SKIP_CLOUD_ONLY => $skip[] = $e['migration'],
                default => $blockers[] = $e['migration'],
            };
        }

        return [
            'format' => self::PLAN_FORMAT,
            'manifest_version' => $this->version,
            'entries' => $entries,
            'run' => $run,
            'skip' => $skip,
            'blockers' => $blockers,
            'plan_hash' => self::planHash($entries),
            'verdict' => $blockers === [] ? self::VERDICT_OK : self::VERDICT_RELEASE_BLOCKER,
        ];
    }

    /** sha256 over the canonical (migration, group, classification, decision) tuples in plan order. */
    public static function planHash(array $entries): string
    {
        $tuples = array_map(fn ($e) => [
            (string) ($e['migration'] ?? ''), (string) ($e['group'] ?? ''), (string) ($e['classification'] ?? ''), (string) ($e['decision'] ?? ''),
        ], array_values($entries));

        return 'sha256:' . hash('sha256', json_encode([self::PLAN_FORMAT, $tuples], JSON_UNESCAPED_SLASHES));
    }

    /**
     * Release-blocker check for an artifact plan: tenant migration files that are unclassified, or CLOUD_ONLY files
     * that are still present. Returns [] when the plan is clean.
     *
     * @param  array<int,string>  $relPaths  artifact-relative paths
     * @return array<int,string> problems
     */
    public function artifactProblems(array $relPaths): array
    {
        $problems = [];
        foreach ($relPaths as $rel) {
            $rel = str_replace('\\', '/', $rel);
            if (! preg_match('#(?:^|/)database/migrations/tenant/([^/]+)\.php$#', $rel, $m)) {
                continue;
            }
            $name = $m[1];
            if (! $this->has($name)) {
                $problems[] = "UNCLASSIFIED tenant migration in the artifact plan (RELEASE BLOCKER): {$name}";
            } elseif ($this->classification($name) === self::CLOUD_ONLY) {
                $problems[] = "CLOUD_ONLY tenant migration must not ship: {$name}";
            }
        }

        return $problems;
    }

    /** Human table rows for a plan (migration, group, classification, decision, tables). */
    public static function planRows(array $plan): array
    {
        return array_map(fn ($e) => [
            $e['migration'], $e['group'], $e['classification'], $e['decision'],
            $e['tables'] === [] ? '—' : implode(', ', array_slice($e['tables'], 0, 4)) . (count($e['tables']) > 4 ? ' …' : ''),
        ], $plan['entries']);
    }
}
