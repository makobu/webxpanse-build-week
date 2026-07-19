<?php

declare(strict_types=1);

if (!function_exists('renderLaunchPackageHighlights')) {
    /**
     * @param array<string,mixed> $package
     */
    function renderLaunchPackageHighlights(array $package, string $className): void
    {
        $highlights = is_array($package['feature_highlights'] ?? null) ? (array) $package['feature_highlights'] : [];
        if ($highlights === []) {
            return;
        }
        ?>
        <ul class="<?php echo htmlspecialchars($className); ?>">
            <?php foreach ($highlights as $highlight): ?>
                <li><?php echo htmlspecialchars((string) $highlight); ?></li>
            <?php endforeach; ?>
        </ul>
        <?php
    }
}

if (!function_exists('launchPackageFormatMoney')) {
    function launchPackageFormatMoney(float $amount, string $currency = 'KES'): string
    {
        return strtoupper($currency) . ' ' . number_format($amount, 0);
    }
}

if (!function_exists('launchPackageBestFit')) {
    /**
     * @param array<string,mixed> $package
     */
    function launchPackageBestFit(array $package): string
    {
        return trim((string) ($package['best_fit'] ?? $package['best_for'] ?? ''));
    }
}

if (!function_exists('renderLaunchPackageCadenceOptions')) {
    /**
     * @param array<string,mixed> $package
     */
    function renderLaunchPackageCadenceOptions(
        array $package,
        string $className,
        ?int $selectedPriceId = null,
        bool $required = true,
        bool $checkFirstWhenNoSelection = true
    ): void {
        $options = is_array($package['checkout_options'] ?? null) ? array_values((array) $package['checkout_options']) : [];
        if ($options === []) {
            return;
        }

        $freeOptions = array_values(array_filter($options, static function (array $option): bool {
            return (float) ($option['amount'] ?? 0) <= 0;
        }));
        if (count($freeOptions) === count($options) && count($options) > 1) {
            $options = [$options[0]];
            $options[0]['label'] = 'Free access';
        }

        $radioGroupLabel = trim((string) ($package['display_name'] ?? $package['name'] ?? 'Package')) . ' billing cadence';
        ?>
        <div class="<?php echo htmlspecialchars($className); ?>" role="radiogroup" aria-label="<?php echo htmlspecialchars($radioGroupLabel); ?>">
            <?php foreach ($options as $optionIndex => $option): ?>
                <?php
                    $priceId = (int) ($option['billing_plan_price_id'] ?? 0);
                    if ($priceId <= 0) {
                        continue;
                    }
                    $amount = (float) ($option['amount'] ?? 0);
                    $currency = (string) ($option['currency'] ?? 'KES');
                    $label = (string) ($option['label'] ?? 'Monthly');
                    $cadence = strtolower((string) ($option['cadence'] ?? ''));
                    $isFree = $amount <= 0;
                    $isChecked = $selectedPriceId !== null
                        ? $selectedPriceId === $priceId
                        : ($checkFirstWhenNoSelection && $optionIndex === 0);
                ?>
                <label class="<?php echo htmlspecialchars($className); ?>__option">
                    <input
                        type="radio"
                        name="billing_plan_price_id"
                        value="<?php echo $priceId; ?>"
                        <?php echo $isChecked ? 'checked' : ''; ?>
                        <?php echo $required ? 'required' : ''; ?>
                        data-package-card-radio
                    >
                    <span class="<?php echo htmlspecialchars($className); ?>__label"><?php echo htmlspecialchars($label); ?></span>
                    <strong class="<?php echo htmlspecialchars($className); ?>__price">
                        <?php echo htmlspecialchars($isFree ? 'KES 0' : launchPackageFormatMoney($amount, $currency)); ?>
                    </strong>
                    <?php if (!$isFree && $cadence !== ''): ?>
                        <small class="<?php echo htmlspecialchars($className); ?>__meta"><?php echo $cadence === 'annual' ? 'per year' : 'per month'; ?></small>
                    <?php endif; ?>
                </label>
            <?php endforeach; ?>
        </div>
        <?php
    }
}

