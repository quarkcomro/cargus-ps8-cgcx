/**
 * src/Form/Type/ConfigType.php
 */

<?php

namespace Cargus\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // API
            ->add('api_key', TextType::class, ['required' => false, 'label' => 'API Key'])
            ->add('username', TextType::class, ['required' => false, 'label' => 'Username'])
            ->add('password', PasswordType::class, ['required' => false, 'label' => 'Password'])

            // Extra services
            ->add('enable_cod', CheckboxType::class, ['required' => false, 'label' => 'Enable COD (account collect)'])
            ->add('enable_open_package', CheckboxType::class, ['required' => false, 'label' => 'Open package'])
            ->add('enable_declared_value', CheckboxType::class, ['required' => false, 'label' => 'Declared value'])
            ->add('enable_saturday', CheckboxType::class, ['required' => false, 'label' => 'Saturday delivery'])
            ->add('fee_saturday', TextType::class, ['required' => false, 'label' => 'Saturday fee'])

            ->add('enable_pre10', CheckboxType::class, ['required' => false, 'label' => 'PRE10'])
            ->add('fee_pre10', TextType::class, ['required' => false, 'label' => 'PRE10 fee'])

            ->add('enable_pre12', CheckboxType::class, ['required' => false, 'label' => 'PRE12'])
            ->add('fee_pre12', TextType::class, ['required' => false, 'label' => 'PRE12 fee'])

            // Quota (BO only)
            ->add('quota_source', ChoiceType::class, [
                'label' => 'Quota source',
                'choices' => [
                    'Manual' => 'manual',
                    'API (coming soon)' => 'api',
                ],
            ])
            ->add('quota_remaining', IntegerType::class, [
                'label' => 'Remaining included deliveries',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
