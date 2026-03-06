<?php

namespace App\Form;

use App\Entity\Tenant\DiscountType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DiscountTypeForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Discount Name (e.g. Scholarship)'])
            ->add('mode', ChoiceType::class, [
                'choices' => [
                    'Percentage (%)' => 'PERCENTAGE',
                    'Fixed Amount (₦)' => 'FIXED'
                ]
            ])
            ->add('value', NumberType::class, [
                'label' => 'Value (e.g. 50 for 50%)',
                'scale' => 2
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DiscountType::class]);
    }
}