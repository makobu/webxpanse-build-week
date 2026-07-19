<?php

namespace CRM\Services;

class VideoBrandOverlayUi
{
    public static function frame(string $mediaHtml, string $class = ''): string
    {
        $classes = trim('video-brand-overlay-frame ' . $class);

        return '<div class="' . self::escape($classes) . '">'
            . $mediaHtml
            . self::logo()
            . '</div>';
    }

    public static function logo(): string
    {
        return '<img class="video-brand-overlay-logo" src="' . self::escape(self::logoUrl()) . '" alt="" aria-hidden="true" loading="lazy">';
    }

    public static function assets(): string
    {
        return <<<'HTML'
<style>
.video-brand-overlay-frame {
    position: relative;
    overflow: hidden;
    background: #020617;
    isolation: isolate;
}
.video-brand-overlay-frame > video,
.video-brand-overlay-frame > iframe {
    position: relative;
    z-index: 1;
    display: block;
    width: 100%;
}
.video-brand-overlay-logo {
    position: absolute;
    right: clamp(0.28rem, 0.75vw, 0.62rem);
    bottom: clamp(0.12rem, 0.45vw, 0.36rem);
    z-index: 3;
    width: clamp(5.15rem, 7vw, 7.75rem);
    max-width: 28%;
    height: auto;
    box-sizing: border-box;
    border-radius: 8px;
    padding: clamp(0.08rem, 0.24vw, 0.16rem) clamp(0.08rem, 0.28vw, 0.2rem) clamp(0.18rem, 0.38vw, 0.3rem);
    background: linear-gradient(180deg, rgba(90, 108, 132, 0.82) 0%, rgba(15, 23, 42, 0.94) 54%, #020617 100%);
    border: 1px solid rgba(147, 197, 253, 0.16);
    box-shadow:
        0 10px 22px rgba(2, 6, 23, 0.36),
        0 0 0 1px rgba(255, 255, 255, 0.08),
        0 0 16px rgba(59, 130, 246, 0.2);
    pointer-events: none;
    user-select: none;
}
@media (max-width: 640px) {
    .video-brand-overlay-logo {
        width: clamp(4.7rem, 24vw, 6.4rem);
        max-width: 36%;
        border-radius: 7px;
    }
}
</style>
HTML;
    }

    public static function logoUrl(): string
    {
        return function_exists('assetUrl')
            ? assetUrl('images/webxpanse-video-watermark.png')
            : 'assets/images/webxpanse-video-watermark.png';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
