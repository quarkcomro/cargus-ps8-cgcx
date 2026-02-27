<?php
/**
 * src/Controller/Admin/CargusReturnController.php
 */

declare(strict_types=1);

namespace Cargus\Controller\Admin;

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Cargus\Service\API\ReturnAwbService;
use Exception;

class CargusReturnController extends FrameworkBundleAdminController
{
    public function generateReturnAction(int $orderId, Request $request): RedirectResponse
    {
        try {
            $pickupType = $request->request->get('pickup_type', 'address');
            $deliveryType = $request->request->get('delivery_type', 'hq');
            $pickupPudo = $request->request->get('pickup_pudo_id', '');
            $deliveryPudo = $request->request->get('delivery_pudo_id', '');

            $returnService = new ReturnAwbService();
            $result = $returnService->generateReturnAwb($orderId, $pickupType, $deliveryType, $pickupPudo, $deliveryPudo);

            if ($result['success']) {
                $this->addFlash('success', 'AWB Retur generat: ' . $result['awb_number'] . ' (Acesta a fost trimis catre Cargus. Retururile nu se salveaza direct peste AWB-ul principal al comenzii).');
            } else {
                $this->addFlash('error', $result['message']);
            }
        } catch (Exception $e) {
            $this->addFlash('error', 'Eroare la generare retur: ' . $e->getMessage());
        }

        $referer = $request->headers->get('referer');
        return $referer ? $this->redirect($referer) : $this->redirectToRoute('admin_orders_index');
    }
}