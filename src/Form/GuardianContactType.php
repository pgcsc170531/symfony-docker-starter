<?php

namespace App\Form;

use App\Entity\Tenant\Guardian;
use App\Service\Wording\LearnerWording;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The parent's own details — the record behind every child, not the child.
 *
 * It is {@see GuardianType} plus one unmapped checkbox. The checkbox only
 * appears when the new phone number belongs to another parent record and a
 * child's name proves the two are one family; ticking it is the office saying
 * "yes, move the children across".
 *
 * Two admission-time fields are relaxed here. A parent imported from a CSV can
 * have no address and no recorded relationship, and correcting their phone
 * number must not turn into a demand for both.
 */
class GuardianContactType extends AbstractType
{
    public function __construct(private readonly LearnerWording $wording)
    {
    }

    public function getParent(): string
    {
        return GuardianType::class;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('relationshipToStudent', ChoiceType::class, [
                'label' => 'Relationship to the ' . $this->wording->singular(),
                'choices' => [
                    'Father' => 'Father',
                    'Mother' => 'Mother',
                    'Uncle' => 'Uncle',
                    'Aunt' => 'Aunt',
                    'Grandparent' => 'Grandparent',
                    'Guardian' => 'Guardian',
                ],
                'placeholder' => 'Relationship',
                'required' => false,
            ])
            ->add('address', TextareaType::class, [
                'label' => 'Home Address',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('confirmMerge', CheckboxType::class, [
                'label' => 'Yes, these children belong to one family — merge the parent records',
                'mapped' => false,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Guardian::class,
        ]);
    }
}
