<?php

namespace App\Service;

use App\Entity\AirbnbCheckEquipment;
use App\Enum\RoomType;

class AirbnbUsabilityChecklist
{
    /**
     * @return list<array{
     *     key:string,
     *     name:string,
     *     roomType:string,
     *     icon:string,
     *     equipments:list<array{key:string,name:string,importance:string,weight:int,icon:string}>
     * }>
     */
    public function rooms(): array
    {
        return [
            [
                'key' => 'kitchen',
                'name' => 'Cuisine',
                'roomType' => RoomType::Kitchen->value,
                'icon' => 'cup-hot',
                'equipments' => [
                    $this->equipment('fridge', 'Réfrigérateur / Congélateur', true, 'snow'),
                    $this->equipment('hob', 'Plaques de cuisson', true, 'grid-3x3-gap'),
                    $this->equipment('microwave', 'Micro-ondes', true, 'badge-hd'),
                    $this->equipment('sink', 'Évier', true, 'droplet'),
                    $this->equipment('dishes', 'Vaisselle', true, 'basket'),
                    $this->equipment('glasses', 'Verres', true, 'cup'),
                    $this->equipment('cutlery', 'Couverts', true, 'fork-knife'),
                    $this->equipment('pots', 'Casseroles', true, 'bucket'),
                    $this->equipment('pans', 'Poêles', true, 'disc'),
                    $this->equipment('trash', 'Poubelle', true, 'trash3'),
                    $this->equipment('kettle', 'Bouilloire', false, 'cup-hot'),
                    $this->equipment('coffee_machine', 'Machine à café', false, 'cup-hot'),
                    $this->equipment('toaster', 'Grille-pain', false, 'box'),
                    $this->equipment('oven', 'Four', false, 'badge-hd'),
                    $this->equipment('dishwasher', 'Lave-vaisselle', false, 'water'),
                ],
            ],
            [
                'key' => 'bathroom',
                'name' => 'Salle de bain',
                'roomType' => RoomType::Bathroom->value,
                'icon' => 'droplet',
                'equipments' => [
                    $this->equipment('shower_bath', 'Douche ou baignoire', true, 'droplet-half'),
                    $this->equipment('toilet', 'WC', true, 'badge-wc'),
                    $this->equipment('sink', 'Lavabo', true, 'droplet'),
                    $this->equipment('mirror', 'Miroir', true, 'square'),
                    $this->equipment('towels', 'Serviettes', true, 'layers'),
                    $this->equipment('soap', 'Savon', true, 'capsule'),
                    $this->equipment('hairdryer', 'Sèche-cheveux', false, 'wind'),
                    $this->equipment('shower_gel', 'Gel douche', false, 'droplet'),
                    $this->equipment('shampoo', 'Shampooing', false, 'droplet'),
                    $this->equipment('bath_mat', 'Tapis de bain', false, 'grid'),
                ],
            ],
            [
                'key' => 'bedroom',
                'name' => 'Chambre',
                'roomType' => RoomType::Bedroom->value,
                'icon' => 'lamp',
                'equipments' => [
                    $this->equipment('bed', 'Lit', true, 'lamp'),
                    $this->equipment('mattress', 'Matelas', true, 'layers'),
                    $this->equipment('pillows', 'Oreillers', true, 'cloud'),
                    $this->equipment('sheets', 'Draps', true, 'layers'),
                    $this->equipment('curtains', 'Rideaux', true, 'columns'),
                    $this->equipment('bedside_lamps', 'Lampes de chevet', false, 'lamp'),
                    $this->equipment('accessible_sockets', 'Prises accessibles', false, 'plug'),
                    $this->equipment('ac', 'Climatisation', false, 'snow2'),
                ],
            ],
            [
                'key' => 'living_room',
                'name' => 'Salon',
                'roomType' => RoomType::LivingRoom->value,
                'icon' => 'tv',
                'equipments' => [
                    $this->equipment('sofa', 'Canapé', true, 'display'),
                    $this->equipment('lighting', 'Éclairage', true, 'lightbulb'),
                    $this->equipment('coffee_table', 'Table basse', true, 'square'),
                    $this->equipment('tv', 'Télévision', false, 'tv'),
                    $this->equipment('netflix', 'Netflix', false, 'badge-hd'),
                    $this->equipment('decoration', 'Décoration', false, 'stars'),
                    $this->equipment('ac', 'Climatisation', false, 'snow2'),
                ],
            ],
            [
                'key' => 'entrance',
                'name' => 'Entrée',
                'roomType' => RoomType::Entrance->value,
                'icon' => 'door-open',
                'equipments' => [
                    $this->equipment('door_lock', 'Serrure fonctionnelle', true, 'lock'),
                    $this->equipment('entry_lighting', 'Éclairage entrée', true, 'lightbulb'),
                    $this->equipment('entry_mat', 'Tapis entrée', false, 'grid'),
                    $this->equipment('key_holder', 'Support clés', false, 'key'),
                ],
            ],
            [
                'key' => 'outdoor',
                'name' => 'Terrasse / extérieur',
                'roomType' => RoomType::Terrace->value,
                'icon' => 'sun',
                'equipments' => [
                    $this->equipment('outdoor_lighting', 'Éclairage extérieur', false, 'lightbulb'),
                    $this->equipment('outdoor_furniture', 'Mobilier extérieur', false, 'umbrella'),
                    $this->equipment('outdoor_trash', 'Poubelle extérieure', false, 'trash3'),
                ],
            ],
            [
                'key' => 'security',
                'name' => 'Sécurité',
                'roomType' => RoomType::Other->value,
                'icon' => 'shield-check',
                'equipments' => [
                    $this->equipment('smoke_detector', 'Détecteur de fumée', true, 'broadcast'),
                    $this->equipment('fire_extinguisher', 'Extincteur', true, 'fire'),
                    $this->equipment('first_aid', 'Trousse de secours', true, 'heart-pulse'),
                    $this->equipment('co2_detector', 'Détecteur CO2', false, 'activity'),
                    $this->equipment('outdoor_camera', 'Caméra extérieure', false, 'camera-video'),
                    $this->equipment('safe', 'Coffre-fort', false, 'safe'),
                ],
            ],
            [
                'key' => 'connectivity',
                'name' => 'Connectivité',
                'roomType' => RoomType::Other->value,
                'icon' => 'wifi',
                'equipments' => [
                    $this->equipment('wifi', 'Wi-Fi', true, 'wifi'),
                    $this->equipment('fiber', 'Fibre', false, 'router'),
                    $this->equipment('smart_tv', 'Smart TV', false, 'tv'),
                    $this->equipment('usb_chargers', 'Chargeurs USB', false, 'usb-plug'),
                ],
            ],
        ];
    }

    /**
     * @return array{key:string,name:string,importance:string,weight:int,icon:string}
     */
    private function equipment(string $key, string $name, bool $required, string $icon): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'importance' => $required ? AirbnbCheckEquipment::IMPORTANCE_REQUIRED : AirbnbCheckEquipment::IMPORTANCE_RECOMMENDED,
            'weight' => $required ? 3 : 2,
            'icon' => $icon,
        ];
    }
}
