<?php
/**
 * src/Service/API/PrintAwbService.php
 * Handles downloading the AWB PDF document from Cargus API V3.
 */

declare(strict_types=1);

namespace Cargus\Service\API;

use Configuration;
use Exception;

class PrintAwbService
{
    private string $apiUrl;
    private string $subscriptionKey;

    public function __construct()
    {
        $this->apiUrl = Configuration::get('CARGUS_API_URL') ?: 'https://api.cargus.ro';
        $this->subscriptionKey = Configuration::get('CARGUS_API_KEY') ?: '';
    }

    /**
     * Get the PDF bytes for a specific AWB.
     * Format 1 is standard for A6 Thermal Printers.
     *
     * @param string $awbNumber
     * @return string PDF raw data
     * @throws Exception
     */
    public function getPdf(string $awbNumber): string
    {
        $token = $this->authenticate();

        // format=1 means A6 format (Thermal label). format=0 means A4.
        $url = rtrim($this->apiUrl, '/') . '/AwbDocuments?type=PDF&format=1&barCodes=' . urlencode($awbNumber);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey,
            'Authorization: Bearer ' . $token,
            'Accept: application/pdf' // We expect a PDF file stream
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($response)) {
            throw new Exception('Could not download the AWB PDF from Cargus. HTTP Code: ' . $httpCode);
        }

        return $response;
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
}