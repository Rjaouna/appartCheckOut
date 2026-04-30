<?php

namespace App\Service;

use App\Entity\Anomaly;
use App\Entity\User;
use App\Enum\AnomalyStatus;

class AnomalyWorkflowManager
{
    public function __construct(
        private readonly AnomalyHistoryRecorder $historyRecorder,
    ) {
    }

    public function advance(Anomaly $anomaly, AnomalyStatus $nextStatus, ?User $actor): void
    {
        $allowed = array_map(static fn (AnomalyStatus $status) => $status->value, $anomaly->getStatus()->nextChoices());
        if (!in_array($nextStatus->value, $allowed, true)) {
            throw new \InvalidArgumentException('Transition de workflow invalide.');
        }

        $fromStatus = $anomaly->getStatus();
        $anomaly
            ->setStatus($nextStatus)
            ->setUpdatedAt(new \DateTimeImmutable());

        if ($nextStatus->isClosed()) {
            $anomaly->setClosedAt(new \DateTimeImmutable());
        }

        $this->historyRecorder->record($anomaly, $fromStatus, $nextStatus, $actor);
    }

    public function reset(Anomaly $anomaly, ?User $actor): void
    {
        if ($anomaly->getStatus() === AnomalyStatus::New) {
            return;
        }

        $fromStatus = $anomaly->getStatus();
        $anomaly
            ->setStatus(AnomalyStatus::New)
            ->setUpdatedAt(new \DateTimeImmutable())
            ->setClosedAt(null);

        $this->historyRecorder->record($anomaly, $fromStatus, AnomalyStatus::New, $actor);
    }
}
