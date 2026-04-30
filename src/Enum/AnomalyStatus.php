<?php

namespace App\Enum;

enum AnomalyStatus: string
{
    case New = 'new';
    case ComplaintFiled = 'complaint_filed';
    case ReimbursementPaid = 'reimbursement_paid';
    case ReimbursementRefused = 'reimbursement_refused';
    case RepairInProgress = 'repair_in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Nouvelle',
            self::ComplaintFiled => 'Reclamation faite aupres de RBNB',
            self::ReimbursementPaid => 'Remboursement fait',
            self::ReimbursementRefused => 'Remboursement refuse',
            self::RepairInProgress => 'Reparation en cours',
            self::Resolved => 'Probleme regle',
            self::Closed => 'Cloturee',
        };
    }

    public function isClosed(): bool
    {
        return $this === self::Closed;
    }

    /**
     * @return list<self>
     */
    public function nextChoices(): array
    {
        return match ($this) {
            self::New => [self::ComplaintFiled],
            self::ComplaintFiled => [self::ReimbursementPaid, self::ReimbursementRefused],
            self::ReimbursementPaid, self::ReimbursementRefused => [self::RepairInProgress],
            self::RepairInProgress => [self::Resolved],
            self::Resolved => [self::Closed],
            self::Closed => [],
        };
    }
}
