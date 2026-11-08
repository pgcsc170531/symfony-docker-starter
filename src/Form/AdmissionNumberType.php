<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One field: the number a pupil carries for life.
 *
 * Deliberately narrow. The pupil's name, class and guardian are not editable
 * here — this screen exists to correct a number, usually one an uploaded list
 * brought in wrong. Everything else belongs on the profile.
 *
 * The uniqueness check is NOT a constraint here: a duplicate has to be reported
 * with the name and class of the pupil who already holds the number, which a
 * `UniqueEntity` message cannot do. See AdmissionNumberController::edit().
 */
final class AdmissionNumberType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('admissionNumber', TextType::class, [
            'label' => 'Admission number',
            'required' => true,
            'attr' => [
                'maxlength' => 20,
                'autocomplete' => 'off',
                'spellcheck' => 'false',
                'placeholder' => 'e.g. ADM/2026/0001',
            ],
            'constraints' => [
                new Assert\NotBlank(message: 'An admission number cannot be empty.'),
                new Assert\Length(
                    max: 20,
                    maxMessage: 'An admission number can be at most {{ limit }} characters.'
                ),
                // Letters, digits, spaces and ordinary punctuation. A number that
                // carries an em dash or a smart quote still prints, but it cannot be
                // typed back by a parent reading it off a receipt.
                new Assert\Regex(
                    pattern: '/^[\x20-\x7E]+$/',
                    message: 'Use letters, digits and simple punctuation only.'
                ),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // A plain array: the value is only written to the pupil after the
        // collision check passes, so a rejected submit cannot leave the entity
        // holding a number that belongs to somebody else.
        $resolver->setDefaults(['data_class' => null]);
    }
}
