/**
 * @file
 * Places the description bubble of the toolbar links.
 *
 * The bubble itself is pure CSS. This script only measures, when a link is
 * hovered or focused, how much room there is on each side: it passes the real
 * width of the link's submenu (so the bubble opens after it) and flips the
 * bubble to the other side when it would not fit in the window.
 */
(function (Drupal, once) {
  'use strict';

  // Widest bubble (18rem) plus the gap to the link and a safety margin.
  var BUBBLE_REM = 18;
  var GAP_REM = 0.9;
  var MARGIN_PX = 8;

  function place(link) {
    var bar = link.closest('#subadmin-toolbar');
    if (!bar || bar.classList.contains('is-narrow')) return;

    var li = link.parentElement;
    var submenu = li ? li.querySelector(':scope > .subadmin-toolbar-submenu') : null;
    var width = submenu ? submenu.getBoundingClientRect().width : 0;

    // Top-level links only need the submenu width: their bubble sits below the bar.
    if (!link.classList.contains('subadmin-toolbar-sublink')) {
      link.style.setProperty('--subadmin-toolbar-tip-offset', width + 'px');
      return;
    }

    var rem = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
    var need = Math.min(BUBBLE_REM * rem, window.innerWidth * 0.6) + GAP_REM * rem + MARGIN_PX;
    var rect = link.getBoundingClientRect();
    // Submenus inside "More" open to the left, the rest to the right.
    var submenuSide = link.closest('.subadmin-toolbar-more') ? 'left' : 'right';
    var room = {
      left: rect.left - (submenuSide === 'left' ? width : 0),
      right: window.innerWidth - rect.right - (submenuSide === 'right' ? width : 0)
    };

    var side = submenuSide === 'left' ? 'left' : 'right';
    var other = side === 'left' ? 'right' : 'left';
    if (room[side] < need && room[other] > room[side]) side = other;

    link.setAttribute('data-tip-side', side);
    link.style.setProperty('--subadmin-toolbar-tip-offset', (side === submenuSide ? width : 0) + 'px');
  }

  Drupal.behaviors.subadminToolbarTooltip = {
    attach: function (context) {
      once('sat-tooltip', '#subadmin-toolbar', context).forEach(function (bar) {
        var handler = function (e) {
          var link = e.target.closest ? e.target.closest('[data-tooltip]') : null;
          if (link && bar.contains(link)) place(link);
        };
        bar.addEventListener('mouseover', handler);
        bar.addEventListener('focusin', handler);
      });
    }
  };

})(Drupal, once);
