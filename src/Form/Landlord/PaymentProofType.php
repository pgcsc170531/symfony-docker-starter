<?php
// src/Form/PaymentProofType.php

namespace App\Form\Landlord;

use App\Entity\Payment;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

class PaymentProofType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('referenceCode', TextType::class, [
                'label' => 'Bank Reference / Teller Number',
                'required' => false,
                'attr' => ['class' => 'block w-full rounded-md border-gray-300 shadow-sm sm:text-sm'],
            ])
            ->add('proofImage', FileType::class, [
                'label' => 'Upload Receipt (Image or PDF)',
                'mapped' => false, // Not mapped directly to entity because we need to handle file upload manually
                'required' => true,
                'constraints' => [
                    new File([
                        'maxSize' => '2M',
                        'mimeTypes' => ['image/jpeg', 'image/png', 'application/pdf'],
                        'mimeTypesMessage' => 'Please upload a valid image (JPG/PNG) or PDF',
                    ])
                ],
                'attr' => ['class' => 'block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Payment::class,
        ]);
    }
}