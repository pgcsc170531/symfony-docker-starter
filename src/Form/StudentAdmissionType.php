<?php

namespace App\Form;

use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\Country;
use App\Entity\Tenant\LGA;
use App\Entity\Tenant\State;
use App\Entity\Tenant\Student;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\FormEvent;        // 🟢 Added for dynamic fields
use Symfony\Component\Form\FormEvents;       // 🟢 Added for dynamic fields

class StudentAdmissionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // --- SECTION 1: IDENTITY ---
            ->add('firstName', TextType::class)
            ->add('middleName', TextType::class, [
                'required' => false
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Surname'
            ])
            ->add('gender', ChoiceType::class, [
                'choices' => [
                    'Male' => 'Male',
                    'Female' => 'Female',
                ],
                'expanded' => true,
                'multiple' => false,
            ])
            ->add('dateOfBirth', DateType::class, [
                'widget' => 'single_text',
                'label' => 'Date of Birth'
            ])

            // --- SECTION 2: CLASSROOM ---
            ->add('currentClass', EntityType::class, [
                'class' => Classroom::class,
                'choice_label' => 'name',
                'placeholder' => 'Select Class',
                'label' => 'Class to Admit Into'
            ])

            // --- SECTION 3: DEMOGRAPHICS ---
            ->add('religion', ChoiceType::class, [
                'choices' => [
                    'Christianity' => 'Christianity',
                    'Islam' => 'Islam',
                    'Traditional' => 'Traditional',
                    'Other' => 'Other'
                ],
                'placeholder' => 'Select Religion',
                'required' => false
            ])
            ->add('nationality', EntityType::class, [
                'class' => Country::class,
                'choice_label' => 'name',
                'placeholder' => 'Select Country',
                'label' => 'Nationality',
                'attr' => ['id' => 'select-country'],
            ])
            ->add('stateOfOrigin', EntityType::class, [
                'class' => State::class,
                'choice_label' => 'name',
                'placeholder' => 'Select State',
                'required' => false,
                'attr' => ['id' => 'select-state'],
            ])
            ->add('lga', EntityType::class, [
                'class' => LGA::class,
                'choice_label' => 'name',
                'placeholder' => 'Select LGA',
                'label' => 'LGA',
                'required' => false,
                'attr' => ['id' => 'select-lga', 'disabled' => 'disabled'],
            ])
            ->add('homeTown', TextType::class, [
                'label' => 'Home Town',
                'required' => false
            ])

            // --- SECTION 4: MEDICAL (Important) ---
            ->add('bloodGroup', ChoiceType::class, [
                'choices' => [
                    'O+' => 'O+', 'O-' => 'O-',
                    'A+' => 'A+', 'A-' => 'A-',
                    'B+' => 'B+', 'B-' => 'B-',
                    'AB+' => 'AB+', 'AB-' => 'AB-',
                ],
                'placeholder' => 'Select Blood Group',
                'required' => false
            ])
            ->add('genotype', ChoiceType::class, [
                'choices' => [
                    'AA' => 'AA',
                    'AS' => 'AS',
                    'SS' => 'SS',
                    'AC' => 'AC',
                ],
                'placeholder' => 'Select Genotype',
                'required' => false
            ])
            ->add('medicalConditions', TextareaType::class, [
                'label' => 'Medical Conditions / Allergies',
                'required' => false,
                'attr' => ['rows' => 2, 'placeholder' => 'e.g. Asthmatic, Peanut Allergy...']
            ])

            // --- SECTION 5: HISTORY ---
            ->add('previousSchool', TextType::class, [
                'label' => 'Previous School Attended',
                'required' => false
            ]);

        // ======================================================
        // 🟢 DYNAMIC EVENT LISTENER FOR ADMISSION NUMBER
        // ======================================================
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $student = $event->getData();
            $form = $event->getForm();

            // Check if the student exists and already has an admission number
            $hasAdmissionNumber = $student && $student->getAdmissionNumber() !== null;

            // Dynamically add the field based on whether they have a number or not
            $form->add('admissionNumber', TextType::class, [
                'label' => 'Admission Number',
                'required' => false,
                'disabled' => $hasAdmissionNumber, // 🔒 Lock it ONLY if it exists
                'attr' => [
                    'placeholder' => $hasAdmissionNumber ? '' : 'Auto-generated (or enter legacy Adm No)',
                    'class' => $hasAdmissionNumber 
                        ? 'bg-gray-100 cursor-not-allowed text-gray-600 font-mono font-bold border-gray-300 w-full' 
                        : 'font-mono w-full border-gray-300 focus:ring-indigo-500 focus:border-indigo-500 rounded-lg p-3 shadow-sm'
                ]
            ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Student::class,
        ]);
    }
}