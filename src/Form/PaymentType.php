<?php

namespace App\Form;

use App\Entity\Tenant\Payment;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PaymentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('amount', MoneyType::class, [
                'currency' => 'NGN',
                'label' => 'Amount Paying (₦)',
                'attr' => ['class' => 'font-bold text-lg']
            ])
            ->add('method', ChoiceType::class, [
                'choices'  => [
                    'Cash' => 'CASH',
                    'Bank Transfer' => 'TRANSFER',
                    'POS' => 'POS',
                ],
            ])
            ->add('reference', TextType::class, [
                'label' => 'Reference / Teller No.',
                'required' => false,
                'attr' => ['placeholder' => 'Optional']
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Payment::class,
        ]);
    }
}