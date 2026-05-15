<?php

namespace App\Enum;

enum ConsumableCheckStatus: string
{
    case Ok = 'ok';
    case Low = 'low';
    case Missing = 'missing';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::Low => 'Stock faible',
            self::Missing => 'Manquant',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Ok => 'is-success',
            self::Low => 'is-warning',
            self::Missing => 'is-danger',
        };
    }

    public function requiresRestock(): bool
    {
        return $this !== self::Ok;
    }
}
