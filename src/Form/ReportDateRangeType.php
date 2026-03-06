<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ReportDateRangeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('startDate', DateType::class, [
                'widget' => 'single_text',
                'label' => 'From:',
                'data' => new \DateTime('first day of this month') // Default to 1st of month
            ])
            ->add('endDate', DateType::class, [
                'widget' => 'single_text',
                'label' => 'To:',
                'data' => new \DateTime('today')
            ]);
    }
}