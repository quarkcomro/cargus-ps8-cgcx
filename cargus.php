<?php
declare(strict_types=1);

/**
 * cargus.php
 * Version: 1.0.5
 * @author    Quark
 * @copyright 2026 Quark
 * @license   Proprietary
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Autoloader Hibrid (Regulă Fixă)
if (file_exists(dirname(__FILE__) . '/vendor/autoload.php')) {
    require_once dirname(__FILE__) . '/vendor/autoload.php';
} else {
    spl_autoload_register(function ($class) {
        $prefix = 'Cargus\\';
        $base_dir = dirname(__FILE__) . '/src/';
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }
        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });
}

use Cargus\Install\Installer;
use Cargus\Service\Config\ConfigurationService;
use Cargus\Helper\CargusV3Client;

class Cargus extends CarrierModule
{
    public function __construct()
    {
        $this->name = 'cargus';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.5';
        $this->author = 'Quark';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Cargus Courier Premium');
        $this->description = $this->l('Advanced shipping integration with Cargus API V3. Supports Ship & Go and Heavy Cargo.');
        $this->ps_versions_compliancy = ['min' => '8.2.0', 'max' => '9.9.9'];
    }

    public function install(): bool
    {
        if (!parent::install()) {
            return false;
        }

        $installer = new Installer($this);
        
        return $installer->installDatabase() && 
               $installer->installCarrier() &&
               $installer->installTabs() &&
               $this->registerHook('actionAdminControllerSetMedia') &&
               $this->registerHook('displayCarrierExtraContent');
    }

    public function uninstall(): bool
    {
        $installer = new Installer($this);
        $installer->uninstallDatabase();
        Configuration::deleteByName('CARGUS_TAX_ZONE_WARNING');

        return parent::uninstall();
    }

    public function getContent(): string
    {
        $output = '';

        if (\Tools::isSubmit('dismissCargusWarning')) {
            Configuration::updateValue('CARGUS_TAX_ZONE_WARNING', 0);
            $redirectUrl = $this->context->link->getAdminLink('AdminModules', true) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
            \Tools::redirectAdmin($redirectUrl);
        }

        if (\Tools::isSubmit('submitCargusConfig')) {
            $configService = new ConfigurationService();
            $result = $configService->saveConfiguration(\Tools::getAllValues());

            if (isset($result['success']) && $result['success']) {
                $output .= $this->displayConfirmation($this->l('Settings successfully saved.'));
            } else {
                $errorMessage = isset($result['message']) ? $result['message'] : 'Unknown error occurred.';
                $output .= $this->displayError($errorMessage);
            }
        }

        if (Configuration::get('CARGUS_TAX_ZONE_WARNING')) {
            $output .= $this->renderTaxZoneWarning();
        }

        return $output . $this->renderHelperForm();
    }

    private function renderTaxZoneWarning(): string
    {
        $dismissUrl = $this->context->link->getAdminLink('AdminModules', true) . '&configure=' . $this->name . '&dismissCargusWarning=1';

        return '
        <div class="alert alert-warning">
            <button type="button" class="close" data-dismiss="alert" onclick="window.location.href=\''.$dismissUrl.'\'">&times;</button>
            <h4><i class="icon-warning-sign"></i> ' . $this->l('Post-Installation Action Required') . '</h4>
            <p><strong>' . $this->l('Check tax rate and Zones / Verifică cota de TVA și Zonele de livrare.') . '</strong></p>
            <a href="'.$dismissUrl.'" class="btn btn-warning">' . $this->l('I have verified the settings (Dismiss)') . '</a>
        </div>';
    }

    private function getPickupLocationsForDropdown(): array
    {
        $options = [
            ['id_location' => '', 'name' => $this->l('-- Select default pickup location --')]
        ];

        try {
            $client = new CargusV3Client();
            $response = $client->request('PickupLocations', 'GET');
            
            if (is_array($response) && !isset($response['error'])) {
                foreach ($response as $loc) {
                    $options[] = [
                        'id_location' => isset($loc['LocationId']) ? $loc['LocationId'] : '',
                        'name' => (isset($loc['Name']) ? $loc['Name'] : '') . ' - ' . (isset($loc['AddressText']) ? $loc['AddressText'] : '')
                    ];
                }
            }
        } catch (\Exception $e) {
            // Silently ignore if credentials are wrong or missing
        }

        return $options;
    }

    private function renderHelperForm(): string
    {
        $helper = new \HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = (int)$this->context->language->id;
        $helper->allow_employee_form_lang = (int)Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitCargusConfig';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = \Tools::getAdminTokenLite('AdminModules');

        $pickupLocations = $this->getPickupLocationsForDropdown();

        $fields_form = [
            [
                'form' => [
                    'legend' => ['title' => $this->l('1. API Credentials'), 'icon' => 'icon-cogs'],
                    'input' => [
                        ['type' => 'text', 'label' => $this->l('API URL'), 'name' => 'CARGUS_API_URL', 'required' => true],
                        ['type' => 'text', 'label' => $this->l('Subscription Key'), 'name' => 'CARGUS_SUBSCRIPTION_KEY', 'required' => true],
                        ['type' => 'text', 'label' => $this->l('Username'), 'name' => 'CARGUS_USERNAME', 'required' => true],
                        ['type' => 'password', 'label' => $this->l('Password'), 'name' => 'CARGUS_PASSWORD', 'required' => true],
                        [
                            'type' => 'select',
                            'label' => $this->l('Default Pickup Location'),
                            'name' => 'CARGUS_PICKUP_LOCATION',
                            'desc' => $this->l('Select the location where the courier will pick up the parcels.'),
                            'options' => [
                                'query' => $pickupLocations,
                                'id' => 'id_location',
                                'name' => 'name'
                            ]
                        ]
                    ],
                    'submit' => ['title' => $this->l('Save Configuration'), 'class' => 'btn btn-default pull-right']
                ]
            ],
            [
                'form' => [
                    'legend' => ['title' => $this->l('2. Pricing & Logistics'), 'icon' => 'icon-money'],
                    'input' => [
                        [
                            'type' => 'radio',
                            'label' => $this->l('Calculation Mode'),
                            'name' => 'CARGUS_CALC_MODE',
                            'desc' => $this->l('Local mode computes instantly using the rates below. API mode queries Cargus servers at checkout.'),
                            'values' => [
                                ['id' => 'mode_local', 'value' => 'local', 'label' => $this->l('Local Calculation (Fast)')],
                                ['id' => 'mode_api', 'value' => 'api', 'label' => $this->l('API Calculation (Precise)')]
                            ]
                        ],
                        ['type' => 'text', 'label' => $this->l('Base Price Standard (Lei)'), 'name' => 'CARGUS_PRICE_BASE', 'class' => 'col-lg-2'],
                        ['type' => 'text', 'label' => $this->l('Base Price Ship&Go (Lei)'), 'name' => 'CARGUS_PRICE_PUDO', 'class' => 'col-lg-2'],
                        ['type' => 'text', 'label' => $this->l('Extra Cost per Kg (Lei)'), 'name' => 'CARGUS_PRICE_KG', 'class' => 'col-lg-2'],
                        ['type' => 'text', 'label' => $this->l('Fixed Fee Heavy Cargo (Lei)'), 'name' => 'CARGUS_PRICE_HEAVY_OFFSET', 'class' => 'col-lg-2', 'desc' => $this->l('Added to the cost if weight exceeds the threshold.')],
                        ['type' => 'text', 'label' => $this->l('Heavy Cargo Threshold (kg)'), 'name' => 'CARGUS_HEAVY_THRESHOLD', 'class' => 'col-lg-2', 'placeholder' => '31'],
                    ],
                    'submit' => ['title' => $this->l('Save Configuration'), 'class' => 'btn btn-default pull-right']
                ]
            ],
            [
                'form' => [
                    'legend' => ['title' => $this->l('3. Shipping Defaults & Client Options'), 'icon' => 'icon-truck'],
                    'input' => [
                        [
                            'type' => 'select',
                            'label' => $this->l('Default Payer'),
                            'name' => 'CARGUS_DEFAULT_PAYER',
                            'options' => [
                                'query' => [
                                    ['id' => 1, 'name' => $this->l('Sender (Expeditor)')],
                                    ['id' => 2, 'name' => $this->l('Recipient (Destinatar)')]
                                ],
                                'id' => 'id',
                                'name' => 'name'
                            ]
                        ],
                        [
                            'type' => 'select',
                            'label' => $this->l('Default COD Type'),
                            'name' => 'CARGUS_DEFAULT_COD',
                            'options' => [
                                'query' => [
                                    ['id' => 'Cash', 'name' => $this->l('Cash')],
                                    ['id' => 'Account', 'name' => $this->l('Bank Account (Cont Colector)')]
                                ],
                                'id' => 'id',
                                'name' => 'name'
                            ]
                        ],
                        ['type' => 'switch', 'label' => $this->l('Allow Saturday Delivery'), 'name' => 'CARGUS_SATURDAY_DELIVERY', 'is_bool' => true, 'values' => [['id' => 'active_on', 'value' => 1, 'label' => 'Yes'], ['id' => 'active_off', 'value' => 0, 'label' => 'No']]],
                        ['type' => 'switch', 'label' => $this->l('Allow Open Package'), 'name' => 'CARGUS_OPEN_PACKAGE', 'is_bool' => true, 'values' => [['id' => 'active_on', 'value' => 1, 'label' => 'Yes'], ['id' => 'active_off', 'value' => 0, 'label' => 'No']]],
                        ['type' => 'switch', 'label' => $this->l('Allow Shipment Insurance'), 'name' => 'CARGUS_INSURANCE', 'is_bool' => true, 'values' => [['id' => 'active_on', 'value' => 1, 'label' => 'Yes'], ['id' => 'active_off', 'value' => 0, 'label' => 'No']]],
                    ],
                    'submit' => ['title' => $this->l('Save Configuration'), 'class' => 'btn btn-default pull-right']
                ]
            ]
        ];

        $keys = [
            'CARGUS_API_URL', 'CARGUS_SUBSCRIPTION_KEY', 'CARGUS_USERNAME', 'CARGUS_PASSWORD', 'CARGUS_PICKUP_LOCATION',
            'CARGUS_CALC_MODE', 'CARGUS_PRICE_BASE', 'CARGUS_PRICE_PUDO', 'CARGUS_PRICE_KG', 'CARGUS_PRICE_HEAVY_OFFSET',
            'CARGUS_HEAVY_THRESHOLD', 'CARGUS_DEFAULT_PAYER', 'CARGUS_DEFAULT_COD', 
            'CARGUS_SATURDAY_DELIVERY', 'CARGUS_OPEN_PACKAGE', 'CARGUS_INSURANCE'
        ];

        foreach ($keys as $key) {
            $helper->fields_value[$key] = Configuration::get($key);
        }

        if (!$helper->fields_value['CARGUS_CALC_MODE']) {
            $helper->fields_value['CARGUS_CALC_MODE'] = 'local';
        }
        if (!$helper->fields_value['CARGUS_HEAVY_THRESHOLD']) {
            $helper->fields_value['CARGUS_HEAVY_THRESHOLD'] = 31;
        }
        if (!$helper->fields_value['CARGUS_DEFAULT_PAYER']) {
            $helper->fields_value['CARGUS_DEFAULT_PAYER'] = 1;
        }

        return $helper->generateForm($fields_form);
    }

    /**
     * Required by CarrierModuleCore
     * Calculates the shipping cost for the current cart.
     *
     * @param Cart $params
     * @param float $shipping_cost
     * @return float|bool Shipping cost or false if not available
     */
    public function getOrderShippingCost($params, $shipping_cost)
    {
        return (float)$shipping_cost; 
    }

    /**
     * Required by CarrierModuleCore
     *
     * @param Cart $params
     * @return float|bool
     */
    public function getOrderShippingCostExternal($params)
    {
        return $this->getOrderShippingCost($params, 0.0);
    }
}