if (!function_exists('renderLaunchPackageFeatureDetails')) {
    /**
     * @param array<string,mixed> $package
     */
    function renderLaunchPackageFeatureDetails(array $package, string $className = 'launch-package-feature-details', string $summaryLabel = 'Full feature breakdown'): void
    {
        $groups = is_array($package['feature_groups'] ?? null) ? (array) $package['feature_groups'] : [];
        $bestFit = launchPackageBestFit($package);
        $upgradeReason = trim((string) ($package['upgrade_reason'] ?? ''));
        if ($groups === [] && $bestFit === '' && $upgradeReason === '') {
            return;
        }
        ?>
        <details class="<?php echo htmlspecialchars($className); ?>">
            <summary>
                <span><?php echo htmlspecialchars($summaryLabel); ?></span>
            </summary>
            <div class="launch-package-feature-details__body">
                <?php if ($bestFit !== '' || $upgradeReason !== ''): ?>
                    <div class="launch-package-feature-details__fit">
                        <?php if ($bestFit !== ''): ?>
                            <p><strong>Best fit:</strong> <?php echo htmlspecialchars($bestFit); ?></p>
                        <?php endif; ?>
                        <?php if ($upgradeReason !== ''): ?>
                            <p><strong>Why upgrade:</strong> <?php echo htmlspecialchars($upgradeReason); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php foreach ($groups as $group): ?>
                    <?php
                        $title = trim((string) ($group['title'] ?? 'Features'));
                        $items = is_array($group['items'] ?? null) ? (array) $group['items'] : [];
                    ?>
                    <?php if ($items !== []): ?>
                        <section class="launch-package-feature-details__group">
                            <h4><?php echo htmlspecialchars($title !== '' ? $title : 'Features'); ?></h4>
                            <ul>
                                <?php foreach ($items as $item): ?>
                                    <li><?php echo htmlspecialchars((string) $item); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </section>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </details>
        <?php
    }
}

if (!function_exists('renderLaunchPackageSharedDetailPanels')) {
    /**
     * @param list<array<string,mixed>> $packages
     */
    function renderLaunchPackageSharedDetailPanels(array $packages, string $defaultCode, string $className = 'launch-package-detail-panels'): void
    {
        $packages = array_values(array_filter($packages, static function (array $package): bool {
            return (string) ($package['code'] ?? $package['plan_code'] ?? '') !== '';
        }));
        if ($packages === []) {
            return;
        }

        $codes = array_map(static fn(array $package): string => (string) ($package['code'] ?? $package['plan_code'] ?? ''), $packages);
        if (!in_array($defaultCode, $codes, true)) {
            $defaultCode = $codes[0];
        }
        ?>
        <div class="<?php echo htmlspecialchars($className); ?>" data-package-detail-panels>
            <?php foreach ($packages as $package): ?>
                <?php
                    $packageCode = (string) ($package['code'] ?? $package['plan_code'] ?? '');
                    $isDefault = $packageCode === $defaultCode;
                    $name = (string) ($package['display_name'] ?? $package['name'] ?? 'Package');
                    $tierIntro = trim((string) ($package['tier_intro'] ?? ''));
                    $bestFit = launchPackageBestFit($package);
                    $upgradeReason = trim((string) ($package['upgrade_reason'] ?? ''));
                    $groups = is_array($package['feature_groups'] ?? null) ? (array) $package['feature_groups'] : [];
                ?>
                <article
                    id="package-details-<?php echo htmlspecialchars($packageCode); ?>"
                    class="launch-package-detail-panel"
                    data-package-detail-panel
                    data-package-code="<?php echo htmlspecialchars($packageCode); ?>"
                    <?php echo $isDefault ? '' : 'hidden'; ?>
                >
                    <div class="launch-package-detail-panel__header">
                        <div>
                            <p class="launch-package-detail-panel__eyebrow">Package details</p>
                            <h3><?php echo htmlspecialchars($name); ?></h3>
                        </div>
                        <span><?php echo htmlspecialchars((string) ($package['price_short'] ?? $package['price'] ?? '')); ?></span>
                    </div>
                    <?php if ($tierIntro !== ''): ?>
                        <p class="launch-package-detail-panel__tier"><?php echo htmlspecialchars($tierIntro); ?></p>
                    <?php endif; ?>
                    <?php if ($bestFit !== '' || $upgradeReason !== ''): ?>
                        <div class="launch-package-detail-panel__fit">
                            <?php if ($bestFit !== ''): ?>
                                <p><strong>Best fit:</strong> <?php echo htmlspecialchars($bestFit); ?></p>
                            <?php endif; ?>
                            <?php if ($upgradeReason !== ''): ?>
                                <p><strong>Why upgrade:</strong> <?php echo htmlspecialchars($upgradeReason); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($groups !== []): ?>
                        <div class="launch-package-detail-panel__groups">
                            <?php foreach ($groups as $group): ?>
                                <?php
                                    $title = trim((string) ($group['title'] ?? 'Features'));
                                    $items = is_array($group['items'] ?? null) ? (array) $group['items'] : [];
                                ?>
                                <?php if ($items !== []): ?>
                                    <section class="launch-package-detail-panel__group">
                                        <h4><?php echo htmlspecialchars($title !== '' ? $title : 'Features'); ?></h4>
                                        <ul>
                                            <?php foreach ($items as $item): ?>
                                                <li><?php echo htmlspecialchars((string) $item); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </section>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
    }
}

