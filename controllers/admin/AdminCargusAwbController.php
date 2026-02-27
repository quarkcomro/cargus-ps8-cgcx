<?php
/**
 * controllers/admin/AdminCargusAwbController.php
 * Controller pentru grila de Istoric AWB-uri Cargus.
 */

declare(strict_types=1);

class AdminCargusAwbController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'orders';
        $this->className = 'Order';
        $this->identifier = 'id_order';
        $this->list_no_link = true; // Nu vrem sa intram in editarea standard a comenzii prin click pe rand
        $this->explicitSelect = true;

        parent::__construct();

        // Titlul paginii
        $this->page_header_toolbar_title = $this->l('Istoric Livrări Cargus');

        // Selectăm doar comenzile care au un AWB generat
        $this->_where = 'AND a.shipping_number != "" AND a.shipping_number IS NOT NULL';
        
        // Adăugăm date despre client și status
        $this->_select = '
            CONCAT(c.`firstname`, " ", c.`lastname`) AS `customer_name`,
            osl.`name` AS `osname`,
            a.`shipping_number` AS `awb`
        ';
        
        $this->_join = '
            LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON (c.`id_customer` = a.`id_customer`)
            LEFT JOIN `' . _DB_PREFIX_ . 'order_state_lang` osl ON (osl.`id_order_state` = a.`current_state` AND osl.`id_lang` = ' . (int)$this->context->language->id . ')
        ';
        
        $this->_orderBy = 'id_order';
        $this->_orderWay = 'DESC';

        // Definim coloanele tabelului
        $this->fields_list = [
            'id_order' => [
                'title' => $this->l('ID Comandă'),
                'align' => 'center',
                'class' => 'fixed-width-xs'
            ],
            'reference' => [
                'title' => $this->l('Referință')
            ],
            'customer_name' => [
                'title' => $this->l('Client'),
                'havingFilter' => true,
            ],
            'awb' => [
                'title' => $this->l('AWB Cargus'),
                'class' => 'fixed-width-lg'
            ],
            'osname' => [
                'title' => $this->l('Status PrestaShop'),
                'type' => 'text',
            ],
            'date_add' => [
                'title' => $this->l('Data Comenzii'),
                'type' => 'datetime',
            ]
        ];
    }

    /**
     * Adăugăm butoanele de acțiune pentru fiecare rând (Vezi Comanda, Print AWB, Track)
     */
    public function renderList()
    {
        $this->addRowAction('vieworder');
        $this->addRowAction('printawb');
        $this->addRowAction('trackawb');

        return parent::renderList();
    }

    public function displayVieworderLink($token, $id, $name = null)
    {
        $link = $this->context->link->getAdminLink('AdminOrders', true, ['id_order' => $id, 'vieworder' => 1]);
        return '<a href="' . $link . '" class="btn btn-default"><i class="icon-search-plus"></i> ' . $this->l('Vezi Comanda') . '</a>';
    }

    public function displayPrintawbLink($token, $id, $name = null)
    {
        // Preluăm AWB-ul pentru a genera link-ul de print
        $order = new Order((int)$id);
        if (empty($order->shipping_number)) return '';
        
        $link = $this->context->link->getAdminLink('CargusPrintController', true, ['route' => 'cargus_print_awb', 'awbNumber' => $order->shipping_number]);
        return '<a href="' . $link . '" target="_blank" class="btn btn-primary"><i class="icon-print"></i> ' . $this->l('Print PDF') . '</a>';
    }

    public function displayTrackawbLink($token, $id, $name = null)
    {
        $order = new Order((int)$id);
        if (empty($order->shipping_number)) return '';
        
        $link = 'https://www.cargus.ro/find-shipment/?tracking=' . $order->shipping_number;
        return '<a href="' . $link . '" target="_blank" class="btn btn-default"><i class="icon-truck"></i> ' . $this->l('Track') . '</a>';
    }
}