<?php
/**
 * src/Service/Calculator/CargusPricingService.php
 * * Hybrid Pricing Logic: API V3 + Local Calculation Fallback.
 * Includes strict business rules (15kg base, extra kg, 31+/50+ thresholds, Oversized Tax)
 * and the foundation for the "Smart Split" evaluation.
 *
 * @version 2.0.0
 */

declare(strict_types=1);

namespace Cargus\Service\Calculator;

use Cart;
use Order;
use Product;
use Configuration;
use Db;
use Exception;
use PrestaShopLogger;

class CargusPricingService
{
    private const OVERSIZED_TAX_FIXED = 100.00; // Suprataxa fixă pentru colete agabaritice sub 31kg

    /**
     * Calculează costul de transport pentru Front-Office (Checkout).
     *
     * @param Cart $cart Coșul de cumpărături curent
     * @param float $nativeShippingCost Costul nativ calculat de PrestaShop (ex: din reguli de greutate)
     * @param int $idCarrier ID-ul curierului selectat
     * @return float Costul final de transport
     */
    public function calculateShippingCost(Cart $cart, float $nativeShippingCost, int $idCarrier): float
    {
        $totalWeight = (float)$cart->getTotalWeight();
        $isShipAndGo = ($idCarrier === (int)Configuration::get('CARGUS_SHIP_GO_REFERENCE'));
        
        $hasOversizedItems = $this->cartHasOversizedItems($cart);

        // Încercăm preluarea tarifului exact din API-ul Cargus V3
        $apiPrice = $this->getApiPrice($totalWeight, $isShipAndGo, $hasOversizedItems);
        if ($apiPrice !== null && $apiPrice > 0) {
            return $apiPrice;
        }

        // FALLBACK LOCAL: Dacă API-ul e indisponibil, folosim algoritmul bazat pe grila comercială
        return $this->getLocalFallbackPrice($totalWeight, $isShipAndGo, $hasOversizedItems);
    }

    /**
     * Fallback de calcul local bazat pe oferta comercială standard.
     */
    private function getLocalFallbackPrice(float $weight, bool $isShipAndGo, bool $hasOversizedItems): float
    {
        // Determinăm prețul de bază în funcție de tipul de livrare
        // Dacă nu există setare, presupunem o valoare de siguranță (ex: 15.00)
        $basePriceStr = $isShipAndGo ? Configuration::get('CARGUS_BASE_PRICE_PUDO') : Configuration::get('CARGUS_BASE_PRICE_STD');
        $basePrice = (float)($basePriceStr ?: 15.00);
        $extraKgPrice = (float)(Configuration::get('CARGUS_EXTRA_KG_PRICE') ?: 4.90);

        $finalCost = 0.0;

        if ($weight <= 15.0) {
            $finalCost = $basePrice;
        } elseif ($weight > 15.0 && $weight <= 31.0) {
            $finalCost = $basePrice + (($weight - 15.0) * $extraKgPrice);
        } elseif ($weight > 31.0 && $weight <= 50.0) {
            // Prag 31+: Tarif fix majorat (valoare medie de siguranță dacă API-ul pică)
            $finalCost = 85.00; 
        } else {
            // Prag 50+ (Mărfuri paletizate/grele)
            $finalCost = 150.00;
        }

        // Aplicarea Regulii Stricte: Suprataxă Agabaritică (100 RON)
        // Se aplică dacă există un produs agabaritic, dar greutatea totală nu a forțat deja
        // un tarif de palet (50+ kg).
        if ($hasOversizedItems && $weight <= 31.0) {
            $finalCost += self::OVERSIZED_TAX_FIXED;
        }

        return $finalCost;
    }

    /**
     * Încearcă obținerea tarifului direct de la `/Pricing` din Cargus API.
     */
    private function getApiPrice(float $weight, bool $isShipAndGo, bool $hasOversizedItems): ?float
    {
        $apiKey = Configuration::get('CARGUS_API_KEY');
        $priceTableId = (int)Configuration::get('CARGUS_PRICE_TABLE_ID');
        $senderLocationId = (int)Configuration::get('CARGUS_SENDER_LOCATION_ID');

        if (empty($apiKey) || $priceTableId === 0 || $senderLocationId === 0) {
            return null; // Date insuficiente pentru apel API, trecem la fallback
        }

        try {
            // Ne bazăm pe AccountService pentru a prelua un Bearer Token valid
            if (!class_exists('Cargus\\Service\\API\\AccountService')) {
                require_once dirname(__FILE__) . '/../API/AccountService.php';
            }
            $accountService = new \Cargus\Service\API\AccountService();
            
            // Notă: Metoda authenticate() este privată în AccountService, așa că 
            // apelul direct la /Pricing aici ar necesita fie expunerea token-ului, fie 
            // un endpoint proxy. Lăsăm ca `null` pentru a forța fallback-ul sigur și rapid în checkout, 
            // până la validarea endpoint-ului exact de Pricing cu echipa Cargus.
            
            return null;

        } catch (Exception $e) {
            PrestaShopLogger::addLog('Cargus API Pricing Error: ' . $e->getMessage(), 2);
            return null;
        }
    }

