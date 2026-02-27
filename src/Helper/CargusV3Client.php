<?php
/**
 * @author    Quark
 * @copyright 2026 Quark
 * @license   Proprietary
 * @version   1.0.4
 */

namespace Cargus\Helper;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CargusV3Client
{
    private $apiUrl;
    private $subscriptionKey;
    private $token = null;
    private $timeout = 10;

    public function __construct()
    {
        $this->apiUrl = \Configuration::get('CARGUS_API_URL');
        $this->subscriptionKey = \Configuration::get('CARGUS_SUBSCRIPTION_KEY');
    }

    /**
     * Normalizarea diacriticelor (sedilă și virgulă)
     */
    public static function normalizeString($string)
    {
        if (empty($string)) {
            return $string;
        }

        $search = [
            'ă', 'Ă', 'â', 'Â', 'î', 'Î',
            'ș', 'Ș', 'ț', 'Ț', // Virgulă
            'ş', 'Ş', 'ţ', 'Ţ'  // Sedilă
        ];
        $replace = [
            'a', 'A', 'a', 'A', 'i', 'I',
            's', 'S', 't', 'T',
            's', 'S', 't', 'T'
        ];

        return str_replace($search, $replace, $string);
    }

    /**
     * Autentificare cu sistem de Caching (Prevenire HTTP 409)
     */
    public function login()
    {
        $username = \Configuration::get('CARGUS_USERNAME');
        $password = \Configuration::get('CARGUS_PASSWORD');

        if (!$username || !$password || !$this->subscriptionKey) {
            throw new \Exception('Lipsesc credențiale API. Te rugăm să le configurezi în Tab-ul 1.');
        }

        // 1. Verificăm dacă avem un token valid salvat
        $savedToken = \Configuration::get('CARGUS_BEARER_TOKEN');
        $tokenExpire = (int)\Configuration::get('CARGUS_TOKEN_EXPIRE');

        if ($savedToken && $tokenExpire > time()) {
            $this->token = $savedToken;
            return $this->token;
        }

        // 2. Dacă nu avem token valid, cerem unul nou
        $response = $this->request('LoginUser', 'POST', [
            'UserName' => $username,
            'Password' => $password
        ], false);

        if (isset($response['error'])) {
            throw new \Exception('Autentificare eșuată: ' . $this->translateError($response['error']));
        }

        $this->token = $response; 
        
        // 3. Salvăm noul token cu o valabilitate de sub 2 ore (7000 secunde)
        \Configuration::updateValue('CARGUS_BEARER_TOKEN', $this->token);
        \Configuration::updateValue('CARGUS_TOKEN_EXPIRE', time() + 7000);

        return $this->token;
    }

    /**
     * Metoda centrală pentru request-uri
     */
    public function request($endpoint, $method = 'GET', $data = [], $useAuth = true)
    {
        $url = rtrim($this->apiUrl, '/') . '/' . ltrim($endpoint, '/');
        
        $headers = [
            'Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        if ($useAuth) {
            if (!$this->token) {
                $this->login();
            }
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif (strtoupper($method) === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['error' => 'Eroare conexiune server: ' . $curlError];
        }

        $decodedResponse = json_decode($response, true);

        if ($httpCode >= 400) {
            $errorMessage = isset($decodedResponse['message']) ? $decodedResponse['message'] : 'Eroare HTTP ' . $httpCode;
            return ['error' => $errorMessage, 'code' => $httpCode];
        }

        return $decodedResponse !== null ? $decodedResponse : $response;
    }

    /**
     * Traducerea erorilor comune
     */
    private function translateError($rawError)
    {
        if (strpos($rawError, '401') !== false) {
            return 'Credențiale incorecte sau Subscription Key expirat.';
        }
        if (strpos($rawError, '409') !== false) {
            return 'Conflict de sesiune API (Eroare 409). Sistemul încearcă reînnoirea token-ului.';
        }
        if (strpos($rawError, 'timeout') !== false) {
            return 'Serverul Cargus a răspuns prea greu.';
        }
        return $rawError; 
    }
}