if (!function_exists('renderLaunchPackageDetailsModal')) {
    /**
     * @param list<array<string,mixed>> $packages
     */
    function renderLaunchPackageDetailsModal(array $packages, string $defaultCode): void
    {
        $packages = array_values(array_filter($packages, static function (array $package): bool {
            return (string) ($package['code'] ?? $package['plan_code'] ?? '') !== '';
        }));
        if ($packages === []) {
            return;
        }

        $codes = array_map(static fn(array $package): string => (string) ($package['code'] ?? $package['plan_code'] ?? ''), $packages);
        if (!in_array($defaultCode, $codes, true)) {
            $defaultCode = $codes[0];
        }
        ?>
        <div class="launch-package-modal" id="package-details-modal" hidden data-package-modal>
            <button type="button" class="launch-package-modal__backdrop" aria-label="Close package details" data-package-modal-close></button>
            <section
                class="launch-package-modal__dialog"
                role="dialog"
                aria-modal="true"
                aria-labelledby="package-details-modal-heading"
                tabindex="-1"
                data-package-modal-dialog
            >
                <h2 id="package-details-modal-heading" class="sr-only">Package details</h2>
                <button type="button" class="launch-package-modal__close" aria-label="Close package details" data-package-modal-close>&times;</button>
                <?php foreach ($packages as $package): ?>
                    <?php
                        $packageCode = (string) ($package['code'] ?? $package['plan_code'] ?? '');
                        $isDefault = $packageCode === $defaultCode;
                        $name = (string) ($package['display_name'] ?? $package['name'] ?? 'Package');
                        $tierIntro = trim((string) ($package['tier_intro'] ?? ''));
                        $bestFit = launchPackageBestFit($package);
                        $upgradeReason = trim((string) ($package['upgrade_reason'] ?? ''));
                        $groups = is_array($package['feature_groups'] ?? null) ? (array) $package['feature_groups'] : [];
                        $highlights = is_array($package['feature_highlights'] ?? null) ? (array) $package['feature_highlights'] : [];
                        $capabilitySummary = array_values(array_filter((array) ($package['capability_summary'] ?? []), static fn($item): bool => trim((string) $item) !== ''));
                    ?>
                    <article
                        class="launch-package-modal__panel"
                        data-package-modal-panel
                        data-package-code="<?php echo htmlspecialchars($packageCode); ?>"
                        <?php echo $isDefault ? '' : 'hidden'; ?>
                    >
                        <div class="launch-package-modal__header">
                            <div>
                                <p class="launch-package-modal__eyebrow">Package details</p>
                                <h3><?php echo htmlspecialchars($name); ?></h3>
                            </div>
                            <span class="launch-package-modal__price"><?php echo htmlspecialchars((string) ($package['price_short'] ?? $package['price'] ?? '')); ?></span>
                        </div>
                        <?php if ($tierIntro !== ''): ?>
                            <p class="launch-package-modal__tier"><?php echo htmlspecialchars($tierIntro); ?></p>
                        <?php endif; ?>
                        <?php if ($bestFit !== '' || $upgradeReason !== ''): ?>
                            <div class="launch-package-modal__fit">
                                <?php if ($bestFit !== ''): ?>
                                    <p><strong>Best fit:</strong> <?php echo htmlspecialchars($bestFit); ?></p>
                                <?php endif; ?>
                                <?php if ($upgradeReason !== ''): ?>
                                    <p><strong>Why upgrade:</strong> <?php echo htmlspecialchars($upgradeReason); ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($capabilitySummary !== []): ?>
                            <section class="launch-package-modal__capability" aria-label="Package capability">
                                <h4>Package capability</h4>
                                <ul>
                                    <?php foreach ($capabilitySummary as $capability): ?>
                                        <li><?php echo htmlspecialchars((string) $capability); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </section>
                        <?php endif; ?>
                        <?php if ($highlights !== []): ?>
                            <div class="launch-package-modal__highlights" aria-label="Package highlights">
                                <?php foreach ($highlights as $highlight): ?>
                                    <span><?php echo htmlspecialchars((string) $highlight); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($groups !== []): ?>
                            <div class="launch-package-modal__groups">
                                <?php foreach ($groups as $group): ?>
                                    <?php
                                        $title = trim((string) ($group['title'] ?? 'Features'));
                                        $items = is_array($group['items'] ?? null) ? (array) $group['items'] : [];
                                    ?>
                                    <?php if ($items !== []): ?>
                                        <section class="launch-package-modal__group">
                                            <h4><?php echo htmlspecialchars($title !== '' ? $title : 'Features'); ?></h4>
                                            <ul>
                                                <?php foreach ($items as $item): ?>
                                                    <li><?php echo htmlspecialchars((string) $item); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </section>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </section>
        </div>
        <?php
    }
}

