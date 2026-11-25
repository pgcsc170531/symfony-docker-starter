<?php

namespace App\Form;

use App\Entity\Tenant\Staff;
use App\Entity\Tenant\User;
use App\Service\Staff\StaffImporter;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class StaffType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Staff|null $staff */
        $staff = $options['staff'];

        $builder
            ->add('fullName', TextType::class, [
                'label' => 'Full Name',
                'constraints' => [new NotBlank()],
                'attr' => ['placeholder' => 'e.g. Mr. John Doe']
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email Address',
                'required' => false,
                // Most schools hand out a phone number, not an inbox. The office
                // types the phone and the account gets <phone>@edus.ng — the same
                // derivation the student importer uses for parents. The trunk zero
                // is dropped, because PhoneNumber::national() is the one stored
                // form; typing 0803… still signs in (loginCandidates tries both).
                'help' => 'Optional. Leave it blank and the phone number becomes the login, e.g. 8031234567@edus.ng (08031234567@edus.ng also works).',
                'attr' => ['placeholder' => 'staff@school.com', 'autocomplete' => 'email']
            ])
            /*
             * Phone, home address and the staff number belong to the Staff
             * profile, not to the login account, so they are unmapped here and
             * written once by the controller. Prefilled from the existing row so
             * the edit screen shows what is actually stored.
             */
            ->add('phone', TextType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Phone Number',
                'data' => $staff?->getPhone(),
                'attr' => ['placeholder' => 'e.g. 08031234567', 'inputmode' => 'tel'],
            ])
            /*
             * The question the office already knows the answer to.
             *
             * A teacher is very often a parent here first, and the two logins
             * must not stay separate: ticking this says "match them up". The
             * matching itself (phone first, then email) is unchanged — this box
             * makes the intent explicit and turns "no parent found" into a
             * message instead of a silent second account.
             */
            ->add('existingParent', CheckboxType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'This person already has a child in this school',
                'help' => 'Tick if they already use the parent portal here. Their existing login gets staff access — the password they know keeps working — instead of a second account being created.',
                'attr' => ['class' => 'h-5 w-5 rounded border-ink-300 text-accent-600 focus:ring-accent-500'],
            ])
            ->add('address', TextareaType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Home Address',
                'data' => $staff?->getAddress(),
                'attr' => ['rows' => 2, 'placeholder' => 'e.g. 12 Kofar Kaura, Katsina'],
            ])
            ->add('employeeNumber', TextType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Staff / Employee Number',
                'data' => $staff?->getEmployeeNumber(),
            ])
            ->add('plainPassword', PasswordType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Initial Password',
                'help' => 'Leave blank to use the default password ' . StaffImporter::DEFAULT_PASSWORD . ' — the staff member is asked to change it the first time they sign in.',
                'constraints' => [new Length(['min' => 8])],
                'attr' => ['autocomplete' => 'new-password'],
            ])
            ->add('roles', ChoiceType::class, [
                'label' => 'Job Title / Role',
                'required' => true,
                'multiple' => false, // Dropdown select
                'expanded' => false,
                'choices'  => $this->roleChoices($options['allow_super_admin']),
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
            // Only the school's owner (the account created at sign-up) may mint
            // another super admin. For everyone else the choice is absent from
            // the dropdown AND from the accepted values, so a hand-crafted POST
            // cannot escalate an account either.
            'allow_super_admin' => false,
            // The Staff profile being edited, if any — used to prefill the
            // unmapped phone / address / staff-number fields.
            'staff' => null,
        ]);

        $resolver->setAllowedTypes('allow_super_admin', 'bool');
        $resolver->setAllowedTypes('staff', ['null', Staff::class]);
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