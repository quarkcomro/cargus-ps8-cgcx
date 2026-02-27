<?php
/**
 * cargus.php - v6.0.8
 * Include toate setările comerciale din PS 1.7 și sistemul de Tab-uri cu Debugger.
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/src/Service/Calculator/CargusPricingService.php';
require_once dirname(__FILE__) . '/src/Service/API/AccountService.php';

class Cargus extends CarrierModule
{
    private const CONFIG_FIELDS = [
        'CARGUS_API_URL', 'CARGUS_API_KEY', 'CARGUS_API_USER', 'CARGUS_API_PASS',
        'CARGUS_SENDER_LOCATION_ID', 'CARGUS_PRICE_TABLE_ID', 'CARGUS_SERVICE_ID',
        'CARGUS_PAYER_TYPE', 'CARGUS_PARCEL_TYPE', 'CARGUS_COD_TYPE',
        'CARGUS_INSURANCE', 'CARGUS_OPEN_PKG', 'CARGUS_SATURDAY',
        'CARGUS_PRE10', 'CARGUS_PRE12', 'CARGUS_BASE_PRICE_STD', 'CARGUS_BASE_PRICE_PUDO'
    ];

    public function __construct()
    {
        $this->name = 'cargus';
        $this->tab = 'shipping_logistics';
        $this->version = '6.0.8';
        $this->author = 'Cargus';
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = 'Cargus Courier Premium';
    }

    public function hookActionAdminControllerSetMedia(): void
    {
        Media::addJsDef([
            'cargus_ajax_url' => $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name])
        ]);
        $this->context->controller->addJS($this->_path . 'views/js/cargus_admin.js');
    }

    public function ajaxProcessTestEndpoint(): void
    {
        $endpoint = Tools::getValue('endpoint');
        $service = new \Cargus\Service\API\AccountService();
        $data = [];

        try {
            if ($endpoint === 'PickupLocations') $data = $service->getSenderLocations();
            if ($endpoint === 'PriceTables') $data = $service->getPriceTables();
            if ($endpoint === 'Services') $data = $service->getServices();
            
            die(json_encode(['success' => !empty($data), 'data' => $data]));
        } catch (Exception $e) {
            die(json_encode(['success' => false, 'raw' => $e->getMessage()]));
        }
    }

    public function getContent(): string
    {
        if (Tools::getValue('ajax')) {
            $this->ajaxProcessTestEndpoint();
        }

        $output = '';
        if (Tools::isSubmit('submitCargusConfig')) {
            foreach (self::CONFIG_FIELDS as $f) {
                if ($f === 'CARGUS_API_PASS' && empty(Tools::getValue($f))) continue;
                Configuration::updateValue($f, Tools::getValue($f));
            }
            $output .= $this->displayConfirmation('Configurația a fost salvată cu succes.');
        }

        return $output . $this->renderConfigForm();
    }

    public function renderConfigForm(): string
    {
        $service = new \Cargus\Service\API\AccountService();
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name;
        $helper->submit_action = 'submitCargusConfig';

        $fieldsForm = [
            'form' => [
                'legend' => ['title' => 'Configurare Premium Cargus', 'icon' => 'icon-rocket'],
                'tabs' => [
                    'general' => 'Cont & API',
                    'commercial' => 'Preferințe Expediție',
                    'debug' => 'API Debugger',
                ],
                'input' => [
                    // CONT & API
                    ['type' => 'text', 'label' => 'API URL', 'name' => 'CARGUS_API_URL', 'tab' => 'general'],
                    ['type' => 'text', 'label' => 'Subscription Key', 'name' => 'CARGUS_API_KEY', 'tab' => 'general'],
                    ['type' => 'text', 'label' => 'User (WebExpress)', 'name' => 'CARGUS_API_USER', 'tab' => 'general'],
                    ['type' => 'password', 'label' => 'Parolă', 'name' => 'CARGUS_API_PASS', 'tab' => 'general'],

                    // PREFERINTE (Din screenshot-urile PS 1.7)
                    [
                        'type' => 'select', 'label' => 'Punct Ridicare', 'name' => 'CARGUS_SENDER_LOCATION_ID', 'tab' => 'commercial',
                        'options' => ['query' => $service->getSenderLocations(), 'id' => 'id', 'name' => 'name']
                    ],
                    [
                        'type' => 'select', 'label' => 'Serviciu Implicit', 'name' => 'CARGUS_SERVICE_ID', 'tab' => 'commercial',
                        'options' => ['query' => $service->getServices(), 'id' => 'id', 'name' => 'name']
                    ],
                    [
                        'type' => 'select', 'label' => 'Plătitor Expediție', 'name' => 'CARGUS_PAYER_TYPE', 'tab' => 'commercial',
                        'options' => ['query' => [['id' => 'sender', 'name' => 'Expeditor'], ['id' => 'receiver', 'name' => 'Destinatar']], 'id' => 'id', 'name' => 'name']
                    ],
                    [
                        'type' => 'select', 'label' => 'Tip Ramburs', 'name' => 'CARGUS_COD_TYPE', 'tab' => 'commercial',
                        'options' => ['query' => [['id' => 'cash', 'name' => 'Numerar'], ['id' => 'collector', 'name' => 'Cont Colector']], 'id' => 'id', 'name' => 'name']
                    ],
                    ['type' => 'switch', 'label' => 'Deschidere Colet', 'name' => 'CARGUS_OPEN_PKG', 'tab' => 'commercial', 'is_bool' => true, 'values' => [['id' => 'on', 'value' => 1], ['id' => 'off', 'value' => 0]]],
                    ['type' => 'switch', 'label' => 'Livrare Sâmbăta', 'name' => 'CARGUS_SATURDAY', 'tab' => 'commercial', 'is_bool' => true, 'values' => [['id' => 'on', 'value' => 1], ['id' => 'off', 'value' => 0]]],

                    // DEBUGGER
                    [
                        'type' => 'html', 'name' => 'debug_tool', 'tab' => 'debug',
                        'html_content' => '
                            <div class="panel">
                                <button type="button" class="btn btn-info" onclick="runCargusDebug(\'PickupLocations\')">Test Locații</button>
                                <button type="button" class="btn btn-info" onclick="runCargusDebug(\'PriceTables\')">Test Tarife</button>
                                <hr/><pre id="cargus_debug_log" style="background:#000; color:#0f0; padding:10px; font-family:monospace;">Consolă activă...</pre>
                            </div>'
                    ],
                ],
                'submit' => ['title' => 'Salvează Configurarea']
            ]
        ];

        foreach (self::CONFIG_FIELDS as $f) {
            $helper->fields_value[$f] = Configuration::get($f);
        }
        $helper->fields_value['CARGUS_API_PASS'] = '';

        return $helper->generateForm([$fieldsForm]);
    }
}