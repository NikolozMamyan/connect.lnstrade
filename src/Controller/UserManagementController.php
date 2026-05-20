<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserManagementType;
use App\Repository\UserRepository;
use App\Service\Ui\NotificationManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/supervision/users', name: 'supervision_users_')]
class UserManagementController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, UserRepository $userRepository): Response
    {
        $query = trim((string) $request->query->get('q', ''));

        return $this->render('supervision/users/index.html.twig', [
            'users' => $query !== '' ? $userRepository->searchByNameOrEmail($query) : $userRepository->findAllOrdered(),
            'searchQuery' => $query,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        NotificationManager $notificationManager,
    ): Response {
        $user = new User();
        $form = $this->createForm(UserManagementType::class, $user, [
            'require_password' => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = (string) $form->get('plainPassword')->getData();

            if ($plainPassword === '') {
                $form->get('plainPassword')->addError(new FormError('Le mot de passe est obligatoire.'));
            } else {
                $user->setRoles([(string) $form->get('role')->getData()]);
                $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));

                $entityManager->persist($user);
                $entityManager->flush();
                $notificationManager->notify(
                    'Utilisateur cree',
                    sprintf('Le compte %s a ete ajoute a la plateforme.', $user->getEmail()),
                    \App\Entity\Notification::LEVEL_SUCCESS,
                    'supervision_users_index'
                );

                $this->addFlash('success', sprintf('Utilisateur cree : %s', $user->getEmail()));

                return $this->redirectToRoute('supervision_users_index');
            }
        }

        return $this->render('supervision/users/form.html.twig', [
            'form' => $form->createView(),
            'pageTitle' => 'Creer un utilisateur',
            'pageSubtitle' => 'Ajoutez un acces plateforme avec role et mot de passe.',
            'submitLabel' => 'Creer l utilisateur',
            'user' => $user,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(
        User $user,
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        NotificationManager $notificationManager,
    ): Response {
        $form = $this->createForm(UserManagementType::class, $user, [
            'require_password' => false,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setRoles([(string) $form->get('role')->getData()]);

            $plainPassword = (string) $form->get('plainPassword')->getData();

            if ($plainPassword !== '') {
                $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
            }

            $entityManager->flush();
            $notificationManager->notify(
                'Utilisateur mis a jour',
                sprintf('Le compte %s a ete modifie.', $user->getEmail()),
                \App\Entity\Notification::LEVEL_INFO,
                'supervision_users_index'
            );

            $this->addFlash('success', sprintf('Utilisateur mis a jour : %s', $user->getEmail()));

            return $this->redirectToRoute('supervision_users_index');
        }

        return $this->render('supervision/users/form.html.twig', [
            'form' => $form->createView(),
            'pageTitle' => 'Modifier un utilisateur',
            'pageSubtitle' => 'Mettez a jour les informations, le role ou le mot de passe.',
            'submitLabel' => 'Enregistrer les modifications',
            'user' => $user,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(
        User $user,
        Request $request,
        EntityManagerInterface $entityManager,
        NotificationManager $notificationManager,
    ): Response {
        if (!$this->isCsrfTokenValid('delete_user_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('supervision_users_index');
        }

        if ($this->getUser() instanceof User && $this->getUser()->getId() === $user->getId()) {
            $this->addFlash('error', 'Vous ne pouvez pas supprimer votre propre compte.');

            return $this->redirectToRoute('supervision_users_index');
        }

        $entityManager->remove($user);
        $entityManager->flush();
        $notificationManager->notify(
            'Utilisateur supprime',
            sprintf('Le compte %s a ete supprime.', $user->getEmail()),
            \App\Entity\Notification::LEVEL_WARNING,
            'supervision_users_index'
        );

        $this->addFlash('success', sprintf('Utilisateur supprime : %s', $user->getEmail()));

        return $this->redirectToRoute('supervision_users_index');
    }
}
