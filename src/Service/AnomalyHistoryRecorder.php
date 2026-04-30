<?php

namespace App\Service;

use App\Entity\Anomaly;
use App\Entity\AnomalyStatusHistory;
use App\Entity\User;
use App\Enum\AnomalyStatus;
use Doctrine\ORM\EntityManagerInterface;

class AnomalyHistoryRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function record(Anomaly $anomaly, ?AnomalyStatus $fromStatus, AnomalyStatus $toStatus, ?User $actor): void
    {
        $history = (new AnomalyStatusHistory())
            ->setAnomaly($anomaly)
            ->setFromStatus($fromStatus)
            ->setToStatus($toStatus)
            ->setChangedBy($actor)
            ->setChangedAt(new \DateTimeImmutable());

        $anomaly->addStatusHistory($history);
        $this->entityManager->persist($history);
    }
}
