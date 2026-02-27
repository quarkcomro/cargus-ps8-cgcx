<?php
/**
 * cargus.php
 *
 * Main module file for Cargus PrestaShop 9 Integration.
 * Handles installation, hooks, carrier creation, and shipping cost calculation.
 *
 * @author    Interdisciplinary Team
 * @copyright 2024 Cargus
 * @license   Commercial
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

// We require the services manually if Composer autoload is not used/dumped yet
require_once dirname(__FILE__) . '/src/Service/Calculator/CargusPricingService.php';

class Cargus extends CarrierModule
{
    public int $id_carrier;

    /**
     * @var array List of configuration keys used by the module
     */
    private const CONFIG_KEYS = [
        'CARGUS_API_URL',
        'CARGUS_API_KEY',
        'CARGUS_API_USER',
        'CARGUS_API_PASS',
        'CARGUS_BASE_PRICE_STD',
        'CARGUS_BASE_PRICE_PUDO',
        'CARGUS_EXTRA_KG_PRICE',
        'CARGUS_OVERSIZED_TAX',
        'CARGUS_COD_FEE',
        'CARGUS_CALCULATION_MODE', // 'LOCAL' or 'API'
        'CARGUS_STANDARD_REFERENCE',
        'CARGUS_SHIP_GO_REFERENCE',
        'CARGUS_CRON_TOKEN',
        'CARGUS_LAST_PUDO_SYNC'
    ];

    public function __construct()
    {
        $this->name = 'cargus';
        $this->tab = 'shipping_logistics';
        $this->version = '6.0.0';
        $this->author = 'Interdisciplinary Team';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '_PS_VERSION_'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Cargus Courier', [], 'Modules.Cargus.Admin');
        $this->description = $this->trans('Advanced integration with Ship & Go Lockers and Hybrid Pricing Engine.', [], 'Modules.Cargus.Admin');

        $this->confirmUninstall = $this->trans('Are you sure you want to uninstall? All settings and cached lockers will be deleted.', [], 'Modules.Cargus.Admin');
    }

    /**
     * Module Installation
     */
    public function install(): bool
    {
        if (extension_loaded('curl') == false) {
            $this->_errors[] = $this->trans('You have to enable the cURL extension on your server to install this module', [], 'Modules.Cargus.Admin');
            return false;
        }

        // generate a secure random token for Cron Jobs
        Configuration::updateValue('CARGUS_CRON_TOKEN', Tools::passwdGen(16));
        
        // Set default calculation mode to Local for speed
        Configuration::updateValue('CARGUS_CALCULATION_MODE', 'LOCAL');

        return parent::install()
            && $this->installDb()
            && $this->installCarriers()
            && $this->registerHook('actionCarrierUpdate')
            && $this->registerHook('displayCarrierExtraContent') // UI for Locker selection
            && $this->registerHook('actionFrontControllerSetMedia') // JS/CSS injection
            && $this->registerHook('displayBeforeCarrier') // Optional: alerts
            && $this->registerHook('displayAdminOrder'); // For AWB generation button
    }

    /**
     * Module Uninstallation
     */
    public function uninstall(): bool
    {
        return parent::uninstall()
            && $this->uninstallDb()
            && $this->uninstallCarriers()
            && $this->deleteConfiguration();
    }

    /**
     * Database Installation
     */
    private function installDb(): bool
    {
        $sqlFile = dirname(__FILE__) . '/sql/install.sql';
        if (!file_exists($sqlFile)) {
            return false;
        }

        $sql = file_get_contents($sqlFile);
        if (!$sql) {
            return false;
        }

        $sql = str_replace(['PREFIX_', 'ENGINE=InnoDB'], [_DB_PREFIX_, 'ENGINE=' . _MYSQL_ENGINE_], $sql);
        $queries = preg_split('/;\s*[\r\n]+/', $sql);

        foreach ($queries as $query) {
            if (!empty(trim($query))) {
                if (!Db::getInstance()->execute($query)) {
                    return false;
                }
            }
        }
        return true;
    }

    private function uninstallDb(): bool
    {
        $tables = ['cargus_pudo', 'cargus_order_pudo', 'cargus_geo_cache'];
        foreach ($tables as $table) {
            Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $table . '`');
        }
        return true;
    }

    private function deleteConfiguration(): bool
    {
        foreach (self::CONFIG_KEYS as $key) {
            Configuration::deleteByName($key);
        }
        return true;
    }

    /**
     * Create default carriers (Standard & Ship & Go)
     */
    private function installCarriers(): bool
    {
        // 1. Create Standard Carrier
        $carrierStd = $this->createCarrierObject('Cargus Courier (Delivery to Address)', 'Fast delivery to your door.');
        Configuration::updateValue('CARGUS_STANDARD_REFERENCE', (int)$carrierStd->id);

        // 2. Create Ship & Go Carrier
        $carrierPudo = $this->createCarrierObject('Cargus Ship & Go (Locker/Point)', 'Pick up from a nearby location.');
        Configuration::updateValue('CARGUS_SHIP_GO_REFERENCE', (int)$carrierPudo->id);

        return true;
    }

    private function createCarrierObject(string $name, string $delay): Carrier
    {
        $carrier = new Carrier();
        $carrier->name = $name;
        $carrier->id_tax_rules_group = 0;
        $carrier->url = 'https://www.cargus.ro/find-shipment/?tracking=@'; // Tracking URL
        $carrier->active = true;
        $carrier->deleted = 0;
        $carrier->delay[Configuration::get('PS_LANG_DEFAULT')] = $delay;
        $carrier->shipping_handling = false;
        $carrier->range_behavior = 0;
        $carrier->is_module = true;
        $carrier->shipping_external = true;
        $carrier->external_module_name = $this->name;
        $carrier->need_range = true;

        if ($carrier->add()) {
            // Add groups access
            $groups = Group::getGroups(true);
            foreach ($groups as $group) {
                Db::getInstance()->insert('carrier_group', [
                    'id_carrier' => (int)$carrier->id,
                    'id_group' => (int)$group['id_group']
                ]);
            }
            
            // Add dummy range price to ensure it appears in checkout
            $rangePrice = new RangePrice();
            $rangePrice->id_carrier = $carrier->id;
            $rangePrice->delimiter1 = '0';
            $rangePrice->delimiter2 = '10000';
            $rangePrice->add();

            $rangeWeight = new RangeWeight();
            $rangeWeight->id_carrier = $carrier->id;
            $rangeWeight->delimiter1 = '0';
            $rangeWeight->delimiter2 = '10000';
            $rangeWeight->add();
            
            // Set Zone (Europe or similar) - Assuming Zone 1 exists
            $zones = Zone::getZones(true);
            foreach ($zones as $zone) {
                $carrier->addZone((int)$zone['id_zone']);
            }

            // Copy logo
            copy(dirname(__FILE__) . '/logo.png', _PS_SHIP_IMG_DIR_ . '/' . (int)$carrier->id . '.jpg');
            
            return $carrier;
        }

        throw new Exception('Could not create Carrier: ' . $name);
    }

    private function uninstallCarriers(): bool
    {
        $stdRef = Configuration::get('CARGUS_STANDARD_REFERENCE');
        $pudoRef = Configuration::get('CARGUS_SHIP_GO_REFERENCE');
        
        $this->deleteCarrierByReference((int)$stdRef);
        $this->deleteCarrierByReference((int)$pudoRef);
        
        return true;
    }

    private function deleteCarrierByReference(int $id_reference): void
    {
        if ($id_reference) {
            $carrier = Carrier::getCarrierByReference($id_reference);
            if ($carrier) {
                $carrier->deleted = true;
                $carrier->update();
            }
        }
    }

    /**
     * Update configuration if Carrier ID changes (e.g. edited in BO)
     */
    public function hookActionCarrierUpdate($params): void
    {
        $id_carrier_old = (int)$params['id_carrier'];
        $id_carrier_new = (int)$params['carrier']->id;

        $stdRef = (int)Configuration::get('CARGUS_STANDARD_REFERENCE');
        $pudoRef = (int)Configuration::get('CARGUS_SHIP_GO_REFERENCE');
        
        // We compare loaded objects reference to stored reference
        $oldCarrier = new Carrier($id_carrier_old);
        
        if ((int)$oldCarrier->id_reference === $stdRef) {
            Configuration::updateValue('CARGUS_STANDARD_REFERENCE', $id_carrier_new);
        }
        
        if ((int)$oldCarrier->id_reference === $pudoRef) {
            Configuration::updateValue('CARGUS_SHIP_GO_REFERENCE', $id_carrier_new);
        }
    }

    /**
     * Calculation Engine
     */
    public function getOrderShippingCost($params, $shipping_cost)
    {
        // Instantiate our Service
        $calculator = new \Cargus\Service\Calculator\CargusPricingService();
        
        // Calculate
        return $calculator->calculateShippingCost($params, (float)$shipping_cost, (int)$this->id_carrier);
    }

    public function getOrderShippingCostExternal($params)
    {
        return $this->getOrderShippingCost($params, 0.0);
    }

    /**
     * Front Office Assets
     */
    public function hookActionFrontControllerSetMedia(): void
    {
        if ($this->context->controller->php_self === 'order') {
            $this->context->controller->registerJavascript(
                'modules-cargus-checkout',
                'modules/' . $this->name . '/views/js/cargus_checkout.js',
                ['position' => 'bottom', 'priority' => 200]
            );
            $this->context->controller->registerStylesheet(
                'modules-cargus-style',
                'modules/' . $this->name . '/views/css/cargus_style.css',
                ['media' => 'all', 'priority' => 200]
            );
            
            // Add Leaflet JS/CSS if map is needed (could be conditionally loaded)
            $this->context->controller->registerStylesheet('leaflet-css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', ['server' => 'remote']);
            $this->context->controller->registerJavascript('leaflet-js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', ['server' => 'remote', 'position' => 'bottom', 'priority' => 190]);
        }
    }

    /**
     * Display Ship & Go Selector in Checkout
     */
    public function hookDisplayCarrierExtraContent($params)
    {
        $carrier = $params['carrier'];
        
        // Only display for Ship & Go Carrier
        // Use Reference to check, as ID changes on update
        if ($carrier['id_reference'] == Configuration::get('CARGUS_SHIP_GO_REFERENCE')) {
            
            $this->context->smarty->assign([
                'cargus_ship_go_id' => $carrier['id'], // Pass current ID to JS
                'cargus_ajax_url' => $this->context->link->getModuleLink('cargus', 'ajax', ['token' => Tools::getToken(false)]),
            ]);

            return [
                $this->display(__FILE__, 'views/templates/hook/display_carrier_extra_content.tpl')
            ];
        }

        return [];
    }

    /**
     * Back Office Configuration Page
     */
    public function getContent(): string
    {
        $output = '';

        // Handle Form Submission
        if (Tools::isSubmit('submitCargusConfig')) {
            foreach (self::CONFIG_KEYS as $key) {
                $value = Tools::getValue($key);
                Configuration::updateValue($key, $value);
            }
            $output .= $this->displayConfirmation($this->trans('Settings updated successfully', [], 'Admin.Notifications.Success'));
        }

        return $output . $this->renderForm();
    }

    /**
     * Render the Configuration Form (Simplified for brevity, but functional)
     */
    private function renderForm(): string
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitCargusConfig';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        // Load current values
        foreach (self::CONFIG_KEYS as $key) {
            $helper->fields_value[$key] = Configuration::get($key);
        }
        
        // Define Form Fields (Tabs structure would go here)
        // For this file, we define a basic structure. 
        // In a real scenario, this array is large.
        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Cargus Settings', [], 'Modules.Cargus.Admin'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('API Key (Subscription Key)', [], 'Modules.Cargus.Admin'),
                        'name' => 'CARGUS_API_KEY',
                        'required' => true,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('API User', [], 'Modules.Cargus.Admin'),
                        'name' => 'CARGUS_API_USER',
                        'required' => true,
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->trans('API Password', [], 'Modules.Cargus.Admin'),
                        'name' => 'CARGUS_API_PASS',
                        'required' => true,
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->trans('Pricing Mode', [], 'Modules.Cargus.Admin'),
                        'name' => 'CARGUS_CALCULATION_MODE',
                        'options' => [
                            'query' => [
                                ['id' => 'LOCAL', 'name' => 'Local Hybrid (Recommended)'],
                                ['id' => 'API', 'name' => 'Real-time API'],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ]
                    ],
                    // Pricing Fields
                    [
                        'type' => 'text',
                        'label' => $this->trans('Standard Delivery Base Price (RON)', [], 'Modules.Cargus.Admin'),
                        'name' => 'CARGUS_BASE_PRICE_STD',
                        'class' => 'fixed-width-lg',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Ship & Go Base Price (RON)', [], 'Modules.Cargus.Admin'),
                        'name' => 'CARGUS_BASE_PRICE_PUDO',
                        'class' => 'fixed-width-lg',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Oversized/Agabaritic Tax (RON)', [], 'Modules.Cargus.Admin'),
                        'desc' => $this->trans('Fixed tax added if product has additional shipping costs.', [], 'Modules.Cargus.Admin'),
                        'name' => 'CARGUS_OVERSIZED_TAX',
                        'class' => 'fixed-width-lg',
                    ],
                    [
                        'type' => 'html',
                        'name' => 'cron_info',
                        'html_content' => '<div class="alert alert-info"><strong>Cron Job URL for PUDO Sync:</strong><br>' . 
                                          $this->context->link->getModuleLink('cargus', 'cron', ['token' => Configuration::get('CARGUS_CRON_TOKEN')]) . 
                                          '</div>'
                    ]
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Admin.Actions'),
                ],
            ],
        ];

        return $helper->generateForm([$fieldsForm]);
    }
}