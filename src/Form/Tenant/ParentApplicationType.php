<?php

namespace App\Form\Tenant;

use App\Entity\Tenant\Application;
use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\Country;
use App\Entity\Tenant\LGA;
use App\Entity\Tenant\State;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;

/**
 * The parent-facing child application form (docs/parent-application/DESIGN.md §8 item 5).
 *
 * One form, five visual steps (Alpine tabs in the template). Everything is required except
 * the fields explicitly marked otherwise — a draft may be saved at any point regardless,
 * validation only bites on submit (`submit` action re-validates the whole form).
 */
class ParentApplicationType extends AbstractType
{
    /** Shared Tailwind classes so every wizard input looks identical. */
    public const INPUT = 'w-full rounded-lg border-gray-300 p-3 shadow-sm focus:ring-indigo-500 focus:border-indigo-500';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // ── Step 1 · The child ──────────────────────────────────────────────
            ->add('firstName', TextType::class, [
                'label' => "Child's first name",
                'attr' => ['class' => self::INPUT, 'placeholder' => 'e.g. Aisha'],
            ])
            ->add('middleName', TextType::class, [
                'label' => 'Middle name',
                'required' => false,
                'attr' => ['class' => self::INPUT],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Surname',
                'attr' => ['class' => self::INPUT, 'placeholder' => 'e.g. Bello'],
            ])
            ->add('dateOfBirth', DateType::class, [
                'label' => 'Date of birth',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => [
                    'class' => self::INPUT,
                    'max' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
                ],
                'constraints' => [new LessThanOrEqual('today')],
            ])
            ->add('gender', ChoiceType::class, [
                'label' => 'Gender',
                'choices' => ['Male' => 'Male', 'Female' => 'Female'],
                'expanded' => true,
                'multiple' => false,
            ])
            ->add('religion', ChoiceType::class, [
                'label' => 'Religion',
                'choices' => [
                    'Christianity' => 'Christianity',
                    'Islam' => 'Islam',
                    'Traditional' => 'Traditional',
                    'Other' => 'Other',
                ],
                'placeholder' => 'Select religion',
                'required' => false,
                'attr' => ['class' => self::INPUT],
            ])
            ->add('requestedClassroom', EntityType::class, [
                'class' => Classroom::class,
                'choice_label' => 'name',
                'label' => 'Class applying for',
                'placeholder' => 'Select class',
                'attr' => ['class' => self::INPUT],
            ])

            // ── Step 2 · Origin & health ────────────────────────────────────────
            ->add('nationality', EntityType::class, [
                'class' => Country::class,
                'choice_label' => 'name',
                'label' => 'Nationality',
                'placeholder' => 'Select country',
                'attr' => ['class' => self::INPUT],
            ])
            ->add('stateOfOrigin', EntityType::class, [
                'class' => State::class,
                'choice_label' => 'name',
                'label' => 'State of origin',
                'placeholder' => 'Select state',
                'required' => false,
                'attr' => ['class' => self::INPUT],
            ])
            ->add('lga', EntityType::class, [
                'class' => LGA::class,
                'choice_label' => 'name',
                'label' => 'Local Government Area',
                'placeholder' => 'Select LGA',
                'required' => false,
                'attr' => ['class' => self::INPUT],
            ])
            ->add('homeTown', TextType::class, [
                'label' => 'Home town',
                'required' => false,
                'attr' => ['class' => self::INPUT],
            ])
            ->add('bloodGroup', ChoiceType::class, [
                'label' => 'Blood group',
                'choices' => [
                    'O+' => 'O+', 'O-' => 'O-',
                    'A+' => 'A+', 'A-' => 'A-',
                    'B+' => 'B+', 'B-' => 'B-',
                    'AB+' => 'AB+', 'AB-' => 'AB-',
                ],
                'placeholder' => 'Select blood group',
                'required' => false,
                'attr' => ['class' => self::INPUT],
            ])
            ->add('genotype', ChoiceType::class, [
                'label' => 'Genotype',
                'choices' => ['AA' => 'AA', 'AS' => 'AS', 'SS' => 'SS', 'AC' => 'AC'],
                'placeholder' => 'Select genotype',
                'required' => false,
                'attr' => ['class' => self::INPUT],
            ])
            ->add('medicalConditions', TextareaType::class, [
                'label' => 'Medical conditions / allergies',
                'required' => false,
                'attr' => [
                    'class' => self::INPUT,
                    'rows' => 3,
                    'placeholder' => 'e.g. Asthmatic, peanut allergy. Write "None" if there are none.',
                ],
            ])

            // ── Step 3 · Previous school ────────────────────────────────────────
            ->add('previousSchool', TextType::class, [
                'label' => 'Previous school attended',
                'required' => false,
                'attr' => ['class' => self::INPUT, 'placeholder' => 'Name of the last school'],
            ])
            ->add('previousClass', TextType::class, [
                'label' => 'Last class completed there',
                'required' => false,
                'attr' => ['class' => self::INPUT, 'placeholder' => 'e.g. Primary 5'],
            ])

            // ── Step 4 · Guardian ───────────────────────────────────────────────
            // Name / phone / email are the verified account details, so they are shown
            // read-only. The controller re-sets them from the Guardian on every save, so
            // a tampered request cannot change the SMS channel on the application.
            ->add('guardianName', TextType::class, [
                'label' => 'Your name',
                'disabled' => true,
                'attr' => ['class' => self::INPUT . ' bg-gray-100 text-gray-600 cursor-not-allowed'],
            ])
            ->add('relationshipToStudent', TextType::class, [
                'label' => 'Relationship to the child',
                'disabled' => true,
                'attr' => ['class' => self::INPUT . ' bg-gray-100 text-gray-600 cursor-not-allowed'],
            ])
            ->add('guardianPhone', TextType::class, [
                'label' => 'Phone number (used for school SMS)',
                'disabled' => true,
                'attr' => ['class' => self::INPUT . ' bg-gray-100 text-gray-600 cursor-not-allowed'],
            ])
            ->add('guardianEmail', TextType::class, [
                'label' => 'Email address',
                'disabled' => true,
                'attr' => ['class' => self::INPUT . ' bg-gray-100 text-gray-600 cursor-not-allowed'],
            ])
            ->add('guardianAlternatePhone', TextType::class, [
                'label' => 'Alternate phone (optional)',
                'required' => false,
                'attr' => ['class' => self::INPUT],
            ])
            ->add('guardianOccupation', TextType::class, [
                'label' => 'Occupation',
                'required' => false,
                'attr' => ['class' => self::INPUT],
            ])
            ->add('guardianAddress', TextareaType::class, [
                'label' => 'Home address',
                'required' => false,
                'attr' => ['class' => self::INPUT, 'rows' => 2],
            ])
            ->add('guardianOfficeAddress', TextareaType::class, [
                'label' => 'Office address',
                'required' => false,
                'attr' => ['class' => self::INPUT, 'rows' => 2],
            ])

            // ── Step 5 · Declaration (not part of the entity) ───────────────────
            ->add('declaration', CheckboxType::class, [
                'mapped' => false,
                'label' => 'I declare that the information given above is true and correct, '
                    . 'and I consent to the school processing it for this admission.',
                'constraints' => [
                    new IsTrue(message: 'Please tick the declaration before submitting.'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Application::class,
        ]);
    }
}
