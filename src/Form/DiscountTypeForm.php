<?php

namespace App\Form;

use App\Entity\Tenant\DiscountType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class DiscountTypeForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Discount Name (e.g. Scholarship)',
                'constraints' => [
                    new Assert\NotBlank(message: 'Give the rule a name.'),
                    // The invoice stores the rule name in a 120-character label.
                    new Assert\Length(max: 100),
                ],
            ])
            ->add('mode', ChoiceType::class, [
                'choices' => [
                    'Percentage (%)' => 'PERCENTAGE',
                    'Fixed Amount (₦)' => 'FIXED'
                ]
            ])
            ->add('value', NumberType::class, [
                'label' => 'Value (e.g. 50 for 50%)',
                'scale' => 2,
                'help' => 'A percentage or fixed amount comes off tuition only, unless you tick the box below.',
                'constraints' => [
                    new Assert\NotBlank(message: 'Enter a value.'),
                    new Assert\Positive(message: 'The value must be greater than zero.'),
                    new Assert\Callback(static function ($value, ExecutionContextInterface $context): void {
                        $mode = $context->getRoot()->get('mode')->getData();

                        if ($mode === 'PERCENTAGE' && (float) $value > 100.0) {
                            $context->buildViolation('A percentage cannot be more than 100%.')
                                ->addViolation();
                        }
                    }),
                ],
            ])
            ->add('appliesToAll', CheckboxType::class, [
                'label' => 'Apply to grand total',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DiscountType::class]);
    }
}