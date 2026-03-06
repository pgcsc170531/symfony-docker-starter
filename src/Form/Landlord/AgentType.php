<?php

namespace App\Form\Landlord;

use App\Entity\Landlord\Agent;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class AgentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // 1. Check if we are Editing or Creating
        /** @var Agent|null $agent */
        $agent = $options['data'] ?? null;
        $isEdit = $agent && $agent->getId();

        // 2. Define Password Constraints Dynamically
        $passwordConstraints = [
            new Length(['min' => 6, 'minMessage' => 'Password must be at least 6 characters']),
        ];

        // Only require password if this is a NEW agent
        if (!$isEdit) {
            $passwordConstraints[] = new NotBlank(['message' => 'Please enter a password']);
        }

        $builder
            ->add('fullName', TextType::class, [
                'label' => 'Full Name',
                'attr' => ['placeholder' => 'e.g. John Doe']
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email Address',
                'attr' => ['placeholder' => 'agent@example.com']
            ])
            ->add('phoneNumber', TextType::class, [
                'label' => 'Phone Number',
                'required' => false
            ])
            
            // 3. Dynamic Password Field
            ->add('plainPassword', PasswordType::class, [
                'mapped' => false,
                'required' => !$isEdit, // True if New, False if Edit
                'label' => $isEdit ? 'New Password (Optional)' : 'Initial Password',
                'help' => $isEdit ? 'Leave blank to keep current password.' : 'The agent will use this to log in.',
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => $passwordConstraints, // Uses the dynamic array above
            ])

            ->add('commissionPercentage', NumberType::class, [
                'label' => 'Commission (%)',
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01', 'placeholder' => '10.00']
            ])
            
            ->add('bankDetails', TextareaType::class, [
                'label' => 'Bank Details',
                'required' => false,
                'attr' => ['rows' => 3, 'placeholder' => 'Bank Name, Account Number, etc.']
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Agent::class,
        ]);
    }
}