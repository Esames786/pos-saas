<?php

namespace App\Services\Edge;

use App\Services\Edge\MigrationSubstitutes\EdgeMigrationSubstitute;
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
 *   - OWNER DECISION (16 Nov 2026, item 5): every shipped OWNER_REVIEW_REQUIRED entry states its OWNER_APPROVAL — the
 *     Edge release that already executed it (release:<x.y.z>-edge …) or the dated owner decision (owner-decision:<date> …);
 *     while it is PENDING the decision is ABORT_OWNER_APPROVAL_PENDING = RELEASE_BLOCKER (plan, db-init, upgrade, artifact
 *     build and update install all fail closed). A reviewed-but-unapproved migration is never RUN;
 *   - OWNER DECISION (16 Nov 2026, item 3): an entry may name an Edge SUBSTITUTE (EDGE_EXECUTION=SUBSTITUTE +
 *     EDGE_SUBSTITUTE=class implementing MigrationSubstitutes\EdgeMigrationSubstitute): the canonical file is untouched, the
 *     substitute body runs on the appliance, the canonical NAME is recorded — decision RUN_EDGE_SUBSTITUTE, visible in
 *     every plan / audit; it still needs the owner approval above;
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
    /** OWNER DECISION (16 Nov 2026, item 5): reviewed but not approved by the owner — a RELEASE BLOCKER, fail closed. */
    public const DECISION_ABORT_OWNER_APPROVAL_PENDING = 'ABORT_OWNER_APPROVAL_PENDING';
    /** OWNER DECISION (16 Nov 2026, item 3): the NAME is recorded, the BODY that runs is the manifest's EDGE_SUBSTITUTE class. */
    public const DECISION_RUN_EDGE_SUBSTITUTE = 'RUN_EDGE_SUBSTITUTE';

    public const EXECUTION_RUN = 'RUN';
    public const EXECUTION_SUBSTITUTE = 'SUBSTITUTE';

    /** OWNER_APPROVAL values: 'PENDING …' blocks; an approval is EXPLICIT and migration-specific — the Edge release that already executed it, or the dated owner decision. */
    public const OWNER_APPROVAL_PENDING = 'PENDING';
    public const OWNER_APPROVAL_PATTERN = '/^(release:\d+\.\d+\.\d+-edge|owner-decision:\d{4}-\d{2}-\d{2})(\s|$)/';
    private const OWNER_APPROVAL_PENDING_PATTERN = '/^PENDING(\s|$)/';
    private const TEST_GRANT = 'owner-decision:0000-00-00 TEST-ONLY grant by the PHPUnit base TestCase — NOT an owner approval; ignored outside tests';

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

    /** Test-only seam (see overrideForTests): ['root' => normalised root, 'sha' => sha256 of the source file or null, 'manifest' => self]. */
    private static ?array $testOverride = null;

    /** @param array<string,array<string,mixed>> $migrations */
    private function __construct(
        private readonly string $version,
        private readonly array $meta,
        private readonly array $migrations,
        private readonly string $source,
    ) {
    }

    /**
     * Load + validate the manifest of a source tree (default: this application). Throws on an invalid manifest.
     *
     * Inside PHPUnit a manifest installed by overrideForTests() is returned instead for its own root and for any tree
     * whose manifest file is byte-identical to the one it was loaded from (a package or artifact built from this tree);
     * the base TestCase installs the real manifest with its pending approvals granted so the suites exercise an
     * installable tree. Pass $ignoreTestOverride to read the real file regardless. Outside PHPUnit the seam does not exist.
     */
    public static function load(?string $root = null, bool $ignoreTestOverride = false): self
    {
        $dir = rtrim(str_replace('\\', '/', $root ?? base_path()), '/');
        $path = $dir . '/' . self::FILE;
        if (! $ignoreTestOverride && self::$testOverride !== null && app()->runningUnitTests()) {
            if (self::$testOverride['root'] === $dir
                || (self::$testOverride['sha'] !== null && is_file($path) && hash_equals(self::$testOverride['sha'], (string) hash_file('sha256', $path)))) {
                return self::$testOverride['manifest'];
            }
        }
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

    // ── test-only seams (every one is inert outside PHPUnit) ─────────────────

    /** Install the manifest load() returns for its source root (null clears). Ignored unless app()->runningUnitTests(). */
    public static function overrideForTests(?self $manifest): void
    {
        if ($manifest === null) {
            self::$testOverride = null;

            return;
        }
        $dir = rtrim(str_replace('\\', '/', dirname($manifest->source, substr_count(self::FILE, '/') + 1)), '/');
        self::$testOverride = [
            'root' => $dir,
            'sha' => is_file($manifest->source) ? (string) hash_file('sha256', $manifest->source) : null,
            'manifest' => $manifest,
        ];
    }

    /** A copy whose PENDING approvals carry a marker that says what it is (a test grant), so the suites see an installable tree. */
    public function withOwnerApprovalsGrantedForTests(): self
    {
        $migrations = $this->migrations;
        foreach ($migrations as $name => $entry) {
            if (is_string($entry['OWNER_APPROVAL'] ?? null) && self::approvalIsPending($entry['OWNER_APPROVAL'])) {
                $migrations[$name]['OWNER_APPROVAL'] = self::TEST_GRANT . ' (was: ' . $entry['OWNER_APPROVAL'] . ')';
            }
        }

        return new self($this->version . '+test-approvals', $this->meta, $migrations, $this->source);
    }

    /** A copy with entries added/replaced (validated like the file). Test-only: refused outside PHPUnit. */
    public function withEntriesForTests(array $entries): self
    {
        if (! app()->runningUnitTests()) {
            throw new RuntimeException('EDGE_MIGRATION_MANIFEST_SEAM: withEntriesForTests is a test-only seam.');
        }

        return self::fromArray(['manifest_version' => $this->version, 'meta' => $this->meta, 'migrations' => array_merge($this->migrations, $entries)], $this->source);
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
            // OWNER DECISION (16 Nov 2026, item 5): explicit, migration-specific approval on every shipped review entry.
            $review = ($entry['OWNER_REVIEW_REQUIRED'] ?? false) === true;
            $approval = $entry['OWNER_APPROVAL'] ?? null;
            if ($class === self::CLOUD_ONLY) {
                if (array_key_exists('OWNER_APPROVAL', $entry)) {
                    $p[] = "[{$name}] CLOUD_ONLY is never executed on an appliance — it must not carry OWNER_APPROVAL";
                }
            } elseif ($review) {
                if (! is_string($approval) || ! (self::approvalIsPending($approval) || preg_match(self::OWNER_APPROVAL_PATTERN, $approval) === 1)) {
                    $p[] = "[{$name}] OWNER_REVIEW_REQUIRED entries must carry OWNER_APPROVAL = 'PENDING …' or an explicit approval ('release:<x.y.z>-edge …' | 'owner-decision:<YYYY-MM-DD> …')";
                }
            } elseif (array_key_exists('OWNER_APPROVAL', $entry)) {
                $p[] = "[{$name}] OWNER_APPROVAL is only meaningful on an OWNER_REVIEW_REQUIRED entry";
            }
            // OWNER DECISION (16 Nov 2026, item 3): an Edge substitute is declared explicitly and written for this exact migration.
            $execution = $entry['EDGE_EXECUTION'] ?? self::EXECUTION_RUN;
            if (! in_array($execution, [self::EXECUTION_RUN, self::EXECUTION_SUBSTITUTE], true)) {
                $p[] = "[{$name}] EDGE_EXECUTION must be RUN or SUBSTITUTE";
            } elseif ($execution === self::EXECUTION_SUBSTITUTE) {
                $sub = $entry['EDGE_SUBSTITUTE'] ?? null;
                if ($class === self::CLOUD_ONLY) {
                    $p[] = "[{$name}] a CLOUD_ONLY migration cannot have an Edge substitute (it is never executed)";
                } elseif (! is_string($sub) || ! class_exists($sub) || ! is_subclass_of($sub, EdgeMigrationSubstitute::class)) {
                    $p[] = "[{$name}] EDGE_EXECUTION=SUBSTITUTE needs EDGE_SUBSTITUTE = a class implementing " . EdgeMigrationSubstitute::class;
                } elseif ($sub::forMigration() !== $name) {
                    $p[] = "[{$name}] EDGE_SUBSTITUTE {$sub} is written for " . $sub::forMigration() . ', not this migration';
                }
            } elseif (array_key_exists('EDGE_SUBSTITUTE', $entry)) {
                $p[] = "[{$name}] EDGE_SUBSTITUTE needs EDGE_EXECUTION=SUBSTITUTE";
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

    public static function approvalIsPending(string $approval): bool
    {
        return preg_match(self::OWNER_APPROVAL_PENDING_PATTERN, $approval) === 1;
    }

    /** The entry's OWNER_APPROVAL statement (null when the entry needs none: not review-required, CLOUD_ONLY, unknown). */
    public function ownerApproval(string $migration): ?string
    {
        $v = $this->migrations[$migration]['OWNER_APPROVAL'] ?? null;

        return is_string($v) ? $v : null;
    }

    /** True when the migration may execute on an appliance: not review-required, or explicitly approved (release / owner decision). */
    public function isOwnerApproved(string $migration): bool
    {
        $e = $this->migrations[$migration] ?? null;
        if ($e === null || ($e['OWNER_REVIEW_REQUIRED'] ?? false) !== true) {
            return true;
        }
        $approval = $this->ownerApproval($migration);

        return $approval !== null && ! self::approvalIsPending($approval) && preg_match(self::OWNER_APPROVAL_PATTERN, $approval) === 1;
    }

    /** @return array<int,string> shipped (non-CLOUD_ONLY) review-required entries whose owner approval is still PENDING — release blockers */
    public function ownerApprovalPending(): array
    {
        return array_values(array_filter(array_keys($this->migrations), fn ($n) => $this->classification($n) !== self::CLOUD_ONLY && ! $this->isOwnerApproved($n)));
    }

    /** RUN (the canonical body) or SUBSTITUTE (the Edge body named by substituteFor()). */
    public function executionMode(string $migration): string
    {
        return (string) ($this->migrations[$migration]['EDGE_EXECUTION'] ?? self::EXECUTION_RUN);
    }

    /** The EdgeMigrationSubstitute class that executes instead of the canonical body, or null. */
    public function substituteFor(string $migration): ?string
    {
        if ($this->executionMode($migration) !== self::EXECUTION_SUBSTITUTE) {
            return null;
        }
        $c = $this->migrations[$migration]['EDGE_SUBSTITUTE'] ?? null;

        return is_string($c) ? $c : null;
    }

    /** Artifact-relative paths the package must NOT contain (CLOUD_ONLY tenant migrations). */
    public function packageExcludedPaths(): array
    {
        return array_map(fn ($n) => 'database/migrations/tenant/' . $n . '.php', $this->ofClass(self::CLOUD_ONLY));
    }

    // ── decisions / plan ─────────────────────────────────────────────────────

    /** RUN | RUN_EDGE_SUBSTITUTE | SKIP_CLOUD_ONLY | ABORT_OWNER_APPROVAL_PENDING | ABORT_UNCLASSIFIED for one tenant migration name. */
    public function decisionFor(string $migration): string
    {
        return match ($this->classification($migration)) {
            self::EDGE_REQUIRED, self::EDGE_SCHEMA_SAFE => ! $this->isOwnerApproved($migration)
                ? self::DECISION_ABORT_OWNER_APPROVAL_PENDING
                : ($this->executionMode($migration) === self::EXECUTION_SUBSTITUTE ? self::DECISION_RUN_EDGE_SUBSTITUTE : self::DECISION_RUN),
            self::CLOUD_ONLY => self::DECISION_SKIP_CLOUD_ONLY,
            default => self::DECISION_ABORT_UNCLASSIFIED,
        };
    }

    /**
     * Plan entry for one pending migration (stable field order — this is what gets hashed and printed).
     *
     * @return array{migration:string,group:string,classification:string,decision:string,tables:array<int,string>,owner_review_required:bool,owner_approval:?string,edge_substitute:?string}
     */
    public function planEntry(string $migration, string $group): array
    {
        if ($group !== 'tenant') {
            // migrations/edge (and test-only extra paths) are Edge-owned by definition.
            return ['migration' => $migration, 'group' => $group, 'classification' => self::EDGE_OWNED, 'decision' => self::DECISION_RUN, 'tables' => [], 'owner_review_required' => false, 'owner_approval' => null, 'edge_substitute' => null];
        }
        $e = $this->entry($migration);

        return [
            'migration' => $migration,
            'group' => $group,
            'classification' => $this->classification($migration),
            'decision' => $this->decisionFor($migration),
            'tables' => array_values((array) ($e['TABLES_CHANGED'] ?? [])),
            'owner_review_required' => (bool) ($e['OWNER_REVIEW_REQUIRED'] ?? false),
            'owner_approval' => $this->ownerApproval($migration),
            'edge_substitute' => $this->substituteFor($migration),
        ];
    }

    /**
     * The exact migration plan for a set of pending names per group — machine-readable, deterministic, hashable.
     *
     * @param  array<string,array<int,string>>  $pendingByGroup  e.g. ['tenant' => [...], 'edge' => [...]]
     * @return array{format:string,manifest_version:string,entries:array<int,array>,run:array<int,string>,substitute:array<int,string>,skip:array<int,string>,blockers:array<int,string>,blocker_reasons:array<string,string>,plan_hash:string,verdict:string}
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
        $run = $substitute = $skip = $blockers = $reasons = [];
        foreach ($entries as $e) {
            match ($e['decision']) {
                self::DECISION_RUN => $run[] = $e['migration'],
                self::DECISION_RUN_EDGE_SUBSTITUTE => $substitute[] = $e['migration'],
                self::DECISION_SKIP_CLOUD_ONLY => $skip[] = $e['migration'],
                default => [$blockers[] = $e['migration'], $reasons[$e['migration']] = $e['decision']],
            };
        }

        return [
            'format' => self::PLAN_FORMAT,
            'manifest_version' => $this->version,
            'entries' => $entries,
            'run' => $run,
            'substitute' => $substitute,
            'skip' => $skip,
            'blockers' => $blockers,
            'blocker_reasons' => $reasons,
            'plan_hash' => self::planHash($entries),
            'verdict' => $blockers === [] ? self::VERDICT_OK : self::VERDICT_RELEASE_BLOCKER,
        ];
    }

    /**
     * The operator-facing RELEASE BLOCKER line for a blocked plan, with a code per cause:
     * <prefix>_UNCLASSIFIED_MIGRATION for files without a classification, <prefix>_OWNER_APPROVAL_PENDING for reviewed
     * files the owner has not approved (both when both apply). Empty string for a clean plan.
     */
    public static function blockerMessage(string $prefix, array $plan): string
    {
        $reasons = (array) ($plan['blocker_reasons'] ?? []);
        $unclassified = $pending = [];
        foreach ((array) ($plan['blockers'] ?? []) as $name) {
            if (($reasons[$name] ?? self::DECISION_ABORT_UNCLASSIFIED) === self::DECISION_ABORT_OWNER_APPROVAL_PENDING) {
                $pending[] = $name;
            } else {
                $unclassified[] = $name;
            }
        }
        $parts = [];
        if ($unclassified !== []) {
            $parts[] = "{$prefix}_UNCLASSIFIED_MIGRATION: RELEASE BLOCKER — tenant migration(s) without a manifest classification: "
                . implode(', ', $unclassified) . ' (classify them in ' . self::FILE . ')';
        }
        if ($pending !== []) {
            $parts[] = "{$prefix}_OWNER_APPROVAL_PENDING: RELEASE BLOCKER — owner approval pending for reviewed tenant migration(s): "
                . implode(', ', $pending) . ' (OWNER_APPROVAL in ' . self::FILE . ' — owner decision 16 Nov 2026, item 5)';
        }

        return implode('; ', $parts);
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
            } elseif (! $this->isOwnerApproved($name)) {
                $problems[] = "OWNER_APPROVAL_PENDING tenant migration in the artifact plan (RELEASE BLOCKER — owner decision 16 Nov 2026, item 5): {$name}";
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
