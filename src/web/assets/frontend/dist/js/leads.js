/**
 * Leads — Vanilla JS Popup Engine
 * Reads window._leadsConfig JSON array and manages popup display.
 */
(function () {
    'use strict';

    var COOKIE_PREFIX = 'leads_seen_';
    var SUBMIT_URL = '/leads/submit';
    var TRACK_URL = '/leads/track';

    function init() {
        var config = window._leadsConfig;
        if (!config || !config.length) return;

        config.forEach(function (popup) {
            setupPopup(popup);
        });
    }

    function setupPopup(config) {
        // Check frequency cookie
        if (getCookie(COOKIE_PREFIX + config.id)) return;

        // Inject custom CSS if provided
        if (config.customCss) {
            var style = document.createElement('style');
            style.textContent = config.customCss;
            document.head.appendChild(style);
        }

        // Create container
        var container = document.createElement('div');
        container.innerHTML = config.html;
        var popup = container.firstElementChild;

        if (!popup) return;

        // Set position data attribute
        if (config.position) {
            popup.setAttribute('data-leads-position', config.position);
        }

        // Attach form handler
        var form = popup.querySelector('[data-leads-form]');
        if (form) {
            // Add honeypot field
            var hp = document.createElement('input');
            hp.type = 'text';
            hp.name = 'leads_hp';
            hp.className = 'leads-hp';
            hp.tabIndex = -1;
            hp.autocomplete = 'off';
            form.appendChild(hp);

            // Add page URL
            var pageInput = document.createElement('input');
            pageInput.type = 'hidden';
            pageInput.name = 'pageUrl';
            pageInput.value = window.location.href;
            form.appendChild(pageInput);

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                handleSubmit(form, popup, config);
            });
        }

        // Attach close handler
        var closeButtons = popup.querySelectorAll('[data-leads-close]');
        closeButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                hidePopup(popup, config);
                trackEvent(config.id, 'close');
            });
        });

        // Set up trigger
        switch (config.trigger) {
            case 'time':
                var delay = (parseInt(config.triggerValue, 10) || 3) * 1000;
                setTimeout(function () { showPopup(popup, config); }, delay);
                break;

            case 'scroll':
                var scrollPercent = parseInt(config.triggerValue, 10) || 50;
                var scrollTriggered = false;
                window.addEventListener('scroll', function () {
                    if (scrollTriggered) return;
                    var scrolled = (window.scrollY / (document.body.scrollHeight - window.innerHeight)) * 100;
                    if (scrolled >= scrollPercent) {
                        scrollTriggered = true;
                        showPopup(popup, config);
                    }
                });
                break;

            case 'exit':
                var exitTriggered = false;
                document.addEventListener('mouseleave', function (e) {
                    if (exitTriggered) return;
                    if (e.clientY <= 0) {
                        exitTriggered = true;
                        showPopup(popup, config);
                    }
                });
                break;

            case 'click':
                var selector = config.triggerValue;
                if (selector) {
                    var triggers = document.querySelectorAll(selector);
                    triggers.forEach(function (trigger) {
                        trigger.addEventListener('click', function (e) {
                            e.preventDefault();
                            showPopup(popup, config);
                        });
                    });
                }
                break;
        }
    }

    function showPopup(popup, config) {
        // Don't show if already dismissed
        if (getCookie(COOKIE_PREFIX + config.id)) return;

        document.body.appendChild(popup);

        // Add overlay for modals
        var overlay = null;
        if (config.type === 'modal') {
            overlay = document.createElement('div');
            overlay.className = 'leads-overlay';
            document.body.appendChild(overlay);
            overlay.addEventListener('click', function () {
                hidePopup(popup, config);
                trackEvent(config.id, 'close');
            });
            // Trigger animation
            requestAnimationFrame(function () {
                overlay.classList.add('leads-visible');
            });
        }

        popup._leadsOverlay = overlay;

        // Trigger animation
        requestAnimationFrame(function () {
            popup.classList.add('leads-visible');
        });

        // Track impression
        trackEvent(config.id, 'impression');

        // Close on Escape
        popup._leadsEscHandler = function (e) {
            if (e.key === 'Escape') {
                hidePopup(popup, config);
                trackEvent(config.id, 'close');
            }
        };
        document.addEventListener('keydown', popup._leadsEscHandler);
    }

    function hidePopup(popup, config) {
        popup.classList.remove('leads-visible');

        if (popup._leadsOverlay) {
            popup._leadsOverlay.classList.remove('leads-visible');
            setTimeout(function () {
                if (popup._leadsOverlay && popup._leadsOverlay.parentNode) {
                    popup._leadsOverlay.parentNode.removeChild(popup._leadsOverlay);
                }
            }, 300);
        }

        if (popup._leadsEscHandler) {
            document.removeEventListener('keydown', popup._leadsEscHandler);
        }

        // Set cookie to prevent re-showing (24 hours)
        setCookie(COOKIE_PREFIX + config.id, '1', 1);

        setTimeout(function () {
            if (popup.parentNode) {
                popup.parentNode.removeChild(popup);
            }
        }, 400);
    }

    function handleSubmit(form, popup, config) {
        var btn = form.querySelector('.leads-btn');
        if (btn) {
            btn.classList.add('leads-loading');
            btn.disabled = true;
        }

        var formData = new FormData(form);
        var data = {};
        formData.forEach(function (value, key) {
            data[key] = value;
        });

        fetch(SUBMIT_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify(data),
        })
        .then(function (response) { return response.json(); })
        .then(function (result) {
            if (result.success) {
                // Show success message
                form.style.display = 'none';
                var success = popup.querySelector('.leads-success');
                if (success) {
                    success.style.display = 'block';
                }

                // Set cookie to prevent re-showing
                setCookie(COOKIE_PREFIX + config.id, '1', 30);

                // Auto-close after 3 seconds
                setTimeout(function () {
                    hidePopup(popup, config);
                }, 3000);
            } else {
                if (btn) {
                    btn.classList.remove('leads-loading');
                    btn.disabled = false;
                }
            }
        })
        .catch(function () {
            if (btn) {
                btn.classList.remove('leads-loading');
                btn.disabled = false;
            }
        });
    }

    function trackEvent(popupId, type) {
        var data = { popupId: popupId, type: type };

        if (navigator.sendBeacon) {
            navigator.sendBeacon(TRACK_URL, JSON.stringify(data));
        } else {
            fetch(TRACK_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
                keepalive: true,
            });
        }
    }

    function setCookie(name, value, days) {
        var expires = '';
        if (days) {
            var date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = '; expires=' + date.toUTCString();
        }
        document.cookie = name + '=' + encodeURIComponent(value) + expires + '; path=/; SameSite=Lax';
    }

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? decodeURIComponent(match[2]) : null;
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
