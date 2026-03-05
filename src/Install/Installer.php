<?php
declare(strict_types=1);

/**
 * src/Install/Installer.php
 * Version: 1.0.1
 * @author    Quark
 * @copyright 2026 Quark
 * @license   Proprietary
 */

namespace Cargus\Install;

use Db;
use Carrier;
use Configuration;
use Language;
use Group;
use Zone;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Installer
{
    /**
     * @var \Module
     */
    private $module;

    /**
     * @param \Module $module
     */
    public function __construct($module)
    {
        $this->module = $module;
    }

    /**
     * Creates required database tables
     *
     * @return bool
     */
    public function installDatabase(): bool
    {
        $queries = [
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'cargus_awb` (
                `id_cargus_awb` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_order` INT(11) UNSIGNED NOT NULL,
                `bar_code` VARCHAR(255) DEFAULT NULL,
                `return_awb` VARCHAR(255) DEFAULT NULL,
                `status` VARCHAR(50) DEFAULT NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY  (`id_cargus_awb`),
                INDEX (`id_order`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;',
            
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'cargus_pudo` (
                `id_cargus_pudo` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_cart` INT(11) UNSIGNED NOT NULL,
                `pudo_id` VARCHAR(50) NOT NULL,
                `pudo_name` VARCHAR(255) NOT NULL,
                `pudo_address` VARCHAR(255) NOT NULL,
                PRIMARY KEY  (`id_cargus_pudo`),
                INDEX (`id_cart`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;'
        ];

        foreach ($queries as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Drops module database tables
     *
     * @return bool
     */
    public function uninstallDatabase(): bool
    {
        $queries = [
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'cargus_awb`',
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'cargus_pudo`'
        ];

        foreach ($queries as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Installs and configures the Carrier
     *
     * @return bool
     */
    public function installCarrier(): bool
    {
        if (Configuration::get('CARGUS_CARRIER_ID')) {
            return true;
        }

        $carrier = new Carrier();
        $carrier->name = 'Cargus';
        $carrier->is_module = true;
        $carrier->active = 1;
        $carrier->range_behavior = 1;
        $carrier->need_range = 1;
        $carrier->shipping_external = true;
        $carrier->external_module_name = $this->module->name;
        $carrier->shipping_method = Carrier::SHIPPING_METHOD_WEIGHT;

        foreach (Language::getLanguages(true) as $language) {
            $carrier->delay[(int)$language['id_lang']] = 'Livrare în 24-48 ore / Delivery in 24-48 hours';
        }

        if ($carrier->add()) {
            $groups = Group::getGroups(true);
            foreach ($groups as $group) {
                Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'carrier_group` (id_carrier, id_group) VALUES (' . (int)$carrier->id . ', ' . (int)$group['id_group'] . ')');
            }

            $rangePrice = new \RangePrice();
            $rangePrice->id_carrier = $carrier->id;
            $rangePrice->delimiter1 = '0';
            $rangePrice->delimiter2 = '10000';
            $rangePrice->add();

            $rangeWeight = new \RangeWeight();
            $rangeWeight->id_carrier = $carrier->id;
            $rangeWeight->delimiter1 = '0';
            $rangeWeight->delimiter2 = '10000';
            $rangeWeight->add();

            $zones = Zone::getZones(true);
            foreach ($zones as $zone) {
                if ($zone['name'] === 'Europe') {
                    Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'carrier_zone` (id_carrier, id_zone) VALUES (' . (int)$carrier->id . ', ' . (int)$zone['id_zone'] . ')');
                    Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'delivery` (id_carrier, id_range_price, id_range_weight, id_zone, price) VALUES (' . (int)$carrier->id . ', ' . (int)$rangePrice->id . ', NULL, ' . (int)$zone['id_zone'] . ', 0)');
                    Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'delivery` (id_carrier, id_range_price, id_range_weight, id_zone, price) VALUES (' . (int)$carrier->id . ', NULL, ' . (int)$rangeWeight->id . ', ' . (int)$zone['id_zone'] . ', 0)');
                }
            }

            @copy(dirname(__FILE__) . '/../../../logo.png', _PS_SHIP_IMG_DIR_ . '/' . (int)$carrier->id . '.jpg');

            Configuration::updateValue('CARGUS_CARRIER_ID', (int)$carrier->id);
            Configuration::updateValue('CARGUS_CARRIER_REFERENCE', (int)$carrier->id);
            
            // Extragere ID taxă (corectat: fără LIMIT 1)
            $sql = 'SELECT id_tax_rules_group FROM `' . _DB_PREFIX_ . 'tax_rules_group` WHERE active = 1 AND deleted = 0';
            $id_tax_rules_group = (int) Db::getInstance()->getValue($sql);
            
            if ($id_tax_rules_group > 0) {
                $carrier->setTaxRulesGroup($id_tax_rules_group);
            } else {
                Configuration::updateValue('CARGUS_TAX_ZONE_WARNING', 1);
            }

            return true;
        }

        return false;
    }

    /**
     * Registers Back Office tabs (To be implemented with controllers)
     *
     * @return bool
     */
    public function installTabs(): bool
    {
        return true;
    }
}
