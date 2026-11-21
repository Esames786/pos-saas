<?php

namespace App\Services\Edge;

/**
 * THE ONE canonical-JSON + manifest-hash strategy shared by the Cloud (bootstrap snapshots, ingestion identity) and the
 * appliance (envelope content hashes, bootstrap package re-verification). Dependency-free on purpose.
 *
 * P5 release-shape finding: the appliance classes used to inject the Cloud's EdgeBootstrapService only for these two
 * helpers; on a REAL release runtime that dragged the Cloud entitlement service (and the excluded SaaS subscription
 * service) into every offline sale, return and bootstrap import — the dev vendor junction had hidden it. The Cloud
 * service now delegates here, so the wire contract (byte-identical output) is unchanged.
 */
final class EdgeCanonicalJson
{
    /** Canonical JSON: associative keys sorted recursively, lists kept in order, unicode + slashes unescaped. */
    public static function encode(mixed $data): string
    {
        return json_encode(self::canonicalize($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** sha256 over the canonical JSON — the content hash every envelope and ingestion registry carries. */
    public static function hash(mixed $data): string
    {
        return hash('sha256', self::encode($data));
    }

    /**
     * The ONE bootstrap-manifest hash formula (EDGE-CONFIG-REFRESH-1 v5: config_revision + config_schema_version are inside
     * the hash). Used when the Cloud builds a snapshot and when the appliance re-verifies the package it received.
     */
    public static function manifestHash(
        string $schemaVersion,
        string $snapshotUuid,
        int $tenantId,
        int $branchId,
        ?string $deviceUuid,
        ?int $activationEpoch,
        ?int $configRevision,
        string $configSchemaVersion,
        array $sectionSummary,
    ): string {
        return self::hash([
            'schema_version' => $schemaVersion, 'snapshot_uuid' => $snapshotUuid,
            'tenant_id' => $tenantId, 'branch_id' => $branchId,
            'device_public_uuid' => $deviceUuid,
            'activation_epoch' => $activationEpoch,
            'config_revision' => $configRevision,
            'config_schema_version' => $configSchemaVersion,
            'sections' => $sectionSummary,
        ]);
    }

    private static function canonicalize(mixed $v): mixed
    {
        if (is_array($v)) {
            if (array_is_list($v)) {
                return array_map(fn ($x) => self::canonicalize($x), $v);
            }
            ksort($v);

            return array_map(fn ($x) => self::canonicalize($x), $v);
        }

        return $v;
    }
}
