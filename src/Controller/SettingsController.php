<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Service\Ui\NotificationManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/supervision/settings', name: 'supervision_settings_')]
class SettingsController extends AbstractController
{
    private const SESSION_KEY = 'supervision_settings_preview';

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request, NotificationManager $notificationManager): Response
    {
        $defaults = [
            'email_alerts' => true,
            'critical_only' => false,
            'daily_summary' => true,
            'webhook_watch' => true,
            'sound_alerts' => false,
            'compact_dashboard' => false,
            'maintenance_banner' => false,
            'debug_widgets' => false,
        ];

        $session = $request->getSession();
        $settings = array_merge($defaults, (array) $session->get(self::SESSION_KEY, []));

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('supervision_settings_preview', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Jeton CSRF invalide.');

                return $this->redirectToRoute('supervision_settings_index');
            }

            foreach (array_keys($defaults) as $key) {
                $settings[$key] = $request->request->getBoolean($key);
            }

            $session->set(self::SESSION_KEY, $settings);
            $notificationManager->notify(
                'Parametres systeme mis a jour',
                'Les preferences personnelles de supervision ont ete enregistrees.',
                Notification::LEVEL_INFO,
                'supervision_settings_index'
            );
            $this->addFlash('success', 'Preferences mises a jour. Aucun impact metier n est applique pour le moment.');

            return $this->redirectToRoute('supervision_settings_index');
        }

        return $this->render('supervision/settings/index.html.twig', [
            'settings' => $settings,
        ]);
    }
}
