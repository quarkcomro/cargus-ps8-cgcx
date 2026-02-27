<?php
/**
 * src/Service/Sync/PudoSyncService.php
 * Handles the synchronization of Ship & Go Lockers (PUDOs) from Cargus API to the local database.
 */

declare(strict_types=1);

namespace Cargus\Service\Sync;

use Configuration;
use Db;
use Exception;
use PrestaShopLogger;

class PudoSyncService
{
    private string $apiUrl;
    private string $subscriptionKey;

    public function __construct()
    {
        // Allow switching between API endpoints (e.g., production vs staging)
        $this->apiUrl = Configuration::get('CARGUS_API_URL') ?: 'https://api.cargus.ro';
        $this->subscriptionKey = Configuration::get('CARGUS_API_KEY') ?: '';
    }

    /**
     * Main method to execute the synchronization process.
     *
     * @return array Status of the sync process
     */
    public function syncPudos(): array
    {
        try {
            if (empty($this->subscriptionKey)) {
                throw new Exception('Cargus API Key is missing. Please configure the module.');
            }

            // 1. Authenticate and get Token
            $token = $this->authenticate();

            // 2. Fetch PUDO list
            $pudos = $this->fetchPudosFromApi($token);

            if (empty($pudos)) {
                return ['success' => false, 'message' => 'No PUDOs returned from API.'];
            }

            // 3. Process and upsert data in batches
            $stats = $this->processAndSave($pudos);

            return [
                'success' => true,
                'message' => 'Synchronization completed successfully.',
                'stats' => $stats
            ];

        } catch (Exception $e) {
            PrestaShopLogger::addLog('Cargus PUDO Sync Error: ' . $e->getMessage(), 3);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Authenticate with Cargus V3 API.
     *
     * @return string Bearer Token
     * @throws Exception
     */
    private function authenticate(): string
    {
        $username = Configuration::get('CARGUS_API_USER');
        $password = Configuration::get('CARGUS_API_PASS');

        $url = rtrim($this->apiUrl, '/') . '/LoginUser';
        
        $data = json_encode([
            'UserName' => $username,
            'Password' => $password
        ]);

        $headers = [
            'Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey,
            'Content-Type: application/json'
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($response)) {
            throw new Exception('Failed to authenticate with Cargus API. HTTP Code: ' . $httpCode);
        }

        // Cargus usually returns the token as a plain string surrounded by quotes, or a JSON object
        $token = trim($response, '"'); 
        return $token;
    }

    /**
     * Fetch PUDO locations from the API.
     *
     * @param string $token
     * @return array
     * @throws Exception
     */
    private function fetchPudosFromApi(string $token): array
    {
        $url = rtrim($this->apiUrl, '/') . '/Pudo?countryId=1'; // 1 is usually Romania

        $headers = [
            'Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey,
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // PUDO list can be large

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($response)) {
            throw new Exception('Failed to fetch PUDO list. HTTP Code: ' . $httpCode);
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Upsert PUDOs in batches to avoid overwhelming the database.
     *
     * @param array $pudos
     * @return array Statistics
     */
    private function processAndSave(array $pudos): array
    {
        $db = Db::getInstance();
        $tableName = _DB_PREFIX_ . 'cargus_pudo';
        
        $activePudoIds = [];
        $batchSize = 100;
        $batchValues = [];
        $processedCount = 0;

        foreach ($pudos as $pudo) {
            // Sanitize data
            $id = pSQL($pudo['Id']);
            $name = pSQL($pudo['Name']);
            $city = pSQL($pudo['City']);
            $county = pSQL($pudo['County']);
            $address = pSQL($pudo['Address']);
            $lat = (float)($pudo['Latitude'] ?? 0);
            $lng = (float)($pudo['Longitude'] ?? 0);
            $type = pSQL($pudo['PointType'] ?? 'LOCKER'); // Can be PUDO or LOCKER
            $active = 1;

            $activePudoIds[] = "'" . $id . "'";

            // Build value string for bulk insert
            $batchValues[] = "('$id', '$name', '$city', '$county', '$address', $lat, $lng, '$type', $active)";

            if (count($batchValues) >= $batchSize) {
                $this->executeBatchInsert($db, $tableName, $batchValues);
                $processedCount += count($batchValues);
                $batchValues = []; // Reset batch
            }
        }

        // Insert remaining records
        if (!empty($batchValues)) {
            $this->executeBatchInsert($db, $tableName, $batchValues);
            $processedCount += count($batchValues);
        }

        // Deactivate old PUDOs not present in the current sync
        $deactivatedCount = $this->deactivateMissingPudos($db, $tableName, $activePudoIds);

        // Update last sync time in config
        Configuration::updateValue('CARGUS_LAST_PUDO_SYNC', date('Y-m-d H:i:s'));

        return [
            'processed' => $processedCount,
            'deactivated' => $deactivatedCount
        ];
    }

    /**
     * Execute the bulk insert with ON DUPLICATE KEY UPDATE.
     */
    private function executeBatchInsert($db, string $tableName, array $values): void
    {
        $sql = "INSERT INTO `$tableName` 
                (`pudo_id`, `name`, `city`, `county`, `address`, `lat`, `lng`, `type`, `is_active`) 
                VALUES " . implode(', ', $values) . " 
                ON DUPLICATE KEY UPDATE 
                `name` = VALUES(`name`),
                `city` = VALUES(`city`),
                `county` = VALUES(`county`),
                `address` = VALUES(`address`),
                `lat` = VALUES(`lat`),
                `lng` = VALUES(`lng`),
                `type` = VALUES(`type`),
                `is_active` = 1,
                `updated_at` = CURRENT_TIMESTAMP";

        $db->execute($sql);
    }

    /**
     * Mark PUDOs as inactive if they are no longer returned by the API.
     */
    private function deactivateMissingPudos($db, string $tableName, array $activePudoIds): int
    {
        if (empty($activePudoIds)) {
            return 0;
        }

        // Disable records where ID is not in the newly fetched list
        $activeIdsString = implode(',', $activePudoIds);
        
        $sql = "UPDATE `$tableName` 
                SET `is_active` = 0 
                WHERE `is_active` = 1 AND `pudo_id` NOT IN ($activeIdsString)";

        $db->execute($sql);
        return (int)$db->Affected_Rows();
    }
}