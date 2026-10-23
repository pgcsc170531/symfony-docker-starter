<?php

namespace App\Form\Wizard;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One row in the class structure: tick it, rename it, give it its own arms.
 *
 * Arms are per row on purpose. A school commonly runs JSS 1 with three arms and
 * SS 1 with two, and one shared "A, B, C" field for the whole school forced either
 * an arm that does not exist or a rename afterwards.
 */
class ClassRowType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('checked', CheckboxType::class, [
                'label' => false,
                'required' => false,
            ])
            ->add('name', TextType::class, [
                'label' => false,
                'required' => false,
            ])
            ->add('arms', TextType::class, [
                'label' => false,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
