/**
 * Cookie Consent Manager
 * Handles cookie consent banner and preferences
 */

(function() {
    'use strict';

    const COOKIE_CONSENT_KEY = 'crm_cookie_consent';
    const COOKIE_PREFERENCES_KEY = 'crm_cookie_preferences';
    
    const CookieConsent = {
        init: function() {
            this.checkConsent();
            this.setupBanner();
            this.setupSettingsModal();
        },

        checkConsent: function() {
            const consent = this.getConsent();
            if (!consent) {
                this.showBanner();
            } else {
                this.applyPreferences(consent);
            }
        },

        getConsent: function() {
            try {
                const stored = localStorage.getItem(COOKIE_CONSENT_KEY);
                return stored ? JSON.parse(stored) : null;
            } catch (e) {
                return null;
            }
        },

        getPreferences: function() {
            try {
                const stored = localStorage.getItem(COOKIE_PREFERENCES_KEY);
                return stored ? JSON.parse(stored) : {
                    essential: true, // Always true, cannot be disabled
                    analytics: false,
                    functional: false
                };
            } catch (e) {
                return {
                    essential: true,
                    analytics: false,
                    functional: false
                };
            }
        },

        saveConsent: function(preferences) {
            const consent = {
                timestamp: new Date().toISOString(),
                preferences: preferences
            };
            localStorage.setItem(COOKIE_CONSENT_KEY, JSON.stringify(consent));
            localStorage.setItem(COOKIE_PREFERENCES_KEY, JSON.stringify(preferences));
            this.applyPreferences(consent);
            this.hideBanner();
        },

        showBanner: function() {
            const banner = document.getElementById('cookie-consent-banner');
            if (banner) {
                banner.classList.add('show');
            }
        },

        hideBanner: function() {
            const banner = document.getElementById('cookie-consent-banner');
            if (banner) {
                banner.classList.remove('show');
            }
        },

        setupBanner: function() {
            const acceptBtn = document.getElementById('cookie-accept');
            const rejectBtn = document.getElementById('cookie-reject');
            const settingsBtn = document.getElementById('cookie-settings');

            if (acceptBtn) {
                acceptBtn.addEventListener('click', () => {
                    this.saveConsent({
                        essential: true,
                        analytics: true,
                        functional: true
                    });
                });
            }

            if (rejectBtn) {
                rejectBtn.addEventListener('click', () => {
                    this.saveConsent({
                        essential: true,
                        analytics: false,
                        functional: false
                    });
                });
            }

            if (settingsBtn) {
                settingsBtn.addEventListener('click', () => {
                    this.showSettings();
                });
            }
        },

        showSettings: function() {
            const modal = document.getElementById('cookie-settings-modal');
            if (modal) {
                const preferences = this.getPreferences();
                
                // Update toggle switches
                const analyticsToggle = document.getElementById('cookie-analytics-toggle');
                const functionalToggle = document.getElementById('cookie-functional-toggle');
                
                if (analyticsToggle) {
                    analyticsToggle.checked = preferences.analytics || false;
                }
                if (functionalToggle) {
                    functionalToggle.checked = preferences.functional || false;
                }
                
                modal.classList.add('show');
            }
        },

        hideSettings: function() {
            const modal = document.getElementById('cookie-settings-modal');
            if (modal) {
                modal.classList.remove('show');
            }
        },

        setupSettingsModal: function() {
            const saveBtn = document.getElementById('cookie-save-settings');
            const cancelBtn = document.getElementById('cookie-cancel-settings');
            const modal = document.getElementById('cookie-settings-modal');

            if (saveBtn) {
                saveBtn.addEventListener('click', () => {
                    const preferences = {
                        essential: true, // Always true
                        analytics: document.getElementById('cookie-analytics-toggle')?.checked || false,
                        functional: document.getElementById('cookie-functional-toggle')?.checked || false
                    };
                    this.saveConsent(preferences);
                    this.hideSettings();
                });
            }

            if (cancelBtn) {
                cancelBtn.addEventListener('click', () => {
                    this.hideSettings();
                });
            }

            // Close modal when clicking outside
            if (modal) {
                modal.addEventListener('click', (e) => {
                    if (e.target === modal) {
                        this.hideSettings();
                    }
                });
            }
        },

        applyPreferences: function(consent) {
            const preferences = consent.preferences || this.getPreferences();
            
            // Essential cookies are always enabled (no check needed)
            
            // Analytics cookies
            if (preferences.analytics) {
                this.enableAnalytics();
            } else {
                this.disableAnalytics();
            }
            
            // Functional cookies
            if (preferences.functional) {
                this.enableFunctional();
            } else {
                this.disableFunctional();
            }
        },

        enableAnalytics: function() {
            // Enable tracking scripts
            if (window._crm && typeof window._crm.trackPageView === 'function') {
                window._crm.trackPageView();
            }
            
            // Allow visitor ID storage
            if (!localStorage.getItem('crm_visitor_id')) {
                const visitorId = 'vis_' + Math.random().toString(36).substr(2, 9) + '_' + Date.now();
                localStorage.setItem('crm_visitor_id', visitorId);
            }
        },

        disableAnalytics: function() {
            // Remove visitor ID
            localStorage.removeItem('crm_visitor_id');
            sessionStorage.removeItem('crm_utm');
            
            // Disable tracking (tracking.js should check consent before tracking)
        },

        enableFunctional: function() {
            // Enable functional features like saved preferences
        },

        disableFunctional: function() {
            // Disable functional features
        },

        hasConsent: function(category) {
            const consent = this.getConsent();
            if (!consent) return false;
            
            const preferences = consent.preferences || {};
            
            if (category === 'essential') {
                return true; // Always allowed
            }
            
            return preferences[category] || false;
        }
    };

    // Expose globally
    window.cookieConsent = CookieConsent;

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            CookieConsent.init();
        });
    } else {
        CookieConsent.init();
    }
})();
