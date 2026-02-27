<?php
/**
 * @author    Quark
 * @copyright 2026 Quark
 * @license   Proprietary
 * @version   1.0.2
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'cargus/src/Helper/CargusV3Client.php';

use Cargus\Helper\CargusV3Client;

class AdminCargusDebuggerController extends ModuleAdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->ajax = true;
    }

    public function ajaxProcessTestLocations()
    {
        $client = new CargusV3Client();
        
        try {
            // Testăm exact endpoint-ul specificat în manual (5.3)
            $response = $client->request('PickupLocations', 'GET');
            
            if (isset($response['error'])) {
                die(json_encode([
                    'success' => false,
                    // Returnăm eroarea + endpoint-ul pentru a înțelege clar dacă problema e de rută
                    'message' => 'API Error: ' . $response['error'] . ' (Endpoint verificat: /PickupLocations)'
                ]));
            }

            $count = is_array($response) ? count($response) : 0;
            
            die(json_encode([
                'success' => true,
                'message' => "Succes! Conexiune validă. Am preluat {$count} locații (PickupLocations) din Cargus V3."
            ]));
            
        } catch (Exception $e) {
            die(json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]));
        }
    }

    public function ajaxProcessTestTarife()
    {
        die(json_encode([
            'success' => true,
            'message' => 'Endpoint-ul de calculare tarife răspunde corect (Mock).'
        ]));
    }

    public function ajaxProcessTestServicii()
    {
        die(json_encode([
            'success' => true,
            'message' => 'Endpoint-ul de servicii răspunde corect (Mock).'
        ]));
    }
}
