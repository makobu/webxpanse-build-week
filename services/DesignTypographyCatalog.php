<?php

namespace CRM\Services;

/**
 * Curated typography choices shared by public design surfaces.
 *
 * Values stored in design documents are stable keys. CSS stacks remain
 * code-owned so documents and signature settings cannot inject arbitrary CSS.
 */
final class DesignTypographyCatalog
{
    /** @var array<string,array{label:string,css:string}> */
    private const WEB_FONTS = [
        'system' => ['label' => 'Modern system', 'css' => 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif'],
        'arial' => ['label' => 'Arial', 'css' => 'Arial, Helvetica, sans-serif'],
        'georgia' => ['label' => 'Georgia', 'css' => 'Georgia, "Times New Roman", serif'],
        'verdana' => ['label' => 'Verdana', 'css' => 'Verdana, Geneva, sans-serif'],
        'trebuchet' => ['label' => 'Trebuchet', 'css' => '"Trebuchet MS", Arial, sans-serif'],
        'tahoma' => ['label' => 'Tahoma', 'css' => 'Tahoma, Verdana, sans-serif'],
        'times' => ['label' => 'Times New Roman', 'css' => '"Times New Roman", Times, serif'],
        'courier' => ['label' => 'Courier New', 'css' => '"Courier New", Courier, monospace'],
    ];

    /** @var array<string,array{label:string,heading:float,body:float}> */
    private const WEB_SCALES = [
        'compact' => ['label' => 'Compact', 'heading' => 0.9, 'body' => 0.94],
        'balanced' => ['label' => 'Balanced', 'heading' => 1.0, 'body' => 1.0],
        'expressive' => ['label' => 'Expressive', 'heading' => 1.12, 'body' => 1.05],
    ];

    /** @var array<string,array{label:string,css:string}> */
    private const EMAIL_FONTS = [
        'arial' => ['label' => 'Arial', 'css' => 'Arial, Helvetica, sans-serif'],
        'verdana' => ['label' => 'Verdana', 'css' => 'Verdana, Geneva, sans-serif'],
        'tahoma' => ['label' => 'Tahoma', 'css' => 'Tahoma, Verdana, sans-serif'],
        'georgia' => ['label' => 'Georgia', 'css' => 'Georgia, "Times New Roman", serif'],
        'times' => ['label' => 'Times New Roman', 'css' => '"Times New Roman", Times, serif'],
    ];

    /** @var array<string,array{label:string,pixels:int,line_height:float}> */
    private const EMAIL_SIZES = [
        'compact' => ['label' => 'Compact · 12px', 'pixels' => 12, 'line_height' => 1.4],
        'standard' => ['label' => 'Standard · 14px', 'pixels' => 14, 'line_height' => 1.45],
        'comfortable' => ['label' => 'Comfortable · 16px', 'pixels' => 16, 'line_height' => 1.5],
    ];

    /** @return array<int,array{value:string,label:string,css:string}> */
    public static function webFonts(): array
    {
        return self::options(self::WEB_FONTS);
    }

    /** @return array<int,array{value:string,label:string,heading:float,body:float}> */
    public static function webScales(): array
    {
        $options = [];
        foreach (self::WEB_SCALES as $value => $definition) {
            $options[] = ['value' => $value] + $definition;
        }
        return $options;
    }

    /** @return array<int,array{value:string,label:string,css:string}> */
    public static function emailFonts(): array
    {
        return self::options(self::EMAIL_FONTS);
    }

    /** @return array<int,array{value:string,label:string,pixels:int,line_height:float}> */
    public static function emailSizes(): array
    {
        $options = [];
        foreach (self::EMAIL_SIZES as $value => $definition) {
            $options[] = ['value' => $value] + $definition;
        }
        return $options;
    }

    /** @return array{web_fonts:array<int,array<string,mixed>>,web_scales:array<int,array<string,mixed>>} */
    public static function webEditorConfig(): array
    {
        return ['web_fonts' => self::webFonts(), 'web_scales' => self::webScales()];
    }

    public static function normalizeWebFont(string $value, string $fallback = 'system'): string
    {
        $value = trim($value);
        $legacy = [
            'Inter, system-ui, sans-serif' => 'system',
            'system-ui, sans-serif' => 'system',
            'Georgia, serif' => 'georgia',
        ];
        $value = $legacy[$value] ?? strtolower($value);
        return isset(self::WEB_FONTS[$value]) ? $value : $fallback;
    }

    public static function webFontCss(string $value): string
    {
        $key = self::normalizeWebFont($value);
        return self::WEB_FONTS[$key]['css'];
    }

    public static function normalizeWebScale(string $value, string $fallback = 'balanced'): string
    {
        $value = strtolower(trim($value));
        return isset(self::WEB_SCALES[$value]) ? $value : $fallback;
    }

    /** @return array{label:string,heading:float,body:float} */
    public static function webScale(string $value): array
    {
        return self::WEB_SCALES[self::normalizeWebScale($value)];
    }

    public static function normalizeEmailFont(string $value, string $fallback = 'arial'): string
    {
        $value = strtolower(trim($value));
        return isset(self::EMAIL_FONTS[$value]) ? $value : $fallback;
    }

    public static function emailFontCss(string $value): string
    {
        return self::EMAIL_FONTS[self::normalizeEmailFont($value)]['css'];
    }

    public static function normalizeEmailSize(string $value, string $fallback = 'standard'): string
    {
        $value = strtolower(trim($value));
        return isset(self::EMAIL_SIZES[$value]) ? $value : $fallback;
    }

    /** @return array{label:string,pixels:int,line_height:float} */
    public static function emailSize(string $value): array
    {
        return self::EMAIL_SIZES[self::normalizeEmailSize($value)];
    }

    /**
     * @param array<string,array{label:string,css:string}> $catalog
     * @return array<int,array{value:string,label:string,css:string}>
     */
    private static function options(array $catalog): array
    {
        $options = [];
        foreach ($catalog as $value => $definition) {
            $options[] = ['value' => $value] + $definition;
        }
        return $options;
    }
}
