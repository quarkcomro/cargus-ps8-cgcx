<?php
/**
 * src/Service/Config/ConfigurationService.php
 * Version: 1.0.0
 */

namespace Cargus\Service\Config;

use Configuration;
use Shop;
use Exception;

if (!defined('_PS_VERSION_')) {
    exit;
}

class ConfigurationService
{
    /**
     * Processes form data, sanitizes it, and updates configurations.
     * * @param array $values POST values
     * @return array Status array with success boolean and message
     */
    public function saveConfiguration(array $values): array
    {
        // Fetch current shop context for multistore compatibility
        $id_shop = (int)Shop::getContextShopID(true);
        $id_shop_group = (int)Shop::getContextShopGroupID(true);

        try {
            // 1. Sanitize and Save API URL
            if (isset($values['CARGUS_API_URL'])) {
                $apiUrl = rtrim(trim($values['CARGUS_API_URL']), '/') . '/';
                Configuration::updateValue('CARGUS_API_URL', $apiUrl, false, $id_shop_group, $id_shop);
            }

            // 2. Save Credentials
            if (isset($values['CARGUS_SUBSCRIPTION_KEY'])) {
                Configuration::updateValue('CARGUS_SUBSCRIPTION_KEY', trim($values['CARGUS_SUBSCRIPTION_KEY']), false, $id_shop_group, $id_shop);
            }
            if (isset($values['CARGUS_USERNAME'])) {
                Configuration::updateValue('CARGUS_USERNAME', trim($values['CARGUS_USERNAME']), false, $id_shop_group, $id_shop);
            }
            if (!empty($values['CARGUS_PASSWORD'])) {
                Configuration::updateValue('CARGUS_PASSWORD', trim($values['CARGUS_PASSWORD']), false, $id_shop_group, $id_shop);
            }

            // 3. Save Logistics Rules
            if (isset($values['CARGUS_HEAVY_THRESHOLD'])) {
                $threshold = (float)$values['CARGUS_HEAVY_THRESHOLD'];
                Configuration::updateValue('CARGUS_HEAVY_THRESHOLD', $threshold, false, $id_shop_group, $id_shop);
            }

            // 4. Test API Connection (Placeholder for Client invocation)
            $this->testApiConnection();

            return [
                'success' => true,
                'message' => 'Configuration saved successfully.'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error saving configuration: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Tests the connectivity with Cargus API using the saved credentials.
     * * @throws Exception If connection fails.
     */
    private function testApiConnection(): void
    {
        // TODO: Instantiate \Cargus\Helper\CargusV3Client
        // Make a lightweight call (e.g., Login or Ping)
        // If the call returns an error status code or unauthorized, throw Exception.
        
        // Example structure for future logic:
        /*
        $client = new \Cargus\Helper\CargusV3Client();
        $response = $client->login();
        if (isset($response['error'])) {
            throw new Exception("Invalid API Credentials. Cargus returned: " . $response['error']);
        }
        */
    }
}
