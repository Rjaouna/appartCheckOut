<?php

namespace App\DataFixtures;

use App\Entity\Apartment;
use App\Entity\EquipmentCatalog;
use App\Entity\Room;
use App\Entity\RoomEquipment;
use App\Entity\User;
use App\Enum\ApartmentStatus;
use App\Enum\RoomType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    /**
     * @var array<string, array<int, string>>
     */
    private array $catalogMap = [
        'kitchen' => ['Frigo', 'Congélateur', 'Four', 'Micro-ondes', 'Plaque induction', 'Plaque gaz', 'Hotte', 'Lave-vaisselle', 'Évier', 'Robinetterie', 'Cafetière', 'Machine Nespresso', 'Bouilloire', 'Grille-pain', 'Mixeur', 'Poubelle', 'Sacs poubelle', 'Assiettes plates', 'Assiettes creuses', 'Bols', 'Verres à eau', 'Verres à vin', 'Tasses', 'Mugs', 'Couverts complets', 'Couteaux de cuisine', 'Casseroles', 'Poêles', 'Plats four', 'Passoire', 'Saladier', 'Ouvre-bouteille', 'Ouvre-boîte', 'Torchons', 'Table', 'Chaises', 'Vaisselle'],
        'living_room' => ['Canape', 'Fauteuil', 'Table basse', 'Meuble TV', 'Television', 'Telecommande TV', 'Telecommande climatisation', 'Box internet', 'Routeur wifi', 'Rideaux', 'Voilages', 'Lampadaire', 'Lampe d appoint', 'Climatisation', 'Chauffage', 'Tapis', 'Coussins'],
        'bedroom' => ['Lit simple', 'Lit double', 'Tête de lit', 'Matelas', 'Protège-matelas', 'Draps', 'Housse de couette', 'Couette', 'Couvertures', 'Oreillers', 'Taies d’oreiller', 'Table de chevet', 'Lampe de chevet', 'Armoire', 'Penderie', 'Cintres', 'Commode', 'Miroir', 'Rideaux occultants', 'Volets', 'Climatiseur', 'Radiateur'],
        'bathroom' => ['Lavabo', 'Miroir', 'Éclairage miroir', 'Douche', 'Paroi de douche', 'Pommeau de douche', 'Robinetterie', 'Tapis de bain', 'Serviettes', 'Porte-serviettes', 'Sèche-cheveux', 'Distributeur de savon', 'Poubelle', 'WC', 'Chasse d’eau', 'Brosse WC', 'Papier toilette'],
        'shower_room' => ['Douche', 'Paroi de douche', 'Pommeau de douche', 'Robinetterie', 'Serviettes', 'Distributeur de savon', 'Poubelle', 'Miroir'],
        'toilet' => ['Cuvette WC', 'Chasse d’eau', 'Porte-papier', 'Brosse WC', 'Poubelle', 'Papier toilette'],
        'terrace' => ['Table extérieure', 'Chaises extérieures', 'Fauteuil extérieur', 'Coussins extérieurs', 'Rambarde', 'Éclairage extérieur', 'Store', 'Cendrier', 'Séchoir'],
        'balcony' => ['Table extérieure', 'Chaises extérieures', 'Rambarde', 'Éclairage extérieur', 'Cendrier'],
        'laundry' => ['Lave-linge', 'Sèche-linge', 'Panier linge', 'Étagères', 'Aspirateur', 'Balai', 'Serpillère', 'Seau', 'Produits ménagers', 'Planche à repasser', 'Fer à repasser'],
        'entrance' => ['Porte entrée', 'Interphone', 'Tapis entrée', 'Porte-manteaux', 'Meuble rangement', 'Boîte à clés interne'],
        'hallway' => ['Éclairage couloir', 'Miroir', 'Console', 'Tapis couloir'],
        'storage' => ['Étagères', 'Kit ménage', 'Stock linge', 'Boîte à outils'],
        'dining_room' => ['Table a manger', 'Chaises salle a manger', 'Buffet', 'Suspension'],
        'other' => ['Équipement complémentaire', 'Prise électrique', 'Éclairage', 'Décor'],
    ];

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $admin = new User();
        $admin
            ->setFullName('Mohamed Bourjime')
            ->setEmail('b.mohamed@gmail.com')
            ->setRoles(['ROLE_ADMIN'])
            ->setCanManageAnomalyWorkflow(true);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'password'));

        $employee = new User();
        $employee
            ->setFullName('Marouane Bourjime')
            ->setEmail('b.marouane@gmail.com')
            ->setRoles(['ROLE_EMPLOYEE'])
            ->setCanManageAnomalyWorkflow(false);
        $employee->setPassword($this->passwordHasher->hashPassword($employee, 'password'));

        $manager->persist($admin);
        $manager->persist($employee);

        $catalogByType = $this->seedCatalog($manager);

        $apartment = (new Apartment())
            ->setName('Riviera Horizon')
            ->setReferenceCode('APT-RIVIERA-001')
            ->setAddressLine1('24, avenue Gabrielle Techer')
            ->setAddressLine2('Appartement 752')
            ->setCity('Marrakech')
            ->setPostalCode('94279')
            ->setFloor('1')
            ->setDoorNumber('1')
            ->setMailboxNumber('18')
            ->setWazeLink('https://www.waze.com/ul?q=24%20avenue%20Gabrielle%20Techer%20Marrakech')
            ->setGoogleMapsLink('https://maps.google.com/?q=24%20avenue%20Gabrielle%20Techer%20Marrakech')
            ->setBuildingAccessCode('4346')
            ->setKeyBoxCode('4627')
            ->setEntryInstructions('Accès principal par l’entrée de l’immeuble. Code porte et boîte disponibles dans la fiche.')
            ->setConditionStatus('Bon état')
            ->setBedroomCount(2)
            ->setSleepsCount(0)
            ->setOwnerName('Nathalie Le Andre')
            ->setOwnerPhone('+33 7 78 71 53 87')
            ->setInternalNotes('Appartement de demonstration unique pour les tests.')
            ->setStatus(ApartmentStatus::Active)
            ->setIsInventoryPriority(true)
            ->setInventoryDueAt(new \DateTimeImmutable('tomorrow 09:00'))
            ->setGeneralPhotos([
                'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?auto=format&fit=crop&w=1200&q=80',
            ]);

        $apartment->addAssignedEmployee($employee);
        $manager->persist($apartment);

        $rooms = [
            [
                'type' => RoomType::Entrance,
                'name' => 'Entrée',
                'equipments' => ['Porte entrée', 'Interphone', 'Tapis entrée'],
            ],
            [
                'type' => RoomType::LivingRoom,
                'name' => 'Salon principal',
                'equipments' => ['Canape', 'Table basse', 'Television', 'Climatisation'],
            ],
            [
                'type' => RoomType::Kitchen,
                'name' => 'Cuisine',
                'equipments' => ['Frigo', 'Four', 'Micro-ondes', 'Vaisselle'],
            ],
            [
                'type' => RoomType::Bedroom,
                'name' => 'Chambre 1',
                'equipments' => ['Lit double', 'Matelas', 'Draps', 'Oreillers'],
            ],
            [
                'type' => RoomType::Bathroom,
                'name' => 'Salle de bain',
                'equipments' => ['Lavabo', 'Miroir', 'Douche', 'Serviettes'],
            ],
        ];

        foreach ($rooms as $roomIndex => $roomData) {
            $room = (new Room())
                ->setType($roomData['type'])
                ->setName($roomData['name'])
                ->setDisplayOrder($roomIndex + 1)
                ->setNotes(null);

            $apartment->addRoom($room);
            $manager->persist($room);

            foreach ($roomData['equipments'] as $equipmentIndex => $label) {
                $catalogEquipment = $this->findCatalogEquipment($catalogByType[$roomData['type']->value] ?? [], $label);
                $equipment = (new RoomEquipment())
                    ->setCatalogEquipment($catalogEquipment)
                    ->setLabel($label)
                    ->setDisplayOrder($equipmentIndex + 1)
                    ->setNotes(null)
                    ->setIsActive(true);

                $room->addRoomEquipment($equipment);
                $manager->persist($equipment);
            }
        }

        $manager->flush();
    }

    /**
     * @return array<string, array<int, EquipmentCatalog>>
     */
    private function seedCatalog(ObjectManager $manager): array
    {
        $catalogByType = [];

        foreach ($this->catalogMap as $roomTypeValue => $equipmentLabels) {
            $roomType = RoomType::from($roomTypeValue);

            foreach ($equipmentLabels as $label) {
                $equipment = (new EquipmentCatalog())
                    ->setName($label)
                    ->setRoomType($roomType)
                    ->setDescription(null)
                    ->setIsRequired(true)
                    ->setIsActive(true)
                    ->setReferencePhoto(null);

                $manager->persist($equipment);
                $catalogByType[$roomTypeValue][] = $equipment;
            }
        }

        return $catalogByType;
    }

    /**
     * @param array<int, EquipmentCatalog> $catalog
     */
    private function findCatalogEquipment(array $catalog, string $label): ?EquipmentCatalog
    {
        foreach ($catalog as $equipment) {
            if ($equipment->getName() === $label) {
                return $equipment;
            }
        }

        return null;
    }
}
