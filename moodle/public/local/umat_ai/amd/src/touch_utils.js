define([], function () {
    'use strict';

    var BREAKPOINTS = {
        xs: 480,
        sm: 640,
        md: 768,
        lg: 960,
        xl: 1200
    };

    function isTouchDevice() {
        return 'ontouchstart' in window ||
            navigator.maxTouchPoints > 0 ||
            window.DocumentTouch && document instanceof DocumentTouch;
    }

    function isMobileView() {
        return window.innerWidth < BREAKPOINTS.md;
    }

    function isSmallMobile() {
        return window.innerWidth < BREAKPOINTS.sm;
    }

    var resizeCallbacks = [];
    var resizeTimer = null;

    function onResize(callback) {
        resizeCallbacks.push(callback);
        if (!resizeTimer) {
            resizeTimer = setInterval(function () {
                // handled by event below
            }, 100000);
        }
    }

    var lastWidth = window.innerWidth;
    window.addEventListener('resize', function () {
        var w = window.innerWidth;
        if (w === lastWidth) return;
        lastWidth = w;
        if (resizeTimer) clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            for (var i = 0; i < resizeCallbacks.length; i++) {
                try { resizeCallbacks[i](w); } catch (e) {}
            }
        }, 150);
    });

    function ensureTouchTarget(el, minSize) {
        minSize = minSize || 44;
        var rect = el.getBoundingClientRect();
        if (rect.width < minSize) {
            el.style.minWidth = minSize + 'px';
        }
        if (rect.height < minSize) {
            el.style.minHeight = minSize + 'px';
        }
    }

    function makeTouchable(el) {
        if (!el) return;
        el.style.cursor = 'pointer';
        el.style.touchAction = 'manipulation';
        el.style.userSelect = 'none';
        el.style.webkitTapHighlightColor = 'transparent';
    }

    function onSwipe(el, callbacks) {
        if (!el) return;
        var x0 = 0, y0 = 0, x1 = 0, y1 = 0;
        var swiping = false;
        var threshold = 50;

        el.addEventListener('touchstart', function (e) {
            var t = e.changedTouches[0];
            x0 = t.clientX;
            y0 = t.clientY;
            swiping = true;
        }, { passive: true });

        el.addEventListener('touchmove', function (e) {
            if (!swiping) return;
            var t = e.changedTouches[0];
            x1 = t.clientX;
            y1 = t.clientY;
        }, { passive: true });

        el.addEventListener('touchend', function (e) {
            if (!swiping) return;
            swiping = false;
            var dx = x1 - x0;
            var dy = y1 - y0;
            if (Math.abs(dx) < threshold && Math.abs(dy) < threshold) return;
            if (Math.abs(dx) > Math.abs(dy)) {
                if (dx > 0 && callbacks.swipeRight) callbacks.swipeRight(e);
                else if (dx < 0 && callbacks.swipeLeft) callbacks.swipeLeft(e);
            } else {
                if (dy > 0 && callbacks.swipeDown) callbacks.swipeDown(e);
                else if (dy < 0 && callbacks.swipeUp) callbacks.swipeUp(e);
            }
        }, { passive: true });
    }

    function addTouchEvents(el, handlers) {
        if (!el) return;
        makeTouchable(el);
        var startX, startY, startTime;
        var longPressTimer = null;

        el.addEventListener('touchstart', function (e) {
            var t = e.changedTouches[0];
            startX = t.clientX;
            startY = t.clientY;
            startTime = Date.now();
            if (handlers.longPress) {
                longPressTimer = setTimeout(function () {
                    handlers.longPress(e);
                }, 500);
            }
            if (handlers.touchStart) handlers.touchStart(e);
        }, { passive: true });

        el.addEventListener('touchmove', function (e) {
            if (longPressTimer) {
                var t = e.changedTouches[0];
                var dx = t.clientX - startX;
                var dy = t.clientY - startY;
                if (Math.abs(dx) > 10 || Math.abs(dy) > 10) {
                    clearTimeout(longPressTimer);
                    longPressTimer = null;
                }
            }
            if (handlers.touchMove) handlers.touchMove(e);
        }, { passive: true });

        el.addEventListener('touchend', function (e) {
            if (longPressTimer) {
                clearTimeout(longPressTimer);
                longPressTimer = null;
            }
            var dt = Date.now() - startTime;
            if (dt < 300 && handlers.tap) handlers.tap(e);
            if (handlers.touchEnd) handlers.touchEnd(e);
        }, { passive: true });
    }

    function replaceInlineOnclick(el) {
        var attr = el.getAttribute('onclick');
        if (!attr) return;
        el.removeAttribute('onclick');
        try {
            var fn = new Function(attr);
            el.addEventListener('click', function (e) { fn.call(el, e); });
            addTouchEvents(el, {
                tap: function (e) { fn.call(el, e); }
            });
        } catch (e) {
            console.warn('[touch_utils] could not parse onclick:', attr);
        }
    }

    function scanAndFixOnclick(container) {
        if (!container) container = document;
        if (typeof container === 'string') container = document.querySelector(container) || document;
        var els = container.querySelectorAll('[onclick]');
        for (var i = 0; i < els.length; i++) {
            replaceInlineOnclick(els[i]);
        }
    }

    return {
        BREAKPOINTS: BREAKPOINTS,
        isTouchDevice: isTouchDevice,
        isMobileView: isMobileView,
        isSmallMobile: isSmallMobile,
        onResize: onResize,
        ensureTouchTarget: ensureTouchTarget,
        makeTouchable: makeTouchable,
        onSwipe: onSwipe,
        addTouchEvents: addTouchEvents,
        replaceInlineOnclick: replaceInlineOnclick,
        scanAndFixOnclick: scanAndFixOnclick
    };
});