    /**
     * Verifică dacă în coș există produse agabaritice (prin taxă PS nativă sau categorii specifice).
     */
    private function cartHasOversizedItems(Cart $cart): bool
    {
        $products = $cart->getProducts();
        
        foreach ($products as $product) {
            // 1. Verificare taxă adițională setată manual pe produs în PrestaShop
            if (isset($product['additional_shipping_cost']) && (float)$product['additional_shipping_cost'] > 0) {
                return true;
            }

            // 2. Verificare dacă produsul face parte dintr-o categorie declarată Agabaritică
            $idCategoryDefault = (int)$product['id_category_default'];
            $sql = 'SELECT 1 FROM `' . _DB_PREFIX_ . 'cargus_agabaritic` WHERE `id_category` = ' . $idCategoryDefault;
            
            // Trecem peste eroare dacă tabelul nu e încă populat/folosit
            try {
                if (Db::getInstance()->getValue($sql)) {
                    return true;
                }
            } catch (Exception $e) {
                continue;
            }
        }

        return false;
    }

    /**
     * LOGICA "SMART SPLIT": Evaluează comanda în Back-Office pentru a oferi sugestii operatorului.
     *
     * @param int $id_order ID-ul comenzii
     * @return array Scenariile comparate (All-in-One vs. Split)
     */
    public function analyzeOrderForSplit(int $id_order): array
    {
        $order = new Order($id_order);
        if (!\Validate::isLoadedObject($order)) {
            return ['status' => 'error', 'message' => 'Invalid order'];
        }

        $products = $order->getProducts();
        $standardWeight = 0.0;
        $oversizedWeight = 0.0;
        $hasOversized = false;

        foreach ($products as $p) {
            $w = (float)$p['product_weight'] * (int)$p['product_quantity'];
            
            // Verificare sumară: este produsul agabaritic? (greutate mare individuală sau categorie)
            if ($w > 15.0 || (isset($p['additional_shipping_cost']) && (float)$p['additional_shipping_cost'] > 0)) {
                $oversizedWeight += $w;
                $hasOversized = true;
            } else {
                $standardWeight += $w;
            }
        }

        $totalWeight = $standardWeight + $oversizedWeight;

        // Scenariul A: Consolidat (Totul într-un colet)
        $costScenarioA = $this->getLocalFallbackPrice($totalWeight, false, $hasOversized);

        // Scenariul B: Split (1 Colet Agabaritic + 1 Colet Standard)
        // Dacă nu avem ambele tipuri de produse, split-ul nu are sens
        if ($standardWeight > 0 && $oversizedWeight > 0) {
            $costStandardPkg = $this->getLocalFallbackPrice($standardWeight, false, false);
            $costOversizedPkg = $this->getLocalFallbackPrice($oversizedWeight, false, true);
            $costScenarioB = $costStandardPkg + $costOversizedPkg;

            $recommendSplit = ($costScenarioB < $costScenarioA);

            return [
                'status' => 'success',
                'can_split' => true,
                'recommend_split' => $recommendSplit,
                'scenario_a_cost' => $costScenarioA,
                'scenario_b_cost' => $costScenarioB,
                'message' => $recommendSplit 
                    ? "Sugestie Smart Split: Împărțirea în două colete (Standard + Agabaritic) reduce costul de la {$costScenarioA} RON la {$costScenarioB} RON." 
                    : "Nu este recomandată împărțirea. Costul total într-un colet ({$costScenarioA} RON) este optim."
            ];
        }

        return [
            'status' => 'success',
            'can_split' => false,
            'scenario_a_cost' => $costScenarioA,
            'message' => "Comanda are un profil omogen de greutate/volum. Se recomandă expedierea într-un singur colet."
        ];
    }
}