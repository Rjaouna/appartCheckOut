<?php

namespace App\Enum;

enum CheckoutStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Paused = 'paused';
    case PendingValidation = 'pending_validation';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'À faire',
            self::InProgress => 'En cours',
            self::Paused => 'En pause',
            self::PendingValidation => 'À valider',
            self::Completed => 'Terminé',
            self::Cancelled => 'Annulé',
            self::Blocked => 'Bloque',
        };
    }
}
