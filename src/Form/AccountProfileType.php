<?php

namespace App\Form;

use App\Entity\Tenant\Staff;
use App\Entity\Tenant\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * "My account" — the details a person is allowed to change about themselves.
 *
 * Name, login email, phone and home address. Role and employee number are the
 * school's records, not the person's, and stay where they are.
 *
 * The email is the login identifier, so changing it is gated behind the current
 * password (checked by the controller): a session left open on a shared office
 * computer must not be enough to lock the owner out of their own account.
 */
class AccountProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Staff|null $staff */
        $staff = $options['staff'];

        $builder
            ->add('fullName', TextType::class, [
                'label' => 'Full Name',
                'constraints' => [new NotBlank()],
                'attr' => ['autocomplete' => 'name'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email address',
                'constraints' => [new NotBlank(), new Email()],
                'help' => 'This is what you sign in with. Change it here if it is wrong.',
                'attr' => ['autocomplete' => 'email'],
            ])
            ->add('currentPassword', PasswordType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Current password',
                'help' => 'Only needed if you change the email address above.',
                'attr' => ['autocomplete' => 'current-password'],
            ]);

        if ($staff !== null) {
            $builder
                ->add('phone', TextType::class, [
                    'mapped' => false,
                    'required' => false,
                    'label' => 'Phone Number',
                    'data' => $staff->getPhone(),
                    'attr' => ['inputmode' => 'tel', 'autocomplete' => 'tel'],
                ])
                ->add('address', TextareaType::class, [
                    'mapped' => false,
                    'required' => false,
                    'label' => 'Home Address',
                    'data' => $staff->getAddress(),
                    'attr' => ['rows' => 2, 'autocomplete' => 'street-address'],
                ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'staff' => null,
        ]);

        $resolver->setAllowedTypes('staff', ['null', Staff::class]);
    }
}
