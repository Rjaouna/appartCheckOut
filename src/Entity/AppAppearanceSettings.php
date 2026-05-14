<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class AppAppearanceSettings
{
    public const DEFAULT_PRIMARY_COLOR = '#ff385c';
    public const DEFAULT_SECONDARY_COLOR = '#222222';
    public const DEFAULT_TERTIARY_COLOR = '#f49fb4';
    public const DEFAULT_BACKGROUND_COLOR = '#f7f7f7';
    public const DEFAULT_SURFACE_COLOR = '#ffffff';
    public const DEFAULT_TEXT_COLOR = '#222222';
    public const DEFAULT_MUTED_COLOR = '#6a6a6a';
    public const DEFAULT_BORDER_COLOR = '#e7e7e2';
    public const DEFAULT_SUCCESS_COLOR = '#237b4b';
    public const DEFAULT_WARNING_COLOR = '#b35a00';
    public const DEFAULT_DANGER_COLOR = '#b42318';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 7)]
    private string $primaryColor = self::DEFAULT_PRIMARY_COLOR;

    #[ORM\Column(length: 7)]
    private string $secondaryColor = self::DEFAULT_SECONDARY_COLOR;

    #[ORM\Column(length: 7)]
    private string $tertiaryColor = self::DEFAULT_TERTIARY_COLOR;

    #[ORM\Column(length: 7)]
    private string $backgroundColor = self::DEFAULT_BACKGROUND_COLOR;

    #[ORM\Column(length: 7)]
    private string $surfaceColor = self::DEFAULT_SURFACE_COLOR;

    #[ORM\Column(length: 7)]
    private string $textColor = self::DEFAULT_TEXT_COLOR;

    #[ORM\Column(length: 7)]
    private string $mutedColor = self::DEFAULT_MUTED_COLOR;

    #[ORM\Column(length: 7)]
    private string $borderColor = self::DEFAULT_BORDER_COLOR;

    #[ORM\Column(length: 7)]
    private string $successColor = self::DEFAULT_SUCCESS_COLOR;

    #[ORM\Column(length: 7)]
    private string $warningColor = self::DEFAULT_WARNING_COLOR;

    #[ORM\Column(length: 7)]
    private string $dangerColor = self::DEFAULT_DANGER_COLOR;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPrimaryColor(): string
    {
        return $this->primaryColor;
    }

    public function setPrimaryColor(string $primaryColor): self
    {
        $this->primaryColor = self::normalizeColor($primaryColor, self::DEFAULT_PRIMARY_COLOR);
        $this->tertiaryColor = self::deriveTertiaryColor($this->primaryColor);
        $this->touch();

        return $this;
    }

    public function getSecondaryColor(): string
    {
        return $this->secondaryColor;
    }

    public function setSecondaryColor(string $secondaryColor): self
    {
        $this->secondaryColor = self::normalizeColor($secondaryColor, self::DEFAULT_SECONDARY_COLOR);
        $this->touch();

        return $this;
    }

    public function getTertiaryColor(): string
    {
        return self::deriveTertiaryColor($this->primaryColor);
    }

    public function setTertiaryColor(string $tertiaryColor): self
    {
        $this->tertiaryColor = self::deriveTertiaryColor($this->primaryColor);
        $this->touch();

        return $this;
    }

    public function getBackgroundColor(): string
    {
        return $this->backgroundColor;
    }

    public function setBackgroundColor(string $backgroundColor): self
    {
        $this->backgroundColor = self::normalizeColor($backgroundColor, self::DEFAULT_BACKGROUND_COLOR);
        $this->touch();

        return $this;
    }

    public function getSurfaceColor(): string
    {
        return $this->surfaceColor;
    }

    public function setSurfaceColor(string $surfaceColor): self
    {
        $this->surfaceColor = self::normalizeColor($surfaceColor, self::DEFAULT_SURFACE_COLOR);
        $this->touch();

        return $this;
    }

    public function getTextColor(): string
    {
        return $this->textColor;
    }

    public function setTextColor(string $textColor): self
    {
        $this->textColor = self::normalizeColor($textColor, self::DEFAULT_TEXT_COLOR);
        $this->touch();

        return $this;
    }

    public function getMutedColor(): string
    {
        return $this->mutedColor;
    }

    public function setMutedColor(string $mutedColor): self
    {
        $this->mutedColor = self::normalizeColor($mutedColor, self::DEFAULT_MUTED_COLOR);
        $this->touch();

        return $this;
    }

    public function getBorderColor(): string
    {
        return $this->borderColor;
    }

    public function setBorderColor(string $borderColor): self
    {
        $this->borderColor = self::normalizeColor($borderColor, self::DEFAULT_BORDER_COLOR);
        $this->touch();

        return $this;
    }

    public function getSuccessColor(): string
    {
        return $this->successColor;
    }

    public function setSuccessColor(string $successColor): self
    {
        $this->successColor = self::normalizeColor($successColor, self::DEFAULT_SUCCESS_COLOR);
        $this->touch();

        return $this;
    }

    public function getWarningColor(): string
    {
        return $this->warningColor;
    }

    public function setWarningColor(string $warningColor): self
    {
        $this->warningColor = self::normalizeColor($warningColor, self::DEFAULT_WARNING_COLOR);
        $this->touch();

        return $this;
    }

    public function getDangerColor(): string
    {
        return $this->dangerColor;
    }

    public function setDangerColor(string $dangerColor): self
    {
        $this->dangerColor = self::normalizeColor($dangerColor, self::DEFAULT_DANGER_COLOR);
        $this->touch();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /**
     * @return array{primary: string, secondary: string, tertiary: string, background: string, surface: string, text: string, muted: string, border: string, success: string, warning: string, danger: string}
     */
    public function toPalette(): array
    {
        return [
            'primary' => $this->primaryColor,
            'secondary' => $this->secondaryColor,
            'tertiary' => $this->getTertiaryColor(),
            'background' => $this->backgroundColor,
            'surface' => $this->surfaceColor,
            'text' => $this->textColor,
            'muted' => $this->mutedColor,
            'border' => $this->borderColor,
            'success' => $this->successColor,
            'warning' => $this->warningColor,
            'danger' => $this->dangerColor,
        ];
    }

    public static function default(): self
    {
        return new self();
    }

    public static function normalizeColor(string $value, string $fallback): string
    {
        $value = trim($value);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? strtolower($value) : $fallback;
    }

    public static function deriveTertiaryColor(string $primaryColor): string
    {
        $primaryColor = self::normalizeColor($primaryColor, self::DEFAULT_PRIMARY_COLOR);
        $rgb = self::hexToRgb($primaryColor);
        if ($rgb === null) {
            return self::DEFAULT_TERTIARY_COLOR;
        }

        [$hue, $saturation] = self::rgbToHsl($rgb[0], $rgb[1], $rgb[2]);
        $saturation = min($saturation, 0.8);

        return self::hslToHex($hue, $saturation, 0.79);
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    private static function hexToRgb(string $hex): ?array
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

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private static function rgbToHsl(int $red, int $green, int $blue): array
    {
        $red /= 255;
        $green /= 255;
        $blue /= 255;

        $max = max($red, $green, $blue);
        $min = min($red, $green, $blue);
        $lightness = ($max + $min) / 2;

        if ($max === $min) {
            return [0.0, 0.0, $lightness];
        }

        $delta = $max - $min;
        $saturation = $lightness > 0.5
            ? $delta / (2 - $max - $min)
            : $delta / ($max + $min);

        $hue = match ($max) {
            $red => (($green - $blue) / $delta) + ($green < $blue ? 6 : 0),
            $green => (($blue - $red) / $delta) + 2,
            default => (($red - $green) / $delta) + 4,
        };

        return [$hue * 60, $saturation, $lightness];
    }

    private static function hslToHex(float $hue, float $saturation, float $lightness): string
    {
        $chroma = (1 - abs((2 * $lightness) - 1)) * $saturation;
        $huePrime = $hue / 60;
        $x = $chroma * (1 - abs(fmod($huePrime, 2) - 1));
        $m = $lightness - ($chroma / 2);

        [$red, $green, $blue] = match (true) {
            $huePrime < 1 => [$chroma, $x, 0],
            $huePrime < 2 => [$x, $chroma, 0],
            $huePrime < 3 => [0, $chroma, $x],
            $huePrime < 4 => [0, $x, $chroma],
            $huePrime < 5 => [$x, 0, $chroma],
            default => [$chroma, 0, $x],
        };

        return sprintf(
            '#%02x%02x%02x',
            (int) round(($red + $m) * 255),
            (int) round(($green + $m) * 255),
            (int) round(($blue + $m) * 255)
        );
    }
}
