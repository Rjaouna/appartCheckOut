<?php

namespace App\Enum;

enum EquipmentCheckStatus: string
{
    case Ok = 'ok';
    case ReplaceService = 'rs';
    case MinorIssue = 'minor_issue';
    case MajorIssue = 'major_issue';
    case Missing = 'missing';
    case NotChecked = 'not_checked';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::ReplaceService => 'RS',
            self::MinorIssue => 'Anomalie mineure',
            self::MajorIssue => 'Anomalie majeure',
            self::Missing => 'Absent',
            self::NotChecked => 'Non verifie',
        };
    }

    public function requiresComment(): bool
    {
        return $this !== self::Ok;
    }
}
