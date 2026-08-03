// AMD module for UMaT mobile floating tab bar
// Toggles active class on tabs; no position calculations needed.

define([], function() {
    'use strict';

    var Magnifier = function(container) {
        this.container = container;
        this.tabs = container.querySelectorAll('.umat-glass-tab');
        if (!this.tabs.length) return;
        this._bindEvents();
    };

    Magnifier.prototype.refresh = function() {
        // no-op for compatibility — active class CSS handles visuals
    };

    // Event handlers.
    Magnifier.prototype._bindEvents = function() {
        var self = this;
        Array.prototype.forEach.call(this.tabs, function(tab) {
            tab.addEventListener('pointerdown', function() {
                tab.classList.add('is-pressing');
            });
            tab.addEventListener('pointerup', function() { tab.classList.remove('is-pressing'); });
            tab.addEventListener('pointercancel', function() { tab.classList.remove('is-pressing'); });
            tab.addEventListener('pointerleave', function() { tab.classList.remove('is-pressing'); });
            tab.addEventListener('click', function() {
                if (window.CustomEvent) {
                    try {
                        tab.dispatchEvent(new CustomEvent('umat-glass-tab-changed', {
                            detail: { tab: tab }
                        }));
                    } catch(e) {}
                }
            });
            if (window.MutationObserver) {
                new MutationObserver(function() { /* active class CSS handles it */ })
                    .observe(tab, { attributes: true, attributeFilter: ['class'] });
            }
        });
    };

    return {
        init: function() {
            var containers = document.querySelectorAll('.umat-glass-tabs');
            Array.prototype.forEach.call(containers, function(c) {
                new Magnifier(c);
            });
        }
    };
});
