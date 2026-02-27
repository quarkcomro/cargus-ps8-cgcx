<?php
/**
 * src/Service/API/SubscriptionService.php
 * Interoghează API-ul Cargus pentru a afla cota de expedieri rămase.
 */

declare(strict_types=1);

namespace Cargus\Service\API;

use Configuration;
use Exception;

class SubscriptionService
{
    private string $apiUrl;
    private string $subscriptionKey;

    public function __construct()
    {
        $this->apiUrl = Configuration::get('CARGUS_API_URL') ?: 'https://api.cargus.ro';
        $this->subscriptionKey = Configuration::get('CARGUS_API_KEY') ?: '';
    }

    /**
     * Obține detaliile abonamentului (Cota totală și cea consumată)
     */
    public function getQuotaStatus(): array
    {
        try {
            // Aici ar trebui apelat endpoint-ul specific Cargus pentru cotă.
            // (Numele exact al endpoint-ului depinde de ce expune Cargus în portalul tău. 
            // Vom simula structura de răspuns pentru demonstrație).
            
            /*
            $token = $this->getAuthToken();
            $url = rtrim($this->apiUrl, '/') . '/SubscriptionStatus'; // Exemplu endpoint
            // ... curl request ...
            $response = json_decode($result, true);
            */

            // Exemplu de date simulate în așteptarea endpoint-ului exact:
            return [
                'success' => true,
                'total_included' => 50,          // Ex: Abonament Multi 50
                'consumed_total' => 32,          // Câte s-au consumat din TOATE sursele (PS + eMag + Manual)
                'remaining' => 18,               // Câte mai ai la preț fix
                'extra_cost_active' => false     // Dacă e true, următoarele costă preț per kg
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Nu s-a putut obține starea abonamentului: ' . $e->getMessage()
            ];
        }
    }
}