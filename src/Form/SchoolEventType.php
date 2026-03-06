<?php

namespace App\Form;

use App\Entity\Tenant\SchoolEvent;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SchoolEventType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Event Title',
                'attr' => ['class' => 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border']
            ])
            ->add('startDate', DateTimeType::class, [
                'widget' => 'single_text',
                'label' => 'Start Date & Time',
                'attr' => ['class' => 'mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border']
            ])
            ->add('endDate', DateTimeType::class, [
                'widget' => 'single_text',
                'required' => false,
                'label' => 'End Date (Optional)',
                'attr' => ['class' => 'mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border']
            ])
            ->add('type', ChoiceType::class, [
                'choices'  => [
                    'Academic (Exams, Tests)' => 'Academic',
                    'Holiday (Public Holiday, Break)' => 'Holiday',
                    'Social (Party, Sport)' => 'Social',
                    'Meeting (PTA, Staff)' => 'Meeting',
                ],
                'attr' => ['class' => 'mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border']
            ])
            ->add('isFlashNotice', CheckboxType::class, [
                'label' => 'Show as Top Alert? (Flash Notice)',
                'required' => false,
                'help' => 'Check this to make a red alert box appear at the top of the Parent Dashboard.',
                'attr' => ['class' => 'h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded']
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
                'label' => 'Description / Message',
                'attr' => ['rows' => 3, 'class' => 'mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 border']
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SchoolEvent::class,
        ]);
    }
}