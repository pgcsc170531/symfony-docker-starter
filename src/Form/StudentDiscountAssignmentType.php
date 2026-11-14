<?php

namespace App\Form;

use App\Entity\Tenant\DiscountType;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\StudentDiscount;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class StudentDiscountAssignmentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('student', EntityType::class, [
                'class' => Student::class,
                'choice_label' => function(Student $student) {
                    return $student->getFullName() . ' (' . $student->getAdmissionNumber() . ')';
                },
                'placeholder' => 'Select Student',
                'label' => 'Beneficiary Student'
            ])
            ->add('discountType', EntityType::class, [
                'class' => DiscountType::class,
                'choice_label' => function(DiscountType $type) {
                    $val = $type->getMode() === 'PERCENTAGE' ? $type->getValue().'%' : '₦'.number_format($type->getValue());
                    return $type->getName() . ' (' . $val . ')';
                },
                'label' => 'Discount Rule'
            ])
            // The scope belongs to the award, not only to the rule: two pupils on
            // the same 100% rule can be treated differently, because one family
            // pays nothing at all while the other still owes the uniform.
            // Left unticked it follows the rule, so nothing changes by accident.
            ->add('appliesToAll', CheckboxType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Apply to grand total',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => StudentDiscount::class]);
    }
}