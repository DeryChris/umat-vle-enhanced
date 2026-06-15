// AMD module for UMaT glassmorphism overlay tab bars
// Creates spotlight magnifier that follows active tab
define([], function() {
    'use strict';

    var Magnifier = function(container) {
        this.container = container;
        this.spot = container.querySelector('[data-glass]');
        this.fakeBoxes = container.querySelectorAll('[data-glass-item]');
        this.tabs = container.querySelectorAll('.umat-glass-tab');
        if (!this.spot || !this.fakeBoxes.length) return;
        this.spotRadius = this.spot.offsetWidth / 2;
        this._bindEvents();
        this._onReady();
    };

    // ── Calculate tab positions ──
    Magnifier.prototype._calc = function() {
        var any = false;
        for (var i = 0; i < this.fakeBoxes.length; i++) {
            var box = this.fakeBoxes[i];
            var idx = box.getAttribute('data-glass-item');
            var center = box.offsetLeft + box.offsetWidth / 2;
            this.container.style.setProperty('--gpos' + idx, (center - this.spotRadius) + 'px');
            if (box.offsetLeft > 0) any = true;
        }
        return any;
    };

    // ── Find index of active tab (1-based) ──
    Magnifier.prototype._activeIdx = function() {
        for (var i = 0; i < this.tabs.length; i++) {
            if (this.tabs[i].classList.contains('active')) {
                return i + 1;
            }
        }
        return 0;
    };

    // ── Apply position class ──
    Magnifier.prototype._activate = function(idx) {
        if (!idx) return;
        for (var p = 1; p <= this.fakeBoxes.length; p++) {
            this.container.classList.remove('umat-glass-tabs--on-' + p);
        }
        this.container.classList.add('umat-glass-tabs--on-' + idx);
    };

    // ── Recalculate positions and activate ──
    Magnifier.prototype.refresh = function() {
        if (this._calc()) {
            this._activate(this._activeIdx());
        }
    };

    // ── Initialize after overlay becomes visible ──
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

    // ── Event handlers ──
    Magnifier.prototype._bindEvents = function() {
        var self = this;
        // Tab click — active class already toggled by inline JS in target phase
        this.container.addEventListener('click', function(e) {
            if (e.target.closest('.umat-glass-tab')) {
                self.refresh();
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
