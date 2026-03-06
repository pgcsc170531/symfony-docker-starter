<?php

namespace App\Form\Landlord;

use App\Entity\Landlord\GlobalSetting;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class GlobalSettingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('settingKey', TextType::class, [
                'label' => 'Setting Key',
                'disabled' => true, // 🛡️ Prevent breaking the logic by changing the key name
                'help' => 'This identifier is used by the system and cannot be changed.'
            ])
            ->add('settingValue', TextType::class, [
                'label' => 'Value',
                'attr' => ['placeholder' => 'e.g. 15.00']
            ])
            ->add('description', TextType::class, [
                'label' => 'Purpose',
                'required' => false,
                'attr' => ['placeholder' => 'Describe what this setting controls...']
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GlobalSetting::class,
        ]);
    }
}