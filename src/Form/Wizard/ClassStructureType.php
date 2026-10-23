<?php

namespace App\Form\Wizard;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The class structure: a list of editable rows, one per class.
 *
 * Each row carries its own arms, so "JSS 1 with A, B, C" and "SS 1 with A, B" can
 * be built on the same screen — and a school that does not use arms (a nursery, a
 * single-stream primary) simply leaves the arms box empty.
 */
class ClassStructureType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $suggestions = $options['suggestions'] ?? [];

        $rows = [];
        foreach ($suggestions as $name) {
            $rows[] = ['checked' => true, 'name' => $name, 'arms' => ''];
        }

        $builder->add('classes', CollectionType::class, [
            'entry_type' => ClassRowType::class,
            'allow_add' => true,
            'allow_delete' => true,
            'by_reference' => false,
            'data' => $rows,
            'prototype' => true,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'suggestions' => [],
            'data_class' => null,
        ]);

        $resolver->setAllowedTypes('suggestions', 'array');
    }
}
