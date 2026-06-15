// AMD module for UMaT glassmorphism overlay tab bars
// Creates spotlight magnifier that follows active tab
define([], function() {
    'use strict';

    var Magnifier = function(container) {
        this.container = container;
        this.spot = container.querySelector('[data-glass]');
        this.fakeBoxes = container.querySelectorAll('[data-glass-item]');
        this.tabs = container.querySelectorAll('.umat-glass-tab');
        if (!this.spot || !this.tabs.length) return;
        this.spotRadius = 26;
        this._bindEvents();
        this._onReady();
    };

    // Calculate tab positions.
    Magnifier.prototype._calc = function() {
        var any = false;
        var base = this.container.getBoundingClientRect();
        this.spotRadius = this.spot.offsetWidth ? this.spot.offsetWidth / 2 : 26;
        for (var i = 0; i < this.tabs.length; i++) {
            var tab = this.tabs[i];
            var idx = i + 1;
            var rect = tab.getBoundingClientRect();
            var center = rect.left - base.left + rect.width / 2;
            this.container.style.setProperty('--gpos' + idx, (center - this.spotRadius) + 'px');
            if (rect.width > 0) any = true;
        }
        return any;
    };

    // Find index of active tab (1-based).
    Magnifier.prototype._activeIdx = function() {
        for (var i = 0; i < this.tabs.length; i++) {
            if (this.tabs[i].classList.contains('active')) {
                return i + 1;
            }
        }
        return 0;
    };

    // Apply position class.
    Magnifier.prototype._activate = function(idx) {
        if (!idx) return;
        for (var p = 1; p <= this.tabs.length; p++) {
            this.container.classList.remove('umat-glass-tabs--on-' + p);
        }
        this.container.classList.add('umat-glass-tabs--on-' + idx);
    };

    // Recalculate positions and activate.
    Magnifier.prototype.refresh = function() {
        if (this._calc()) {
            this._activate(this._activeIdx());
        }
    };

    // Initialize after overlay becomes visible.
    Magnifier.prototype._onReady = function() {
        var ov = this.container.closest('.umat-ov, .umat-cp-ov');
        if (!ov) { this.refresh(); return; }

        var self = this;
        if (ov.classList.contains('open')) {
            // Force layout reflow before reading offsetLeft
            void ov.offsetHeight;
            this.refresh();
            return;
        }

        // Poll until the overlay is visible (handles dynamic open())
        var tries = 0;
        function poll() {
            if (!ov.classList.contains('open')) {
                if (++tries < 50) { setTimeout(poll, 60); }
                return;
            }
            void ov.offsetHeight;          // force reflow
            self.refresh();
        }
        poll();
    };

    // Event handlers.
    Magnifier.prototype._bindEvents = function() {
        var self = this;
        Array.prototype.forEach.call(this.tabs, function(tab, index) {
            tab.addEventListener('pointerdown', function() {
                self._calc();
                self._activate(index + 1);
                tab.classList.add('is-pressing');
            });
            tab.addEventListener('pointerup', function() { tab.classList.remove('is-pressing'); });
            tab.addEventListener('pointercancel', function() { tab.classList.remove('is-pressing'); });
            tab.addEventListener('pointerleave', function() { tab.classList.remove('is-pressing'); });
            tab.addEventListener('click', function() {
                // Remove active class from all tabs
                for (var j = 0; j < self.tabs.length; j++) {
                    self.tabs[j].classList.remove('active');
                }
                // Add active class to clicked tab
                tab.classList.add('active');
                
                // Update visual position
                self._calc();
                self._activate(index + 1);
                setTimeout(function() { self.refresh(); }, 0);
                
                // Trigger custom event for other listeners
                if (window.CustomEvent) {
                    try {
                        tab.dispatchEvent(new CustomEvent('umat-glass-tab-changed', {
                            detail: { tab: tab, index: index }
                        }));
                    } catch(e) {}
                }
            });
            if (window.MutationObserver) {
                new MutationObserver(function() { self.refresh(); })
                    .observe(tab, { attributes: true, attributeFilter: ['class'] });
            }
        });
        window.addEventListener('resize', function() {
            self.refresh();
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
