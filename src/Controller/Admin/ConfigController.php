/**
 * src/Controller/Admin/AdminConfigController.php
 */

<?php

namespace Cargus\Controller\Admin;

use Cargus\Form\Type\ConfigType;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ConfigController extends FrameworkBundleAdminController
{
    public function index(Request $request): Response
    {
        // Load current configuration (Phase 1: store in ps_configuration; DB later)
        $data = [
            'api_key' => (string) \Configuration::get('CARGUS_API_KEY'),
            'username' => (string) \Configuration::get('CARGUS_USERNAME'),
            'password' => (string) \Configuration::get('CARGUS_PASSWORD'),

            // Extra services (Phase 1: toggles + fees; validation in FormType)
            'enable_cod' => (bool) \Configuration::get('CARGUS_ENABLE_COD'),
            'enable_open_package' => (bool) \Configuration::get('CARGUS_ENABLE_OPEN_PACKAGE'),
            'enable_declared_value' => (bool) \Configuration::get('CARGUS_ENABLE_DECLARED_VALUE'),
            'enable_saturday' => (bool) \Configuration::get('CARGUS_ENABLE_SATURDAY'),
            'enable_pre10' => (bool) \Configuration::get('CARGUS_ENABLE_PRE10'),
            'enable_pre12' => (bool) \Configuration::get('CARGUS_ENABLE_PRE12'),

            'fee_saturday' => (string) \Configuration::get('CARGUS_FEE_SATURDAY'),
            'fee_pre10' => (string) \Configuration::get('CARGUS_FEE_PRE10'),
            'fee_pre12' => (string) \Configuration::get('CARGUS_FEE_PRE12'),

            // Quota (BO only) – manual for now
            'quota_source' => (string) (\Configuration::get('CARGUS_QUOTA_SOURCE') ?: 'manual'),
            'quota_remaining' => (int) (\Configuration::get('CARGUS_QUOTA_REMAINING') ?: 0),
        ];

        $form = $this->createForm(ConfigType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $values = $form->getData();

            // Persist in ps_configuration (DB tables later)
            \Configuration::updateValue('CARGUS_API_KEY', (string) $values['api_key']);
            \Configuration::updateValue('CARGUS_USERNAME', (string) $values['username']);
            \Configuration::updateValue('CARGUS_PASSWORD', (string) $values['password']);

            \Configuration::updateValue('CARGUS_ENABLE_COD', (int) $values['enable_cod']);
            \Configuration::updateValue('CARGUS_ENABLE_OPEN_PACKAGE', (int) $values['enable_open_package']);
            \Configuration::updateValue('CARGUS_ENABLE_DECLARED_VALUE', (int) $values['enable_declared_value']);
            \Configuration::updateValue('CARGUS_ENABLE_SATURDAY', (int) $values['enable_saturday']);
            \Configuration::updateValue('CARGUS_ENABLE_PRE10', (int) $values['enable_pre10']);
            \Configuration::updateValue('CARGUS_ENABLE_PRE12', (int) $values['enable_pre12']);

            \Configuration::updateValue('CARGUS_FEE_SATURDAY', (string) $values['fee_saturday']);
            \Configuration::updateValue('CARGUS_FEE_PRE10', (string) $values['fee_pre10']);
            \Configuration::updateValue('CARGUS_FEE_PRE12', (string) $values['fee_pre12']);

            \Configuration::updateValue('CARGUS_QUOTA_SOURCE', (string) $values['quota_source']);
            \Configuration::updateValue('CARGUS_QUOTA_REMAINING', (int) $values['quota_remaining']);

            $this->addFlash('success', $this->trans('Settings saved.', 'Admin.Notifications.Success'));

            // Redirect to avoid resubmission
            return $this->redirectToRoute('cargus_admin_config');
        }

        return $this->render('@Modules/cargus/views/templates/admin/configure.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
