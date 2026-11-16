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
  var adjusting = false;

  /** Sets an attribute only when its value differs, so repeated passes leave the DOM untouched. */
  function setAttr(el, name, value) {
    if (el.getAttribute(name) !== value) el.setAttribute(name, value);
  }

  /** Removes an attribute only when it is present. */
  function dropAttr(el, name) {
    if (el.hasAttribute(name)) el.removeAttribute(name);
  }

  /** Writes an inline style property only when it changes. */
  function setStyle(el, prop, value) {
    if (el.style.getPropertyValue(prop) !== value) el.style.setProperty(prop, value);
  }

  /** Writes or clears a custom property on the root only when it changes. */
  function setVar(name, value) {
    var style = document.documentElement.style;
    if (value === null) {
      if (style.getPropertyValue(name) !== '') style.removeProperty(name);
    }
    else if (style.getPropertyValue(name) !== value) {
      style.setProperty(name, value);
    }
  }
  var bar = null;
  var lastLayout = null;

  var STORAGE_KEY = 'subadminToolbar.sidebar';
  var RAIL_WIDTH = 64;

  /** Whether the sidebar is collapsible (and not top-positioned). */
  function isCollapsible() {
    return !!bar && (bar.getAttribute('data-position') || 'top') !== 'top' &&
      bar.getAttribute('data-sidebar-mode') === 'collapsible';
  }

  /** Whether the sidebar is currently collapsed to a rail. */
  function isCollapsed() {
    if (!isCollapsible()) return false;
    var stored = document.documentElement.getAttribute('data-subadmin-sidebar');
    if (stored === 'collapsed') return true;
    if (stored === 'expanded') return false;
    return bar.getAttribute('data-sidebar-collapsed') === 'true';
  }

  /** Whether the submenus open as flyouts at the side of the rail (wide screens only). */
  function isRail() {
    return !!bar && window.innerWidth > 768 && isCollapsed();
  }

  /** Publishes where the flyouts of the rail attach (the edge facing the page). */
  function publishFlyoutEdge() {
    var rect = bar.getBoundingClientRect();
    var edge = bar.getAttribute('data-position') === 'right' ? document.documentElement.clientWidth - rect.left : rect.right;
    setStyle(bar, '--subadmin-toolbar-flyout-edge', Math.round(edge) + 'px');
  }

  /** Aligns the flyout of a top-level item with its row, keeping it inside the viewport. */
  function positionFlyout(li) {
    if (!isRail() || !li || !li.matches('.subadmin-toolbar-item.has-submenu')) return;
    var sub = li.querySelector(':scope > .subadmin-toolbar-submenu');
    if (!sub) return;
    publishFlyoutEdge();
    var top = li.getBoundingClientRect().top;
    var max = window.innerHeight - sub.offsetHeight - 8;
    setStyle(li, '--subadmin-toolbar-flyout-top', Math.round(Math.max(0, Math.min(top, max))) + 'px');
  }

  /** Brings the collapse button in line with the current state. */
  function syncCollapseToggle() {
    var btn = bar && bar.querySelector('.subadmin-toolbar-collapse-toggle');
    if (!btn) return;
    var collapsed = isCollapsed();
    // The rail shows no text, so its top-level links carry a tooltip.
    bar.querySelectorAll('.subadmin-toolbar-menu > .subadmin-toolbar-item > a').forEach(function (a) {
      if (isRail()) {
        var text = a.querySelector('.subadmin-toolbar-text');
        if (text) setAttr(a, 'title', text.textContent.trim());
      }
      else {
        dropAttr(a, 'title');
      }
    });
    bar.querySelectorAll('.has-submenu').forEach(syncAria);
    setAttr(btn, 'aria-expanded', String(!collapsed));
    var label = btn.querySelector('[data-label-collapse]');
    var text = label && label.getAttribute(collapsed ? 'data-label-expand' : 'data-label-collapse');
    if (label && label.textContent !== text) label.textContent = text;
  }

  var displaceKey = '';
  /** Width taken at the left by the bars next to this toolbar, last seen on a wide screen. */
  var knownLeft = 0;

  /** Marks the toolbar with the data-offset-* attribute of the edge it occupies, so core and contrib UI move out of its way. */
  function publishDisplace(edge, size) {
    var key = edge + ':' + Math.round(size);
    if (key === displaceKey && bar.hasAttribute('data-offset-' + edge)) return;
    displaceKey = key;
    ['top', 'right', 'bottom', 'left'].forEach(function (name) {
      if (name === edge) setAttr(bar, 'data-offset-' + name, String(Math.round(size)));
      else dropAttr(bar, 'data-offset-' + name);
    });
    if (window.Drupal && typeof Drupal.displace === 'function') Drupal.displace(true);
  }

  /** Name of the active layout, used to drop stale open states when it changes. */
  function layoutName(isMobile, sidebar) {
    if (isMobile) return 'narrow';
    if (!sidebar) return 'top';
    return isCollapsed() ? 'rail' : 'sidebar';
  }

  function _adjust() {
    raf = null;
    if (adjusting) return;
    bar = bar || document.getElementById('subadmin-toolbar');
    if (!bar) return;
    adjusting = true;
    try {
      applyLayout();
    }
    finally {
      adjusting = false;
    }
  }

  /** Reads the surrounding bars, then applies the toolbar's position and the published offsets. */
  function applyLayout() {

    var mode = detectMode();
    var cs = getComputedStyle(document.documentElement);
    var top = 0;
    var left = 0;
    var w = window.innerWidth;
    var position = bar.getAttribute('data-position') || 'top';
    var isMobile = w <= 768;
    // A sidebar layout only applies on wide screens; narrow ones use the hamburger menu.
    var sidebar = position !== 'top' && !isMobile;

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
    document.querySelectorAll('aside.admin-toolbar, #admin-toolbar, #gin-toolbar-bar').forEach(function (sideEl) {
      var rect = sideEl.getBoundingClientRect();
      if (!left && rect.left >= 0 && rect.left < 50 && rect.width > 30 && rect.width < w / 2) {
        left = Math.round(rect.width);
      }
    });
    if (!left && w >= 976) {
      // The displace variable and Gin's x offset hold this toolbar's own edge once
      // it is registered, so then the last width seen beside it is kept.
      var displaceLeft = bar.hasAttribute('data-offset-left') ? knownLeft :
        (parseFloat(cs.getPropertyValue('--drupal-displace-offset-left')) ||
         parseFloat(cs.getPropertyValue('--gin-toolbar-x-offset')) || 0);
      if (displaceLeft > 30 && displaceLeft < w / 2) {
        left = Math.round(displaceLeft);
      }
    }
    knownLeft = w >= 976 ? left : 0;
    // 3. Posicionamiento del toolbar.
    var sidebarWidth = 0;
    // Read before any write.
    var barHeight = bar.offsetHeight || 56;
    if (sidebar) {
      sidebarWidth = isCollapsed() ? RAIL_WIDTH :
        (parseFloat(getComputedStyle(bar).getPropertyValue('--subadmin-toolbar-sidebar-width')) || 260);
    }
    setStyle(bar, 'top', top + 'px');
    if (sidebar) {
      // The sidebar sits beside the other bars and runs to the bottom of the viewport.
      setStyle(bar, 'left', position === 'left' ? left + 'px' : 'auto');
      setStyle(bar, 'right', position === 'right' ? '0px' : 'auto');
      setStyle(bar, 'width', '');
    } else {
      setStyle(bar, 'left', left + 'px');
      setStyle(bar, 'right', '');
      setStyle(bar, 'width', left ? 'calc(100% - ' + left + 'px)' : '100%');
    }

    var sidebarLeft = position === 'left' ? sidebarWidth : 0;
    // Gin's navigation layout shifts its page by the displace offset, which already holds this sidebar.
    var displaced = sidebar && position === 'left' && left > 0 && w >= 976 && mode === 'gin-nav';
    var padLeft = displaced ? 0 : sidebarLeft;
    var padRight = position === 'right' ? sidebarWidth : 0;
    // In a sidebar layout the toolbar takes no space at the top.
    var totalTop = sidebar ? top : top + barHeight;
    setVar('--subadmin-toolbar-offset', totalTop + 'px');
    setVar('--subadmin-toolbar-left', (left + sidebarLeft) + 'px');
    setVar('--subadmin-toolbar-right', padRight + 'px');
    // Space the sidebar itself takes, on top of what other bars already take.
    setVar('--subadmin-toolbar-pad-left', padLeft + 'px');
    setVar('--subadmin-toolbar-pad-right', padRight + 'px');
    setVar('--subadmin-toolbar-content-top', sidebar ? '0px' : null);

    // Register the occupied edge with Drupal's displace API.
    publishDisplace(sidebar ? position : 'top', sidebar ? (position === 'left' ? left + sidebarLeft : padRight) : totalTop);

    if (mode.startsWith('gin')) {
      setVar('--gin-sticky-offset', totalTop + 'px');
    }

    var secToolbar = document.querySelector('.gin-secondary-toolbar');
    if (secToolbar) {
      var secVisible = secToolbar.offsetHeight > 0 && getComputedStyle(secToolbar).display !== 'none';
      document.body.classList.toggle('gin--secondary-toolbar-hidden', !secVisible);
    } else if (mode.startsWith('gin') && !document.body.classList.contains('gin--secondary-toolbar-hidden')) {
      document.body.classList.add('gin--secondary-toolbar-hidden');
    }

    // Modo compacto (hamburguesa) en móvil (<= 768px).
    bar.classList.toggle('is-narrow', isMobile);
    var layout = layoutName(isMobile, sidebar);
    if (layout !== lastLayout) {
      if (lastLayout !== null) resetMenuState();
      lastLayout = layout;
    }
    syncCollapseToggle();
    if (layout === 'rail') publishFlyoutEdge();
    setAttr(bar, 'data-layout-mode', mode);
    // The sidebar scrolls vertically and has no "More" overflow.
    layoutOverflow(isMobile || sidebar);

    if (!isMobile) {
      var col = document.getElementById('subadmin-toolbar-collapse');
      if (col && col.classList.contains('is-active')) {
        col.classList.remove('is-active');
        var btn = bar.querySelector('.subadmin-toolbar-toggle');
        if (btn) setAttr(btn, 'aria-expanded', 'false');
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

  /** Whether submenus open as an accordion: the hamburger layout or an expanded sidebar. */
  function isAccordion() {
    if (!bar) return false;
    if (bar.classList.contains('is-narrow')) return true;
    return (bar.getAttribute('data-position') || 'top') !== 'top' && window.innerWidth > 768 && !isCollapsed();
  }

  function triggerOf(li) {
    return li.querySelector(':scope > a, :scope > button');
  }

  /** The element that discloses the submenu: the split button of a linked parent, else the trigger. */
  function disclosureOf(li) {
    return li.querySelector(':scope > .subadmin-toolbar-split') || triggerOf(li);
  }

  function childItems(ul) {
    return Array.from(ul.children).filter(function (el) { return el.tagName === 'LI'; });
  }

  /**
   * Keeps aria-expanded of a submenu trigger in sync with its state.
   */
  function syncAria(li) {
    var trigger = disclosureOf(li);
    if (!trigger || !li.classList.contains('has-submenu')) return;
    var open = li.classList.contains('is-open') || li.classList.contains('is-expanded');
    setAttr(trigger, 'aria-expanded', String(open));
    mirrorAria(li, trigger, open);
  }

  /** In the rail the split button is hidden, so the visible link of a linked parent carries the disclosure state. */
  function mirrorAria(li, trigger, open) {
    var link = triggerOf(li);
    if (!link || link === trigger) return;
    if (isRail()) {
      setAttr(link, 'aria-haspopup', 'true');
      setAttr(link, 'aria-expanded', String(open));
    }
    else {
      dropAttr(link, 'aria-haspopup');
      dropAttr(link, 'aria-expanded');
    }
  }

  function initAria(root) {
    root.querySelectorAll('.has-submenu').forEach(function (li) {
      var trigger = disclosureOf(li);
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
    var cls = isAccordion() ? 'is-expanded' : 'is-open';
    li.classList.toggle(cls, open);
    if (open) li.classList.remove('is-dismissed');
    syncAria(li);
  }

  /**
   * Closes every open submenu except the ones that contain `keep`.
   */
  function closeAll(keep) {
    if (!bar) return;
    bar.querySelectorAll('.subadmin-toolbar-menu .is-open, .subadmin-toolbar-menu .is-expanded').forEach(function (li) {
      if (!keep || !li.contains(keep)) {
        li.classList.remove('is-open', 'is-expanded');
        syncAria(li);
      }
    });
  }

  /** Drops every open, expanded and dismissed state (used when the layout changes). */
  function resetMenuState() {
    if (!bar) return;
    bar.querySelectorAll('.subadmin-toolbar-menu :is(.is-open, .is-expanded, .is-dismissed)').forEach(function (li) {
      li.classList.remove('is-open', 'is-expanded', 'is-dismissed');
      syncAria(li);
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

  /** Direct children of the submenu of an item. */
  function subItems(li) {
    var ul = li.querySelector(':scope > .subadmin-toolbar-submenu');
    return ul ? childItems(ul) : [];
  }

  /** The items shown in the column of an accordion, in reading order. */
  function visibleColumn(list, out) {
    childItems(list).forEach(function (li) {
      out.push(li);
      var ul = li.querySelector(':scope > .subadmin-toolbar-submenu');
      if (ul && li.classList.contains('is-expanded')) visibleColumn(ul, out);
    });
    return out;
  }

  /**
   * Arrow keys in a vertical list (accordion): Down and Up walk the visible
   * items, Right opens or enters a parent, Left closes it or goes to the
   * parent. Returns whether the key was handled.
   */
  function accordionKey(key, li, root) {
    var column = visibleColumn(root, []);
    var at = column.indexOf(li);
    var list = li.parentElement;
    var parentLi = list === root ? null : list.closest('li');
    var hasSub = li.classList.contains('has-submenu');
    var siblings = childItems(list);

    if (key === 'ArrowDown') {
      focusItem(column[Math.min(at + 1, column.length - 1)]);
    }
    else if (key === 'ArrowUp') {
      focusItem(column[Math.max(at - 1, 0)]);
    }
    else if (key === 'ArrowRight') {
      if (!hasSub) return false;
      if (!li.classList.contains('is-expanded')) {
        closeAll(li);
        setOpen(li, true);
      }
      else {
        focusItem(subItems(li)[0]);
      }
    }
    else if (key === 'ArrowLeft') {
      if (hasSub && li.classList.contains('is-expanded')) setOpen(li, false);
      else if (parentLi) focusItem(parentLi);
      else return false;
    }
    else if (key === 'Home' || key === 'End') {
      focusItem(siblings[key === 'Home' ? 0 : siblings.length - 1]);
    }
    else {
      return false;
    }
    return true;
  }

  /**
   * Arrow keys in the collapsed rail. The top-level items form a column; the
   * flyout opens toward the page, so the key toward the page enters it and
   * the opposite key returns to the parent.
   */
  function railKey(key, li, root) {
    var list = li.parentElement;
    var isTop = list === root;
    var siblings = childItems(list);
    var index = siblings.indexOf(li);
    var parentLi = isTop ? null : list.closest('li');
    var hasSub = li.classList.contains('has-submenu');
    var enter = bar.getAttribute('data-position') === 'right' ? 'ArrowLeft' : 'ArrowRight';
    var leave = enter === 'ArrowRight' ? 'ArrowLeft' : 'ArrowRight';

    if (key === 'ArrowDown') {
      focusItem(siblings[isTop ? Math.min(index + 1, siblings.length - 1) : (index + 1) % siblings.length]);
    }
    else if (key === 'ArrowUp') {
      focusItem(siblings[isTop ? Math.max(index - 1, 0) : (index + siblings.length - 1) % siblings.length]);
    }
    else if (key === enter) {
      if (!hasSub) return false;
      setOpen(li, true);
      positionFlyout(li);
      focusItem(subItems(li)[0]);
    }
    else if (key === leave) {
      if (!parentLi) return false;
      setOpen(parentLi, false);
      focusItem(parentLi, true);
    }
    else if (key === 'Home' || key === 'End') {
      focusItem(siblings[key === 'Home' ? 0 : siblings.length - 1]);
    }
    else {
      return false;
    }
    return true;
  }

  /**
   * Keyboard support of the menu (disclosure pattern with arrow keys).
   */
  function onMenuKeydown(e) {
    var trigger = e.target.closest('.subadmin-toolbar-link, .subadmin-toolbar-sublink, .subadmin-toolbar-split');
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

    // Vertical layouts (sidebar accordion, narrow list, rail) have their own arrow keys.
    var vertical = isAccordion();
    if (key !== 'Escape' && (vertical || isRail())) {
      // The rail sits at a physical edge, so its flyouts follow the physical arrow keys.
      var handledKey = vertical ? accordionKey(key, li, root) : railKey(e.key, li, root);
      if (handledKey) e.preventDefault();
      return;
    }

    if (key === 'Escape') {
      var hovered = bar.querySelectorAll('.has-submenu:hover');
      var anyOpen = bar.querySelector('.subadmin-toolbar-menu .is-open, .subadmin-toolbar-menu .is-expanded') || hovered.length;
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
    // Restore the original order (items return before the "More" item). Only
    // touch the DOM when something is out of place: re-attaching nodes under
    // the pointer drops their :hover and restarts the caret transition.
    var ordered = items.slice().sort(function (a, b) { return a.dataset.satOrder - b.dataset.satOrder; });
    if (ordered.some(function (li, i) { return li !== items[i]; })) {
      ordered.forEach(function (li) { root.appendChild(li); });
    }
    items = ordered;

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
    var caret = li.querySelector(':scope > a > .subadmin-toolbar-caret, :scope > .subadmin-toolbar-split > .subadmin-toolbar-caret');
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

      // Collapse button of a collapsible sidebar: the choice is kept in this browser.
      once('sat-collapse-toggle', '.subadmin-toolbar-collapse-toggle', context).forEach(function (el) {
        bar = bar || el.closest('#subadmin-toolbar');
        syncCollapseToggle();
        el.addEventListener('click', function () {
          var next = isCollapsed() ? 'expanded' : 'collapsed';
          document.documentElement.setAttribute('data-subadmin-sidebar', next);
          try {
            window.localStorage.setItem(STORAGE_KEY, next);
          }
          catch (e) {}
          syncCollapseToggle();
          adjust();
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
          if (bar && bar.getAttribute('data-position') === 'top' && !bar.classList.contains('is-narrow') && menu.scrollWidth > menu.clientWidth) {
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
          // The split button has its own handler.
          if (!bar || this.closest('.subadmin-toolbar-split')) return;
          if (!isAccordion()) {
            // Desktop: a wrapper parent ("#") only shows its submenu; don't jump to the top.
            if (this.closest('a[href="#"]')) e.preventDefault();
            // In the rail a wrapper click (touch) toggles its flyout.
            var wrapper = this.closest('.has-submenu');
            if (isRail() && wrapper && wrapper.parentElement === menu() && this.closest('a[href="#"]')) {
              var open = !wrapper.classList.contains('is-open');
              if (open) closeAll(wrapper);
              positionFlyout(wrapper);
              setOpen(wrapper, open);
            }
            return;
          }
          e.preventDefault();
          e.stopPropagation();
          var li = this.closest('.has-submenu');
          if (li) {
            // Accordion: opening one closes the others (but not its ancestors).
            if (!li.classList.contains('is-expanded')) closeAll(li);
            li.classList.toggle('is-expanded');
            syncAria(li);
          }
        });
      });

      // Split button of a linked parent: opens or closes its submenu.
      once('sat-split', '.subadmin-toolbar-split', context).forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var li = this.closest('.has-submenu');
          if (!li) return;
          var open = !li.classList.contains('is-open') && !li.classList.contains('is-expanded');
          if (open) closeAll(li);
          setOpen(li, open);
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
          // Keyboard focus opens the submenus that lead to the focused item. In
          // an accordion the focused parent itself stays as it is, so the
          // arrow keys can walk past it.
          var own = e.target.closest('li');
          var start = isAccordion() ? own.parentElement.closest('li') : own;
          for (var li = start; li && inMenu.contains(li); li = li.parentElement.closest('li')) {
            if (li.classList.contains('has-submenu')) setOpen(li, true);
          }
          var topLi = own;
          while (topLi && topLi.parentElement !== inMenu) topLi = topLi.parentElement.closest('li');
          positionFlyout(topLi);
        });
        // The flyouts of the rail line up with the row under the pointer.
        var hovered = null;
        barEl.addEventListener('mouseover', function (e) {
          var li = e.target.closest && e.target.closest('.subadmin-toolbar-item');
          if (li && li !== hovered) positionFlyout(li);
          hovered = li;
        });
        barEl.addEventListener('focusout', function (e) {
          var next = e.relatedTarget;
          // In compact mode closing on focus loss collapses the open list on
          // mousedown, shifting the layout so the click lands on another item
          // and is lost. The click handler already closes the others.
          if (isAccordion() && next && barEl.contains(next)) return;
          // An expanded sidebar keeps its sections when focus leaves the bar (a click on the page).
          if (isAccordion() && !barEl.classList.contains('is-narrow')) return;
          closeAll(next && next.closest && next.closest('.subadmin-toolbar-menu') ? next : null);
        });
        barEl.addEventListener('click', function (e) {
          // Touch has no hover: in the rail the first tap on a linked parent
          // opens its flyout and a second tap follows the link.
          var parentLink = e.target.closest('.subadmin-toolbar-item.is-linked > a');
          if (parentLink && e.pointerType === 'touch' && isRail()) {
            var parentLi = parentLink.parentElement;
            if (!parentLi.classList.contains('is-open')) {
              e.preventDefault();
              closeAll(parentLi);
              positionFlyout(parentLi);
              setOpen(parentLi, true);
              return;
            }
          }
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
        ['resize', 'drupalToolbarTabChange', 'drupalToolbarTrayChange']
          .forEach(function (name) { window.addEventListener(name, adjust); });
        // The first layout runs at once so the body padding is final before first paint.
        _adjust();
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
