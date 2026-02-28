<?php
/**
 * Cargus module for PrestaShop 8.2+ / 9.x
 *
 * Key principles:
 * - No core overrides (hooks only).
 * - Back Office uses Symfony Controller + Twig (modern approach for PS8/PS9).
 * - Two carriers for robustness across Classic Checkout, SuperCheckout, OnePageCheckout:
 *   1) Address delivery
 *   2) Ship&Go (locker / PUDO)
 * - Pricing is computed locally using /PriceTables sync (cron/manual), not live quote in checkout.
 * - Subscription quota is displayed in BO only; remaining can exceed included_total due to rollover.
 *
 * Security:
 * - Configuration stored in ps_configuration (phase 1). Sensitive values should be protected later.
 * - AJAX endpoints must use CSRF token (implemented in Symfony layer, not here).
 *
 * Post-install checklist:
 * - Verify carrier taxes for both Cargus carriers:
 *   Shipping -> Carriers -> (Cargus carriers) -> 2. Shipping locations and costs -> Tax
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Cargus extends CarrierModule
{
    /** Module technical constants */
    public const MODULE_NAME = 'cargus';
    public const MODULE_VERSION = '6.2.0';

    /** Carrier identifiers (stored in Configuration after creation) */
    public const CFG_CARRIER_ADDRESS = 'CARGUS_ID_CARRIER_ADDRESS';
    public const CFG_CARRIER_SHIPGO  = 'CARGUS_ID_CARRIER_SHIPGO';

    /**
     * Forced business rules:
     * - Tax group autodetect by keywords "RO" + "Standard"
     * - Zone name "Europe"
     */
    public const FORCED_TAX_RULE_KEYWORDS = ['RO', 'Standard'];
    public const FORCED_ZONE_NAME = 'Europe';

    /** Config keys (Phase 1 uses ps_configuration) */
    public const CFG_API_KEY  = 'CARGUS_API_KEY';
    public const CFG_USERNAME = 'CARGUS_USERNAME';
    public const CFG_PASSWORD = 'CARGUS_PASSWORD';

    // Extra services toggles/fees (Phase 1)
    public const CFG_ENABLE_COD           = 'CARGUS_ENABLE_COD';
    public const CFG_ENABLE_OPEN_PACKAGE  = 'CARGUS_ENABLE_OPEN_PACKAGE';
    public const CFG_ENABLE_DECLARED_VAL  = 'CARGUS_ENABLE_DECLARED_VALUE';
    public const CFG_ENABLE_SATURDAY      = 'CARGUS_ENABLE_SATURDAY';
    public const CFG_ENABLE_PRE10         = 'CARGUS_ENABLE_PRE10';
    public const CFG_ENABLE_PRE12         = 'CARGUS_ENABLE_PRE12';

    public const CFG_FEE_SATURDAY = 'CARGUS_FEE_SATURDAY';
    public const CFG_FEE_PRE10    = 'CARGUS_FEE_PRE10';
    public const CFG_FEE_PRE12    = 'CARGUS_FEE_PRE12';

    // Quota (BO only) – manual until official endpoint is confirmed
    public const CFG_QUOTA_SOURCE    = 'CARGUS_QUOTA_SOURCE';    // manual|api
    public const CFG_QUOTA_REMAINING = 'CARGUS_QUOTA_REMAINING'; // int

    // Heavy/agabaritic threshold (kg)
    public const CFG_HEAVY_THRESHOLD = 'CARGUS_HEAVY_THRESHOLD'; // default 31

    /**
     * Hardcoded service IDs (Annex / API docs).
     * Keep these as constants to avoid magic numbers across the codebase.
     */
    public const SERVICE_STANDARD              = 1;
    public const SERVICE_ECONOMIC_STANDARD     = 34;
    public const SERVICE_STANDARD_PLUS_31      = 35;
    public const SERVICE_PALLET_STANDARD_50    = 36;
    public const SERVICE_EASY_COLLECT_SHIPGO   = 38;
    public const SERVICE_ECONOMIC_STANDARD_M   = 39;
    public const SERVICE_ECONOMIC_STD_M_PLUS31 = 40;
    public const SERVICE_EXPORT_STANDARD       = 41;

    public function __construct()
    {
        $this->name = self::MODULE_NAME;
        $this->tab = 'shipping_logistics';
        $this->version = self::MODULE_VERSION;
        $this->author = 'Quark';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Cargus Courier Premium');
        $this->description = $this->l('Advanced shipping integration with Cargus API V3 (PS 8/9).');

        $this->ps_versions_compliancy = [
            'min' => '8.2.0',
            'max' => '9.99.99',
        ];
    }

    /**
     * Install:
     * - Minimal DB schema (phase 1).
     * - Create BO Tab pointing to Symfony route.
     * - Create/update carriers with forced Tax Rule + Zone.
     * - Register hooks.
     * - Set default configuration.
     */
    public function install()
    {
        return parent::install()
            && $this->installDb()
            && $this->installAdminTab()
            && $this->installOrUpdateCarriers()
            && $this->registerHooks()
            && $this->forceInitialSettings();
    }

    public function uninstall()
    {
        return $this->uninstallAdminTab()
            && $this->uninstallCarriers()
            && $this->uninstallDb()
            && $this->deleteConfiguration()
            && parent::uninstall();
    }

    /**
     * Keep getContent for Module Manager entrypoint.
     * UI is Symfony-based; this method should not render legacy forms.
     */
    public function getContent()
    {
        // We intentionally do not implement legacy BO UI here.
        return '';
    }

    /**
     * Register hooks used in phase 1 and later phases (hooks only; no overrides).
     */
    private function registerHooks(): bool
    {
        return $this->registerHook('actionAdminControllerSetMedia')
            && $this->registerHook('displayAdminOrder')
            && $this->registerHook('actionValidateOrder');
    }

    /**
     * Minimal DB schema for Phase 1.
     * We keep only agabaritic category mapping here as per your structure.
     * Additional tables (tariffs cache, quota cache, AWB tables, PUDO tables) will be added later.
     */
    private function installDb(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'cargus_agabaritic` (
            `id_rule` INT(11) NOT NULL AUTO_INCREMENT,
            `id_category` INT(11) NULL,
            `weight_threshold` DECIMAL(10,2) NULL,
            PRIMARY KEY (`id_rule`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        return Db::getInstance()->execute($sql);
    }

    private function uninstallDb(): bool
    {
        $sql = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'cargus_agabaritic`;';
        return Db::getInstance()->execute($sql);
    }

    /**
     * Default settings and invariants.
     */
    private function forceInitialSettings(): bool
    {
        if (!Configuration::hasKey(self::CFG_HEAVY_THRESHOLD)) {
            Configuration::updateValue(self::CFG_HEAVY_THRESHOLD, 31);
        }
        if (!Configuration::hasKey(self::CFG_QUOTA_SOURCE)) {
            Configuration::updateValue(self::CFG_QUOTA_SOURCE, 'manual');
        }
        if (!Configuration::hasKey(self::CFG_QUOTA_REMAINING)) {
            Configuration::updateValue(self::CFG_QUOTA_REMAINING, 0);
        }

        foreach ([
            self::CFG_ENABLE_COD,
            self::CFG_ENABLE_OPEN_PACKAGE,
            self::CFG_ENABLE_DECLARED_VAL,
            self::CFG_ENABLE_SATURDAY,
            self::CFG_ENABLE_PRE10,
            self::CFG_ENABLE_PRE12,
        ] as $k) {
            if (!Configuration::hasKey($k)) {
                Configuration::updateValue($k, 0);
            }
        }

        foreach ([self::CFG_FEE_SATURDAY, self::CFG_FEE_PRE10, self::CFG_FEE_PRE12] as $k) {
            if (!Configuration::hasKey($k)) {
                Configuration::updateValue($k, '0');
            }
        }

        return true;
    }

    private function deleteConfiguration(): bool
    {
        $keys = [
            self::CFG_API_KEY,
            self::CFG_USERNAME,
            self::CFG_PASSWORD,

            self::CFG_ENABLE_COD,
            self::CFG_ENABLE_OPEN_PACKAGE,
            self::CFG_ENABLE_DECLARED_VAL,
            self::CFG_ENABLE_SATURDAY,
            self::CFG_ENABLE_PRE10,
            self::CFG_ENABLE_PRE12,

            self::CFG_FEE_SATURDAY,
            self::CFG_FEE_PRE10,
            self::CFG_FEE_PRE12,

            self::CFG_QUOTA_SOURCE,
            self::CFG_QUOTA_REMAINING,
            self::CFG_HEAVY_THRESHOLD,

            self::CFG_CARRIER_ADDRESS,
            self::CFG_CARRIER_SHIPGO,
        ];

        foreach ($keys as $k) {
            Configuration::deleteByName($k);
        }

        return true;
    }

    /**
     * BO Tab that links to Symfony route.
     * PS 8/9 supports Tab->route_name for Symfony controllers.
     */
    private function installAdminTab(): bool
    {
        $tabClassName = 'AdminCargusConfig';
        $existingId = (int) Tab::getIdFromClassName($tabClassName);
        if ($existingId > 0) {
            return true;
        }

        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = $tabClassName;
        $tab->route_name = 'cargus_admin_config'; // must exist in config/routes.yml
        $tab->module = $this->name;

        // Under "Shipping" parent if available
        $parentId = (int) Tab::getIdFromClassName('AdminParentShipping');
        $tab->id_parent = $parentId > 0 ? $parentId : 0;

        $tab->name = [];
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[(int) $lang['id_lang']] = 'Cargus';
        }

        return (bool) $tab->add();
    }

    private function uninstallAdminTab(): bool
    {
        $tabId = (int) Tab::getIdFromClassName('AdminCargusConfig');
        if ($tabId <= 0) {
            return true;
        }

        $tab = new Tab($tabId);
        return (bool) $tab->delete();
    }

    /**
     * Carriers:
     * - Address delivery
     * - Ship&Go (locker/PUDO)
     *
     * Forced constraints:
     * - Tax rules group autodetected by keywords RO + Standard
     * - Zone assignment: Europe only
     */
    private function installOrUpdateCarriers(): bool
    {
        $taxRulesGroupId = $this->resolveTaxRulesGroupId();
        $zoneId = $this->resolveEuropeZoneId();

        $ok = true;

        $ok = $ok && $this->createOrUpdateCarrier(
            self::CFG_CARRIER_ADDRESS,
            'Cargus - Delivery (Address)',
            $taxRulesGroupId,
            $zoneId
        );

        $ok = $ok && $this->createOrUpdateCarrier(
            self::CFG_CARRIER_SHIPGO,
            'Cargus - Ship&Go',
            $taxRulesGroupId,
            $zoneId
        );

        return $ok;
    }

    private function uninstallCarriers(): bool
    {
        $ok = true;

        foreach ([self::CFG_CARRIER_ADDRESS, self::CFG_CARRIER_SHIPGO] as $cfgKey) {
            $idCarrier = (int) Configuration::get($cfgKey);
            if ($idCarrier > 0) {
                $carrier = new Carrier($idCarrier);
                if (Validate::isLoadedObject($carrier)) {
                    // Soft delete to keep order history consistent.
                    $carrier->deleted = 1;
                    $ok = $ok && (bool) $carrier->update();
                }
            }
            Configuration::deleteByName($cfgKey);
        }

        return $ok;
    }

    private function createOrUpdateCarrier(string $cfgKey, string $name, int $taxRulesGroupId, int $zoneId): bool
    {
        $idCarrier = (int) Configuration::get($cfgKey);
        $carrier = $idCarrier > 0 ? new Carrier($idCarrier) : new Carrier();

        if ($idCarrier > 0 && !Validate::isLoadedObject($carrier)) {
            $carrier = new Carrier();
        }

        $carrier->name = $name;
        $carrier->active = 1;
        $carrier->deleted = 0;
        $carrier->shipping_handling = 0;
        $carrier->is_module = 1;
        $carrier->external_module_name = $this->name;
        $carrier->need_range = 1;
        $carrier->shipping_external = 1;
        $carrier->shipping_method = Carrier::SHIPPING_METHOD_WEIGHT;

        // Force tax rules group (if not found -> 0; admin must verify post-install)
        $carrier->id_tax_rules_group = $taxRulesGroupId;

        if (!Validate::isLoadedObject($carrier) || (int) $carrier->id <= 0) {
            if (!$carrier->add()) {
                return false;
            }
        } else {
            if (!$carrier->update()) {
                return false;
            }
        }

        Configuration::updateValue($cfgKey, (int) $carrier->id);

        // Ensure ranges and assignments
        $this->ensureCarrierRanges((int) $carrier->id);
        $this->forceCarrierZone((int) $carrier->id, $zoneId);
        $this->forceCarrierGroups((int) $carrier->id);

        return true;
    }

    private function ensureCarrierRanges(int $idCarrier): void
    {
        // Weight range 0-1000 kg
        $wr = new RangeWeight();
        $wr->id_carrier = $idCarrier;
        $wr->delimiter1 = 0;
        $wr->delimiter2 = 1000;
        $wr->add();

        // Price range 0-100000
        $pr = new RangePrice();
        $pr->id_carrier = $idCarrier;
        $pr->delimiter1 = 0;
        $pr->delimiter2 = 100000;
        $pr->add();
    }

    private function forceCarrierZone(int $idCarrier, int $zoneId): void
    {
        Db::getInstance()->delete('carrier_zone', 'id_carrier=' . (int) $idCarrier);

        if ($zoneId > 0) {
            Db::getInstance()->insert('carrier_zone', [
                'id_carrier' => (int) $idCarrier,
                'id_zone' => (int) $zoneId,
            ]);
        }
    }

    private function forceCarrierGroups(int $idCarrier): void
    {
        Db::getInstance()->delete('carrier_group', 'id_carrier=' . (int) $idCarrier);

        $groups = Group::getGroups($this->context->language->id);
        foreach ($groups as $group) {
            Db::getInstance()->insert('carrier_group', [
                'id_carrier' => (int) $idCarrier,
                'id_group' => (int) $group['id_group'],
            ]);
        }
    }

    /**
     * Resolve the tax rules group by keywords: "RO" and "Standard".
     * Do NOT rely on VAT percentage because VAT rates may change.
     *
     * If multiple groups match, we pick the first by id.
     * Admin should verify taxes post-install (see checklist).
     */
    private function resolveTaxRulesGroupId(): int
    {
        $k1 = pSQL(self::FORCED_TAX_RULE_KEYWORDS[0]);
        $k2 = pSQL(self::FORCED_TAX_RULE_KEYWORDS[1]);

        $id = (int) Db::getInstance()->getValue(
            'SELECT id_tax_rules_group
             FROM `' . _DB_PREFIX_ . 'tax_rules_group`
             WHERE name LIKE "%' . $k1 . '%"
               AND name LIKE "%' . $k2 . '%"
             ORDER BY id_tax_rules_group ASC'
        );

        return $id > 0 ? $id : 0;
    }

    /**
     * Resolve "Europe" zone id.
     * Requirement: carriers are assigned ONLY to Europe zone.
     */
    private function resolveEuropeZoneId(): int
    {
        $zoneId = (int) Db::getInstance()->getValue(
            'SELECT id_zone FROM `' . _DB_PREFIX_ . 'zone` WHERE name = "' . pSQL(self::FORCED_ZONE_NAME) . '"'
        );

        return $zoneId > 0 ? $zoneId : 0;
    }

    /**
     * Hook: add BO assets when needed (optional).
     */
    public function hookActionAdminControllerSetMedia($params): void
    {
        // Keep lightweight; BO UI is Symfony/Twig.
        // Example:
        // $this->context->controller->addCSS($this->_path . 'views/css/admin.css');
        // $this->context->controller->addJS($this->_path . 'views/js/admin.js');
    }

    /**
     * Hook: Admin Order panel placeholder (Phase 1).
     * In Phase 2+ we will show quota widget + Smart Split suggestion here.
     */
    public function hookDisplayAdminOrder($params)
    {
        return '';
    }

    /**
     * Hook: called when order is validated.
     * Used later to persist selected locker/PUDO choice from cart -> order.
     */
    public function hookActionValidateOrder($params): void
    {
        // Placeholder for future phases:
        // - copy cart PUDO selection to order
        // - queue AWB creation
        // - log actions
    }
}