if (!function_exists('launchPackageBookingSlotPayload')) {
    /**
     * @param array<string,mixed> $slot
     * @return array<string,string>
     */
    function launchPackageBookingSlotPayload(array $slot, string $fallbackTimezone): array
    {
        $startsAt = (string) ($slot['starts_at'] ?? '');
        $timezone = (string) ($slot['timezone'] ?? $fallbackTimezone);
        if ($timezone === '') {
            $timezone = date_default_timezone_get() ?: 'UTC';
        }

        try {
            $date = new DateTimeImmutable($startsAt, new DateTimeZone($timezone));
        } catch (Throwable) {
            $date = new DateTimeImmutable('now', new DateTimeZone($timezone));
        }

        return [
            'startsAt' => $startsAt,
            'iso' => (string) ($slot['starts_at_iso'] ?? $date->format(DateTimeInterface::ATOM)),
            'timezone' => $timezone,
            'dateKey' => $date->format('Y-m-d'),
            'monthKey' => $date->format('Y-m'),
            'dayLabel' => $date->format('D, M j'),
            'timeLabel' => $date->format('H:i'),
            'fullLabel' => $date->format('D, M j, Y H:i') . ' ' . $timezone,
        ];
    }
}

if (!function_exists('renderLaunchPackageBookingModal')) {
    /**
     * @param list<array<string,mixed>> $slots
     */
    function renderLaunchPackageBookingModal(array $slots, string $timezone, string $csrfToken): void
    {
        $slotPayload = array_values(array_filter(array_map(
            static fn(array $slot): array => launchPackageBookingSlotPayload($slot, $timezone),
            $slots
        ), static fn(array $slot): bool => (string) ($slot['startsAt'] ?? '') !== ''));
        $slotsJson = json_encode(
            $slotPayload,
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
        );
        if (!is_string($slotsJson)) {
            $slotsJson = '[]';
        }
        ?>
        <div class="launch-package-modal package-booking-modal" id="package-booking-modal" hidden data-package-booking-modal>
            <button type="button" class="launch-package-modal__backdrop" aria-label="Close meeting booking" data-package-booking-close></button>
            <section
                class="launch-package-modal__dialog package-booking-modal__dialog"
                role="dialog"
                aria-modal="true"
                aria-labelledby="package-booking-modal-heading"
                tabindex="-1"
                data-package-booking-dialog
            >
                <button type="button" class="launch-package-modal__close" aria-label="Close meeting booking" data-package-booking-close>&times;</button>
                <form method="POST" class="package-booking-form" data-package-booking-form data-package-booking-slots="<?php echo htmlspecialchars($slotsJson, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="package_action" value="book_meeting">
                    <input type="hidden" name="package_code" value="" data-package-booking-code>
                    <input type="hidden" name="meeting_timezone" value="<?php echo htmlspecialchars($timezone); ?>" data-package-booking-timezone>
                    <input type="hidden" name="meeting_slot" value="" data-package-booking-slot>
                    <div class="package-booking-form__header">
                        <p class="launch-package-modal__eyebrow">Negotiated package</p>
                        <h2 id="package-booking-modal-heading">Book support meeting</h2>
                        <p data-package-booking-name>Custom package</p>
                    </div>
                    <?php if ($slotPayload === []): ?>
                        <div class="package-booking-form__empty">No support meeting slots are available right now.</div>
                    <?php else: ?>
                        <div class="package-booking-calendar" data-package-booking-calendar>
                            <div class="package-booking-calendar__toolbar">
                                <button type="button" class="package-booking-calendar__nav" data-package-booking-prev>Previous</button>
                                <strong data-package-booking-month>Available dates</strong>
                                <div class="package-booking-calendar__actions">
                                    <button type="button" class="package-booking-calendar__nav" data-package-booking-today>Today</button>
                                    <button type="button" class="package-booking-calendar__nav" data-package-booking-next>Next</button>
                                </div>
                            </div>
                            <div class="package-booking-calendar__layout">
                                <div class="package-booking-calendar__month">
                                    <div class="package-booking-calendar__weekdays" aria-hidden="true">
                                        <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday): ?>
                                            <span><?php echo htmlspecialchars($weekday); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="package-booking-calendar__grid" role="grid" aria-label="Available support meeting days" data-package-booking-grid></div>
                                </div>
                                <aside class="package-booking-calendar__times" aria-live="polite">
                                    <div>
                                        <p class="package-booking-calendar__eyebrow">Day and time</p>
                                        <h3 data-package-booking-selected-day>Choose a day</h3>
                                    </div>
                                    <div class="package-booking-calendar__slot-list" data-package-booking-times></div>
                                </aside>
                            </div>
                        </div>
                    <?php endif; ?>
                    <noscript>
                        <div class="package-booking-form__empty">Calendar booking needs JavaScript. Please enable JavaScript to choose an available support slot.</div>
                    </noscript>
                    <p class="package-booking-form__meta">Account and workspace details will be added automatically.</p>
                    <p class="package-booking-calendar__summary" data-package-booking-summary><?php echo $slotPayload === [] ? 'No available support slots.' : 'Choose an available day and time.'; ?></p>
                    <button type="submit" class="btn-premium-primary" data-package-booking-submit <?php echo $slotPayload === [] ? 'disabled' : 'disabled'; ?>>Book now</button>
                </form>
            </section>
        </div>
        <?php
    }
}

