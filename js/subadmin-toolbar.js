(function (Drupal, drupalSettings, once) {
  'use strict';

  /**
   * Detect current layout mode from body classes and DOM elements.
   * Modes: gin-nav | gin-h | gin-v | gin-c | nav-sb | drupal-h | drupal-v | none
   */
  function detectMode() {
    var c = document.body.classList;
    if (c.contains('gin--navigation'))         return 'gin-nav';
    if (c.contains('gin--horizontal-toolbar')) return 'gin-h';
    if (c.contains('gin--vertical-toolbar'))   return 'gin-v';
    if (c.contains('gin--classic-toolbar'))    return 'gin-c';
    if (document.querySelector('aside.admin-toolbar, #admin-toolbar')) return 'nav-sb';
    if (c.contains('toolbar-horizontal'))      return 'drupal-h';
    if (c.contains('toolbar-vertical'))        return 'drupal-v';
    return 'none';
  }

  var raf = null;
  var bar = null;

  function _adjust() {
    raf = null;
    bar = bar || document.getElementById('subadmin-toolbar');
    if (!bar) return;

    var mode = detectMode();
    var cs = getComputedStyle(document.documentElement);
    var top = 0;
    var left = 0;
    var w = window.innerWidth;

    var tbAdmin = document.getElementById('toolbar-administration');
    var tbBar   = document.getElementById('toolbar-bar');
    var ginBar  = document.getElementById('gin-toolbar-bar');

    // 1. Offset superior: solo si existe una barra horizontal fija superior.
    if (w < 976) {
      if (tbAdmin && tbAdmin.offsetHeight > 0 && getComputedStyle(tbAdmin).display !== 'none') {
        top = tbAdmin.offsetHeight;
      } else if (tbBar && tbBar.offsetHeight > 0 && getComputedStyle(tbBar).position === 'fixed') {
        top = tbBar.offsetHeight;
      } else if (ginBar && ginBar.offsetHeight > 0 && getComputedStyle(ginBar).position === 'fixed') {
        top = ginBar.offsetHeight;
      }
    } else {
      if (ginBar && getComputedStyle(ginBar).position === 'fixed' && ginBar.offsetWidth > w / 2) {
        top = ginBar.offsetHeight;
      } else if (tbBar && getComputedStyle(tbBar).position === 'fixed' && tbBar.offsetWidth > w / 2 && getComputedStyle(tbBar).display !== 'none') {
        top = (tbAdmin && tbAdmin.offsetHeight > 0) ? tbAdmin.offsetHeight : tbBar.offsetHeight;
      }
    }

    // 2. Offset lateral: solo si el sidebar es visible en escritorio.
    var sideEl = document.querySelector('aside.admin-toolbar, #admin-toolbar, #gin-toolbar-bar');
    if (sideEl) {
      var rect = sideEl.getBoundingClientRect();
      if (rect.left >= 0 && rect.left < 50 && rect.width > 30 && rect.width < w / 2) {
        left = Math.round(rect.width);
      }
    }
    if (!left && w >= 976) {
      var displaceLeft = parseFloat(cs.getPropertyValue('--drupal-displace-offset-left')) ||
                         parseFloat(cs.getPropertyValue('--gin-toolbar-x-offset')) || 0;
      if (displaceLeft > 30 && displaceLeft < w / 2) {
        left = Math.round(displaceLeft);
      }
    }

    // 3. Posicionamiento del toolbar.
    bar.style.top   = top + 'px';
    bar.style.left  = left + 'px';
    bar.style.width = left ? 'calc(100% - ' + left + 'px)' : '100%';

    var totalTop = top + (bar.offsetHeight || 56);
    var docStyle = document.documentElement.style;
    docStyle.setProperty('--subadmin-toolbar-offset', totalTop + 'px');
    docStyle.setProperty('--subadmin-toolbar-left',   left + 'px');

    if (mode.startsWith('gin')) {
      docStyle.setProperty('--gin-sticky-offset', totalTop + 'px');
    }

    var secToolbar = document.querySelector('.gin-secondary-toolbar');
    if (secToolbar) {
      var secVisible = secToolbar.offsetHeight > 0 && getComputedStyle(secToolbar).display !== 'none';
      document.body.classList.toggle('gin--secondary-toolbar-hidden', !secVisible);
    } else if (mode.startsWith('gin')) {
      document.body.classList.add('gin--secondary-toolbar-hidden');
    }

    // Modo compacto (hamburguesa) en móvil (<= 768px).
    var isMobile = w <= 768;
    bar.classList.toggle('is-narrow', isMobile);
    bar.setAttribute('data-layout-mode', mode);

    if (!isMobile) {
      var col = document.getElementById('subadmin-toolbar-collapse');
      if (col && col.classList.contains('is-active')) {
        col.classList.remove('is-active');
        var btn = bar.querySelector('.subadmin-toolbar-toggle');
        if (btn) btn.setAttribute('aria-expanded', 'false');
      }
    }
  }

  function adjust() {
    if (!raf) raf = requestAnimationFrame(_adjust);
  }

  Drupal.behaviors.subadminToolbar = {
    attach: function (context) {

      // Toggle móvil (abrir/cerrar menú hamburguesa).
      once('sat-toggle', '.subadmin-toolbar-toggle', context).forEach(function (el) {
        el.addEventListener('click', function (e) {
          e.preventDefault();
          var collapse = document.getElementById('subadmin-toolbar-collapse') || document.getElementById('subadmin-toolbar-menu');
          if (collapse) collapse.classList.toggle('is-active');
          this.setAttribute('aria-expanded', String(this.getAttribute('aria-expanded') !== 'true'));
        });
      });

      // Cerrar collapse al hacer click fuera en móvil
      once('sat-outside-click', document, context).forEach(function (doc) {
        doc.addEventListener('click', function (e) {
          var barEl = document.getElementById('subadmin-toolbar');
          if (!barEl) return;
          var collapse = document.getElementById('subadmin-toolbar-collapse');
          if (collapse && collapse.classList.contains('is-active')) {
            if (!barEl.contains(e.target)) {
              collapse.classList.remove('is-active');
              var btn = barEl.querySelector('.subadmin-toolbar-toggle');
              if (btn) btn.setAttribute('aria-expanded', 'false');
            }
          }
        });
      });

      // Rueda del ratón para scroll horizontal en escritorio cuando hay overflow.
      once('sat-wheel', '#subadmin-toolbar-menu', context).forEach(function (menu) {
        menu.addEventListener('wheel', function (e) {
          if (bar && !bar.classList.contains('is-narrow') && menu.scrollWidth > menu.clientWidth) {
            if (e.deltaY !== 0 && e.deltaX === 0) {
              e.preventDefault();
              menu.scrollLeft += e.deltaY;
            }
          }
        }, { passive: false });
      });

      // Submenús en modo compacto.
      once('sat-caret', '.subadmin-toolbar-caret, .has-submenu > a[href="#"]', context).forEach(function (el) {
        el.addEventListener('click', function (e) {
          if (!bar || (!bar.classList.contains('is-narrow') && window.innerWidth > 768)) return;
          e.preventDefault();
          e.stopPropagation();
          var li = this.closest('.has-submenu');
          if (li) li.classList.toggle('is-expanded');
        });
      });

      adjust();


      window.addEventListener('resize',                     adjust);
      window.addEventListener('transitionend',              adjust);
      window.addEventListener('drupalToolbarTabChange',     adjust);
      window.addEventListener('drupalToolbarTrayChange',    adjust);
      window.addEventListener('drupalViewportOffsetChange', adjust);

      // Timeout preventivo para renderizado asíncrono de Gin/sidebars.
      setTimeout(adjust, 300);

      once('sat-obs', 'body', context).forEach(function (bodyEl) {
        // Observar cambios en clases del body (cambios de tema o toolbar).
        new MutationObserver(function (muts) {
          var changed = muts.some(function (m) {
            return (m.type === 'attributes' && m.target === document.body) ||
                   (m.type === 'childList' && m.addedNodes.length > 0);
          });
          if (changed) { bar = null; adjust(); }
        }).observe(bodyEl, { childList: true, attributes: true, attributeFilter: ['class'] });

        // Observar cambios de tamaño de elementos clave.
        if (window.ResizeObserver) {
          var ro = new ResizeObserver(adjust);
          ro.observe(document.documentElement);
          ['.gin-secondary-toolbar', 'aside.admin-toolbar', '#admin-toolbar', '#gin-toolbar-bar', '.region-sticky']
            .forEach(function (sel) {
              var el = document.querySelector(sel);
              if (el) ro.observe(el);
            });
        }
      });
    }
  };

  Drupal.subadminToolbar = { recalculate: adjust };

})(Drupal, drupalSettings, once);
