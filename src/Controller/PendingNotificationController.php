<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\PendingActionNotificationProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class PendingNotificationController extends AbstractController
{
    #[Route('/notifications/pending-actions', name: 'app_pending_notifications', methods: ['GET'])]
    public function __invoke(PendingActionNotificationProvider $notificationProvider): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['success' => false, 'actions' => []], 401);
        }

        $response = new JsonResponse([
            'success' => true,
            'actions' => $notificationProvider->buildForUser($user),
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
