<?php

namespace App\Form\Wizard;

use App\Entity\Tenant\School;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

class OnboardingIdentityType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // 1. Core Identity
            ->add('name', TextType::class, [
                'label' => 'School / College Name',
                'attr' => ['placeholder' => 'e.g. Galaxy International College'],
                'constraints' => [new NotBlank()]
            ])
            ->add('institutionType', ChoiceType::class, [
                'label' => 'Institution Type',
                'choices' => [
                    'Secondary School (3 Terms)' => 'secondary',
                    'Primary / Nursery (3 Terms)' => 'primary', // 🟢 Updated Label
                    'College / Poly (2 Semesters)' => 'tertiary',
                ],
                'expanded' => true, // Radio Buttons
                'multiple' => false,
                'help' => 'Select <strong>Secondary or Primary</strong> for the standard 3-Term calendar.<br>Select <strong>College</strong> only for the Semester system.',
                'help_html' => true, // 🟢 Allows HTML in the help message above
            ])

            // 2. Branding
            ->add('motto', TextType::class, ['required' => false])
            ->add('primaryColor', ColorType::class, [
                'label' => 'Brand Color',
                'html5' => true,
                'attr' => ['class' => 'h-10 w-full cursor-pointer']
            ])
            ->add('logoFile', FileType::class, [
                'label' => 'School Logo',
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new File([
                        'maxSize' => '2M',
                        'mimeTypes' => ['image/jpeg', 'image/png'],
                        'mimeTypesMessage' => 'Please upload a valid image (JPEG/PNG)',
                    ])
                ],
            ])

            // 3. Contact Info (For Receipts)
            ->add('email', EmailType::class, ['required' => false])
            ->add('phoneNumber', TelType::class, ['required' => false])
            ->add('address', TextareaType::class, ['required' => false, 'attr' => ['rows' => 2]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => School::class,
        ]);
    }
}