if (!function_exists('renderLaunchPackageBookingScript')) {
    function renderLaunchPackageBookingScript(): void
    {
        ?>
        <script>
        (() => {
            if (window.__launchPackageBookingReady) {
                return;
            }
            window.__launchPackageBookingReady = true;

            const parseSlots = (form) => {
                try {
                    const parsed = JSON.parse(form.getAttribute('data-package-booking-slots') || '[]');
                    return Array.isArray(parsed) ? parsed.filter((slot) => slot && slot.startsAt && slot.dateKey) : [];
                } catch (error) {
                    return [];
                }
            };

            const keyForDate = (date) => {
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                return `${year}-${month}-${day}`;
            };

            const monthLabel = (year, monthIndex) => {
                return new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric' }).format(new Date(year, monthIndex, 1));
            };

            const sameMonth = (date, year, monthIndex) => date.getFullYear() === year && date.getMonth() === monthIndex;

            document.querySelectorAll('[data-package-booking-modal]').forEach((modal) => {
                const form = modal.querySelector('[data-package-booking-form]');
                const dialog = modal.querySelector('[data-package-booking-dialog]');
                const codeInput = modal.querySelector('[data-package-booking-code]');
                const timezoneInput = modal.querySelector('[data-package-booking-timezone]');
                const slotInput = modal.querySelector('[data-package-booking-slot]');
                const packageName = modal.querySelector('[data-package-booking-name]');
                const submitButton = modal.querySelector('[data-package-booking-submit]');
                const calendar = modal.querySelector('[data-package-booking-calendar]');
                const monthNode = modal.querySelector('[data-package-booking-month]');
                const grid = modal.querySelector('[data-package-booking-grid]');
                const times = modal.querySelector('[data-package-booking-times]');
                const selectedDayNode = modal.querySelector('[data-package-booking-selected-day]');
                const summary = modal.querySelector('[data-package-booking-summary]');
                const slots = form ? parseSlots(form) : [];
                const slotsByDay = slots.reduce((map, slot) => {
                    map.set(slot.dateKey, [...(map.get(slot.dateKey) || []), slot]);
                    return map;
                }, new Map());
                const earliest = slots[0] || null;
                const today = new Date();
                const earliestDate = earliest ? new Date(`${earliest.dateKey}T00:00:00`) : today;
                const state = {
                    visibleYear: earliestDate.getFullYear(),
                    visibleMonth: earliestDate.getMonth(),
                    selectedDateKey: earliest ? earliest.dateKey : keyForDate(today),
                    selectedSlot: null,
                    lastTrigger: null,
                };

                if (!form || !calendar || !grid || !times) {
                    return;
                }

                if (timezoneInput) {
                    timezoneInput.value = timezoneInput.value || Intl.DateTimeFormat().resolvedOptions().timeZone || '';
                }

                const setExpanded = (trigger, expanded) => {
                    if (trigger) {
                        trigger.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                    }
                };

                const setSelectedSlot = (slot) => {
                    state.selectedSlot = slot || null;
                    if (slotInput) {
                        slotInput.value = slot ? slot.startsAt : '';
                    }
                    if (submitButton) {
                        submitButton.disabled = !slot;
                    }
                    if (summary) {
                        summary.textContent = slot ? `Selected ${slot.fullLabel}` : 'Choose an available day and time.';
                    }
                    renderTimes();
                };

                const renderTimes = () => {
                    const daySlots = slotsByDay.get(state.selectedDateKey) || [];
                    const dayLabel = daySlots[0] ? daySlots[0].dayLabel : 'Choose a day';
                    if (selectedDayNode) {
                        selectedDayNode.textContent = dayLabel;
                    }
                    times.innerHTML = '';
                    if (daySlots.length === 0) {
                        const empty = document.createElement('p');
                        empty.className = 'package-booking-calendar__empty';
                        empty.textContent = 'No support slots on this day.';
                        times.appendChild(empty);
                        return;
                    }
                    daySlots.forEach((slot) => {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'package-booking-calendar__time';
                        button.textContent = slot.timeLabel;
                        button.setAttribute('aria-pressed', state.selectedSlot && state.selectedSlot.startsAt === slot.startsAt ? 'true' : 'false');
                        button.addEventListener('click', () => setSelectedSlot(slot));
                        times.appendChild(button);
                    });
                };

                const renderCalendar = () => {
                    if (monthNode) {
                        monthNode.textContent = monthLabel(state.visibleYear, state.visibleMonth);
                    }
                    grid.innerHTML = '';
                    const firstOfMonth = new Date(state.visibleYear, state.visibleMonth, 1);
                    const startOffset = (firstOfMonth.getDay() + 6) % 7;
                    const daysInMonth = new Date(state.visibleYear, state.visibleMonth + 1, 0).getDate();
                    const totalCells = Math.ceil((startOffset + daysInMonth) / 7) * 7;
                    const currentKey = keyForDate(today);

                    for (let index = 0; index < totalCells; index += 1) {
                        const dayNumber = index - startOffset + 1;
                        const cellDate = new Date(state.visibleYear, state.visibleMonth, dayNumber);
                        const dateKey = keyForDate(cellDate);
                        const daySlots = slotsByDay.get(dateKey) || [];
                        const isCurrentMonth = sameMonth(cellDate, state.visibleYear, state.visibleMonth);
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'package-booking-calendar__day';
                        button.disabled = !isCurrentMonth || daySlots.length === 0;
                        button.setAttribute('role', 'gridcell');
                        button.setAttribute('aria-selected', state.selectedDateKey === dateKey ? 'true' : 'false');
                        if (!isCurrentMonth) {
                            button.classList.add('is-muted');
                        }
                        if (dateKey === currentKey) {
                            button.classList.add('is-today');
                        }
                        if (daySlots.length > 0) {
                            button.classList.add('has-slots');
                        }
                        if (state.selectedDateKey === dateKey) {
                            button.classList.add('is-selected');
                        }
                        button.innerHTML = `<span>${cellDate.getDate()}</span>${daySlots.length > 0 ? `<small>${daySlots.length}</small>` : ''}`;
                        if (daySlots.length > 0 && isCurrentMonth) {
                            button.addEventListener('click', () => {
                                state.selectedDateKey = dateKey;
                                setSelectedSlot(null);
                                renderCalendar();
                                renderTimes();
                            });
                        }
                        grid.appendChild(button);
                    }
                    renderTimes();
                };

                const moveMonth = (delta) => {
                    const next = new Date(state.visibleYear, state.visibleMonth + delta, 1);
                    state.visibleYear = next.getFullYear();
                    state.visibleMonth = next.getMonth();
                    renderCalendar();
                };

                modal.querySelector('[data-package-booking-prev]')?.addEventListener('click', () => moveMonth(-1));
                modal.querySelector('[data-package-booking-next]')?.addEventListener('click', () => moveMonth(1));
                modal.querySelector('[data-package-booking-today]')?.addEventListener('click', () => {
                    state.visibleYear = today.getFullYear();
                    state.visibleMonth = today.getMonth();
                    renderCalendar();
                });

                const openBooking = (trigger) => {
                    state.lastTrigger = trigger;
                    state.selectedDateKey = earliest ? earliest.dateKey : keyForDate(today);
                    state.visibleYear = earliestDate.getFullYear();
                    state.visibleMonth = earliestDate.getMonth();
                    setSelectedSlot(null);
                    if (codeInput) {
                        codeInput.value = trigger.getAttribute('data-package-code') || '';
                    }
                    if (packageName) {
                        packageName.textContent = trigger.getAttribute('data-package-name') || 'Custom package';
                    }
                    if (timezoneInput) {
                        timezoneInput.value = timezoneInput.value || Intl.DateTimeFormat().resolvedOptions().timeZone || '';
                    }
                    modal.hidden = false;
                    document.body.classList.add('launch-package-modal-open');
                    setExpanded(trigger, true);
                    renderCalendar();
                    if (dialog && typeof dialog.focus === 'function') {
                        dialog.focus();
                    }
                };

                const closeBooking = () => {
                    modal.hidden = true;
                    document.body.classList.remove('launch-package-modal-open');
                    setExpanded(state.lastTrigger, false);
                    if (state.lastTrigger && typeof state.lastTrigger.focus === 'function') {
                        state.lastTrigger.focus();
                    }
                    state.lastTrigger = null;
                };

                document.addEventListener('click', (event) => {
                    const target = event.target instanceof Element ? event.target : null;
                    const trigger = target ? target.closest('[data-package-booking-trigger]') : null;
                    if (trigger) {
                        event.preventDefault();
                        openBooking(trigger);
                        return;
                    }
                    if (target && target.closest('[data-package-booking-close]')) {
                        event.preventDefault();
                        closeBooking();
                    }
                });

                document.addEventListener('keydown', (event) => {
                    if (!modal.hidden && event.key === 'Escape') {
                        closeBooking();
                    }
                });
            });
        })();
        </script>
        <?php
    }
}

