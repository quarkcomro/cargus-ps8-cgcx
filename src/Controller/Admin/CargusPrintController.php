<?php
/**
 * src/Controller/Admin/CargusPrintController.php
 * Controller to handle the Print AWB request in Back-Office.
 */

declare(strict_types=1);

namespace Cargus\Controller\Admin;

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Cargus\Service\API\PrintAwbService;
use Exception;

class CargusPrintController extends FrameworkBundleAdminController
{
    /**
     * Action to print/download AWB PDF.
     *
     * @param string $awbNumber
     * @return Response|RedirectResponse
     */
    public function printAwbAction(string $awbNumber)
    {
        try {
            $printService = new PrintAwbService();
            $pdfContent = $printService->getPdf($awbNumber);

            // Return the raw PDF bytes with correct headers so the browser opens it as a PDF
            return new Response(
                $pdfContent,
                200,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="Cargus_AWB_' . $awbNumber . '.pdf"'
                ]
            );

        } catch (Exception $e) {
            $this->addFlash('error', $this->trans('An error occurred while printing the AWB: %error%', 'Modules.Cargus.Admin', ['%error%' => $e->getMessage()]));
            
            // Go back to previous page
            return $this->redirect($this->generateUrl('admin_orders_index'));
        }
    }
}