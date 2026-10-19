<?php

namespace App\Form;

use App\Entity\Tenant\Expense;
use App\Entity\Tenant\ExpenseCategory;
use App\Entity\Tenant\Payment;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ExpenseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('expenseDate', DateType::class, [
                'widget' => 'single_text',
                'label' => 'Date Spent'
            ])
            ->add('category', EntityType::class, [
                'class' => ExpenseCategory::class,
                'choice_label' => 'name',
                'placeholder' => 'Select Category',
            ])
            ->add('title', TextType::class, ['label' => 'Short Title'])
            ->add('payee', TextType::class, [
                'required' => false,
                'label' => 'Paid to (payee)',
                'attr' => ['placeholder' => 'e.g. Total Energies, Mrs Bello'],
            ])
            ->add('method', ChoiceType::class, [
                'label' => 'Payment method',
                'choices' => [
                    'Cash' => Payment::METHOD_CASH,
                    'Bank transfer' => Payment::METHOD_TRANSFER,
                    'POS' => Payment::METHOD_POS,
                    'Cheque' => Payment::METHOD_CHEQUE,
                ],
            ])
            ->add('amount', MoneyType::class, [
                'currency' => '',
                'label' => 'Amount (₦)'
            ])
            ->add('note', TextareaType::class, [
                'required' => false,
                'label' => 'Details (Optional)',
                'attr' => ['rows' => 3]
            ])
            ->add('receipt', FileType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Receipt / invoice (optional)',
                'attr' => ['accept' => '.jpg,.jpeg,.png,.webp,.pdf'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Expense::class]);
    }
}