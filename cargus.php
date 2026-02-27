<?php
/**
 * @author    Quark
 * @copyright 2026 Quark
 * @license   Proprietary
 * @version   6.1.8
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/src/Helper/CargusV3Client.php';

class Cargus extends CarrierModule
{
    public function __construct()
    {
        $this->name = 'cargus';
        $this->tab = 'shipping_logistics';
        $this->version = '6.1.8';
        $this->author = 'Quark';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Cargus Courier Premium');
        $this->description = $this->l('Advanced shipping integration with Cargus API V3.');
        $this->ps_versions_compliancy = array('min' => '8.2.0', 'max' => '9.9.9');
    }

    public function install()
    {
        return parent::install() &&
            $this->installDb() &&
            $this->installTab('AdminCargusDebugger', 'Cargus Debugger', -1) &&
            $this->registerHook('actionAdminControllerSetMedia') &&
            $this->forceInitialSettings();
    }

    protected function installDb()
    {
        $q = "CREATE TABLE IF NOT EXISTS `"._DB_PREFIX_."cargus_agabaritic` (
            `id_rule` INT(11) NOT NULL AUTO_INCREMENT,
            `id_category` INT(11),
            `weight_threshold` DECIMAL(10,2),
            PRIMARY KEY (`id_rule`)
        ) ENGINE="._MYSQL_ENGINE_." DEFAULT CHARSET=utf8;";
        return Db::getInstance()->execute($q);
    }

    protected function forceInitialSettings()
    {
        Configuration::updateValue('CARGUS_HEAVY_THRESHOLD', 31);
        return true;
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitCargusConfig')) {
            // Salvare Tab 1
            Configuration::updateValue('CARGUS_API_URL', rtrim(Tools::getValue('CARGUS_API_URL'), '/') . '/');
            Configuration::updateValue('CARGUS_SUBSCRIPTION_KEY', Tools::getValue('CARGUS_SUBSCRIPTION_KEY'));
            Configuration::updateValue('CARGUS_USERNAME', Tools::getValue('CARGUS_USERNAME'));
            Configuration::updateValue('CARGUS_PASSWORD', Tools::getValue('CARGUS_PASSWORD'));

            // Salvare Tab 2 - Switch-uri și Câmpuri Preț
            Configuration::updateValue('CARGUS_PICKUP_LOCATION', Tools::getValue('CARGUS_PICKUP_LOCATION'));
            Configuration::updateValue('CARGUS_PRICE_PLAN', Tools::getValue('CARGUS_PRICE_PLAN'));
            Configuration::updateValue('CARGUS_DEFAULT_SERVICE', Tools::getValue('CARGUS_DEFAULT_SERVICE'));
            Configuration::updateValue('CARGUS_PAYER', Tools::getValue('CARGUS_PAYER'));
            Configuration::updateValue('CARGUS_COD_TYPE', Tools::getValue('CARGUS_COD_TYPE'));
            Configuration::updateValue('CARGUS_SHIPMENT_TYPE', Tools::getValue('CARGUS_SHIPMENT_TYPE'));
            Configuration::updateValue('CARGUS_OPEN_PACKAGE', (int)Tools::getValue('CARGUS_OPEN_PACKAGE'));
            Configuration::updateValue('CARGUS_SATURDAY_DELIVERY', (int)Tools::getValue('CARGUS_SATURDAY_DELIVERY'));
            Configuration::updateValue('CARGUS_INSURANCE', (int)Tools::getValue('CARGUS_INSURANCE'));
            Configuration::updateValue('CARGUS_BASIC_PRICE_STD', Tools::getValue('CARGUS_BASIC_PRICE_STD'));
            Configuration::updateValue('CARGUS_BASIC_PRICE_PUDO', Tools::getValue('CARGUS_BASIC_PRICE_PUDO'));
            Configuration::updateValue('CARGUS_EXTRA_KG_PRICE', Tools::getValue('CARGUS_EXTRA_KG_PRICE'));
            Configuration::updateValue('CARGUS_COD_FEE', Tools::getValue('CARGUS_COD_FEE'));
            Configuration::updateValue('CARGUS_HEAVY_THRESHOLD', (float)Tools::getValue('CARGUS_HEAVY_THRESHOLD'));

            $output .= $this->displayConfirmation($this->l('Settings saved.'));
        }

        // Liste Servicii Hardcodate (Anexa 15)
        $services = [
            ['id' => 1, 'name' => 'Standard'],
            ['id' => 34, 'name' => 'Economic standard'],
            ['id' => 35, 'name' => 'Standard Plus (31+)'],
            ['id' => 36, 'name' => 'Pallet Standard (50+)'],
            ['id' => 38, 'name' => 'Easy collect standard'],
            ['id' => 39, 'name' => 'Economic standard M'],
            ['id' => 40, 'name' => 'Economic Std. M Plus'],
            ['id' => 41, 'name' => 'Export Standard']
        ];

        $pickupLocations = [];
        $pricePlans = [];
        try {
            $client = new \Cargus\Helper\CargusV3Client();
            $resLoc = $client->request('PickupLocations');
            if (is_array($resLoc) && !isset($resLoc['error'])) $pickupLocations = $resLoc;
            
            $resPlans = $client->request('PriceTables');
            if (is_array($resPlans) && !isset($resPlans['error'])) $pricePlans = $resPlans;
        } catch (Exception $e) {}

        $this->context->smarty->assign([
            'cargus_api_url' => Configuration::get('CARGUS_API_URL'),
            'cargus_subscription_key' => Configuration::get('CARGUS_SUBSCRIPTION_KEY'),
            'cargus_username' => Configuration::get('CARGUS_USERNAME'),
            'cargus_password' => Configuration::get('CARGUS_PASSWORD'),
            'cargus_pickup_location' => Configuration::get('CARGUS_PICKUP_LOCATION'),
            'cargus_price_plan' => Configuration::get('CARGUS_PRICE_PLAN'),
            'cargus_default_service' => Configuration::get('CARGUS_DEFAULT_SERVICE'),
            'cargus_payer' => Configuration::get('CARGUS_PAYER') ?: 'Expeditor',
            'cargus_cod_type' => Configuration::get('CARGUS_COD_TYPE') ?: 'Numerar',
            'cargus_shipment_type' => Configuration::get('CARGUS_SHIPMENT_TYPE') ?: 'Plic',
            'cargus_open_package' => Configuration::get('CARGUS_OPEN_PACKAGE'),
            'cargus_saturday_delivery' => Configuration::get('CARGUS_SATURDAY_DELIVERY'),
            'cargus_insurance' => Configuration::get('CARGUS_INSURANCE'),
            'cargus_basic_price_std' => Configuration::get('CARGUS_BASIC_PRICE_STD'),
            'cargus_basic_price_pudo' => Configuration::get('CARGUS_BASIC_PRICE_PUDO'),
            'cargus_extra_kg_price' => Configuration::get('CARGUS_EXTRA_KG_PRICE'),
            'cargus_cod_fee' => Configuration::get('CARGUS_COD_FEE'),
            'cargus_heavy_threshold' => Configuration::get('CARGUS_HEAVY_THRESHOLD'),
            'pickup_locations' => $pickupLocations,
            'price_plans' => $pricePlans,
            'cargus_services' => $services,
            'cargus_ajax_link' => $this->context->link->getAdminLink('AdminCargusDebugger')
        ]);

        return $output . $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }

    private function installTab($class, $name, $parent)
    {
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = $class;
        $tab->name = array();
        foreach (Language::getLanguages(true) as $lang) $tab->name[$lang['id_lang']] = $name;
        $tab->id_parent = $idParent;
        $tab->module = $this->name;
        return $tab->add();
    }
}
