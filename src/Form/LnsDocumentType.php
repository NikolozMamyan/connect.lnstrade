<?php

namespace App\Form;

use App\Entity\LnsDocument;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LnsDocumentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Titre du document',
                'empty_data' => '',
                'attr' => [
                    'maxlength' => 180,
                    'placeholder' => 'Titre du document',
                    'autocomplete' => 'off',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'empty_data' => '',
                'attr' => [
                    'maxlength' => 20000,
                    'rows' => 3,
                    'placeholder' => 'Présentez le sujet du document en quelques lignes.',
                ],
            ])
            ->add('autoGenerateToc', ChoiceType::class, [
                'label' => 'Générer automatiquement le sommaire ?',
                'choices' => [
                    'Oui' => true,
                    'Non' => false,
                ],
                'expanded' => true,
                'multiple' => false,
            ])
            ->add('contentJson', HiddenType::class, [
                'mapped' => false,
                'data' => $options['content_json'],
            ])
            ->add('revision', HiddenType::class, [
                'mapped' => false,
                'data' => (string) $options['revision'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LnsDocument::class,
            'content_json' => '[]',
            'revision' => 1,
        ]);
        $resolver->setAllowedTypes('content_json', 'string');
        $resolver->setAllowedTypes('revision', 'int');
    }
}
