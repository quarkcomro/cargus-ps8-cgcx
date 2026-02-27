<?php
/**
 * src/Service/API/ReturnAwbService.php
 * Gestionează generarea AWB-urilor de Retur prin API Cargus.
 */

declare(strict_types=1);

namespace Cargus\Service\API;

use Order;
use Address;
use Customer;
use Configuration;
use Exception;

class ReturnAwbService
{
    private string $apiUrl;
    private string $subscriptionKey;

    public function __construct()
    {
        $this->apiUrl = Configuration::get('CARGUS_API_URL') ?: 'https://api.cargus.ro';
        $this->subscriptionKey = Configuration::get('CARGUS_API_KEY') ?: '';
    }

    public function generateReturnAwb(int $id_order, string $pickupType, string $deliveryType, string $pickupPudo, string $deliveryPudo): array
    {
        try {
            $order = new Order($id_order);
            $address = new Address((int)$order->id_address_delivery);
            $customer = new Customer((int)$order->id_customer);

            $token = $this->authenticate();

            // Sediul tău (Locația setată în Cargus)
            $myLocationId = (int)Configuration::get('CARGUS_SENDER_LOCATION_ID') ?: 0;

            // Construim Payload-ul Inversat
            $payload = [
                'Parcels' => 1,
                'Envelopes' => 0,
                'TotalWeight' => 1, // La retur se pune o greutate standard, va fi cantarit la depozit
                'DeclaredValue' => 0,
                'CashOnDelivery' => 0, // Retururile nu au ramburs la curier
                'Observations' => 'Retur Comanda #' . $order->reference,
            ];

            // Setăm Expeditorul (Sender) - Adică Clientul care face returul
            if ($pickupType === 'locker' && !empty($pickupPudo)) {
                $payload['Sender'] = [
                    'Name' => $address->firstname . ' ' . $address->lastname,
                    'Phone' => $address->phone ?: $address->phone_mobile,
                    'Email' => $customer->email,
                    'CountyName' => 'Bucuresti', // Placeholder pt PUDO
                    'LocalityName' => $address->city,
                    'AddressText' => 'Retur din Locker: ' . $pickupPudo
                ];
                $payload['PickupPudoPoint'] = $pickupPudo;
            } else {
                $payload['Sender'] = [
                    'Name' => $address->firstname . ' ' . $address->lastname,
                    'Phone' => $address->phone ?: $address->phone_mobile,
                    'Email' => $customer->email,
                    'CountyName' => $address->state ? \State::getNameById((int)$address->id_state) : '',
                    'LocalityName' => $address->city,
                    'AddressText' => $address->address1 . ' ' . $address->address2
                ];
            }

            // Setăm Destinatarul (Recipient) - Adică Tu (Magazinul)
            if ($deliveryType === 'locker' && !empty($deliveryPudo)) {
                $payload['Recipient'] = [
                    'Name' => Configuration::get('PS_SHOP_NAME'),
                    'Phone' => Configuration::get('PS_SHOP_PHONE'),
                    'Email' => Configuration::get('PS_SHOP_EMAIL'),
                    'CountyName' => 'Bucuresti',
                    'LocalityName' => 'Bucuresti',
                    'AddressText' => 'Livrare in Locker Magazin: ' . $deliveryPudo
                ];
                $payload['DeliveryPudoPoint'] = $deliveryPudo;
            } else {
                // Dacă vine la sediu, folosim LocationId-ul tău asociat contului
                $payload['Recipient'] = [
                    'LocationId' => $myLocationId > 0 ? $myLocationId : null,
                    'Name' => Configuration::get('PS_SHOP_NAME'),
                    'Phone' => Configuration::get('PS_SHOP_PHONE'),
                    'Email' => Configuration::get('PS_SHOP_EMAIL'),
                    'AddressText' => Configuration::get('PS_SHOP_ADDR1')
                ];
            }

            $awbNumber = $this->callAwbApi($token, $payload);

            return [
                'success' => true,
                'message' => 'AWB Retur generat cu succes.',
                'awb_number' => $awbNumber
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Eroare la generarea AWB Retur: ' . $e->getMessage()
            ];
        }
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
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey, 'Content-Type: application/json']);
        $response = curl_exec($ch);
        curl_close($ch);
        return trim($response, '"');
    }

    private function callAwbApi(string $token, array $payload): string
    {
        $url = rtrim($this->apiUrl, '/') . '/Awbs';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
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
            throw new Exception('Eroare API Cargus la generare Retur.');
        }

        $decoded = json_decode($response, true);
        if (isset($decoded[0]['BarCode']) && !empty($decoded[0]['BarCode'])) {
            return (string)$decoded[0]['BarCode'];
        }

        throw new Exception($decoded[0]['Error'] ?? 'Eroare necunoscuta API.');
    }
}