<?php

namespace App\Service;

use App\Entity\ApartmentReservation;

class ApartmentReservationMessenger
{
    public function normalizeWhatsAppNumber(mixed $value): string
    {
        $rawValue = trim((string) $value);
        if ($rawValue === '') {
            throw new \InvalidArgumentException('Le numéro WhatsApp est obligatoire.');
        }

        $digits = preg_replace('/\D+/', '', $rawValue) ?? '';
        if ($digits === '') {
            throw new \InvalidArgumentException('Le numéro WhatsApp est obligatoire.');
        }

        if (str_starts_with($rawValue, '+')) {
            $normalized = '+' . $digits;
        } elseif (str_starts_with($rawValue, '00')) {
            $normalized = '+' . substr($digits, 2);
        } else {
            throw new \InvalidArgumentException('Saisissez le numéro au format international, par exemple +33 6 00 00 00 00.');
        }

        if (preg_match('/^\+[1-9]\d{7,14}$/', $normalized) !== 1) {
            throw new \InvalidArgumentException('Saisissez le numéro au format international, par exemple +33 6 00 00 00 00.');
        }

        return $normalized;
    }

    public function buildWhatsAppUrl(ApartmentReservation $reservation, string $siteUrl): string
    {
        $apartment = $reservation->getApartment();
        if ($apartment === null) {
            throw new \InvalidArgumentException('Réservation sans appartement.');
        }

        $accessLines = [];
        if ($apartment->getBuildingAccessCode()) {
            $accessLines[] = 'Code immeuble : ' . $apartment->getBuildingAccessCode();
        }
        if ($apartment->getKeyBoxCode()) {
            $accessLines[] = 'Code accès / boîtier : ' . $apartment->getKeyBoxCode();
        }

        $message = trim(sprintf(
            "Bonjour %s,\n\nVoici les informations pour votre arrivée à %s.\n\nAdresse : %s\nLien du site : %s\n%s\n\nMerci de garder ces informations confidentielles.",
            $reservation->getGuestName(),
            $apartment->getName(),
            $apartment->getFullAddress(),
            $siteUrl,
            $accessLines !== [] ? implode("\n", $accessLines) : 'Code d’accès : à confirmer'
        ));

        return 'https://wa.me/' . ltrim($reservation->getGuestWhatsappNumber(), '+') . '?text=' . rawurlencode($message);
    }
}
