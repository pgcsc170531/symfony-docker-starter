<?php

namespace App\Form\Wizard;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

class OnboardingCalendarType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $currentYear = date('Y');
        $nextYear = $currentYear + 1;
        $defaultSession = "$currentYear/$nextYear"; // e.g. 2025/2026

        $builder
            // 1. Session Details
            ->add('sessionName', TextType::class, [
                'label' => 'Current Academic Session',
                'data' => $defaultSession,
                'attr' => ['placeholder' => 'e.g. 2025/2026'],
                'constraints' => [new NotBlank()],
                'help' => 'This is the name of the full academic year.'
            ])
            
            // 2. Term Details (Name is auto-suggested in view, but editable)
            ->add('termName', TextType::class, [
                'label' => 'Current Term / Semester',
                'constraints' => [new NotBlank()],
                'help' => 'e.g. "1st Term" or "First Semester"'
            ])

            // 3. Dates
            ->add('startDate', DateType::class, [
                'label' => 'Resumption Date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new NotBlank()],
            ])
            ->add('endDate', DateType::class, [
                'label' => 'Vacation Date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new NotBlank()],
            ])

            ->add('save', SubmitType::class, [
                'label' => 'Set Calendar & Continue',
                'attr' => ['class' => 'bg-green-600 text-white px-8 py-3 rounded-lg font-bold shadow hover:bg-green-700 w-full md:w-auto']
            ]);
    }
}