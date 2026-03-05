<?php
/**
 * src/Install/Installer.php
 * Version: 1.0.1
 * @author    Quark
 * @copyright 2026 Quark
 * @license   Proprietary
 */

namespace Cargus\Install;

use Db;
use Exception;
use Carrier;
use Group;
use Zone;
use Configuration;
use PrestaShopLogger;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Installer
{
    private $module;

    public function __construct($module)
    {
        $this->module = $module;
    }

    /**
     * Creates database tables with a fallback/rollback mechanism.
     *
     * @return bool
     */
    public function installDatabase(): bool
    {
        $db = Db::getInstance();
        $prefix = _DB_PREFIX_;
        $engine = _MYSQL_ENGINE_;

        $queries = [
            "CREATE TABLE IF NOT EXISTS `{$prefix}cargus_agabaritic` (
                `id_rule` INT(11) NOT NULL AUTO_INCREMENT,
                `id_category` INT(11),
                `weight_threshold` DECIMAL(10,2),
                PRIMARY KEY (`id_rule`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4;",
            
            "CREATE TABLE IF NOT EXISTS `{$prefix}cargus_pudo` (
                `id_pudo` VARCHAR(50) NOT NULL,
                `name` VARCHAR(255),
                `address` TEXT,
                `latitude` DECIMAL(10,8),
                `longitude` DECIMAL(11,8),
                PRIMARY KEY (`id_pudo`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4;",

            "CREATE TABLE IF NOT EXISTS `{$prefix}cargus_awb` (
                `id_cargus_awb` INT(11) NOT NULL AUTO_INCREMENT,
                `id_order` INT(11) NOT NULL,
                `bar_code` VARCHAR(50),
                `status` VARCHAR(50),
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_cargus_awb`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4;"
        ];

        try {
            foreach ($queries as $query) {
                if (!$db->execute($query)) {
                    throw new Exception("SQL execution failed: " . $query);
                }
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog('Cargus Install Error: ' . $e->getMessage(), 3);
            $this->uninstallDatabase(); // Rollback
            return false;
        }

        return true;
    }

    /**
     * Removes database tables cleanly.
     *
     * @return bool
     */
    public function uninstallDatabase(): bool
    {
        $db = Db::getInstance();
        $prefix = _DB_PREFIX_;

        $db->execute("DROP TABLE IF EXISTS `{$prefix}cargus_agabaritic`");
        $db->execute("DROP TABLE IF EXISTS `{$prefix}cargus_pudo`");
        $db->execute("DROP TABLE IF EXISTS `{$prefix}cargus_awb`");

        return true;
    }

    /**
     * Creates the Cargus carrier with strict business rules.
     *
     * @return bool
     */
    public function installCarrier(): bool
    {
        if (Configuration::get('CARGUS_CARRIER_ID')) {
            return true; // Already installed
        }

        $carrier = new Carrier();
        $carrier->name = 'Cargus';
        $carrier->id_tax_rules_group = $this->getStandardRoTaxId();
        $carrier->active = true;
        $carrier->deleted = false;
        $carrier->delay = [
            (int)Configuration::get('PS_LANG_DEFAULT') => 'Livrare rapidă (24-48h)'
        ];
        $carrier->shipping_handling = false;
        $carrier->range_behavior = 0;
        $carrier->is_module = true;
        $carrier->shipping_external = true;
        $carrier->external_module_name = $this->module->name;
        $carrier->need_range = true;

        if ($carrier->add()) {
            Configuration::updateValue('CARGUS_CARRIER_ID', $carrier->id);

            // Associate with all customer groups
            $groups = Group::getGroups(true);
            foreach ($groups as $group) {
                Db::getInstance()->insert('carrier_group', [
                    'id_carrier' => (int)$carrier->id,
                    'id_group' => (int)$group['id_group']
                ]);
            }

            // Associate ONLY with the Europe Zone (Strict rule)
            $id_zone_europe = Zone::getIdByName('Europe');
            if ($id_zone_europe) {
                $carrier->addZone($id_zone_europe);
            }

            // Copy carrier logo if exists
            $logoPath = dirname(__FILE__) . '/../../views/img/carrier.png';
            if (file_exists($logoPath)) {
                copy($logoPath, _PS_SHIP_IMG_DIR_ . '/' . (int)$carrier->id . '.jpg');
            }

            // Set the flag to trigger the warning in the Back Office
            Configuration::updateValue('CARGUS_TAX_ZONE_WARNING', 1);
            
            return true;
        }

        return false;
    }

    /**
     * Registers Admin tabs.
     *
     * @return bool
     */
    public function installTabs(): bool
    {
        // Placeholder for tab installation logic
        return true;
    }

    /**
     * Dynamically identifies the standard tax group ID for Romania.
     * Searches for the active rule for 'RO' with the highest percentage rate.
     *
     * @return int
     */
    private function getStandardRoTaxId(): int
    {
        $sql = "SELECT trg.id_tax_rules_group 
                FROM `" . _DB_PREFIX_ . "tax_rules_group` trg
                JOIN `" . _DB_PREFIX_ . "tax_rule` tr ON trg.id_tax_rules_group = tr.id_tax_rules_group
                JOIN `" . _DB_PREFIX_ . "country` c ON tr.id_country = c.id_country
                JOIN `" . _DB_PREFIX_ . "tax` t ON tr.id_tax = t.id_tax
                WHERE c.iso_code = 'RO' AND trg.active = 1
                ORDER BY t.rate DESC
                LIMIT 1";

        $id = (int)Db::getInstance()->getValue($sql);
        
        if (!$id) {
            PrestaShopLogger::addLog('Cargus: Nu s-a putut detecta automat grupul de taxe standard pentru România la instalare.', 2);
        }
        
        return $id;
    }
}
