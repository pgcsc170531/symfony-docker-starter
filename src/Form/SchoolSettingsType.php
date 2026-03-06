<?php

namespace App\Form;

use App\Entity\Tenant\School; // ✅ CORRECT
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;


class SchoolSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // === 1. GENERAL INFO ===
            ->add('name', TextType::class, ['label' => 'School Name'])
            ->add('motto', TextType::class, ['label' => 'School Motto/Slogan', 'required' => false])
            ->add('email', EmailType::class, ['label' => 'Official Email'])
            ->add('phoneNumber', TextType::class, ['label' => 'Contact Number'])
            ->add('address', TextareaType::class, ['label' => 'Physical Address', 'attr' => ['rows' => 3]])
            ->add('website', UrlType::class, ['required' => false])
            
            // === 2. BRANDING ===
            ->add('primaryColor', ColorType::class, [
                'label' => 'Brand Color (for ID Cards & Header)',
                'required' => false,
            ])
            ->add('logoFile', FileType::class, [
                'label' => 'School Logo (PNG/JPG)',
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new File([
                        'maxSize' => '2M',
                        'mimeTypes' => ['image/jpeg', 'image/png'],
                    ])
                ],
            ])

            // === 3. 🏦 BANKING DETAILS (New Section) ===
            ->add('bankName', TextType::class, [
                'label' => 'Bank Name',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. Zenith Bank'],
                'help' => 'This appears on generated payment slips.'
            ])
            ->add('accountNumber', TextType::class, [
                'label' => 'Account Number',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. 1012345678']
            ])
            ->add('accountName', TextType::class, [
                'label' => 'Account Name',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. Hope High School Operations']
            ])
            ->add('smsOnEnrollment', CheckboxType::class, [
                'label' => 'New Enrollment Alerts',
                'required' => false,
                'help' => 'Send SMS to parents when a new student is enrolled (₦15.00/unit).'
            ])
            ->add('smsOnFeePayment', CheckboxType::class, [
                'label' => 'Fee Payment Receipts',
                'required' => false,
                'help' => 'Send an automated receipt to parents upon fee payment (₦15.00/unit).'
            ])
            ->add('smsOnCalendarEvent', CheckboxType::class, [
                'label' => 'Calendar & Event Reminders',
                'required' => false,
                'help' => 'Notify parents about school events and holidays (₦15.00/unit).'
            ])

        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => School::class]);
    }
}