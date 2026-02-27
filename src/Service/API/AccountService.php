<?php
/**
 * src/Service/API/AccountService.php
 * Gestionează autentificarea API V3 și preluarea datelor comerciale.
 * Endpoint-ul pentru sedii a fost setat la /PickupLocations.
 */

declare(strict_types=1);

namespace Cargus\Service\API;

use Configuration;
use Exception;
use PrestaShopLogger;

class AccountService
{
    private string $apiUrl;
    private string $subscriptionKey;
    private string $username;
    private string $password;
    private ?string $token = null; 

    public function __construct()
    {
        $this->apiUrl = rtrim(Configuration::get('CARGUS_API_URL') ?: 'https://urgentcargus.azure-api.net/api', '/');
        $this->subscriptionKey = Configuration::get('CARGUS_API_KEY') ?: '';
        $this->username = Configuration::get('CARGUS_API_USER') ?: '';
        $this->password = Configuration::get('CARGUS_API_PASS') ?: '';
    }

    private function authenticate(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        if (empty($this->username) || empty($this->password) || empty($this->subscriptionKey)) {
            throw new Exception('Credențialele API lipsesc.');
        }

        $ch = curl_init($this->apiUrl . '/LoginUser');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['UserName' => $this->username, 'Password' => $this->password]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($response)) {
            throw new Exception('Autentificare eșuată. Verificați credențialele.');
        }

        $this->token = trim($response, '"');
        return $this->token;
    }

    public function getSenderLocations(): array
    {
        // Conform capitolului 5.3 din PDF, endpoint-ul real este /PickupLocations
        return $this->fetchEndpoint('/PickupLocations', 'locationid', 'name', 'localityname');
    }

    public function getPriceTables(): array
    {
        return $this->fetchEndpoint('/PriceTables', 'pricetableid', 'name');
    }

    public function getServices(): array
    {
        return $this->fetchEndpoint('/Services', 'serviceid', 'name');
    }

    private function fetchEndpoint(string $endpoint, string $defaultIdKey, string $nameKey, string $extraKey = ''): array
    {
        try {
            $token = $this->authenticate();
        } catch (Exception $e) {
            return [];
        }

        $headers = [
            'Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey,
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ];

        $ch = curl_init($this->apiUrl . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string)$response, true);
        $options = [];

        if ($httpCode === 404) {
            PrestaShopLogger::addLog('Cargus API V3 - Endpoint not found: ' . $endpoint, 3);
            return [];
        }

        if (is_array($data)) {
            foreach ($data as $item) {
                if (is_array($item)) {
                    $itemLower = array_change_key_case($item, CASE_LOWER);

                    $id = $itemLower[$defaultIdKey] ?? $itemLower['senderlocationid'] ?? $itemLower['id'] ?? null;
                    $name = $itemLower[$nameKey] ?? null;

                    if ($id !== null && $name !== null) {
                        $extra = ($extraKey !== '' && isset($itemLower[$extraKey])) ? ' (' . $itemLower[$extraKey] . ')' : '';
                        $options[] = [
                            'id' => (int)$id,
                            'name' => $name . $extra
                        ];
                    }
                }
            }
        }

        if (empty($options) && !empty($response) && $httpCode === 200) {
            PrestaShopLogger::addLog('Cargus API V3 - Empty Data for ' . $endpoint . '. Raw Response: ' . $response, 2);
        }

        return $options;
    }
}