<?php

namespace App\Form\Wizard;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class FeeItemsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // 🟢 NOTICE: No 'standardItems' choice field here anymore!
        // We use 'items' CollectionType instead.
        
        $builder
            ->add('items', CollectionType::class, [
                'entry_type' => FeeItemRowType::class, // Links to the Row form
                'entry_options' => ['label' => false],
                'allow_add' => true,    // Essential for "Add Custom Fee" button
                'allow_delete' => true, // Essential for removing rows
                'by_reference' => false,
                'label' => false,
            ])
            ->add('save', SubmitType::class, [
                'label' => 'Save Configuration & Set Prices ➡️',
                'attr' => ['class' => 'bg-blue-600 text-white px-8 py-3 rounded-lg font-bold shadow hover:bg-blue-700']
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null, // We use arrays for the wizard data
        ]);
    }
}