<?php

namespace App\Twig;

use App\Entity\AppAppearanceSettings;
use App\Entity\Apartment;
use App\Enum\ApartmentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AdminSecurityExtension extends AbstractExtension
{
    private ?AppAppearanceSettings $appearanceSettings = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('admin_blocked_tenant_access_apartments', [$this, 'getBlockedTenantAccessApartments']),
            new TwigFunction('app_appearance_settings', [$this, 'getAppearanceSettings']),
            new TwigFunction('app_appearance_css_variables', [$this, 'getAppearanceCssVariables'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * @return list<Apartment>
     */
    public function getBlockedTenantAccessApartments(): array
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            return [];
        }

        return $this->entityManager->getRepository(Apartment::class)->findBy(
            [
                'status' => ApartmentStatus::Active,
                'isTenantAccessEnabled' => false,
            ],
            [
                'tenantAccessLockedAt' => 'DESC',
                'name' => 'ASC',
            ]
        );
    }

    public function getAppearanceSettings(): AppAppearanceSettings
    {
        if ($this->appearanceSettings instanceof AppAppearanceSettings) {
            return $this->appearanceSettings;
        }

        try {
            $settings = $this->appearanceSettingsStorageReady()
                ? $this->entityManager
                    ->getRepository(AppAppearanceSettings::class)
                    ->findOneBy([], ['id' => 'ASC'])
                : null;
        } catch (\Throwable) {
            $settings = null;
        }

        $this->appearanceSettings = $settings instanceof AppAppearanceSettings
            ? $settings
            : AppAppearanceSettings::default();

        return $this->appearanceSettings;
    }

    public function getAppearanceCssVariables(): string
    {
        $settings = $this->getAppearanceSettings();
        $primary = $settings->getPrimaryColor();
        $secondary = $settings->getSecondaryColor();
        $tertiary = $settings->getTertiaryColor();
        $background = $settings->getBackgroundColor();
        $surface = $settings->getSurfaceColor();
        $text = $settings->getTextColor();
        $muted = $settings->getMutedColor();
        $border = $settings->getBorderColor();
        $success = $settings->getSuccessColor();
        $warning = $settings->getWarningColor();
        $danger = $settings->getDangerColor();
        $primaryRgb = $this->hexToRgb($primary);
        $secondaryRgb = $this->hexToRgb($secondary);
        $tertiaryRgb = $this->hexToRgb($tertiary);
        $surfaceSoft = $this->mixHex($surface, $background, 74);
        $variables = [
            'bg' => $background,
            'bg-rgb' => $this->hexToRgb($background),
            'surface' => $surface,
            'surface-rgb' => $this->hexToRgb($surface),
            'surface-soft' => $surfaceSoft,
            'surface-soft-rgb' => $this->hexToRgb($surfaceSoft),
            'border' => $border,
            'border-rgb' => $this->hexToRgb($border),
            'text' => $text,
            'text-rgb' => $this->hexToRgb($text),
            'muted' => $muted,
            'muted-rgb' => $this->hexToRgb($muted),
            'accent' => $primary,
            'accent-dark' => $this->darkenHex($primary, 18),
            'accent-strong' => $this->darkenHex($primary, 28),
            'accent-soft' => sprintf('rgba(%s, 0.10)', $primaryRgb),
            'accent-rgb' => $primaryRgb,
            'primary' => $primary,
            'primary-color' => $primary,
            'primary-rgb' => $primaryRgb,
            'primary-soft' => sprintf('rgba(%s, 0.10)', $primaryRgb),
            'primary-border' => sprintf('rgba(%s, 0.18)', $primaryRgb),
            'secondary-color' => $secondary,
            'secondary-rgb' => $secondaryRgb,
            'secondary-soft' => sprintf('rgba(%s, 0.08)', $secondaryRgb),
            'tertiary-color' => $tertiary,
            'tertiary-rgb' => $tertiaryRgb,
            'tertiary-soft' => sprintf('rgba(%s, 0.12)', $tertiaryRgb),
            'success' => $success,
            'success-rgb' => $this->hexToRgb($success),
            'warning' => $warning,
            'warning-rgb' => $this->hexToRgb($warning),
            'danger' => $danger,
            'danger-rgb' => $this->hexToRgb($danger),
            'on-primary' => $this->contrastColor($primary),
            'on-secondary' => $this->contrastColor($secondary),
            'on-tertiary' => $this->contrastColor($tertiary),
            'on-success' => $this->contrastColor($success),
            'on-warning' => $this->contrastColor($warning),
            'on-danger' => $this->contrastColor($danger),
            'focus-ring' => sprintf('rgba(%s, 0.18)', $primaryRgb),
            'shadow-color' => sprintf('rgba(%s, 0.12)', $this->hexToRgb($text)),
        ];

        return implode(' ', array_map(
            static fn (string $name, string $value): string => sprintf('--%s: %s;', $name, $value),
            array_keys($variables),
            $variables
        ));
    }

    private function hexToRgb(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) {
            return '255, 56, 92';
        }

        return sprintf(
            '%d, %d, %d',
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2))
        );
    }

    private function darkenHex(string $hex, int $percent): string
    {
        $rgb = $this->parseHex($hex);
        if ($rgb === null) {
            return AppAppearanceSettings::DEFAULT_PRIMARY_COLOR;
        }

        $factor = max(0, min(100, 100 - $percent)) / 100;

        return sprintf(
            '#%02x%02x%02x',
            (int) floor($rgb[0] * $factor),
            (int) floor($rgb[1] * $factor),
            (int) floor($rgb[2] * $factor)
        );
    }

    private function mixHex(string $firstHex, string $secondHex, int $firstWeight): string
    {
        $first = $this->parseHex($firstHex);
        $second = $this->parseHex($secondHex);
        if ($first === null || $second === null) {
            return AppAppearanceSettings::DEFAULT_SURFACE_COLOR;
        }

        $firstRatio = max(0, min(100, $firstWeight)) / 100;
        $secondRatio = 1 - $firstRatio;

        return sprintf(
            '#%02x%02x%02x',
            (int) round(($first[0] * $firstRatio) + ($second[0] * $secondRatio)),
            (int) round(($first[1] * $firstRatio) + ($second[1] * $secondRatio)),
            (int) round(($first[2] * $firstRatio) + ($second[2] * $secondRatio))
        );
    }

    private function contrastColor(string $hex): string
    {
        $rgb = $this->parseHex($hex);
        if ($rgb === null) {
            return '#ffffff';
        }

        $luminance = (($rgb[0] * 299) + ($rgb[1] * 587) + ($rgb[2] * 114)) / 1000;

        return $luminance >= 150 ? '#222222' : '#ffffff';
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    private function parseHex(string $hex): ?array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) {
            return null;
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    private function appearanceSettingsStorageReady(): bool
    {
        try {
            $schemaManager = $this->entityManager->getConnection()->createSchemaManager();
            if (!$schemaManager->tablesExist(['app_appearance_settings'])) {
                return false;
            }

            $table = $schemaManager->introspectTable('app_appearance_settings');
            foreach ([
                'primary_color',
                'secondary_color',
                'tertiary_color',
                'background_color',
                'surface_color',
                'text_color',
                'muted_color',
                'border_color',
                'success_color',
                'warning_color',
                'danger_color',
            ] as $columnName) {
                if (!$table->hasColumn($columnName)) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
