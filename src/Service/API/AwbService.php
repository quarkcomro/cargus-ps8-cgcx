<?php
/**
 * src/Service/API/AwbService.php
 * Handles the generation of AWBs via Cargus API and updates PrestaShop orders.
 */

declare(strict_types=1);

namespace Cargus\Service\API;

use Order;
use Address;
use Customer;
use Configuration;
use OrderHistory;
use Db;
use Exception;

class AwbService
{
    private string $apiUrl;
    private string $subscriptionKey;

    public function __construct()
    {
        $this->apiUrl = Configuration::get('CARGUS_API_URL') ?: 'https://api.cargus.ro';
        $this->subscriptionKey = Configuration::get('CARGUS_API_KEY') ?: '';
    }

    /**
     * Generate an AWB for a specific PrestaShop Order.
     *
     * @param int $id_order
     * @param int|null $packages Number of packages (defaults to 1 if not provided)
     * @param float|null $weight Total weight (calculated from order if not provided)
     * @return array Status and message/AWB Number
     */
    public function generateAwb(int $id_order, ?int $packages = 1, ?float $weight = null): array
    {
        try {
            $order = new Order($id_order);
            if (!\Validate::isLoadedObject($order)) {
                throw new Exception('Invalid order ID.');
            }

            // Check if AWB already exists
            if (!empty($order->shipping_number)) {
                return [
                    'success' => false,
                    'message' => 'AWB already generated for this order: ' . $order->shipping_number
                ];
            }

            $address = new Address((int)$order->id_address_delivery);
            $customer = new Customer((int)$order->id_customer);

            // 1. Get Authentication Token
            $token = $this->authenticate();

            // 2. Determine Destination (Standard Address vs Ship & Go PUDO)
            $pudoId = $this->getSelectedPudoId((int)$order->id_cart);
            
            // 3. Calculate Weight if not provided
            if ($weight === null || $weight <= 0) {
                $weight = $this->calculateOrderWeight($order);
            }
            // Cargus API requires at least 1kg
            $weight = max(1, ceil($weight));

            // 4. Calculate COD (Cash On Delivery)
            $codValue = 0.0;
            // Native PS way to check if module is COD (adjust if using specific payment modules)
            if (in_array(strtolower($order->module), ['ps_cashondelivery', 'cashondelivery'])) {
                $codValue = (float)$order->total_paid_tax_incl;
            }

            // 5. Build the API Payload
            $payload = $this->buildPayload($order, $address, $customer, $weight, $packages, $codValue, $pudoId);

            // 6. Call Cargus API to create AWB
            $awbNumber = $this->callAwbApi($token, $payload);

            // 7. Save AWB to Order
            $order->shipping_number = $awbNumber;
            $order->update();

            // 8. Change Order Status automatically (based on BO setting)
            $this->changeOrderStatus($order);

            return [
                'success' => true,
                'message' => 'AWB generated successfully.',
                'awb_number' => $awbNumber
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error generating AWB: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Authenticate with Cargus API
     */
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

    /**
     * Check if a PUDO location was selected during checkout
     */
    private function getSelectedPudoId(int $id_cart): ?string
    {
        $sql = 'SELECT `pudo_id` FROM `' . _DB_PREFIX_ . 'cargus_order_pudo` WHERE `id_cart` = ' . $id_cart;
        $result = Db::getInstance()->getValue($sql);
        return $result ? (string)$result : null;
    }

    /**
     * Calculate total weight of the order
     */
    private function calculateOrderWeight(Order $order): float
    {
        $products = $order->getProducts();
        $weight = 0;
        foreach ($products as $product) {
            $weight += ((float)$product['weight'] * (int)$product['product_quantity']);
        }
        return $weight;
    }

    /**
     * Build the JSON Payload required by Cargus API V3
     */
    private function buildPayload(Order $order, Address $address, Customer $customer, float $weight, int $packages, float $codValue, ?string $pudoId): array
    {
        // Basic sender details - in a full implementation, these come from module settings
        // For brevity, we assume SenderLocationId is pre-configured or default
        $senderLocationId = Configuration::get('CARGUS_SENDER_LOCATION_ID') ?: 0; // Requires adding this config later

        $payload = [
            'Sender' => [
                'LocationId' => (int)$senderLocationId
            ],
            'Recipient' => [
                'Name' => $address->firstname . ' ' . $address->lastname,
                'ContactPerson' => $address->firstname . ' ' . $address->lastname,
                'Phone' => $address->phone ?: $address->phone_mobile,
                'Email' => $customer->email,
            ],
            'Parcels' => $packages,
            'Envelopes' => 0,
            'TotalWeight' => $weight,
            'DeclaredValue' => 0, // Insurance value
            'CashOnDelivery' => $codValue,
            'Observations' => 'Order #' . $order->reference,
        ];

        // Address logic (PUDO vs Standard)
        if ($pudoId) {
            // It's a Ship & Go delivery
            $payload['DeliveryPudoPoint'] = $pudoId;
            // The API might still require a dummy address or the customer's billing address
            $payload['Recipient']['CountyName'] = $address->state ? \State::getNameById((int)$address->id_state) : 'Bucuresti';
            $payload['Recipient']['LocalityName'] = $address->city;
            $payload['Recipient']['AddressText'] = 'Livrare la PUDO: ' . $pudoId;
        } else {
            // Standard Address delivery
            $payload['Recipient']['CountyName'] = $address->state ? \State::getNameById((int)$address->id_state) : '';
            $payload['Recipient']['LocalityName'] = $address->city;
            $payload['Recipient']['AddressText'] = $address->address1 . ' ' . $address->address2;
        }

        return $payload;
    }

    /**
     * Call the Awbs endpoint
     */
    private function callAwbApi(string $token, array $payload): string
    {
        $url = rtrim($this->apiUrl, '/') . '/Awbs';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        // Cargus Awbs endpoint expects an array of Awbs
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([$payload])); 
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey,
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($response)) {
            // Log response for debugging
            \PrestaShopLogger::addLog('Cargus API Error: ' . $response, 3);
            throw new Exception('Failed to create AWB on Cargus servers.');
        }

        $decoded = json_decode($response, true);
        
        // Cargus returns an array of created AWBs
        if (isset($decoded[0]['BarCode']) && !empty($decoded[0]['BarCode'])) {
            return (string)$decoded[0]['BarCode'];
        }

        // Handle validation errors returned by API
        $errorMsg = 'Unknown API Error';
        if (isset($decoded[0]['Error'])) {
             $errorMsg = $decoded[0]['Error'];
        }
        throw new Exception($errorMsg);
    }

    /**
     * Update the PrestaShop Order Status based on Module Configuration
     */
    private function changeOrderStatus(Order $order): void
    {
        $targetStatusId = (int)Configuration::get('CARGUS_AWB_GENERATED_STATUS');
        
        // Only change if a status is configured and it's different from the current one
        if ($targetStatusId > 0 && (int)$order->current_state !== $targetStatusId) {
            $history = new OrderHistory();
            $history->id_order = $order->id;
            // The changeIdOrderState method also handles triggering emails if configured for that status
            $history->changeIdOrderState($targetStatusId, $order->id, true);
            $history->addWithemail(true);
        }
    }
}