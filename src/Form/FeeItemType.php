<?php

namespace App\Form;

use App\Entity\Tenant\FeeItem;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class FeeItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Fee Name',
                'attr' => ['placeholder' => 'e.g. Tuition Fee, Sport Levy']
            ])
            ->add('isOptional', CheckboxType::class, [
                'label' => 'Is this optional? (e.g. School Bus)',
                'required' => false,
                'help' => 'Check this box if students can choose not to pay this.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => FeeItem::class,
        ]);
    }
}