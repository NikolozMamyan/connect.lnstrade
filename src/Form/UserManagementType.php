<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class UserManagementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label' => 'Prenom',
                'constraints' => [
                    new NotBlank(message: 'Le prenom est obligatoire.'),
                    new Length(max: 100),
                ],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Nom',
                'constraints' => [
                    new NotBlank(message: 'Le nom est obligatoire.'),
                    new Length(max: 100),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'constraints' => [
                    new NotBlank(message: 'L email est obligatoire.'),
                    new Email(message: 'L email est invalide.'),
                ],
            ])
            ->add('role', ChoiceType::class, [
                'label' => 'Role',
                'mapped' => false,
                'choices' => [
                    'Utilisateur' => 'ROLE_USER',
                    'Commercial' => 'ROLE_COM',
                    'Administrateur' => 'ROLE_ADMIN',
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'required' => (bool) $options['require_password'],
                'invalid_message' => 'Les mots de passe doivent etre identiques.',
                'first_options' => [
                    'label' => 'Mot de passe',
                ],
                'second_options' => [
                    'label' => 'Confirmation du mot de passe',
                ],
            ]);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $form = $event->getForm();
            /** @var User|null $user */
            $user = $event->getData();
            $selectedRole = 'ROLE_USER';

            if ($user instanceof User && in_array('ROLE_ADMIN', $user->getRoles(), true)) {
                $selectedRole = 'ROLE_ADMIN';
            } elseif ($user instanceof User && in_array('ROLE_COM', $user->getRoles(), true)) {
                $selectedRole = 'ROLE_COM';
            }

            $form->get('role')->setData($selectedRole);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'require_password' => true,
        ]);
        $resolver->setAllowedTypes('require_password', 'bool');
    }
}
