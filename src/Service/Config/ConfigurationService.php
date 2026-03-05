<?php
/**
 * src/Service/Config/ConfigurationService.php
 * Version: 1.0.2
 * @author    Quark
 * @copyright 2026 Quark
 * @license   Proprietary
 */

namespace Cargus\Service\Config;

use Configuration;
use Shop;
use Exception;
use Cargus\Helper\CargusV3Client;

if (!defined('_PS_VERSION_')) {
    exit;
}

class ConfigurationService
{
    /**
     * Processes form data, sanitizes it, and updates configurations.
     * @param array $values POST values
     * @return array Status array with success boolean and message
     */
    public function saveConfiguration(array $values): array
    {
        $id_shop = (int)Shop::getContextShopID(true);
        $id_shop_group = (int)Shop::getContextShopGroupID(true);

        try {
            // 1. Sanitize and Save API Configuration
            if (isset($values['CARGUS_API_URL'])) {
                $apiUrl = rtrim(trim($values['CARGUS_API_URL']), '/') . '/';
                Configuration::updateValue('CARGUS_API_URL', $apiUrl, false, $id_shop_group, $id_shop);
            }
            if (isset($values['CARGUS_SUBSCRIPTION_KEY'])) {
                Configuration::updateValue('CARGUS_SUBSCRIPTION_KEY', trim($values['CARGUS_SUBSCRIPTION_KEY']), false, $id_shop_group, $id_shop);
            }
            if (isset($values['CARGUS_USERNAME'])) {
                Configuration::updateValue('CARGUS_USERNAME', trim($values['CARGUS_USERNAME']), false, $id_shop_group, $id_shop);
            }
            if (!empty($values['CARGUS_PASSWORD'])) {
                Configuration::updateValue('CARGUS_PASSWORD', trim($values['CARGUS_PASSWORD']), false, $id_shop_group, $id_shop);
            }
            if (isset($values['CARGUS_PICKUP_LOCATION'])) {
                Configuration::updateValue('CARGUS_PICKUP_LOCATION', (int)$values['CARGUS_PICKUP_LOCATION'], false, $id_shop_group, $id_shop);
            }

            // 2. Save Pricing Options
            $pricingKeys = [
                'CARGUS_CALC_MODE', 'CARGUS_DEFAULT_PAYER', 'CARGUS_DEFAULT_COD'
            ];
            foreach ($pricingKeys as $key) {
                if (isset($values[$key])) {
                    Configuration::updateValue($key, trim($values[$key]), false, $id_shop_group, $id_shop);
                }
            }

            // Floats / Decimals
            $decimalKeys = [
                'CARGUS_PRICE_BASE', 'CARGUS_PRICE_PUDO', 'CARGUS_PRICE_KG', 
                'CARGUS_PRICE_HEAVY_OFFSET', 'CARGUS_HEAVY_THRESHOLD'
            ];
            foreach ($decimalKeys as $key) {
                if (isset($values[$key])) {
                    Configuration::updateValue($key, (float)str_replace(',', '.', $values[$key]), false, $id_shop_group, $id_shop);
                }
            }

            // 3. Save Boolean Options (Switches)
            $boolKeys = [
                'CARGUS_SATURDAY_DELIVERY', 'CARGUS_OPEN_PACKAGE', 'CARGUS_INSURANCE'
            ];
            foreach ($boolKeys as $key) {
                if (isset($values[$key])) {
                    Configuration::updateValue($key, (int)$values[$key], false, $id_shop_group, $id_shop);
                }
            }

            // 4. Test API Connection
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
     * @throws Exception If connection fails.
     */
    private function testApiConnection(): void
    {
        $client = new CargusV3Client();
        $client->login();
    }
}
