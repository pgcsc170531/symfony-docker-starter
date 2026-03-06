<?php

namespace App\Form;

use App\Entity\Tenant\Term;
use App\Entity\Tenant\Session; // 💡 Assuming you link to the Session entity
use Symfony\Bridge\Doctrine\Form\Type\EntityType; // 💡 Needed for the Session field
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\DateType; // 💡 Necessary for date fields
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TermType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('session', EntityType::class, [ // 💡 Add the ManyToOne relationship field
                'class' => Session::class,
                'choice_label' => 'name', // Display the session name (e.g., 2025/2026)
                'label' => 'Academic Session',
                'placeholder' => 'Select Academic Session',
            ])
            ->add('name', TextType::class, [
                'label' => 'Term Name',
                'attr' => ['placeholder' => 'e.g. First Term']
            ])
            
            // 💡 CRITICAL: ADD THE NEW START DATE FIELD
            ->add('startDate', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable', // Matches the entity property type
                'label' => 'Start Date',
                'required' => true, // Term must have a start date
            ])
            
            // 💡 CRITICAL: ADD THE NEW END DATE FIELD
            ->add('endDate', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable', // Matches the entity property type
                'label' => 'End Date',
                'required' => true, // Term must have an end date
            ])
            
            ->add('isActive', CheckboxType::class, [
                'label' => 'Set as Active Term?',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Term::class]);
    }
}