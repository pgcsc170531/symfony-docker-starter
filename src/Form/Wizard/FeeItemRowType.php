<?php

namespace App\Form\Wizard;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType; // 🟢 Needed
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class FeeItemRowType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder

            ->add('position', HiddenType::class, [ // 🟢 ADD THIS
                'attr' => ['class' => 'fee-position'] // Class for JS to find
            ])
            ->add('id', HiddenType::class, [ // 🟢 Track the ID
                'required' => false,
            ])
            ->add('isSelected', CheckboxType::class, [
                'required' => false,
                'label' => false,
            ])
            ->add('name', TextType::class, [
                'required' => false,
                'label' => false,
            ])
            ->add('frequency', ChoiceType::class, [
                'label' => false,
                'choices' => [
                    'Every Term' => 'TERM',
                    'Once per Session' => 'SESSION',
                    'One-Time (Life of Student)' => 'ONETIME',
                ],
            ])
            ->add('target', ChoiceType::class, [
                'label' => false,
                'choices' => [
                    'All Students' => 'ALL',
                    'New Intake Only' => 'NEW',
                ],
            ])
            ->add('isOptional', CheckboxType::class, [
                'required' => false,
                'label' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}