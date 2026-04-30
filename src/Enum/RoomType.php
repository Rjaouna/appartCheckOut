<?php

namespace App\Enum;

enum RoomType: string
{
    case Entrance = 'entrance';
    case LivingRoom = 'living_room';
    case DiningRoom = 'dining_room';
    case Kitchen = 'kitchen';
    case Bedroom = 'bedroom';
    case Bathroom = 'bathroom';
    case ShowerRoom = 'shower_room';
    case Toilet = 'toilet';
    case Laundry = 'laundry';
    case Terrace = 'terrace';
    case Balcony = 'balcony';
    case Hallway = 'hallway';
    case Storage = 'storage';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Entrance => 'Entrée',
            self::LivingRoom => 'Salon',
            self::DiningRoom => 'Salle à manger',
            self::Kitchen => 'Cuisine',
            self::Bedroom => 'Chambre',
            self::Bathroom => 'Salle de bain',
            self::ShowerRoom => 'Douche',
            self::Toilet => 'Toilette',
            self::Laundry => 'Buanderie',
            self::Terrace => 'Terrasse',
            self::Balcony => 'Balcon',
            self::Hallway => 'Couloir',
            self::Storage => 'Rangement',
            self::Other => 'Autre pièce',
        };
    }

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [
            self::Entrance,
            self::LivingRoom,
            self::DiningRoom,
            self::Kitchen,
            self::Bedroom,
            self::Bathroom,
            self::ShowerRoom,
            self::Toilet,
            self::Laundry,
            self::Terrace,
            self::Balcony,
            self::Hallway,
            self::Storage,
            self::Other,
        ];
    }
}
