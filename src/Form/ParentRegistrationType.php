<?php

namespace App\Form;

use App\Entity\Tenant\Guardian;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Parent self-registration — the public "create my parent portal account" form.
 *
 * Deliberately shorter than the bursar's GuardianType: a parent typing on a
 * phone with a poor connection must be able to finish this in under a minute.
 */
class ParentRegistrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', ChoiceType::class, [
                'label' => 'Title',
                'required' => false,
                'placeholder' => 'Choose…',
                'choices' => [
                    'Mr' => 'Mr', 'Mrs' => 'Mrs', 'Miss' => 'Miss', 'Dr' => 'Dr',
                    'Chief' => 'Chief', 'Engr' => 'Engr', 'Alhaji' => 'Alhaji', 'Hajiya' => 'Hajiya',
                ],
            ])
            ->add('fullName', TextType::class, [
                'label' => 'Your full name',
                'attr' => ['autocomplete' => 'name', 'placeholder' => 'e.g. Ibrahim Sanusi'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Please enter your full name.'),
                    new Assert\Length(min: 3, max: 255),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email address',
                'help' => 'Your verification link and sign-in links are sent here.',
                'attr' => ['autocomplete' => 'email', 'placeholder' => 'you@example.com'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Please enter your email address.'),
                    new Assert\Email(message: 'That does not look like a valid email address.'),
                    new Assert\Length(max: 255),
                ],
            ])
            ->add('phoneNumber', TelType::class, [
                'label' => 'Phone number',
                'help' => 'Used for school SMS notices about your child.',
                'attr' => ['autocomplete' => 'tel', 'placeholder' => '08031234567'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Please enter your phone number.'),
                    new Assert\Regex(
                        pattern: '/^[0-9+\s-]{10,15}$/',
                        message: 'Enter a valid phone number, for example 08031234567.'
                    ),
                ],
            ])
            ->add('alternatePhoneNumber', TelType::class, [
                'label' => 'Alternate phone (optional)',
                'required' => false,
            ])
            ->add('relationshipToStudent', ChoiceType::class, [
                'label' => 'Your relationship to the child',
                'placeholder' => 'Choose…',
                'choices' => [
                    'Father' => 'Father', 'Mother' => 'Mother', 'Guardian' => 'Guardian',
                    'Uncle' => 'Uncle', 'Aunt' => 'Aunt', 'Other' => 'Other',
                ],
            ])
            ->add('occupation', TextType::class, [
                'label' => 'Occupation (optional)',
                'required' => false,
            ])
            ->add('address', TextareaType::class, [
                'label' => 'Home address (optional)',
                'required' => false,
                'attr' => ['rows' => 2, 'placeholder' => 'Street, area, town'],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'invalid_message' => 'The two passwords do not match.',
                'first_options' => ['label' => 'Create a password'],
                'second_options' => ['label' => 'Repeat password'],
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Please choose a password.'),
                    new Assert\Length(
                        min: 8,
                        minMessage: 'Use at least {{ limit }} characters.',
                        max: 72
                    ),
                    new Assert\NotEqualTo(value: 'password', message: 'That password is too easy to guess.'),
                    new Assert\NotEqualTo(value: '12345678', message: 'That password is too easy to guess.'),
                    new Assert\NotEqualTo(value: 'password123', message: 'That password is too easy to guess.'),
                ],
            ])
            ->add('consent', CheckboxType::class, [
                'label' => 'I agree that the school may store these details to process my child\'s application.',
                'mapped' => false,
                'constraints' => [
                    new Assert\IsTrue(message: 'Please accept the data-use notice to continue.'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Guardian::class,
        ]);
    }
}