if (!function_exists('renderLaunchPackageModalScript')) {
    function renderLaunchPackageModalScript(): void
    {
        ?>
        <script>
        (() => {
            if (window.__launchPackageModalReady) {
                return;
            }
            window.__launchPackageModalReady = true;

            const modal = document.querySelector('[data-package-modal]');
            if (!modal) {
                return;
            }
            const dialog = modal.querySelector('[data-package-modal-dialog]');
            let lastTrigger = null;

            const focusableSelector = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';
            const packageSectionFor = (element) => element ? element.closest('[data-launch-package-section]') : null;

            const activateCard = (section, packageCode) => {
                if (!section || !packageCode) {
                    return;
                }
                section.querySelectorAll('[data-package-card]').forEach((card) => {
                    card.classList.toggle('is-detail-active', card.getAttribute('data-package-code') === packageCode);
                });
            };

            const openModal = (packageCode, trigger) => {
                if (!packageCode || !dialog) {
                    return;
                }
                lastTrigger = trigger || null;
                modal.querySelectorAll('[data-package-modal-panel]').forEach((panel) => {
                    panel.hidden = panel.getAttribute('data-package-code') !== packageCode;
                });
                document.querySelectorAll('[data-package-detail-trigger]').forEach((button) => {
                    button.setAttribute('aria-expanded', button.getAttribute('data-package-code') === packageCode ? 'true' : 'false');
                });
                activateCard(packageSectionFor(trigger), packageCode);
                modal.hidden = false;
                document.body.classList.add('launch-package-modal-open');
                dialog.focus();
            };

            const closeModal = () => {
                modal.hidden = true;
                document.body.classList.remove('launch-package-modal-open');
                document.querySelectorAll('[data-package-detail-trigger]').forEach((button) => {
                    button.setAttribute('aria-expanded', 'false');
                });
                if (lastTrigger && typeof lastTrigger.focus === 'function') {
                    lastTrigger.focus();
                }
                lastTrigger = null;
            };

            document.addEventListener('click', (event) => {
                const target = event.target instanceof Element ? event.target : null;
                const detailTrigger = target ? target.closest('[data-package-detail-trigger]') : null;
                if (detailTrigger) {
                    openModal(detailTrigger.getAttribute('data-package-code'), detailTrigger);
                    return;
                }

                if (target && target.closest('[data-package-modal-close]')) {
                    closeModal();
                }
            });

            document.addEventListener('change', (event) => {
                const target = event.target instanceof Element ? event.target : null;
                const input = target ? target.closest('[data-package-card-radio]') : null;
                if (!input || !input.checked) {
                    return;
                }
                const section = input.closest('[data-launch-package-section]');
                const card = input.closest('[data-package-card]');
                if (!section || !card) {
                    return;
                }
                section.querySelectorAll('[data-package-card]').forEach((candidate) => {
                    candidate.classList.toggle('is-selected', candidate === card);
                });
            });

            document.addEventListener('keydown', (event) => {
                if (modal.hidden) {
                    return;
                }
                if (event.key === 'Escape') {
                    closeModal();
                    return;
                }
                if (event.key !== 'Tab' || !dialog) {
                    return;
                }
                const focusable = Array.from(dialog.querySelectorAll(focusableSelector)).filter((element) => {
                    return element instanceof HTMLElement && !element.hasAttribute('disabled') && element.offsetParent !== null;
                });
                if (focusable.length === 0) {
                    event.preventDefault();
                    dialog.focus();
                    return;
                }
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            });
        })();
        </script>
        <?php
    }
}

