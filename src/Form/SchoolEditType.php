<?php

namespace App\Form;

use App\Entity\Landlord\Agent;
use App\Entity\Landlord\School;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Editing an existing school.
 *
 * Deliberately narrower than {@see SchoolType}: the subdomain is shown but not
 * editable (it is the tenant's address and what the report card, emails and every
 * link the school has already handed out point at), and the principal's password is
 * optional — leaving it blank keeps the current one instead of resetting it to a
 * value nobody wrote down.
 */
class SchoolEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'School Name',
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'School is active',
                'required' => false,
                'help' => 'An inactive school is refused at sign-in and its pages return “this school is currently inactive”. Nothing is deleted.',
            ])
            ->add('principalName', TextType::class, [
                'label' => 'Owner / principal name',
            ])
            ->add('principalEmail', EmailType::class, [
                'label' => 'Owner / principal email',
                'help' => 'The address on invoices, reminders and the subscription receipt.',
            ])
            ->add('newPassword', PasswordType::class, [
                'label' => 'Set a new password',
                'mapped' => false,
                'required' => false,
                'constraints' => [new Length(['min' => 6, 'minMessage' => 'Use at least {{ limit }} characters.'])],
                'help' => 'Leave blank to keep the current password. This changes the landlord record; the school’s own logins live in its database.',
                'attr' => ['autocomplete' => 'new-password'],
            ])
            ->add('agent', EntityType::class, [
                'class' => Agent::class,
                'label' => 'Referred by (agent)',
                'required' => false,
                'placeholder' => 'Direct — no agent',
                'choice_label' => fn (Agent $agent): string => $agent->getFullName() ?? ('Agent #' . $agent->getId()),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => School::class]);
    }
}
