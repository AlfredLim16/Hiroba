// Hiroba Master Client Script
// Zero-build vanilla JavaScript for UI interactions, theme switching, and notifications

document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  // 1. Theme Switcher and Persistence
  initThemeToggle();

  // 2. Flash Toast System
  initFlashToasts();

  // 3. Skeleton Loading Content Transitions
  initSkeletonTransitions();

  // 4. Smooth Horizontal Scrolling for Categories and Filter Tabs
  initHorizontalScroll();
});

// Reads the current theme and sets up all theme toggle buttons
function initThemeToggle() {
  const toggleButtons = document.querySelectorAll('.btn-theme-toggle');
  if (toggleButtons.length === 0) {
    return;
  }

  toggleButtons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
      const nextTheme = currentTheme === 'dark' ? 'light' : 'dark';

      document.documentElement.setAttribute('data-theme', nextTheme);
      document.documentElement.setAttribute('data-bs-theme', nextTheme);
      document.cookie = 'hiroba_theme=' + nextTheme + '; path=/; max-age=31536000; SameSite=Lax';

      // Synchronize icons across all toggle buttons in DOM
      toggleButtons.forEach(function (b) {
        const lightIcon = b.querySelector('.theme-icon--light');
        const darkIcon = b.querySelector('.theme-icon--dark');
        if (lightIcon && darkIcon) {
          if (nextTheme === 'dark') {
            lightIcon.style.display = 'inline-block';
            darkIcon.style.display = 'none';
          } else {
            lightIcon.style.display = 'none';
            darkIcon.style.display = 'inline-block';
          }
        }
      });
    });
  });
}

// Auto-dismisses flash toasts after 4000ms with a countdown progress bar
function initFlashToasts() {
  const toasts = document.querySelectorAll('.hiroba-toast');
  toasts.forEach(function (toast) {
    const bar = toast.querySelector('.hiroba-toast__bar');
    const closeBtn = toast.querySelector('.hiroba-toast__close');

    if (bar) {
      // Trigger CSS width transition from 100% to 0%
      requestAnimationFrame(function () {
        bar.style.width = '0%';
      });
    }

    const timer = setTimeout(function () {
      dismissToast(toast);
    }, 4000);

    if (closeBtn) {
      closeBtn.addEventListener('click', function () {
        clearTimeout(timer);
        dismissToast(toast);
      });
    }
  });
}

// Fades out and removes a toast element from the DOM
function dismissToast(toast) {
  toast.style.opacity = '0';
  toast.style.transform = 'translateY(-8px)';
  setTimeout(function () {
    if (toast.parentNode) {
      toast.parentNode.removeChild(toast);
    }
  }, 200);
}

// Crossfades skeleton placeholder containers into real content
function initSkeletonTransitions() {
  const skeletonContainers = document.querySelectorAll('[data-skeleton-container]');
  skeletonContainers.forEach(function (container) {
    const realContent = container.querySelector('[data-real-content]');
    const skeletonPlaceholder = container.querySelector('[data-skeleton-placeholder]');

    if (realContent && skeletonPlaceholder) {
      skeletonPlaceholder.classList.add('skeleton-fade-out');
      setTimeout(function () {
        skeletonPlaceholder.style.display = 'none';
        realContent.style.display = 'block';
        realContent.classList.add('content-fade-in');
        container.removeAttribute('aria-busy');
      }, 200);
    }
  });
}

// Translates mouse wheel actions to horizontal scroll and provides drag-to-scroll
function initHorizontalScroll() {
  const rows = document.querySelectorAll('.nav-pill-row');
  rows.forEach(function (row) {
    // 1. Mouse wheel translation (vertical wheel scrolls horizontally)
    row.addEventListener('wheel', function (e) {
      if (Math.abs(e.deltaY) > Math.abs(e.deltaX)) {
        e.preventDefault();
        row.scrollLeft += e.deltaY;
      }
    }, { passive: false });

    // 2. Click and drag interaction
    let isDown = false;
    let startX = 0;
    let scrollLeft = 0;

    row.addEventListener('mousedown', function (e) {
      isDown = true;
      startX = e.pageX - row.offsetLeft;
      scrollLeft = row.scrollLeft;
    });

    row.addEventListener('mouseleave', function () {
      isDown = false;
    });

    row.addEventListener('mouseup', function () {
      isDown = false;
    });

    row.addEventListener('mousemove', function (e) {
      if (!isDown) {
        return;
      }
      e.preventDefault();
      const x = e.pageX - row.offsetLeft;
      const walk = (x - startX) * 1.5;
      row.scrollLeft = scrollLeft - walk;
    });
  });
}
