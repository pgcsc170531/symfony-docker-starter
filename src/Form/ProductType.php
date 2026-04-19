<?php

namespace App\Form;

use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\Product;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Item Name'])
            ->add('category', ChoiceType::class, [
                'choices' => [
                    'Stationery (Pens, Erasers)' => 'STATIONERY',
                    'Textbook' => 'BOOK',
                    'Uniform / Wears' => 'UNIFORM',
                    'Other' => 'OTHER'
                ]
            ])
            ->add('stockQuantity', IntegerType::class, [
                'label' => 'Quantity in Stock',
                'attr' => ['min' => 0]
            ])
            ->add('unitPrice', MoneyType::class, [
                'currency' => '',
                'label' => 'Selling Price'
            ])
            ->add('classroom', EntityType::class, [
                'class' => Classroom::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'General Item (For Everyone)',
                'label' => 'Specific Class? (Optional)'
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class]);
    }
}