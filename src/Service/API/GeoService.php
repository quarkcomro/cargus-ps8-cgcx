<?php
/**
 * src/Service/API/GeoService.php
 * Sincronizează nomenclatura geografică din API-ul Cargus în cache-ul local.
 */

declare(strict_types=1);

namespace Cargus\Service\API;

use Db;
use Configuration;

class GeoService
{
    /**
     * Sincronizează localitățile din API și le salvează în baza de date.
     * * @return bool
     */
    public function syncLocalities(): bool
    {
        $apiKey = Configuration::get('CARGUS_API_KEY');
        if (empty($apiKey)) {
            return false;
        }

        $url = rtrim(Configuration::get('CARGUS_API_URL') ?: 'https://api.cargus.ro', '/') . '/Localities';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Ocp-Apim-Subscription-Key: ' . $apiKey,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Prevenim blocarea serverului la volume mari de date
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || empty($response)) {
            return false;
        }

        $localities = json_decode($response, true);
        
        if (is_array($localities) && !empty($localities)) {
            // Curățăm tabelul cache înainte de o nouă sincronizare
            Db::getInstance()->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . 'cargus_geo_cache`');
            
            // Inserare optimizată rând cu rând (ideal ar fi un bulk insert, dar pentru stabilitate folosim insert simplu)
            foreach ($localities as $loc) {
                Db::getInstance()->insert('cargus_geo_cache', [
                    'cargus_city_id' => pSQL((string)$loc['LocalityId']),
                    'city_name' => pSQL($loc['Name']),
                    'county_name' => pSQL($loc['CountyName']),
                    'normalized_name' => pSQL(self::normalize($loc['Name']))
                ]);
            }
            return true;
        }
        
        return false;
    }

    /**
     * Normalizează un string: elimină spațiile albe extra, transformă în lowercase 
     * și înlocuiește diacriticele românești (inclusiv variantele sedilă vs virgulă).
     * * @param string $text Textul original (ex: județ sau localitate)
     * @return string Textul normalizat
     */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        
        $search = [
            'ă', 'â', 'î', 'ș', 'ț', // Virgula standard
            'ş', 'ţ',                // Sedila (legacy)
            'á', 'é', 'í', 'ó', 'ú'  // Accente maghiare/alte limbi des întâlnite în toponimie
        ];
        $replace = [
            'a', 'a', 'i', 's', 't', 
            's', 't',
            'a', 'e', 'i', 'o', 'u'
        ];
        
        return str_replace($search, $replace, $text);
    }
}