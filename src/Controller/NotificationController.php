<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/notifications', name: 'app_notifications_')]
class NotificationController extends AbstractController
{
    #[Route('/{id}/open', name: 'open', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function open(Notification $notification, EntityManagerInterface $entityManager): RedirectResponse
    {
        if (!$notification->isRead()) {
            $notification->markAsRead();
            $entityManager->flush();
        }

        return $this->redirect($notification->getLinkUrl() ?: $this->generateUrl('app_dashboard'));
    }

    #[Route('/read-all', name: 'read_all', methods: ['POST'])]
    public function readAll(
        Request $request,
        NotificationRepository $notificationRepository,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('notifications_read_all', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_dashboard');
        }

        $notificationRepository->markAllAsRead();

        return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('app_dashboard'));
    }
}
