<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Changing your own password.
 *
 * The current password is required even for someone who was handed a temporary
 * one: it proves the person at the keyboard is the account holder and not a
 * colleague who left a session open.
 */
class ChangePasswordType extends AbstractType
{
    public const MIN_LENGTH = 8;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'mapped' => false,
                'label' => 'Current password',
                'constraints' => [new NotBlank(['message' => 'Enter your current password.'])],
                'attr' => ['autocomplete' => 'current-password'],
            ])
            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'invalid_message' => 'The two new passwords do not match.',
                'first_options' => [
                    'label' => 'New password',
                    'attr' => ['autocomplete' => 'new-password'],
                    'help' => sprintf('At least %d characters.', self::MIN_LENGTH),
                ],
                'second_options' => [
                    'label' => 'Repeat new password',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'constraints' => [
                    new NotBlank(['message' => 'Choose a new password.']),
                    new Length(['min' => self::MIN_LENGTH, 'minMessage' => 'Use at least {{ limit }} characters.']),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
