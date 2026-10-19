<?php

namespace App\Form\Landlord;

use App\Entity\Landlord\Plan;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PlanType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Plan Name',
                'attr' => ['placeholder' => 'e.g. Starter, Growth'],
                'help' => 'The public name of the package.'
            ])
            ->add('price', MoneyType::class, [
                'currency' => '',
                'label' => 'Subscription Price (₦)',
                'attr' => ['placeholder' => '50000'],
                'divisor' => 1, // Ensure raw value is stored if you aren't using cents
            ])
            ->add('durationMonths', IntegerType::class, [
                'label' => 'Duration (Months)',
                'data' => 4,
                'help' => 'Standard Term is 4 months.'
            ])
            ->add('minStudents', IntegerType::class, [
                'label' => 'Min Students',
                'data' => 0
            ])
            ->add('maxStudents', IntegerType::class, [
                'label' => 'Max Students',
                'required' => false,
                'help' => 'Leave empty for Unlimited.'
            ])
            ->add('freeCreditAmount', MoneyType::class, [
                'currency' => '',
                'label' => 'Free WhatsApp Credit (₦)',
                'data' => 0,
                'help' => 'Bonus wallet balance given upon payment.'
            ])

            ->add('isTrial', CheckboxType::class, [
                'label' => 'Is this the Free Trial Plan?',
                'required' => false,
                'help' => 'If checked, new schools will be automatically assigned to this plan for 14 days.',
            ])

            // ── The live tracks: per pupil, or licence ──────────────────────
            ->add('pricingModel', ChoiceType::class, [
                'label' => 'Pricing model',
                'choices' => [
                    'Flat price (legacy contracts only)' => 'flat',
                    'Per pupil (Standard)' => 'per_student',
                    'Licence (Enterprise)' => 'licence',
                ],
                'help' => 'Per-pupil and licence plans ignore “Price” above — it stays 0.',
            ])
            ->add('termPricePerStudent', MoneyType::class, [
                'currency' => '',
                'label' => 'Standard: ₦ per pupil per term',
                'divisor' => 1,
                'required' => false,
            ])
            ->add('annualPricePerStudent', MoneyType::class, [
                'currency' => '',
                'label' => 'Standard: ₦ per pupil per term, when the year is prepaid',
                'divisor' => 1,
                'required' => false,
                'help' => '₦500 here means ₦1,500 per pupil per year.',
            ])
            ->add('minimumTermInvoice', MoneyType::class, [
                'currency' => '',
                'label' => 'Minimum termly invoice (₦)',
                'divisor' => 1,
                'required' => false,
            ])
            ->add('minimumAnnualInvoice', MoneyType::class, [
                'currency' => '',
                'label' => 'Minimum annual invoice (₦)',
                'divisor' => 1,
                'required' => false,
            ])
            ->add('licenceFee', MoneyType::class, [
                'currency' => '',
                'label' => 'Enterprise: one-off licence (₦)',
                'divisor' => 1,
                'required' => false,
            ])
            ->add('annualFee', MoneyType::class, [
                'currency' => '',
                'label' => 'Enterprise: annual fee (₦, email included)',
                'divisor' => 1,
                'required' => false,
            ])
            ->add('implementationFee', MoneyType::class, [
                'currency' => '',
                'label' => 'Enterprise: implementation (₦, one-off)',
                'divisor' => 1,
                'required' => false,
            ])
            ->add('isPublic', CheckboxType::class, [
                'label' => 'Show this plan to schools',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Plan::class,
        ]);
    }
}