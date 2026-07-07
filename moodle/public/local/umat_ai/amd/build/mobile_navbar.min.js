define([], function() {
    'use strict';

    var lastScrollY = 0;
    var isNavbarHidden = false;
    var scrollTimeout = null;
    var scrollTargets = [];
    var isMobileView = false;
    var listenersAttached = false;

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
        var chips = document.getElementById('ws-chips');
        if (chips) chips.classList.remove('umat-chips-hidden');
    }

    function hideNavbar() {
        var glassContainer = document.querySelector('.umat-glass-tabs');
        if (glassContainer && !isNavbarHidden) {
            isNavbarHidden = true;
            glassContainer.classList.add('umat-navbar-hidden');
        }
        var content = document.querySelector('.umat-ov-content');
        if (content) content.style.paddingBottom = '16px';
        var chips = document.getElementById('ws-chips');
        if (chips) chips.classList.add('umat-chips-hidden');
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
        } else if (!isNavbarHidden && currentScrollY > lastScrollY + 15) {
            hideNavbar();
        }

        lastScrollY = currentScrollY;

        scrollTimeout = setTimeout(function() {
            if (isNavbarHidden) showNavbar();
        }, 600);
    }

    function attachScrollListeners() {
        if (listenersAttached) detachScrollListeners();
        scrollTargets = findScrollTargets();
        for (var j = 0; j < scrollTargets.length; j++) {
            scrollTargets[j].addEventListener('scroll', handleScroll, { passive: true });
        }
        listenersAttached = true;
    }

    function detachScrollListeners() {
        for (var j = 0; j < scrollTargets.length; j++) {
            scrollTargets[j].removeEventListener('scroll', handleScroll);
        }
        scrollTargets = [];
        listenersAttached = false;
    }

    function handleTabClick() {
        if (scrollTimeout) clearTimeout(scrollTimeout);
        lastScrollY = 0;
        showNavbar();
    }

    function handleResize() {
        var wasMobile = isMobileView;
        checkMobileView();
        if (isMobileView && !wasMobile) {
            attachScrollListeners();
        } else if (!isMobileView && wasMobile) {
            if (isNavbarHidden) showNavbar();
            detachScrollListeners();
        } else if (isMobileView) {
            attachScrollListeners();
        }
    }

    function init() {
        checkMobileView();
        window.addEventListener('resize', handleResize);

        if (!isMobileView) return;

        attachScrollListeners();

        var glassContainer = document.querySelector('.umat-glass-tabs');
        if (glassContainer) {
            var tabs = glassContainer.querySelectorAll('.umat-glass-tab');
            for (var i = 0; i < tabs.length; i++) {
                tabs[i].addEventListener('click', handleTabClick);
            }
        }

        var ov = document.querySelector('.umat-ov');
        if (ov) {
            var ovObserver = new MutationObserver(function() {
                if (ov.classList.contains('open')) {
                    setTimeout(function() {
                        if (isMobileView) attachScrollListeners();
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
