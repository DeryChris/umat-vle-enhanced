// ============================================================
// AMD module: local_umat_ai/mobile_navbar
// Handles floating glass tab bar scroll hide/show on mobile.
// Hides bar on scroll down, shows on scroll up (threshold 30px).
// Works with overlay scrollable containers.
// ============================================================

define([], function() {
    'use strict';

    var lastScrollY = 0;
    var isNavbarHidden = false;
    var scrollTimeout = null;
    var scrollTargets = [];
    var isMobileView = false;

    function checkMobileView() {
        isMobileView = window.innerWidth < 640;
    }

    function findScrollTargets() {
        var targets = [];
        var ovContent = document.querySelector('.umat-ov-content');
        if (ovContent) targets.push(ovContent);
        var tabPane = document.querySelector('.umat-tab-pane.active');
        if (tabPane && tabPane !== ovContent) targets.push(tabPane);
        var cp = document.querySelector('.umat-cp');
        if (cp) targets.push(cp);
        targets.push(window);
        return targets;
    }

    function getScrollY(target) {
        if (target === window || target === document || target === document.documentElement) {
            return window.scrollY || document.documentElement.scrollTop || window.pageYOffset || 0;
        }
        return target.scrollTop || 0;
    }

    function showNavbar() {
        var glassContainer = document.querySelector('.umat-glass-tabs');
        if (glassContainer && isNavbarHidden) {
            isNavbarHidden = false;
            glassContainer.classList.remove('umat-navbar-hidden');
        }
        var content = document.querySelector('.umat-ov-content');
        if (content) content.style.paddingBottom = '80px';
    }

    function hideNavbar() {
        var glassContainer = document.querySelector('.umat-glass-tabs');
        if (glassContainer && !isNavbarHidden) {
            isNavbarHidden = true;
            glassContainer.classList.add('umat-navbar-hidden');
        }
        var content = document.querySelector('.umat-ov-content');
        if (content) content.style.paddingBottom = '16px';
    }

    function handleScroll(e) {
        if (!isMobileView) return;

        var currentScrollY = 0;
        for (var i = 0; i < scrollTargets.length; i++) {
            var sy = getScrollY(scrollTargets[i]);
            if (sy > currentScrollY) currentScrollY = sy;
        }

        if (scrollTimeout) clearTimeout(scrollTimeout);

        if (isNavbarHidden && currentScrollY < lastScrollY) {
            showNavbar();
        } else if (!isNavbarHidden && currentScrollY > lastScrollY + 30) {
            hideNavbar();
        }

        lastScrollY = currentScrollY;

        scrollTimeout = setTimeout(function() {
            if (isNavbarHidden) showNavbar();
        }, 600);
    }

    function handleTabClick() {
        if (scrollTimeout) clearTimeout(scrollTimeout);
        lastScrollY = 0;
        showNavbar();
    }

    function handleResize() {
        checkMobileView();
        if (!isMobileView && isNavbarHidden) showNavbar();
        if (isMobileView) {
            scrollTargets = findScrollTargets();
        }
    }

    function init() {
        checkMobileView();
        if (!isMobileView) return;

        scrollTargets = findScrollTargets();

        var glassContainer = document.querySelector('.umat-glass-tabs');
        if (glassContainer) {
            var tabs = glassContainer.querySelectorAll('.umat-glass-tab');
            for (var i = 0; i < tabs.length; i++) {
                tabs[i].addEventListener('click', handleTabClick);
            }
        }

        for (var j = 0; j < scrollTargets.length; j++) {
            scrollTargets[j].addEventListener('scroll', handleScroll, { passive: true });
        }

        window.addEventListener('resize', handleResize);

        // Re-scan scroll targets when overlay opens
        var ov = document.querySelector('.umat-ov');
        if (ov) {
            var ovObserver = new MutationObserver(function() {
                if (ov.classList.contains('open')) {
                    setTimeout(function() {
                        scrollTargets = findScrollTargets();
                        for (var k = 0; k < scrollTargets.length; k++) {
                            scrollTargets[k].removeEventListener('scroll', handleScroll);
                            scrollTargets[k].addEventListener('scroll', handleScroll, { passive: true });
                        }
                    }, 100);
                }
            });
            ovObserver.observe(ov, { attributes: true, attributeFilter: ['class'] });
        }
    }

    return {
        init: init,
        show: showNavbar,
        hide: hideNavbar,
        checkMobile: checkMobileView
    };
});
