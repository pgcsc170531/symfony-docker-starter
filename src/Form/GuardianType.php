<?php

namespace App\Form;

use App\Entity\Tenant\Guardian;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class GuardianType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', ChoiceType::class, [
                'choices' => [
                    'Mr.' => 'Mr.',
                    'Mrs.' => 'Mrs.',
                    'Dr.' => 'Dr.',
                    'Chief' => 'Chief',
                    'Engr.' => 'Engr.',
                    'Pastor' => 'Pastor',
                    'Imam' => 'Imam',
                ],
                'placeholder' => 'Select Title',
                'required' => false,
                'attr' => ['class' => 'form-select']
            ])
            ->add('fullName', TextType::class, [
                'label' => 'Full Name (Surname First)',
                'attr' => ['placeholder' => 'e.g. ADEBAYO, John Doe']
            ])
            ->add('email', EmailType::class)
            ->add('phoneNumber', TelType::class, [
                'label' => 'Primary Phone',
                'attr' => ['placeholder' => '080...']
            ])
            ->add('alternatePhoneNumber', TelType::class, [
                'label' => 'Alternate Phone',
                'required' => false,
                'attr' => ['placeholder' => 'Optional']
            ])
            ->add('relationshipToStudent', ChoiceType::class, [
                'choices' => [
                    'Father' => 'Father',
                    'Mother' => 'Mother',
                    'Uncle' => 'Uncle',
                    'Aunt' => 'Aunt',
                    'Grandparent' => 'Grandparent',
                    'Guardian' => 'Guardian',
                ],
                'placeholder' => 'Relationship',
            ])
            ->add('occupation', TextType::class, [
                'label' => 'Occupation',
                'required' => false,
                'help' => 'Required for financial assessment'
            ])
            ->add('address', TextareaType::class, [
                'label' => 'Home Address',
                'attr' => ['rows' => 2]
            ])
            ->add('officeAddress', TextareaType::class, [
                'label' => 'Office Address',
                'required' => false,
                'attr' => ['rows' => 2]
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Guardian::class,
        ]);
    }
}