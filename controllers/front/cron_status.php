<?php
/**
 * controllers/front/cron_status.php
 * Cron job securizat pentru actualizarea automată a statusurilor comenzilor (Tracking AWB).
 */

declare(strict_types=1);

class CargusCron_statusModuleFrontController extends ModuleFrontController
{
    public $display_column_left = false;
    public $display_column_right = false;
    public $display_header = false;
    public $display_footer = false;

    public function initContent()
    {
        parent::initContent();

        // Verificăm token-ul de securitate
        $providedToken = Tools::getValue('token');
        $savedToken = Configuration::get('CARGUS_CRON_TOKEN');

        if (empty($savedToken) || $providedToken !== $savedToken) {
            header('HTTP/1.1 403 Forbidden');
            die(json_encode(['success' => false, 'message' => 'Token de securitate invalid.']));
        }

        // Încărcăm Serviciul
        require_once dirname(__FILE__) . '/../../src/Service/API/SyncAwbStatusService.php';
        $syncService = new \Cargus\Service\API\SyncAwbStatusService();
        
        // Rulăm sincronizarea
        $result = $syncService->syncActiveAwbs();

        header('Content-Type: application/json');
        die(json_encode($result));
    }
}