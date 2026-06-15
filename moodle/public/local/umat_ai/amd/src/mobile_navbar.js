// ============================================================
// AMD module: local_umat_ai/mobile_navbar
// Handles mobile navbar scroll hide/show
// Features:
//   - Hides navbar on scroll down, shows on scroll up (mobile only)
//   - Works with overlay content areas and scrollable containers
//   - Auto-shows after inactivity
// ============================================================

define([], function() {
    'use strict';

    var lastScrollY = 0;
    var isNavbarHidden = false;
    var scrollTimeout = null;
    var scrollContainer = null;
    var isMobileView = false;

    // Detect if we're in mobile view (width < 640px)
    function checkMobileView() {
        isMobileView = window.innerWidth < 640;
    }

    // Find the main scrollable container
    function findScrollContainer() {
        // Try to find overlay content first
        var overlayContent = document.querySelector('.umat-ov-content');
        if (overlayContent && overlayContent.scrollHeight > overlayContent.clientHeight) {
            return overlayContent;
        }
        // Try other scrollable containers
        var containers = [
            document.querySelector('[role="main"]'),
            document.querySelector('main'),
            document.querySelector('.moodle-has-dir-ltr'),
            document.documentElement
        ];
        for (var i = 0; i < containers.length; i++) {
            if (containers[i] && containers[i].scrollHeight > containers[i].clientHeight) {
                return containers[i];
            }
        }
        return document.documentElement;
    }

    // Handle scroll events - hide on down, show on up
    function handleScroll(e) {
        if (!isMobileView) return; // Only on mobile

        // Get current scroll position
        var currentScrollY = 0;
        if (scrollContainer && scrollContainer !== document.documentElement && scrollContainer !== window) {
            currentScrollY = scrollContainer.scrollTop;
        } else {
            currentScrollY = window.scrollY || document.documentElement.scrollTop || window.pageYOffset || 0;
        }

        // Clear existing timeout
        if (scrollTimeout) {
            clearTimeout(scrollTimeout);
        }

        // Show navbar on scroll up
        if (isNavbarHidden && currentScrollY < lastScrollY) {
            showNavbar();
        }
        // Hide navbar on scroll down (with 30px threshold to avoid jitter)
        else if (!isNavbarHidden && currentScrollY > lastScrollY + 30) {
            hideNavbar();
        }

        lastScrollY = currentScrollY;

        // Auto-show navbar after scroll stops (0.5s of no scrolling)
        scrollTimeout = setTimeout(function() {
            if (isNavbarHidden) {
                showNavbar();
            }
        }, 500);
    }

    // Show the navbar (sliding animation)
    function showNavbar() {
        var glassContainer = document.querySelector('.umat-glass-tabs');
        if (glassContainer && isNavbarHidden) {
            isNavbarHidden = false;
            glassContainer.classList.remove('umat-navbar-hidden');
        }
    }

    // Hide the navbar (sliding animation)
    function hideNavbar() {
        var glassContainer = document.querySelector('.umat-glass-tabs');
        if (glassContainer && !isNavbarHidden) {
            isNavbarHidden = true;
            glassContainer.classList.add('umat-navbar-hidden');
        }
    }

    // Handle tab clicks - show navbar after interaction
    function handleTabClick(e) {
        if (scrollTimeout) {
            clearTimeout(scrollTimeout);
        }
        lastScrollY = 0; // Reset scroll position to force navbar show
        showNavbar();
    }

    // Handle window resize
    function handleResize() {
        checkMobileView();
        // If switching from mobile to desktop or vice versa
        if (!isMobileView && isNavbarHidden) {
            showNavbar(); // Show if switching to desktop
        }
    }

    // Initialize the module
    function init() {
        checkMobileView();
        
        if (!isMobileView) {
            console.log('UMaT mobile navbar: desktop view detected, module inactive');
            return;
        }

        // Get the main scrollable container
        scrollContainer = findScrollContainer();

        // Add click handlers to glass tabs
        var glassContainer = document.querySelector('.umat-glass-tabs');
        if (glassContainer) {
            var tabs = glassContainer.querySelectorAll('.umat-glass-tab');
            for (var i = 0; i < tabs.length; i++) {
                tabs[i].addEventListener('click', handleTabClick);
            }
        }

        // Add scroll listeners
        // Listen on window scroll
        window.addEventListener('scroll', handleScroll, { passive: true });

        // Listen on specific scrollable container if not window
        if (scrollContainer && scrollContainer !== document.documentElement) {
            scrollContainer.addEventListener('scroll', handleScroll, { passive: true });
        }

        // Handle document scroll
        document.addEventListener('scroll', handleScroll, { passive: true, capture: true });

        // Update mobile view check on resize
        window.addEventListener('resize', handleResize);

        console.log('UMaT mobile navbar initialized - scroll animations enabled');
    }

    return {
        init: init,
        show: showNavbar,
        hide: hideNavbar,
        checkMobile: checkMobileView
    };
});

