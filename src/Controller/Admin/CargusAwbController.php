<?php
/**
 * src/Controller/Admin/CargusAwbController.php
 */

declare(strict_types=1);

namespace Cargus\Controller\Admin;

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Cargus\Service\API\AwbService;
use Exception;

class CargusAwbController extends FrameworkBundleAdminController
{
    /**
     * Action to generate AWB for a specific order.
     *
     * @param int $orderId
     * @param Request $request
     * @return RedirectResponse
     */
    public function generateAwbAction(int $orderId, Request $request): RedirectResponse
    {
        try {
            // Instantiate the service
            $awbService = new AwbService();
            
            // Call the generation logic
            $result = $awbService->generateAwb($orderId);

            if ($result['success']) {
                $this->addFlash('success', $this->trans('AWB successfully generated: %awb%', 'Modules.Cargus.Admin', ['%awb%' => $result['awb_number']]));
            } else {
                $this->addFlash('error', $result['message']);
            }

        } catch (Exception $e) {
            $this->addFlash('error', $this->trans('An error occurred while generating the AWB: %error%', 'Modules.Cargus.Admin', ['%error%' => $e->getMessage()]));
        }

        // Redirect back to the order view page or the referring page
        $referer = $request->headers->get('referer');
        if ($referer) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('admin_orders_index');
    }
}