<?php

namespace App\Services\Edge;

/**
 * EDGE-COMPATIBILITY-CONTRACT-1 — the ONE compatibility vocabulary between Cloud and a Branch Server.
 *
 * Edge side: manifest() assembles the appliance's grounded compatibility facts — build versions
 * (EdgeBuildInfoService), the schemas this build can consume (config/edge.php), what configuration is
 * actually APPLIED (edge_local_meta via EdgeBranchContext), and the capability list this build
 * implements (config/edge.php `capabilities`; grounded — only features that exist offline today).
 *
 * Cloud side: classify() takes a reported manifest and answers, EXPLICITLY per feature:
 *   - compatible                  the appliance speaks the current contracts and implements it;
 *   - software_update_required    the appliance's schemas are not the Cloud's current contracts;
 *   - feature_unavailable_offline the build is current but that feature does not exist offline.
 * There is deliberately NO silent partial state: every classified feature gets one of the three.
 */
class EdgeCompatibilityService
{
    public const COMPATIBLE = 'compatible';
    public const SOFTWARE_UPDATE_REQUIRED = 'software_update_required';
    public const FEATURE_UNAVAILABLE_OFFLINE = 'feature_unavailable_offline';

    /**
     * Every offline-relevant feature the CLOUD knows about (implemented offline or not) — the default
     * classification surface, so "not offline yet" is an explicit verdict rather than an omission.
     */
    public const CLOUD_OFFLINE_FEATURES = [
        // Implemented offline today (must match config/edge.php capabilities on a current build).
        'local_auth', 'local_pos_cash_sales', 'held_sales', 'dine_in_tables', 'kot',
        'local_printing', 'operational_stock_baseline', 'local_manager_approval', 'config_refresh',
        // Known Cloud features with NO offline implementation (explicitly unavailable offline).
        'returns', 'card_payments', 'aggregator_delivery', 'customer_credit',
        'purchasing', 'manufacturing', 'stock_operations', 'cloud_manager_approval',
    ];

    public function __construct(
        private readonly EdgeBuildInfoService $buildInfo,
        private readonly EdgeBranchContext $context,
    ) {
    }

    /** The appliance's compatibility manifest (Edge side; safe non-secret facts only). */
    public function manifest(): array
    {
        $info = $this->buildInfo->info();
        $meta = $this->context->tryCurrent(); // null while unbound — reported honestly as nulls

        return [
            'edge_app_version' => $info['edge_app_version'],
            'edge_schema_version' => $info['edge_schema_version'],
            'bootstrap_schema_version' => $info['bootstrap_schema'],
            'config_schema_version' => (string) config('edge.config_schema'),
            'applied_config_schema_version' => $meta?->config_schema_version,
            'last_config_revision' => $meta && $meta->last_applied_config_revision !== null ? (int) $meta->last_applied_config_revision : null,
            'activation_epoch' => $meta && $meta->activation_epoch !== null ? (int) $meta->activation_epoch : null,
            'capabilities' => array_values((array) config('edge.capabilities', [])),
        ];
    }

    /**
     * CLOUD-side classification of a reported manifest.
     *
     * @param  array<string,mixed>  $reported  a manifest as produced by manifest()
     * @param  list<string>|null    $features  features to classify (default: every known offline-relevant feature)
     * @return array{overall: string, features: array<string,string>, current_bootstrap_schema: string, current_config_schema: string}
     */
    public function classify(array $reported, ?array $features = null): array
    {
        $updateRequired = (string) ($reported['bootstrap_schema_version'] ?? '') !== EdgeBootstrapService::SCHEMA_VERSION
            || (string) ($reported['config_schema_version'] ?? '') !== EdgeBootstrapService::CONFIG_SCHEMA_VERSION;

        $capabilities = array_values((array) ($reported['capabilities'] ?? []));
        $verdicts = [];
        foreach ($features ?? self::CLOUD_OFFLINE_FEATURES as $feature) {
            if ($updateRequired) {
                $verdicts[$feature] = self::SOFTWARE_UPDATE_REQUIRED;
            } elseif (in_array($feature, $capabilities, true)) {
                $verdicts[$feature] = self::COMPATIBLE;
            } else {
                $verdicts[$feature] = self::FEATURE_UNAVAILABLE_OFFLINE;
            }
        }

        return [
            'overall' => $updateRequired ? self::SOFTWARE_UPDATE_REQUIRED : self::COMPATIBLE,
            'features' => $verdicts,
            'current_bootstrap_schema' => EdgeBootstrapService::SCHEMA_VERSION,
            'current_config_schema' => EdgeBootstrapService::CONFIG_SCHEMA_VERSION,
        ];
    }
}
