/**
 * CRM Website Tracking Script
 * 
 * Captures visitor behavior, UTM parameters, and form submissions
 */

(function() {
    'use strict';
    
    var _crm = window._crm || {};
    
    // Visitor identification (only if analytics consent given)
    // Check will be done when consent is available
    _crm.visitorId = null;
    
    // Initialize visitor ID only if consent is given
    function initVisitorId() {
        if (window.cookieConsent && window.cookieConsent.hasConsent('analytics')) {
            _crm.visitorId = localStorage.getItem('crm_visitor_id');
            if (!_crm.visitorId) {
                _crm.visitorId = 'vis_' + Math.random().toString(36).substr(2, 9) + '_' + Date.now();
                localStorage.setItem('crm_visitor_id', _crm.visitorId);
            }
        }
    }
    
    // Try to initialize immediately, or wait for consent
    if (window.cookieConsent) {
        initVisitorId();
    } else {
        // Wait for cookie consent to be initialized
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(initVisitorId, 100);
        });
    }
    
    // Capture UTM parameters
    _crm.utm = {};
    _crm.campaignId = null;
    var urlParams = new URLSearchParams(window.location.search);
    ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'].forEach(function(param) {
        var value = urlParams.get(param);
        if (value) {
            _crm.utm[param] = value;
        }
    });
    var directCampaignId = urlParams.get('campaign_id');
    if (directCampaignId) {
        _crm.campaignId = directCampaignId;
    }
    
    // Store UTM parameters in sessionStorage for form submissions (only if analytics consent)
    function initUtm() {
        if (window.cookieConsent && !window.cookieConsent.hasConsent('analytics')) {
            return; // Don't store UTM if no consent
        }
        
        if (Object.keys(_crm.utm).length > 0) {
            sessionStorage.setItem('crm_utm', JSON.stringify(_crm.utm));
        } else {
            // Try to get from sessionStorage if not in URL
            var storedUtm = sessionStorage.getItem('crm_utm');
            if (storedUtm) {
                try {
                    _crm.utm = JSON.parse(storedUtm);
                } catch (e) {
                    _crm.utm = {};
                }
            }
        }
    }
    
    // Initialize UTM storage when consent is available
    if (window.cookieConsent) {
        initUtm();
    } else {
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(initUtm, 100);
        });
    }
    
    /**
     * Track page view
     */
    _crm.trackPageView = function() {
        // Check cookie consent before tracking
        if (window.cookieConsent && !window.cookieConsent.hasConsent('analytics')) {
            return; // User has not consented to analytics
        }
        
        var data = {
            visitor_id: _crm.visitorId,
            page: window.location.pathname,
            referrer: document.referrer || '',
            utm: _crm.utm,
            campaign_id: _crm.campaignId,
            timestamp: new Date().toISOString()
        };
        
        // Send via Beacon API for reliability
        if (navigator.sendBeacon) {
            navigator.sendBeacon('/api/track/pageview', JSON.stringify(data));
        } else {
            // Fallback to fetch
            fetch('/api/track/pageview', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data),
                keepalive: true
            }).catch(function(err) {
                console.error('Tracking error:', err);
            });
        }
    };
    
    /**
     * Track form submission
     */
    _crm.trackForm = function(formId, callback) {
        // Check cookie consent before tracking
        if (window.cookieConsent && !window.cookieConsent.hasConsent('analytics')) {
            if (callback) callback();
            return; // User has not consented to analytics
        }
        var form = document.getElementById(formId);
        if (!form) {
            form = document.querySelector('form[data-crm-track="' + formId + '"]');
        }
        
        if (form) {
            form.addEventListener('submit', function(e) {
                var formData = new FormData(form);
                var formObject = {};
                formData.forEach(function(value, key) {
                    formObject[key] = value;
                });
                
                var data = {
                    visitor_id: _crm.visitorId,
                    form_id: formId,
                    form_data: formObject,
                    page: window.location.pathname,
                    utm: _crm.utm,
                    campaign_id: _crm.campaignId,
                    timestamp: new Date().toISOString()
                };
                
                // Send tracking data
                if (navigator.sendBeacon) {
                    navigator.sendBeacon('/api/track/formsubmit', JSON.stringify(data));
                } else {
                    fetch('/api/track/formsubmit', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(data),
                        keepalive: true
                    }).catch(function(err) {
                        console.error('Form tracking error:', err);
                    });
                }
                
                if (callback) {
                    callback();
                }
            });
        }
    };
    
    /**
     * Track custom event
     */
    _crm.trackEvent = function(eventName, eventData) {
        // Check cookie consent before tracking
        if (window.cookieConsent && !window.cookieConsent.hasConsent('analytics')) {
            return; // User has not consented to analytics
        }
        var data = {
            visitor_id: _crm.visitorId,
            event_name: eventName,
            event_data: eventData || {},
            page: window.location.pathname,
            timestamp: new Date().toISOString()
        };
        
        if (navigator.sendBeacon) {
            navigator.sendBeacon('/api/track/event', JSON.stringify(data));
        } else {
            fetch('/api/track/event', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data),
                keepalive: true
            }).catch(function(err) {
                console.error('Event tracking error:', err);
            });
        }
    };
    
    /**
     * Initialize tracking
     */
    function init() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
            return;
        }
        
        // Track page view
        _crm.trackPageView();
        
        // Track all forms with data-crm-track attribute
        document.querySelectorAll('form[data-crm-track]').forEach(function(form) {
            var formId = form.getAttribute('data-crm-track') || form.id || 'form_' + Date.now();
            if (!form.id) {
                form.id = formId;
            }
            _crm.trackForm(formId);
        });
        
        // Track forms with specific IDs
        document.querySelectorAll('form[id]').forEach(function(form) {
            if (form.getAttribute('data-crm-track') !== 'false') {
                _crm.trackForm(form.id);
            }
        });
    }
    
    // Start initialization
    init();
    
    // Expose to global scope
    window._crm = _crm;
})();
