<?php

namespace App\Form;

use App\Entity\Landlord\School;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType; // <--- ADD THIS IMPORT
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class SchoolType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // --- SECTION 1: SCHOOL DETAILS ---
            ->add('name', TextType::class, [
                'label' => 'School Name',
                'attr' => ['placeholder' => 'e.g. Hope High School']
            ])
            ->add('subdomain', TextType::class, [
                'label' => 'Subdomain',
                'attr' => ['placeholder' => 'hope']
            ])
            // --- THIS WAS MISSING ---
            ->add('isActive', CheckboxType::class, [
                'label' => 'School is Active',
                'required' => false,
                'data' => true, // Default to checked
            ])

            // --- SECTION 2: PRINCIPAL ACCOUNT ---
            ->add('principalName', TextType::class, [
                'mapped' => false,
                'label' => 'Principal Full Name',
                'constraints' => [new NotBlank()],
            ])
            ->add('principalEmail', EmailType::class, [
                'mapped' => false,
                'label' => 'Principal Email',
                'constraints' => [new NotBlank()],
            ])
            ->add('principalPassword', PasswordType::class, [
                'mapped' => false,
                'label' => 'Initial Password',
                'constraints' => [new NotBlank(), new Length(['min' => 6])],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => School::class,
        ]);
    }
}