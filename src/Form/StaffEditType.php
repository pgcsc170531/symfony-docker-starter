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
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Editing an existing staff account.
 *
 * Separate from {@see StaffType} for one reason: creating an account requires a
 * password, editing one must not. A single form type forced the administrator to
 * retype a password they do not know, which is why the Edit button had nothing
 * behind it.
 *
 * The role list obeys the same rule as creation: only the school owner may add
 * the Super Admin role, and nobody may change their own role (so the last
 * administrator cannot demote themselves out of this screen).
 */
class StaffEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fullName', TextType::class, [
                'label' => 'Full Name',
                'attr' => ['placeholder' => 'e.g. Mr. John Doe'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email Address',
                'constraints' => [new NotBlank(), new Email()],
                'attr' => ['placeholder' => 'staff@school.com', 'autocomplete' => 'email'],
            ])
            ->add('plainPassword', PasswordType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'New password',
                'help' => 'Leave blank to keep the current password.',
                'constraints' => [new Length(['min' => 6])],
                'attr' => ['autocomplete' => 'new-password'],
            ])
            ->add('roles', ChoiceType::class, [
                'label' => 'Job Title / Role',
                'required' => true,
                'multiple' => false,
                'expanded' => false,
                'choices' => $this->roleChoices($options['allow_super_admin']),
                'disabled' => $options['lock_role'],
                'help' => $options['lock_role']
                    ? 'You cannot change your own role. Ask the school owner if it is wrong.'
                    : null,
            ]);

        $builder->get('roles')->addModelTransformer(new CallbackTransformer(
            static fn ($roles) => is_array($roles) && $roles !== [] ? $roles[0] : null,
            static fn ($role) => $role ? [$role] : [],
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'allow_super_admin' => false,
            'lock_role' => false,
        ]);

        $resolver->setAllowedTypes('allow_super_admin', 'bool');
        $resolver->setAllowedTypes('lock_role', 'bool');
    }

    /** @return array<string, string> */
    private function roleChoices(bool $allowSuperAdmin): array
    {
        $choices = [
            'School Administrator (Principal)' => 'ROLE_ADMIN',
            'Admission Officer' => 'ROLE_ADMISSION_OFFICER',
            'Bursar (Finance Officer)' => 'ROLE_BURSAR',
            'Store Keeper' => 'ROLE_STORE',
            'Class Teacher' => 'ROLE_TEACHER',
        ];

        if ($allowSuperAdmin) {
            $choices = ['Super Admin (School Owner)' => 'ROLE_TENANT_SUPER_ADMIN'] + $choices;
        }

        return $choices;
    }
}