if (!function_exists('renderLaunchPackageCardScript')) {
    function renderLaunchPackageCardScript(): void
    {
        ?>
        <script>
        (() => {
            if (window.__launchPackageCardsReady) {
                return;
            }
            window.__launchPackageCardsReady = true;

            const activatePackage = (section, packageCode) => {
                if (!section || !packageCode) {
                    return;
                }
                section.querySelectorAll('[data-package-detail-panel]').forEach((panel) => {
                    panel.hidden = panel.getAttribute('data-package-code') !== packageCode;
                });
                section.querySelectorAll('[data-package-card]').forEach((card) => {
                    card.classList.toggle('is-detail-active', card.getAttribute('data-package-code') === packageCode);
                });
                section.querySelectorAll('[data-package-detail-trigger]').forEach((trigger) => {
                    const isActive = trigger.getAttribute('data-package-code') === packageCode;
                    trigger.setAttribute('aria-expanded', isActive ? 'true' : 'false');
                });
            };

            document.addEventListener('click', (event) => {
                const target = event.target instanceof Element ? event.target : null;
                const trigger = target ? target.closest('[data-package-detail-trigger]') : null;
                if (!trigger) {
                    return;
                }
                const section = trigger.closest('[data-launch-package-section]');
                activatePackage(section, trigger.getAttribute('data-package-code'));
            });

            document.addEventListener('change', (event) => {
                const target = event.target instanceof Element ? event.target : null;
                const input = target ? target.closest('[data-package-card-radio]') : null;
                if (!input || !input.checked) {
                    return;
                }
                const section = input.closest('[data-launch-package-section]');
                const card = input.closest('[data-package-card]');
                if (!section || !card) {
                    return;
                }
                section.querySelectorAll('[data-package-card]').forEach((candidate) => {
                    candidate.classList.toggle('is-selected', candidate === card);
                });
                activatePackage(section, card.getAttribute('data-package-code'));
            });
        })();
        </script>
        <?php
    }
}
