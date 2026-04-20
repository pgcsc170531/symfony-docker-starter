<?php

namespace App\Form\Wizard;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

class ClassStructureType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // We get the suggestions passed from the controller
        $suggestions = $options['data']['suggestions'] ?? [];

        $builder
            ->add('selectedClasses', ChoiceType::class, [
                'label' => 'Select Classes to Create',
                'choices' => array_combine($suggestions, $suggestions), // Key=Value
                'expanded' => true, // Checkboxes
                'multiple' => true, // Allow multiple selection
                'data' => $suggestions, // Select all by default
                'attr' => ['class' => 'grid grid-cols-2 gap-4'],
                'label_attr' => ['class' => 'font-bold text-gray-700 mb-2 block']
            ])
            ->add('arms', TextType::class, [
                'label' => 'Divisions / Arms (Comma Separated)',
                'data' => 'A, B, C', // Default
                'help' => 'Leave blank if you have no divisions. Examples: "A, B, C" or "Gold, Silver, Bronze"',
                'required' => false
            ])
          ;
    }
}