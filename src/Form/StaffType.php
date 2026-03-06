<?php

namespace App\Form;

use App\Entity\Tenant\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class StaffType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fullName', TextType::class, [
                'label' => 'Full Name',
                'attr' => ['placeholder' => 'e.g. Mr. John Doe']
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email Address',
                'attr' => ['placeholder' => 'staff@school.com']
            ])
            ->add('plainPassword', PasswordType::class, [
                'mapped' => false,
                'label' => 'Initial Password',
                'constraints' => [new NotBlank(), new Length(['min' => 6])],
            ])
            ->add('roles', ChoiceType::class, [
                'label' => 'Job Title / Role',
                'required' => true,
                'multiple' => false, // Dropdown select
                'expanded' => false, 
                'choices'  => [
                    'School Administrator (Principal)' => 'ROLE_ADMIN',
                    'Bursar (Finance Officer)' => 'ROLE_BURSAR',
                    'Store Keeper' => 'ROLE_STORE',
                    'Class Teacher' => 'ROLE_TEACHER',
                ],
            ])
        ;

        // Data Transformer: Convert Array ['ROLE_ADMIN'] to String 'ROLE_ADMIN' for the dropdown
        $builder->get('roles')
            ->addModelTransformer(new CallbackTransformer(
                function ($rolesArray) {
                    return count($rolesArray ?? []) ? $rolesArray[0] : null;
                },
                function ($rolesString) {
                    return [$rolesString];
                }
            ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}