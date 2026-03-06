<?php

namespace App\Form;

use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\FeeItem;
use App\Entity\Tenant\FeeStructure;
use App\Entity\Tenant\Term;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class FeeStructureType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('classroom', EntityType::class, [
                'class' => Classroom::class,
                'choice_label' => 'name',
                'placeholder' => 'Select Class',
                'label' => 'Who Pays?'
            ])
            ->add('term', EntityType::class, [
                'class' => Term::class,
                'choice_label' => 'name',
                'placeholder' => 'Select Term',
                'label' => 'Which Term?'
            ])
            ->add('feeItem', EntityType::class, [
                'class' => FeeItem::class,
                'choice_label' => 'name',
                'placeholder' => 'Select Fee',
                'label' => 'For What?'
            ])
            ->add('amount', MoneyType::class, [
                'currency' => 'NGN',
                'label' => 'Amount (₦)'
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => FeeStructure::class,
        ]);
    }
}