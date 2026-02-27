<?php
/**
 * controllers/front/ajax.php
 * Front controller handling AJAX requests for PUDO map and selections.
 */

declare(strict_types=1);

class CargusAjaxModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();
        
        // Security check: must be an AJAX request
        if (!$this->isXmlHttpRequest()) {
            die(json_encode(['success' => false, 'message' => 'Invalid request']));
        }

        // CSRF Token validation for PrestaShop 8/9 security standards
        $token = Tools::getValue('token');
        if ($token !== Tools::getToken(false)) {
            die(json_encode(['success' => false, 'message' => 'Invalid CSRF token']));
        }

        $action = Tools::getValue('action');

        switch ($action) {
            case 'getPudos':
                $this->processGetPudos();
                break;
            case 'saveSelectedPudo':
                $this->processSaveSelectedPudo();
                break;
            default:
                die(json_encode(['success' => false, 'message' => 'Unknown action']));
        }
    }

    /**
     * Fetch active PUDOs from local cache based on city search.
     * Uses normalization to ensure a match regardless of how the user typed the city.
     */
    private function processGetPudos(): void
    {
        $cityRaw = trim((string)Tools::getValue('city'));
        
        if (empty($cityRaw) || strlen($cityRaw) < 2) {
            die(json_encode(['success' => true, 'pudos' => []]));
        }

        // Încărcăm manual serviciul dacă autoloader-ul nu e complet inițializat în contextul FO
        require_once dirname(__FILE__) . '/../../src/Service/API/GeoService.php';
        
        // Normalizăm input-ul utilizatorului
        $cityNormalized = \Cargus\Service\API\GeoService::normalize($cityRaw);
        
        $cityRawSafe = pSQL($cityRaw);
        $cityNormSafe = pSQL($cityNormalized);

        // Căutăm atât după string-ul brut (în caz că API-ul a trimis denumiri cu diacritice), 
        // cât și după varianta normalizată.
        $sql = 'SELECT pudo_id, name, address, lat, lng 
                FROM `' . _DB_PREFIX_ . 'cargus_pudo` 
                WHERE (`city` LIKE "%' . $cityRawSafe . '%" OR `city` LIKE "%' . $cityNormSafe . '%") 
                  AND `is_active` = 1
                ORDER BY `name` ASC 
                LIMIT 50';

        $pudos = Db::getInstance()->executeS($sql);

        die(json_encode([
            'success' => true,
            'pudos' => $pudos ?: []
        ]));
    }

    /**
     * Save the selected PUDO ID to the current Cart.
     */
    private function processSaveSelectedPudo(): void
    {
        $pudoId = pSQL(Tools::getValue('pudo_id'));
        $idCart = (int) $this->context->cart->id;

        if (empty($pudoId) || $idCart === 0) {
            die(json_encode(['success' => false, 'message' => 'Invalid cart or PUDO ID.']));
        }

        // Inserăm sau actualizăm selecția clientului pentru coșul curent
        $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'cargus_order_pudo` (`id_cart`, `pudo_id`) 
                VALUES (' . $idCart . ', "' . $pudoId . '") 
                ON DUPLICATE KEY UPDATE `pudo_id` = "' . $pudoId . '"';

        $result = Db::getInstance()->execute($sql);

        if ($result) {
            die(json_encode(['success' => true, 'message' => 'PUDO location saved successfully.']));
        } else {
            die(json_encode(['success' => false, 'message' => 'Database error while saving PUDO.']));
        }
    }
}