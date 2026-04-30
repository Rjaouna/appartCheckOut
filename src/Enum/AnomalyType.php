<?php

namespace App\Enum;

enum AnomalyType: string
{
    case ReplacementNeeded = 'replacement_needed';
    case Minor = 'minor';
    case Major = 'major';
    case Missing = 'missing';
    case NotChecked = 'not_checked';

    public function label(): string
    {
        return match ($this) {
            self::ReplacementNeeded => 'RS',
            self::Minor => 'Anomalie mineure',
            self::Major => 'Anomalie majeure',
            self::Missing => 'Absent',
            self::NotChecked => 'Non verifie',
        };
    }

    public static function fromCheckStatus(EquipmentCheckStatus $status): ?self
    {
        return match ($status) {
            EquipmentCheckStatus::Ok => null,
            EquipmentCheckStatus::ReplaceService => self::ReplacementNeeded,
            EquipmentCheckStatus::MinorIssue => self::Minor,
            EquipmentCheckStatus::MajorIssue => self::Major,
            EquipmentCheckStatus::Missing => self::Missing,
            EquipmentCheckStatus::NotChecked => self::NotChecked,
        };
    }
}
