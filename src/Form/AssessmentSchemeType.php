<?php

namespace App\Form;

use App\Entity\Tenant\AssessmentScheme;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * How a school scores a subject: how many CAs, each out of what, and what the
 * examination is worth.
 *
 * These numbers used to live in code — a CA was out of 20 and the exam carried
 * the rest — so a school that marks CA out of 10, or runs a 40-mark exam beside a
 * 30-mark practical, could not be served at all. Nothing here is fixed: the school
 * types its own maxima and every screen reads them back through
 * AssessmentSchemeResolver.
 *
 * Not mapped to the entity on purpose: one save writes a row per term of the
 * session, so the form is a small set of values, not one database record.
 */
class AssessmentSchemeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('caCount', ChoiceType::class, [
                'label' => 'Continuous assessment tests per subject',
                'choices' => [
                    'Two CA tests' => AssessmentScheme::CA_COUNT_TWO,
                    'Three CA tests' => AssessmentScheme::CA_COUNT_THREE,
                ],
                'expanded' => true,
                'help' => 'The teacher’s entry sheet shows exactly this many CA boxes, and the report card prints this many CA columns.',
                'constraints' => [
                    new Assert\NotBlank(message: 'Choose how many CA tests this school runs.'),
                    new Assert\Choice(
                        choices: [AssessmentScheme::CA_COUNT_TWO, AssessmentScheme::CA_COUNT_THREE],
                        message: 'A school runs two or three CA tests.'
                    ),
                ],
            ])
            ->add('caMax', IntegerType::class, [
                'label' => 'Mark per CA test',
                'help' => 'What one CA test is scored out of — 10, 20, 30, whatever your school uses.',
                'constraints' => [
                    new Assert\NotBlank(message: 'Enter the mark for one CA test.'),
                    new Assert\Positive(message: 'A CA mark must be greater than zero.'),
                    new Assert\LessThanOrEqual(value: 100, message: 'A single CA cannot be out of more than 100.'),
                ],
            ])
            ->add('examMode', ChoiceType::class, [
                'label' => 'Examination',
                'choices' => [
                    'One examination paper' => AssessmentScheme::EXAM_MODE_SINGLE,
                    'Examination + practical' => AssessmentScheme::EXAM_MODE_DUAL,
                ],
                'expanded' => true,
                'help' => 'Add a practical only where the subject is scored on one — art, science, computer studies.',
            ])
            ->add('theoryMax', IntegerType::class, [
                'label' => 'Examination mark',
                'constraints' => [
                    new Assert\NotBlank(message: 'Enter the examination mark.'),
                    new Assert\Positive(message: 'The examination mark must be greater than zero.'),
                    new Assert\LessThanOrEqual(value: 200, message: 'An examination cannot be out of more than 200.'),
                ],
            ])
            ->add('practicalMax', IntegerType::class, [
                'label' => 'Practical mark',
                'required' => false,
                'help' => 'Ignored while the examination is a single paper.',
                'constraints' => [
                    new Assert\PositiveOrZero(message: 'A practical mark cannot be negative.'),
                    new Assert\LessThanOrEqual(value: 200, message: 'A practical cannot be out of more than 200.'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Values, not an entity: one save writes a row per term.
        $resolver->setDefaults(['data_class' => null]);
    }
}
