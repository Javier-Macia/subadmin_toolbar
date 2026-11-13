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
    layoutOverflow(isMobile);

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


  var MENU_ID = 'subadmin-toolbar-menu';

  function menu() {
    return document.getElementById(MENU_ID);
  }

  function triggerOf(li) {
    return li.querySelector(':scope > a, :scope > button');
  }

  function childItems(ul) {
    return Array.from(ul.children).filter(function (el) { return el.tagName === 'LI'; });
  }

  /**
   * Keeps aria-expanded of a submenu trigger in sync with its state.
   */
  function syncAria(li) {
    var trigger = triggerOf(li);
    if (!trigger || !li.classList.contains('has-submenu')) return;
    var open = li.classList.contains('is-open') || li.classList.contains('is-expanded');
    trigger.setAttribute('aria-expanded', String(open));
  }

  function initAria(root) {
    root.querySelectorAll('.has-submenu').forEach(function (li) {
      var trigger = triggerOf(li);
      if (trigger) {
        trigger.setAttribute('aria-haspopup', 'true');
        syncAria(li);
      }
    });
  }

  /**
   * Opens or closes the submenu of an item (hover keeps working on its own).
   */
  function setOpen(li, open) {
    var cls = bar && bar.classList.contains('is-narrow') ? 'is-expanded' : 'is-open';
    li.classList.toggle(cls, open);
    if (open) li.classList.remove('is-dismissed');
    syncAria(li);
  }

  /**
   * Closes every open submenu except the ones that contain `keep`.
   */
  function closeAll(keep) {
    if (!bar) return;
    bar.querySelectorAll('.is-open, .is-expanded').forEach(function (li) {
      if (!keep || !li.contains(keep)) {
        li.classList.remove('is-open', 'is-expanded');
        syncAria(li);
      }
    });
  }

  /** Set while code moves the focus, so it doesn't reopen a closed menu. */
  var keepClosed = false;

  function focusItem(li, closed) {
    var trigger = li && triggerOf(li);
    if (!trigger) return;
    keepClosed = !!closed;
    trigger.focus();
    keepClosed = false;
  }

  /**
   * Keyboard support of the menu (disclosure pattern with arrow keys).
   */
  function onMenuKeydown(e) {
    var trigger = e.target.closest('.subadmin-toolbar-link, .subadmin-toolbar-sublink');
    var root = menu();
    if (!trigger || !root || !root.contains(trigger) || e.altKey || e.ctrlKey || e.metaKey) return;

    var li = trigger.closest('li');
    var list = li.parentElement;
    var isTop = list === root;
    var siblings = childItems(list);
    var index = siblings.indexOf(li);
    var hasSub = li.classList.contains('has-submenu');
    var rtl = document.documentElement.dir === 'rtl';
    var key = e.key;
    if (rtl && key === 'ArrowLeft') key = 'ArrowRight';
    else if (rtl && key === 'ArrowRight') key = 'ArrowLeft';

    var parentLi = isTop ? null : list.closest('li');
    var handled = true;

    if (key === 'Escape') {
      var hovered = bar.querySelectorAll('.has-submenu:hover');
      var anyOpen = bar.querySelector('.is-open, .is-expanded') || hovered.length;
      if (!anyOpen) return;
      hovered.forEach(function (item) { item.classList.add('is-dismissed'); });
      var top = li;
      while (top.parentElement !== root) top = top.parentElement.closest('li');
      closeAll();
      focusItem(top, true);
    }
    else if (key === 'ArrowRight' || key === 'ArrowLeft') {
      var forward = key === 'ArrowRight';
      if (isTop) {
        focusItem(siblings[(index + (forward ? 1 : siblings.length - 1)) % siblings.length]);
      }
      else if (forward && hasSub) {
        setOpen(li, true);
        focusItem(childItems(li.querySelector(':scope > .subadmin-toolbar-submenu'))[0]);
      }
      else if (!forward && parentLi && !parentLi.parentElement.isSameNode(root)) {
        // Back out of a nested submenu.
        setOpen(parentLi, false);
        focusItem(parentLi);
      }
      else {
        // First-level submenu: move along the top-level items.
        var topLi = parentLi;
        var topSiblings = childItems(root);
        var at = topSiblings.indexOf(topLi);
        closeAll();
        focusItem(topSiblings[(at + (forward ? 1 : topSiblings.length - 1)) % topSiblings.length]);
      }
    }
    else if (key === 'ArrowDown') {
      if (isTop && hasSub) {
        setOpen(li, true);
        focusItem(childItems(li.querySelector(':scope > .subadmin-toolbar-submenu'))[0]);
      }
      else if (!isTop) {
        focusItem(siblings[(index + 1) % siblings.length]);
      }
      else {
        handled = false;
      }
    }
    else if (key === 'ArrowUp') {
      if (!isTop && index > 0) focusItem(siblings[index - 1]);
      else if (!isTop && parentLi) focusItem(parentLi);
      else handled = false;
    }
    else if (key === 'Home' || key === 'End') {
      if (isTop && !hasSub) { handled = false; }
      else { focusItem(siblings[key === 'Home' ? 0 : siblings.length - 1]); }
    }
    else {
      handled = false;
    }

    if (handled) e.preventDefault();
  }

  /**
   * Moves the items that don't fit into a "More" submenu.
   */
  function layoutOverflow(isNarrow) {
    var root = menu();
    if (!root) return;

    var more = root.querySelector(':scope > .subadmin-toolbar-more');
    // Rebuilding the overflow menu would drop the focus placed inside it.
    if (more && more.contains(document.activeElement)) return;
    var moreList = more && more.querySelector(':scope > .subadmin-toolbar-submenu');

    // Put everything back in its original position first.
    if (moreList) {
      childItems(moreList)
        .sort(function (a, b) { return a.dataset.satOrder - b.dataset.satOrder; })
        .forEach(function (li) { demote(li, false); root.insertBefore(li, more); });
      more.remove();
    }
    var items = childItems(root);
    items.forEach(function (li, i) {
      if (li.dataset.satOrder === undefined) li.dataset.satOrder = String(i);
    });
    // Restore the original order (items return before the "More" item).
    items.sort(function (a, b) { return a.dataset.satOrder - b.dataset.satOrder; })
      .forEach(function (li) { root.appendChild(li); });

    if (isNarrow || root.scrollWidth <= root.clientWidth + 1 || items.length < 2) return;

    more = document.createElement('li');
    more.className = 'subadmin-toolbar-item has-submenu subadmin-toolbar-more';
    more.innerHTML = '<button type="button" class="subadmin-toolbar-link" aria-haspopup="true" aria-expanded="false">' +
      '<span class="subadmin-toolbar-text"></span>' +
      '<span class="subadmin-toolbar-caret material-symbols-outlined" translate="no" aria-hidden="true">expand_more</span>' +
      '</button><ul class="subadmin-toolbar-submenu"></ul>';
    more.querySelector('.subadmin-toolbar-text').textContent = Drupal.t('More');
    moreList = more.querySelector('.subadmin-toolbar-submenu');
    root.appendChild(more);

    // Move items from the end until the rest fit, keeping at least one.
    var movable = items.slice();
    while (movable.length > 1 && root.scrollWidth > root.clientWidth + 1) {
      var li = movable.pop();
      demote(li, true);
      moreList.insertBefore(li, moreList.firstChild);
    }
    if (!childItems(moreList).length) more.remove();
  }

  /**
   * Switches an item between its top-level and its submenu appearance.
   */
  function demote(li, toSub) {
    li.classList.toggle('subadmin-toolbar-item', !toSub);
    li.classList.toggle('subadmin-toolbar-subitem', toSub);
    var link = li.querySelector(':scope > a');
    if (link) {
      link.classList.toggle('subadmin-toolbar-link', !toSub);
      link.classList.toggle('subadmin-toolbar-sublink', toSub);
    }
    var caret = li.querySelector(':scope > a > .subadmin-toolbar-caret');
    if (caret) caret.textContent = toSub ? 'chevron_right' : 'expand_more';
    if (!toSub) li.classList.remove('is-open', 'is-expanded');
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
          if (li) {
            li.classList.toggle('is-expanded');
            syncAria(li);
          }
        });
      });

      // Submenu accessibility: ARIA, keyboard and focus handling.
      once('sat-a11y', '#subadmin-toolbar', context).forEach(function (barEl) {
        bar = barEl;
        initAria(barEl);

        barEl.addEventListener('keydown', onMenuKeydown);
        barEl.addEventListener('focusin', function (e) {
          var inMenu = e.target.closest('.subadmin-toolbar-menu');
          if (!inMenu || keepClosed || !e.target.matches(':focus-visible')) return;
          // Keyboard focus opens the submenus that lead to the focused item.
          for (var li = e.target.closest('li'); li && inMenu.contains(li); li = li.parentElement.closest('li')) {
            if (li.classList.contains('has-submenu')) setOpen(li, true);
          }
        });
        barEl.addEventListener('focusout', function (e) {
          var next = e.relatedTarget;
          closeAll(next && next.closest && next.closest('.subadmin-toolbar-menu') ? next : null);
        });
        barEl.addEventListener('click', function (e) {
          var more = e.target.closest('.subadmin-toolbar-more > button');
          if (more) {
            var item = more.closest('li');
            setOpen(item, !item.classList.contains('is-open'));
          }
        });
        // An Escape-dismissed hover menu comes back once the pointer leaves.
        barEl.addEventListener('mouseout', function (e) {
          barEl.querySelectorAll('.is-dismissed').forEach(function (li) {
            if (!li.contains(e.relatedTarget)) li.classList.remove('is-dismissed');
          });
        });

        // Icon font metrics change the widths the overflow depends on.
        if (document.fonts && document.fonts.ready) document.fonts.ready.then(adjust);
      });

      // Global listeners and the initial layout run once per page, not on
      // every AJAX attach.
      once('sat-global', 'html').forEach(function () {
        ['resize', 'transitionend', 'drupalToolbarTabChange', 'drupalToolbarTrayChange', 'drupalViewportOffsetChange']
          .forEach(function (name) { window.addEventListener(name, adjust); });

        adjust();
        // Deferred pass for Gin/sidebars that render asynchronously.
        setTimeout(adjust, 300);
      });

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
