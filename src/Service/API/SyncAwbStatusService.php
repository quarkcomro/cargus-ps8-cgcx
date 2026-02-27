<?php
/**
 * src/Service/API/SyncAwbStatusService.php
 * Interoghează API-ul Cargus pentru a afla statusul AWB-urilor și actualizează comenzile în PrestaShop.
 */

declare(strict_types=1);

namespace Cargus\Service\API;

use Configuration;
use Order;
use OrderHistory;
use Db;
use Exception;
use PrestaShopLogger;

class SyncAwbStatusService
{
    private string $apiUrl;
    private string $subscriptionKey;

    public function __construct()
    {
        $this->apiUrl = Configuration::get('CARGUS_API_URL') ?: 'https://api.cargus.ro';
        $this->subscriptionKey = Configuration::get('CARGUS_API_KEY') ?: '';
    }

    /**
     * Sincronizează statusurile pentru toate comenzile care au AWB dar nu sunt încă livrate/refuzate.
     */
    public function syncActiveAwbs(): array
    {
        try {
            // Căutăm comenzile cu AWB Cargus care nu au atins statusul final (Livrat sau Returnat)
            $statusDelivered = (int)Configuration::get('CARGUS_OS_DELIVERED');
            $statusReturned = (int)Configuration::get('CARGUS_OS_RETURNED');
            
            $sql = 'SELECT `id_order`, `shipping_number` 
                    FROM `' . _DB_PREFIX_ . 'orders` 
                    WHERE `shipping_number` != "" 
                    AND `shipping_number` IS NOT NULL
                    AND `current_state` NOT IN (' . $statusDelivered . ', ' . $statusReturned . ')';
                    
            $orders = Db::getInstance()->executeS($sql);

            if (empty($orders)) {
                return ['success' => true, 'message' => 'Nicio comandă activă de sincronizat.'];
            }

            $token = $this->authenticate();
            $updatedCount = 0;

            foreach ($orders as $orderData) {
                $awb = $orderData['shipping_number'];
                $id_order = (int)$orderData['id_order'];

                // Verificăm statusul la Cargus
                $cargusStatus = $this->getAwbTrace($token, $awb);
                
                if ($cargusStatus !== null) {
                    $changed = $this->updatePrestashopOrder($id_order, $cargusStatus);
                    if ($changed) {
                        $updatedCount++;
                    }
                }
            }

            return ['success' => true, 'message' => "Sincronizare finalizată. Comenzi actualizate: $updatedCount"];

        } catch (Exception $e) {
            PrestaShopLogger::addLog('Cargus Sync Status Error: ' . $e->getMessage(), 3);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function getAwbTrace(string $token, string $awb): ?string
    {
        // Interogăm endpoint-ul de tracking
        $url = rtrim($this->apiUrl, '/') . '/Awbs/Trace?barCode=' . urlencode($awb);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey,
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($response)) {
            return null;
        }

        $decoded = json_decode($response, true);
        
        // Returnăm ultimul status (presupunând că API-ul returnează un array cu evenimente)
        // Adaptare conform structurii exacte a răspunsului /Trace din API V3
        if (isset($decoded[0]['EventId'])) {
            return (string)$decoded[0]['EventId']; // Exemplu: 21 (Livrat), 3 (In Tranzit) etc.
        }

        return null;
    }

    private function updatePrestashopOrder(int $id_order, string $cargusEventId): bool
    {
        $order = new Order($id_order);
        $targetStatusId = null;

        // Mapare simplificată a ID-urilor de eveniment Cargus (conform documentației standard curieri)
        // Aceste ID-uri trebuie ajustate exact cu cele din documentația specifică a contului tău.
        switch ($cargusEventId) {
            case '21': // Exemplu ID pentru Livrat
                $targetStatusId = (int)Configuration::get('CARGUS_OS_DELIVERED');
                break;
            case '35': // Exemplu ID pentru Refuzat/Returnat
                $targetStatusId = (int)Configuration::get('CARGUS_OS_RETURNED');
                break;
            case '3':  // Exemplu ID pentru În Tranzit
                $targetStatusId = (int)Configuration::get('CARGUS_OS_TRANSIT');
                break;
        }

        if ($targetStatusId && (int)$order->current_state !== $targetStatusId) {
            $history = new OrderHistory();
            $history->id_order = $order->id;
            $history->changeIdOrderState($targetStatusId, $order->id, true);
            $history->addWithemail(true);
            return true;
        }

        return false;
    }

    private function authenticate(): string
    {
        $username = Configuration::get('CARGUS_API_USER');
        $password = Configuration::get('CARGUS_API_PASS');

        $url = rtrim($this->apiUrl, '/') . '/LoginUser';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['UserName' => $username, 'Password' => $password]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey,
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($response)) {
            throw new Exception('API Authentication failed.');
        }

        return trim($response, '"');
    }
}