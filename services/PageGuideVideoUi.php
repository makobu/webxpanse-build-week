<?php

namespace CRM\Services;

class PageGuideVideoUi
{
    public static function activeVideoUrl(string $pageKey): string
    {
        $explainer = (new MarketplacePageExplainerService())->getActive($pageKey);

        return self::assetUrl((string) ($explainer['video_url'] ?? ''));
    }

    public static function assetUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '' || preg_match('#^https?://#i', $path) === 1 || str_starts_with($path, '/')) {
            return $path;
        }
        if (str_starts_with($path, 'uploads/')) {
            return function_exists('publicUrl') ? publicUrl('../' . $path) : '../' . $path;
        }
        if (str_starts_with($path, 'assets/')) {
            $path = substr($path, 7);
        }

        return function_exists('assetUrl') ? assetUrl($path) : 'assets/' . ltrim($path, '/');
    }

    public static function button(string $pageKey, string $label, string $class = ''): string
    {
        $classes = trim($class . ' page-guide-button');
        $safeKey = self::safeKey($pageKey);
        $safeLabel = self::escape('Watch the ' . $label);

        return '<button type="button" class="' . self::escape($classes) . '" data-page-guide-open="' . self::escape($safeKey) . '" aria-label="' . $safeLabel . '">'
            . '<i class="fas fa-circle-play" aria-hidden="true"></i>'
            . '<span>Watch guide</span>'
            . '</button>';
    }

    public static function modal(string $pageKey, string $title, string $videoUrl): string
    {
        $videoUrl = trim($videoUrl);
        if ($videoUrl === '') {
            return '';
        }

        $safeKey = self::safeKey($pageKey);
        $titleId = 'page-guide-video-title-' . $safeKey;

        return '<div class="page-guide-video-modal" data-page-guide-modal="' . self::escape($safeKey) . '" role="dialog" aria-modal="true" aria-labelledby="' . self::escape($titleId) . '" hidden>'
            . '<div class="page-guide-video-dialog" role="document">'
            . '<div class="page-guide-video-head">'
            . '<h2 class="page-guide-video-title" id="' . self::escape($titleId) . '">' . self::escape($title) . '</h2>'
            . '<button class="page-guide-video-close" type="button" data-page-guide-close aria-label="Close guide video"><i class="fas fa-times" aria-hidden="true"></i></button>'
            . '</div>'
            . '<div class="page-guide-video-frame">'
            . VideoBrandOverlayUi::frame(
                '<video controls preload="metadata" playsinline data-page-guide-video>'
                . '<source src="' . self::escape($videoUrl) . '">'
                . 'Your browser does not support embedded video playback.'
                . '</video>'
            )
            . '</div>'
            . '</div>'
            . '</div>';
    }

    public static function assets(): string
    {
        return VideoBrandOverlayUi::assets() . <<<'HTML'
<style>
.page-guide-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
    min-height: 2.35rem;
    border: 1px solid rgba(37, 99, 235, 0.22);
    border-radius: 8px;
    background: #ffffff;
    color: #1d4ed8;
    box-shadow: 0 10px 24px rgba(37, 99, 235, 0.1);
    cursor: pointer;
    font: inherit;
    font-size: 0.88rem;
    font-weight: 700;
    line-height: 1.2;
    padding: 0.55rem 0.85rem;
    text-decoration: none;
    white-space: nowrap;
    transition: border-color 0.18s ease, box-shadow 0.18s ease, color 0.18s ease, transform 0.18s ease;
}
.page-guide-button:hover,
.page-guide-button:focus-visible {
    border-color: rgba(37, 99, 235, 0.45);
    color: #1e40af;
    box-shadow: 0 14px 30px rgba(37, 99, 235, 0.16), 0 0 0 4px rgba(37, 99, 235, 0.08);
    outline: none;
    transform: translateY(-1px);
}
.page-guide-button i {
    color: #2563eb;
}
.page-guide-video-modal[hidden] {
    display: none;
}
.page-guide-video-modal {
    position: fixed;
    inset: 0;
    z-index: 13000;
    box-sizing: border-box;
    display: grid;
    place-items: center;
    padding: clamp(1rem, 3vw, 2rem);
    overflow: hidden;
    background: rgba(15, 23, 42, 0.62);
}
.page-guide-video-dialog {
    width: min(920px, 100%);
    max-height: min(760px, calc(100vh - 2rem));
    overflow: hidden;
    display: flex;
    flex-direction: column;
    border: 1px solid rgba(226, 232, 240, 0.7);
    border-radius: 16px;
    background: #ffffff;
    box-shadow: 0 30px 80px rgba(15, 23, 42, 0.28);
}
.page-guide-video-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.1rem;
    border-bottom: 1px solid rgba(15, 23, 42, 0.08);
}
.page-guide-video-title {
    margin: 0;
    color: #0f172a;
    font-size: 1.05rem;
    font-weight: 600;
    line-height: 1.2;
}
.page-guide-video-close {
    width: 2.35rem;
    height: 2.35rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(148, 163, 184, 0.32);
    border-radius: 10px;
    background: #ffffff;
    color: #334155;
    cursor: pointer;
}
.page-guide-video-close:hover,
.page-guide-video-close:focus-visible {
    border-color: rgba(37, 99, 235, 0.34);
    color: #1d4ed8;
    outline: none;
}
.page-guide-video-frame {
    background: #020617;
    min-height: 0;
    flex: 1 1 auto;
}
.page-guide-video-frame video {
    width: 100%;
    max-height: min(640px, calc(100vh - 7.25rem));
    display: block;
    object-fit: contain;
    background: #020617;
}
</style>
<script>
(function () {
    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
        } else {
            callback();
        }
    }

    ready(function () {
        document.querySelectorAll('[data-page-guide-open]').forEach(function (openButton) {
            var pageKey = openButton.getAttribute('data-page-guide-open') || '';
            var modal = document.querySelector('[data-page-guide-modal="' + pageKey + '"]');
            if (!modal) {
                return;
            }

            var closeButton = modal.querySelector('[data-page-guide-close]');
            var video = modal.querySelector('[data-page-guide-video]');
            var lastFocused = null;

            function openModal() {
                lastFocused = document.activeElement;
                if (modal.parentNode !== document.body) {
                    document.body.appendChild(modal);
                }
                modal.hidden = false;
                modal.scrollTop = 0;
                document.body.style.overflow = 'hidden';
                if (video) {
                    try { video.currentTime = 0; } catch (error) {}
                    var playAttempt = video.play();
                    if (playAttempt && typeof playAttempt.catch === 'function') {
                        playAttempt.catch(function () {});
                    }
                }
                if (closeButton) {
                    closeButton.focus();
                }
            }

            function closeModal() {
                modal.hidden = true;
                document.body.style.overflow = '';
                if (video) {
                    video.pause();
                    try { video.currentTime = 0; } catch (error) {}
                }
                if (lastFocused && typeof lastFocused.focus === 'function') {
                    lastFocused.focus();
                }
            }

            openButton.addEventListener('click', openModal);
            if (closeButton) {
                closeButton.addEventListener('click', closeModal);
            }
            modal.addEventListener('click', function (event) {
                if (event.target === modal) {
                    closeModal();
                }
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !modal.hidden) {
                    closeModal();
                }
            });
        });
    });
})();
</script>
HTML;
    }

    private static function safeKey(string $pageKey): string
    {
        return trim(preg_replace('/[^a-z0-9_]+/', '_', strtolower($pageKey)) ?? '', '_') ?: 'page';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
