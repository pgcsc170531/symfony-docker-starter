<?php

namespace App\Form;

use App\Entity\Landlord\School;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SchoolType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'School Name',
                'attr' => ['placeholder' => 'e.g. Hope High School']
            ])
            ->add('subdomain', TextType::class, [
                'label' => 'Subdomain',
                'attr' => ['placeholder' => 'e.g. hope']
            ])
            // We use HiddenType for databaseName because we generate it automatically in the Controller
            ->add('databaseName', HiddenType::class, [
                'mapped' => false, // We set this manually in the controller
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Is Active?',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => School::class,
        ]);
    }
}