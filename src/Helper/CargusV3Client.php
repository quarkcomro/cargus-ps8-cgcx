<?php
/**
 * src/Helper/CargusV3Client.php
 * Version: 1.0.0
 * * @author    Quark
 * @copyright 2026 Quark
 * @license   Proprietary
 */

namespace Cargus\Helper;

use Exception;
use Configuration;

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
        $this->apiUrl = Configuration::get('CARGUS_API_URL');
        $this->subscriptionKey = Configuration::get('CARGUS_SUBSCRIPTION_KEY');
    }

    /**
     * Normalizes diacritics (cedilla and comma) for safe API transmission.
     *
     * @param string $string
     * @return string
     */
    public static function normalizeString(string $string): string
    {
        if (empty($string)) {
            return $string;
        }

        $search = [
            'ă', 'Ă', 'â', 'Â', 'î', 'Î',
            'ș', 'Ș', 'ț', 'Ț', // Comma
            'ş', 'Ş', 'ţ', 'Ţ'  // Cedilla
        ];
        $replace = [
            'a', 'A', 'a', 'A', 'i', 'I',
            's', 'S', 't', 'T',
            's', 'S', 't', 'T'
        ];

        return str_replace($search, $replace, $string);
    }

    /**
     * Authentication with Caching System (Prevents HTTP 409 Conflict).
     *
     * @return string Bearer Token
     * @throws Exception
     */
    public function login(): string
    {
        $username = Configuration::get('CARGUS_USERNAME');
        $password = Configuration::get('CARGUS_PASSWORD');

        if (!$username || !$password || !$this->subscriptionKey) {
            throw new Exception('Missing API credentials. Please configure them in the module settings.');
        }

        // 1. Check if we have a valid saved token
        $savedToken = Configuration::get('CARGUS_BEARER_TOKEN');
        $tokenExpire = (int)Configuration::get('CARGUS_TOKEN_EXPIRE');

        if ($savedToken && $tokenExpire > time()) {
            $this->token = $savedToken;
            return $this->token;
        }

        // 2. If no valid token, request a new one
        $response = $this->request('LoginUser', 'POST', [
            'UserName' => $username,
            'Password' => $password
        ], false);

        if (isset($response['error'])) {
            throw new Exception('Authentication failed: ' . $this->translateError($response['error']));
        }

        // Cargus returns the token directly as a string or inside an array depending on the exact endpoint version
        $this->token = is_array($response) && isset($response['token']) ? $response['token'] : (string)$response; 
        
        // 3. Save the new token with a validity of slightly under 2 hours (7000 seconds)
        Configuration::updateValue('CARGUS_BEARER_TOKEN', $this->token);
        Configuration::updateValue('CARGUS_TOKEN_EXPIRE', time() + 7000);

        return $this->token;
    }

    /**
     * Core method for executing API requests.
     *
     * @param string $endpoint API endpoint
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param array $data Payload data
     * @param bool $useAuth Whether to inject the Bearer token
     * @return mixed Response array, string, or error array
     */
    public function request(string $endpoint, string $method = 'GET', array $data = [], bool $useAuth = true)
    {
        if (empty($this->apiUrl)) {
            return ['error' => 'API URL is not configured.'];
        }

        $url = rtrim($this->apiUrl, '/') . '/' . ltrim($endpoint, '/');
        
        $headers = [
            'Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        if ($useAuth) {
            if (!$this->token) {
                try {
                    $this->login();
                } catch (Exception $e) {
                    return ['error' => $e->getMessage()];
                }
            }
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Added for security

        $method = strtoupper($method);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'PUT' || $method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['error' => 'Server connection error: ' . $curlError];
        }

        $decodedResponse = json_decode($response, true);

        // Handle API level errors
        if ($httpCode >= 400) {
            $errorMessage = isset($decodedResponse['message']) ? $decodedResponse['message'] : 'HTTP Error ' . $httpCode;
            
            // Extract specific Cargus nested error data if available
            if (isset($decodedResponse['ErrorData'])) {
                 $errorMessage .= ' Details: ' . json_encode($decodedResponse['ErrorData']);
            }
            
            return ['error' => $errorMessage, 'code' => $httpCode];
        }

        return $decodedResponse !== null ? $decodedResponse : $response;
    }

    /**
     * Translates common API errors to user-friendly English messages.
     *
     * @param string|array $rawError
     * @return string
     */
    private function translateError($rawError): string
    {
        $errorStr = is_array($rawError) ? json_encode($rawError) : (string)$rawError;

        if (strpos($errorStr, '401') !== false) {
            return 'Incorrect credentials or expired Subscription Key.';
        }
        if (strpos($errorStr, '409') !== false) {
            return 'API session conflict (Error 409). The system is trying to renew the token.';
        }
        if (strpos($errorStr, 'timeout') !== false) {
            return 'Cargus server responded too slowly.';
        }
        
        return $errorStr; 
    }
}
