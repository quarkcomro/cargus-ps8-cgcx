<?php
/**
 * cargus.php
 * Version: 1.0.1
 * @author    Quark
 * @copyright 2026 Quark
 * @license   Proprietary
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Require Composer's autoloader if it exists
if (file_exists(dirname(__FILE__) . '/vendor/autoload.php')) {
    require_once dirname(__FILE__) . '/vendor/autoload.php';
}

use Cargus\Install\Installer;
use Cargus\Service\Config\ConfigurationService;

class Cargus extends CarrierModule
{
    public function __construct()
    {
        $this->name = 'cargus';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.1';
        $this->author = 'Quark';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Cargus Courier Premium');
        $this->description = $this->l('Advanced shipping integration with Cargus API V3. Supports Ship & Go and Heavy Cargo.');
        $this->ps_versions_compliancy = array('min' => '8.2.0', 'max' => '9.9.9');
    }

    /**
     * Module installation process.
     */
    public function install()
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

    /**
     * Module uninstallation process.
     */
    public function uninstall()
    {
        $installer = new Installer($this);
        $installer->uninstallDatabase();
        
        // Optional: Remove the warning flag
        Configuration::deleteByName('CARGUS_TAX_ZONE_WARNING');

        return parent::uninstall();
    }

    /**
     * Main configuration page in Back Office.
     */
    public function getContent()
    {
        $output = '';

        // Handle the dismissal of the post-install warning
        if (Tools::isSubmit('dismissCargusWarning')) {
            Configuration::updateValue('CARGUS_TAX_ZONE_WARNING', 0);
            $redirectUrl = $this->context->link->getAdminLink('AdminModules', true) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
            Tools::redirectAdmin($redirectUrl);
        }

        // Handle form submission
        if (Tools::isSubmit('submitCargusConfig')) {
            $configService = new ConfigurationService();
            $result = $configService->saveConfiguration(Tools::getAllValues());

            if ($result['success']) {
                $output .= $this->displayConfirmation($this->l('Settings successfully saved.'));
            } else {
                $output .= $this->displayError($result['message']);
            }
        }

        // Display the post-installation warning if the flag is active
        if (Configuration::get('CARGUS_TAX_ZONE_WARNING')) {
            $output .= $this->renderTaxZoneWarning();
        }

        // Output the instructions manual/tutorial before the form
        $output .= $this->renderInstructions();

        // Output the generated form
        return $output . $this->renderHelperForm();
    }

    /**
     * Renders the post-installation warning and checklist.
     *
     * @return string
     */
    private function renderTaxZoneWarning()
    {
        $dismissUrl = $this->context->link->getAdminLink('AdminModules', true) . '&configure=' . $this->name . '&dismissCargusWarning=1';

        return '
        <div class="alert alert-warning">
            <button type="button" class="close" data-dismiss="alert" onclick="window.location.href=\''.$dismissUrl.'\'">&times;</button>
            <h4><i class="icon-warning-sign"></i> ' . $this->l('Post-Installation Action Required') . '</h4>
            <p><strong>' . $this->l('Check tax rate and Zones / Verifică cota de TVA și Zonele de livrare.') . '</strong></p>
            <p>' . $this->l('The module attempted to auto-configure the standard tax rate and European delivery zone. Please verify these settings to avoid checkout errors.') . '</p>
            <br>
            <p><strong>' . $this->l('To Do Checklist:') . '</strong></p>
            <ul>
                <li>' . $this->l('Go to Shipping > Carriers and edit the Cargus carrier.') . '</li>
                <li>' . $this->l('Step 2 (Shipping locations and costs): Ensure the correct Tax rule (e.g., RO Standard Rate) is selected.') . '</li>
                <li>' . $this->l('Step 2: Ensure the correct geographical Zones (e.g., Europe) are checked.') . '</li>
                <li>' . $this->l('Step 3 (Size, weight, and group access): Verify maximum package dimensions if applicable.') . '</li>
            </ul>
            <br>
            <a href="'.$dismissUrl.'" class="btn btn-warning">' . $this->l('I have verified the settings (Dismiss)') . '</a>
        </div>';
    }

    /**
     * Renders a basic manual/tutorial for the module.
     *
     * @return string
     */
    private function renderInstructions()
    {
        return '
        <div class="panel">
            <div class="panel-heading"><i class="icon-info"></i> '.$this->l('Getting Started').'</div>
            <p>'.$this->l('Welcome to the Cargus API V3 integration. Please fill in your API credentials below.').'</p>
            <ul>
                <li>'.$this->l('Ensure you have your Subscription Key from the Cargus Developer Portal.').'</li>
                <li>'.$this->l('After saving the valid credentials, the Pickup Locations and Price Plans will become available.').'</li>
            </ul>
        </div>';
    }

    /**
     * Builds the configuration form using PrestaShop HelperForm.
     *
     * @return string HTML Form
     */
    private function renderHelperForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitCargusConfig';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        // Form structure definition
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('API Credentials'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('API URL'),
                        'name' => 'CARGUS_API_URL',
                        'desc' => $this->l('The base URL for Cargus API V3.'),
                        'placeholder' => 'https://urgentcargus.developer.azure-api.net/api/',
                        'required' => true,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Subscription Key'),
                        'name' => 'CARGUS_SUBSCRIPTION_KEY',
                        'desc' => $this->l('Your primary or secondary subscription key.'),
                        'placeholder' => 'e.g. 1234567890abcdef1234567890abcdef',
                        'required' => true,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Username'),
                        'name' => 'CARGUS_USERNAME',
                        'required' => true,
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->l('Password'),
                        'name' => 'CARGUS_PASSWORD',
                        'required' => true,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Heavy Cargo Threshold (kg)'),
                        'name' => 'CARGUS_HEAVY_THRESHOLD',
                        'desc' => $this->l('Any package exceeding this weight will trigger the Heavy Cargo logic (Agabaritic).'),
                        'placeholder' => '31',
                        'required' => true,
                    ]
                ],
                'submit' => [
                    'title' => $this->l('Save & Test Connection'),
                    'class' => 'btn btn-default pull-right'
                ]
            ],
        ];

        // Load current values
        $helper->fields_value['CARGUS_API_URL'] = Configuration::get('CARGUS_API_URL', null, null, null, 'https://urgentcargus.developer.azure-api.net/api/');
        $helper->fields_value['CARGUS_SUBSCRIPTION_KEY'] = Configuration::get('CARGUS_SUBSCRIPTION_KEY');
        $helper->fields_value['CARGUS_USERNAME'] = Configuration::get('CARGUS_USERNAME');
        $helper->fields_value['CARGUS_PASSWORD'] = Configuration::get('CARGUS_PASSWORD');
        $helper->fields_value['CARGUS_HEAVY_THRESHOLD'] = Configuration::get('CARGUS_HEAVY_THRESHOLD', null, null, null, 31);

        return $helper->generateForm([$fields_form]);
    }
}
