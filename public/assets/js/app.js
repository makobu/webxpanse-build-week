/**
 * Core Application JavaScript
 * Handles animations, form interactions, and common UI behaviors
 */

(function() {
    'use strict';
    
    /**
     * Initialize fade-in animations on scroll
     */
    function initScrollAnimations() {
        const elements = document.querySelectorAll('.fade-in');
        
        if (!elements.length) return;
        
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('active');
                }
            });
        }, {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        });
        
        elements.forEach(element => {
            observer.observe(element);
        });
    }
    
    /**
     * Initialize form validation
     */
    function initFormValidation() {
        const forms = document.querySelectorAll('form[data-validate]');
        
        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
                if (!form.checkValidity()) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                form.classList.add('was-validated');
            });
        });
    }
    
    /**
     * Initialize CSRF token injection
     */
    function initCSRFTokens() {
        const forms = document.querySelectorAll('form');
        const csrfToken = document.querySelector('meta[name="csrf-token"]');
        
        if (!csrfToken) return;
        
        const token = csrfToken.getAttribute('content');
        
        forms.forEach(form => {
            // GET forms are read-only navigation. Adding a CSRF field would expose
            // the session token in filter/search URLs, browser history, and logs.
            if (form.method.toLowerCase() !== 'post') return;

            if (!form.querySelector('input[name="csrf_token"]')) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'csrf_token';
                input.value = token;
                form.appendChild(input);
            }
        });
    }
    
    /**
     * Initialize modals
     */
    function initModals() {
        const modalTriggers = document.querySelectorAll('[data-modal]');
        const modalOverlays = document.querySelectorAll('.modal-overlay');
        
        modalTriggers.forEach(trigger => {
            trigger.addEventListener('click', function(e) {
                e.preventDefault();
                const modalId = this.getAttribute('data-modal');
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.style.display = 'flex';
                }
            });
        });
        
        modalOverlays.forEach(overlay => {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.style.display = 'none';
                }
            });
            
            const closeBtn = overlay.querySelector('.modal-close');
            if (closeBtn) {
                closeBtn.addEventListener('click', function() {
                    overlay.style.display = 'none';
                });
            }
        });
    }
    
    /**
     * Initialize tooltips and dropdowns
     */
    function initUIComponents() {
        // Simple dropdown toggle
        const dropdowns = document.querySelectorAll('.dropdown-toggle');
        dropdowns.forEach(toggle => {
            toggle.addEventListener('click', function(e) {
                e.preventDefault();
                const menu = this.nextElementSibling;
                if (menu && menu.classList.contains('dropdown-menu')) {
                    menu.classList.toggle('show');
                }
            });
        });
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.dropdown')) {
                document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
                    menu.classList.remove('show');
                });
            }
        });
    }
    
    /**
     * Initialize everything when DOM is ready
     */
    function init() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
            return;
        }
        
        initScrollAnimations();
        initFormValidation();
        initCSRFTokens();
        initModals();
        initUIComponents();
    }
    
    // Start initialization
    init();
    
    // Export for global access if needed
    window.CRM = {
        initScrollAnimations,
        initFormValidation,
        initCSRFTokens,
        initModals
    };
})();
