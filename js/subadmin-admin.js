/**
 * @file
 * Visual builder for the Subadmin Toolbar links form.
 *
 * Mirrors the (hidden) Drupal table as a nested tree that can be reordered
 * with the pointer or the keyboard. The table stays the source of truth: every
 * change is written back to its hidden inputs before the form is submitted.
 */
(function (Drupal, drupalSettings) {
  'use strict';

  /**
   * Horizontal distance, in px, that corresponds to one nesting level.
   *
   * It comes from the --subadmin-indent CSS variable so the drag maths follow
   * the (narrower) indentation used on small screens.
   */
  const indentSize = (el) => parseFloat(getComputedStyle(el).getPropertyValue('--subadmin-indent')) || 24;

  /** Icon names offered as suggestions (provided by the form, alterable). */
  const iconSuggestions = () => (drupalSettings.subadminToolbar && drupalSettings.subadminToolbar.iconSuggestions) || [];

  /** Shown while nothing has been typed yet. */
  const COMMON_ICONS = [
    'home', 'dashboard', 'calendar_month', 'person', 'group', 'description', 'folder', 'payments', 'bar_chart',
    'settings', 'mail', 'call', 'notifications', 'help', 'search', 'map', 'work', 'medical_services',
  ];

  /**
   * Icon names for a query: those that start with it first, then the rest.
   */
  const matchIcons = (query, limit = 24) => {
    const all = iconSuggestions();
    if (!query) return COMMON_ICONS;
    const starts = [];
    const contains = [];
    for (const name of all) {
      if (name.startsWith(query)) starts.push(name);
      else if (name.includes(query)) contains.push(name);
      if (starts.length >= limit) break;
    }
    return starts.concat(contains).slice(0, limit);
  };

  /** Distance from the scroll edge, in px, where auto-scroll starts. */
  const SCROLL_ZONE = 56;

  const VIEW_PREF_KEY = 'subadmin_toolbar_view_pref';

  /**
   * Safe localStorage access.
   */
  const storage = {
    get(key) {
      try { return localStorage.getItem(key); } catch (e) { return null; }
    },
    set(key, value) {
      try { localStorage.setItem(key, value); } catch (e) { /* Ignore. */ }
    }
  };

  const esc = (value) => Drupal.checkPlain(String(value ?? ''));

  const isFontAwesome = (iconType, icon) =>
    (iconType && iconType.startsWith('fa')) || (icon && icon.startsWith('fa'));

  /**
   * Returns the markup of a link icon.
   */
  function iconMarkup(iconType, icon) {
    if (isFontAwesome(iconType, icon)) {
      return `<i class="${esc(icon || 'fas fa-link')}" aria-hidden="true"></i>`;
    }
    return `<span class="${esc(iconType || 'material-symbols-outlined')}" translate="no" aria-hidden="true">${esc(icon || 'link')}</span>`;
  }

  const parentItem = (li) => li.parentElement.closest('.subadmin-drag-item');
  const headerOf = (li) => li.querySelector(':scope > .subadmin-drag-item-header');
  const sublistOf = (li) => li.querySelector(':scope > .subadmin-drag-sublist');

  function depthOf(li) {
    let depth = 0;
    for (let p = parentItem(li); p; p = parentItem(p)) {
      depth++;
    }
    return depth;
  }

  Drupal.behaviors.subadminToolbarAdmin = {
    initialized: false,
    collapsed: new Set(),
    drag: null,

    attach(context) {
      const linksWrapper = (context === document ? document : context).querySelector('#links-wrapper');
      if (!linksWrapper) return;

      const form = linksWrapper.closest('form');
      const previousCount = document.querySelectorAll('#subadmin-drag-root .subadmin-drag-item').length;

      if (!this.initialized || !document.getElementById('subadmin-drag-root')) {
        this.init(form);
        this.initialized = true;
      }
      else {
        this.table = form.querySelector('#links-wrapper table');
        this.renderVisualItems();
      }

      this.switchView(storage.get(VIEW_PREF_KEY) || 'visual');

      // Open the editor of a link that was just added.
      const items = document.querySelectorAll('#subadmin-drag-root .subadmin-drag-item');
      if (this.wasAddingItem && items.length > previousCount) {
        this.wasAddingItem = false;
        const newItem = items[items.length - 1];
        this.openEditor(newItem, true);
        newItem.scrollIntoView({ block: 'nearest' });
      }
    },

    init(form) {
      this.form = form;
      this.table = form.querySelector('#links-wrapper table');
      this.buildUI();
      this.bindEvents();
    },

    buildUI() {
      this.form.insertAdjacentHTML('afterbegin', `
        <div class="subadmin-admin-header">
          <h3><span class="material-symbols-outlined" aria-hidden="true">widgets</span> ${Drupal.t('Subadmin Toolbar menu')}</h3>
          <p class="subadmin-dirty" role="status" hidden>${Drupal.t('Unsaved changes. Use "Save configuration" at the bottom of the page.')}</p>
          <div class="subadmin-view-switcher" role="group" aria-label="${Drupal.t('View')}">
            <button type="button" class="subadmin-btn-toggle" data-view="visual">
              <span class="material-symbols-outlined" aria-hidden="true">dashboard</span> ${Drupal.t('Visual view')}
            </button>
            <button type="button" class="subadmin-btn-toggle" data-view="classic">
              <span class="material-symbols-outlined" aria-hidden="true">table_rows</span> ${Drupal.t('Classic view')}
            </button>
          </div>
        </div>`);

      this.form.querySelector('#links-wrapper').insertAdjacentHTML('beforebegin', `
        <div class="subadmin-visual-builder-container subadmin-view-hidden">
          <div class="subadmin-builder-preview">
            <div class="subadmin-preview-title">
              <span>${Drupal.t('Toolbar navigation')} <span class="subadmin-count" aria-live="polite"></span></span>
              <button type="button" class="subadmin-btn-add-item">
                <span class="material-symbols-outlined" aria-hidden="true">add</span> ${Drupal.t('Add')}
              </button>
            </div>
            <div class="subadmin-tree-tools">
              <input type="search" class="subadmin-tree-filter" placeholder="${Drupal.t('Filter links…')}" aria-label="${Drupal.t('Filter links')}">
              <button type="button" class="subadmin-icon-btn subadmin-btn-expand-all" title="${Drupal.t('Expand all')}" aria-label="${Drupal.t('Expand all')}">
                <span class="material-symbols-outlined" aria-hidden="true">unfold_more</span>
              </button>
              <button type="button" class="subadmin-icon-btn subadmin-btn-collapse-all" title="${Drupal.t('Collapse all')}" aria-label="${Drupal.t('Collapse all')}">
                <span class="material-symbols-outlined" aria-hidden="true">unfold_less</span>
              </button>
            </div>
            <p class="subadmin-tree-hint">${Drupal.t('Drag the handle to reorder; move sideways to change the level. With the keyboard, focus a handle and use the arrow keys.')}</p>
            <div class="subadmin-tree-scroll">
              <ul class="subadmin-drag-list" id="subadmin-drag-root"></ul>
              <div class="subadmin-drop-indicator" hidden></div>
            </div>
          </div>
          <div class="subadmin-builder-editor">
            <div class="subadmin-editor-empty">
              <span class="material-symbols-outlined" aria-hidden="true">ads_click</span>
              <p>${Drupal.t('Select a link on the left to edit its properties.')}</p>
            </div>
            <div class="subadmin-editor-form-wrapper" hidden></div>
          </div>
        </div>`);

      const container = this.form.querySelector('.subadmin-visual-builder-container');
      this.visualContainer = container;
      this.root = container.querySelector('#subadmin-drag-root');
      this.scroller = container.querySelector('.subadmin-tree-scroll');
      this.indicator = container.querySelector('.subadmin-drop-indicator');
      this.filterInput = container.querySelector('.subadmin-tree-filter');
      this.countEl = container.querySelector('.subadmin-count');
      this.editorEmpty = container.querySelector('.subadmin-editor-empty');
      this.editorWrapper = container.querySelector('.subadmin-editor-form-wrapper');

      this.renderVisualItems();
    },

    switchView(viewMode) {
      storage.set(VIEW_PREF_KEY, viewMode);

      this.form.querySelectorAll('.subadmin-btn-toggle').forEach((btn) => {
        const active = btn.dataset.view === viewMode;
        btn.classList.toggle('active', active);
        btn.setAttribute('aria-pressed', String(active));
      });

      const linksWrapper = document.getElementById('links-wrapper');
      const visual = viewMode === 'visual';
      if (!visual) this.syncVisualToClassic();
      // Pick up what was changed in the classic table.
      else if (this.view === 'classic') this.renderVisualItems();
      this.view = viewMode;
      if (linksWrapper) linksWrapper.classList.toggle('subadmin-view-hidden', visual);
      this.visualContainer.classList.toggle('subadmin-view-hidden', !visual);
    },

    /**
     * Rebuilds the tree from the rows of the classic table.
     */
    renderVisualItems() {
      this.root.textContent = '';
      this.activeEditLi = null;
      this.closeEditor();

      const items = this.extractItemsFromTable();
      const nodes = new Map(items.map((item) => [item.id, { ...item, children: [] }]));
      const roots = [];
      nodes.forEach((node) => {
        const parent = node.pid && node.pid !== node.id ? nodes.get(node.pid) : null;
        (parent ? parent.children : roots).push(node);
      });

      const renderTree = (list, target) => {
        list.forEach((node) => {
          const li = this.createItemElement(node);
          target.appendChild(li);
          if (node.children.length) {
            const sub = document.createElement('ul');
            sub.className = 'subadmin-drag-sublist';
            li.appendChild(sub);
            renderTree(node.children, sub);
          }
        });
      };
      renderTree(roots, this.root);

      this.refreshStructure();
      this.applyFilter();
    },

    createItemElement(node) {
      const disabled = node.status === 'disabled';
      const li = document.createElement('li');
      li.className = 'subadmin-drag-item' + (disabled ? ' disabled-item' : '');
      li.dataset.id = node.id;

      li.innerHTML = `
        <div class="subadmin-drag-item-header">
          <button type="button" class="subadmin-icon-btn subadmin-btn-collapse" aria-expanded="true" title="${Drupal.t('Expand/collapse')}">
            <span class="material-symbols-outlined" aria-hidden="true">expand_more</span>
          </button>
          <button type="button" class="subadmin-drag-handle" aria-roledescription="${Drupal.t('sortable')}">
            <span class="material-symbols-outlined" aria-hidden="true">drag_indicator</span>
          </button>
          <span class="subadmin-item-icon">${iconMarkup(node.iconType, node.icon)}</span>
          <span class="subadmin-item-title-text"></span>
          <span class="subadmin-item-actions">
            <label class="subadmin-toggle-switch" title="${Drupal.t('Enable/disable')}">
              <input type="checkbox" class="subadmin-status-toggle" ${disabled ? '' : 'checked'} aria-label="${Drupal.t('Enabled')}">
              <span class="subadmin-slider"></span>
            </label>
            <button type="button" class="subadmin-icon-btn subadmin-btn-edit" title="${Drupal.t('Edit')}" aria-label="${Drupal.t('Edit')}">
              <span class="material-symbols-outlined" aria-hidden="true">edit</span>
            </button>
            <button type="button" class="subadmin-icon-btn subadmin-btn-delete" title="${Drupal.t('Remove')}" aria-label="${Drupal.t('Remove')}">
              <span class="material-symbols-outlined" aria-hidden="true">delete</span>
            </button>
          </span>
        </div>`;

      this.setTitle(li, node.title);
      li.nodeData = node;
      return li;
    },

    setTitle(li, title) {
      const text = headerOf(li).querySelector('.subadmin-item-title-text');
      text.textContent = title || Drupal.t('Untitled');
      text.classList.toggle('is-untitled', !title);
      headerOf(li).querySelector('.subadmin-drag-handle').setAttribute(
        'aria-label',
        Drupal.t('Reorder @title. Use the arrow keys to move it or change its level.', { '@title': title || Drupal.t('Untitled') })
      );
    },

    /**
     * Reads the rows of the classic table.
     */
    extractItemsFromTable() {
      if (!this.table) return [];
      const value = (tr, selector, fallback = '') => {
        const input = tr.querySelector(selector);
        return input ? input.value : fallback;
      };

      const items = [];
      this.table.querySelectorAll('tbody > tr').forEach((tr, index) => {
        const id = value(tr, '.link-id', null);
        if (!id) return;
        items.push({
          id,
          pid: value(tr, '.link-pid'),
          title: value(tr, 'input[name$="[title]"]'),
          url: value(tr, 'input[name$="[url]"]'),
          iconType: value(tr, 'select[name$="[icon_type]"]'),
          icon: value(tr, 'input[name$="[icon]"]'),
          weight: parseInt(value(tr, '.link-weight', index), 10),
          status: value(tr, 'input[name$="[status]"]', 'enabled'),
          tr,
        });
      });
      return items;
    },

    /**
     * Updates derived UI state after the tree structure changed.
     */
    refreshStructure() {
      let total = 0;
      this.root.querySelectorAll('.subadmin-drag-item').forEach((li) => {
        total++;
        const sub = sublistOf(li);
        if (sub && !sub.children.length) sub.remove();
        const hasChildren = !!sublistOf(li);
        li.classList.toggle('has-children', hasChildren);
        const collapsed = hasChildren && this.collapsed.has(li.dataset.id);
        li.classList.toggle('is-collapsed', collapsed);
        headerOf(li).querySelector('.subadmin-btn-collapse').setAttribute('aria-expanded', String(!collapsed));
      });
      this.countEl.textContent = `(${total})`;
    },

    setCollapsed(li, collapsed) {
      if (collapsed) this.collapsed.add(li.dataset.id);
      else this.collapsed.delete(li.dataset.id);
      this.refreshStructure();
    },

    setAllCollapsed(collapsed) {
      this.root.querySelectorAll('.subadmin-drag-item.has-children').forEach((li) => {
        if (collapsed) this.collapsed.add(li.dataset.id);
        else this.collapsed.delete(li.dataset.id);
      });
      this.refreshStructure();
    },

    /**
     * Hides the links that don't match the filter (keeping their ancestors).
     */
    applyFilter() {
      const query = this.filterInput.value.trim().toLowerCase();
      this.visualContainer.classList.toggle('is-filtering', query !== '');

      const items = Array.from(this.root.querySelectorAll('.subadmin-drag-item'));
      items.forEach((li) => li.classList.remove('is-filtered-out', 'is-match'));
      if (!query) return;

      items.forEach((li) => {
        const data = li.nodeData;
        if (`${data.title} ${data.url}`.toLowerCase().includes(query)) {
          li.classList.add('is-match');
        }
      });
      items.forEach((li) => {
        const visible = li.classList.contains('is-match') || li.querySelector('.is-match');
        li.classList.toggle('is-filtered-out', !visible);
      });
    },

    /**
     * Writes the tree structure to the node data (parent and sibling weight).
     */
    updateHierarchyFromDOM() {
      const process = (ul, pid) => {
        let weight = 0;
        ul.querySelectorAll(':scope > .subadmin-drag-item').forEach((li) => {
          li.nodeData.pid = pid;
          // Weights are relative to the siblings, so they stay within the
          // range of the weight element however many links there are.
          li.nodeData.weight = weight++;
          const sub = sublistOf(li);
          if (sub) process(sub, li.dataset.id);
        });
      };
      process(this.root, '');
    },

    /**
     * Writes the visual state back into the hidden classic table.
     */
    syncVisualToClassic() {
      this.updateHierarchyFromDOM();

      const tbody = this.table ? this.table.querySelector('tbody') : null;
      if (!tbody) return;

      const fragment = document.createDocumentFragment();
      this.root.querySelectorAll('.subadmin-drag-item').forEach((li) => {
        const data = li.nodeData;
        const row = data.tr;
        if (!row) return;
        const set = (selector, val) => {
          const input = row.querySelector(selector);
          if (input) input.value = val;
        };
        set('.link-pid', data.pid);
        set('input[name$="[status]"]', data.status || 'enabled');
        set('.link-weight', data.weight);
        fragment.appendChild(row);
      });
      tbody.appendChild(fragment);
    },

    /**
     * Persists a structural change made in the tree.
     */
    commit() {
      this.refreshStructure();
      this.syncVisualToClassic();
      this.markDirty();
    },

    /**
     * Tells the user that there are changes that were not saved yet.
     */
    markDirty() {
      const notice = this.form.querySelector('.subadmin-dirty');
      if (notice) notice.hidden = false;
    },

    bindEvents() {
      const self = this;

      this.form.addEventListener('click', (e) => {
        const btn = e.target.closest('.subadmin-btn-toggle');
        if (btn) self.switchView(btn.dataset.view);
      });

      const container = this.visualContainer;

      container.addEventListener('click', (e) => {
        if (e.target.closest('.subadmin-btn-add-item')) return self.addItem(e);
        if (e.target.closest('.subadmin-btn-expand-all')) return self.setAllCollapsed(false);
        if (e.target.closest('.subadmin-btn-collapse-all')) return self.setAllCollapsed(true);

        const li = e.target.closest('.subadmin-drag-item');
        if (!li) return;

        if (e.target.closest('.subadmin-btn-collapse')) {
          return self.setCollapsed(li, !li.classList.contains('is-collapsed'));
        }
        if (e.target.closest('.subadmin-btn-delete')) return self.deleteItem(li);
        // Anything else in the row (but the handle and the switch) edits it.
        if (!e.target.closest('.subadmin-drag-handle, .subadmin-toggle-switch')) {
          self.openEditor(li);
        }
      });

      container.addEventListener('change', (e) => {
        const toggle = e.target.closest('.subadmin-status-toggle');
        if (!toggle) return;
        const li = toggle.closest('.subadmin-drag-item');
        // The state of a link applies to all of its sub-links.
        [li, ...li.querySelectorAll('.subadmin-drag-item')].forEach((item) => {
          item.nodeData.status = toggle.checked ? 'enabled' : 'disabled';
          item.classList.toggle('disabled-item', !toggle.checked);
          headerOf(item).querySelector('.subadmin-status-toggle').checked = toggle.checked;
        });
        self.syncVisualToClassic();
        self.markDirty();
      });

      this.filterInput.addEventListener('input', () => self.applyFilter());
      this.filterInput.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && self.filterInput.value) {
          e.stopPropagation();
          self.filterInput.value = '';
          self.applyFilter();
        }
      });

      container.addEventListener('pointerdown', (e) => {
        const handle = e.target.closest('.subadmin-drag-handle');
        if (handle && e.button === 0) self.startDrag(e, handle.closest('.subadmin-drag-item'));
      });
      container.addEventListener('keydown', (e) => {
        const handle = e.target.closest('.subadmin-drag-handle');
        if (handle) self.onHandleKey(e, handle.closest('.subadmin-drag-item'));
      });
    },

    /**
     * Triggers the "Add link" button of the hidden Drupal form.
     */
    addItem(e) {
      e.preventDefault();
      this.wasAddingItem = true;
      this.syncVisualToClassic();

      const addBtn = document.querySelector('#links-wrapper .subadmin-btn-add-row');
      if (addBtn) {
        addBtn.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
        addBtn.click();
      }
    },

    deleteItem(li) {
      const data = li.nodeData;
      const title = data.title || Drupal.t('Untitled');
      const message = li.classList.contains('has-children')
        ? Drupal.t('Remove "@title" and its sub-links?', { '@title': title })
        : Drupal.t('Remove "@title"?', { '@title': title });
      this.confirm(message, Drupal.t('Remove'), () => {
        this.syncVisualToClassic();
        const removeBtn = data.tr && data.tr.querySelector('.subadmin-btn-remove-row');
        if (removeBtn) {
          removeBtn.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
          removeBtn.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
        }
      });
    },

    /**
     * Asks for confirmation in a Drupal dialog (modal, keyboard friendly).
     */
    confirm(message, confirmLabel, onConfirm) {
      const content = document.createElement('p');
      content.textContent = message;
      const dialog = Drupal.dialog(content, {
        title: Drupal.t('Please confirm'),
        modal: true,
        width: 420,
        dialogClass: 'subadmin-confirm-dialog',
        buttons: [
          {
            text: confirmLabel,
            class: 'button button--primary',
            click: () => {
              dialog.close();
              onConfirm();
            },
          },
          {
            text: Drupal.t('Cancel'),
            class: 'button',
            click: () => dialog.close(),
          },
        ],
        close: (event) => event.target.remove(),
      });
      dialog.showModal();
    },

    // ------------------------------------------------------------------
    // Pointer dragging.
    // ------------------------------------------------------------------

    startDrag(e, li) {
      if (this.drag || this.visualContainer.classList.contains('is-filtering')) return;
      e.preventDefault();

      const header = headerOf(li);
      const headerRect = header.getBoundingClientRect();
      const scrollerRect = this.scroller.getBoundingClientRect();

      // Snapshot of the rows the item can be dropped between, in scroller
      // content coordinates (they don't change while dragging).
      const rows = [];
      this.root.querySelectorAll('.subadmin-drag-item').forEach((item) => {
        const h = headerOf(item);
        if (li.contains(item) || !h.offsetHeight) return;
        const r = h.getBoundingClientRect();
        const top = r.top - scrollerRect.top + this.scroller.scrollTop;
        rows.push({ li: item, depth: depthOf(item), top, bottom: top + r.height, mid: top + r.height / 2 });
      });

      const ghost = header.cloneNode(true);
      ghost.classList.add('subadmin-drag-ghost');
      ghost.style.width = `${headerRect.width}px`;
      ghost.style.left = `${headerRect.left}px`;
      document.body.appendChild(ghost);

      this.drag = {
        li,
        ghost,
        rows,
        pointerId: e.pointerId,
        startX: e.clientX,
        startDepth: depthOf(li),
        indent: indentSize(this.root),
        offsetY: e.clientY - headerRect.top,
        clientX: e.clientX,
        clientY: e.clientY,
        target: null,
        raf: 0,
        handle: e.target.closest('.subadmin-drag-handle'),
      };

      li.classList.add('dragging');
      document.body.classList.add('subadmin-is-dragging');
      this.drag.handle.setPointerCapture(e.pointerId);

      this.onMove = (ev) => {
        if (ev.pointerId !== this.drag.pointerId) return;
        this.drag.clientX = ev.clientX;
        this.drag.clientY = ev.clientY;
        this.updateDrag();
      };
      this.onUp = (ev) => this.endDrag(ev.type === 'pointerup');
      this.onKeyCancel = (ev) => {
        if (ev.key === 'Escape') this.endDrag(false);
      };
      document.addEventListener('pointermove', this.onMove);
      document.addEventListener('pointerup', this.onUp);
      document.addEventListener('pointercancel', this.onUp);
      document.addEventListener('keydown', this.onKeyCancel);

      this.updateDrag();
      this.drag.raf = requestAnimationFrame(() => this.autoScroll());
    },

    /**
     * Computes the drop target for the current pointer position.
     */
    updateDrag() {
      const d = this.drag;
      const scrollerRect = this.scroller.getBoundingClientRect();

      d.ghost.style.top = `${d.clientY - d.offsetY}px`;

      const y = d.clientY - scrollerRect.top + this.scroller.scrollTop;
      let gap = 0;
      while (gap < d.rows.length && d.rows[gap].mid < y) gap++;

      const prev = d.rows[gap - 1];
      const next = d.rows[gap];
      const max = prev ? prev.depth + 1 : 0;
      const min = Math.min(next ? next.depth : 0, max);
      const wanted = d.startDepth + Math.round((d.clientX - d.startX) / d.indent);
      const depth = Math.max(min, Math.min(max, wanted));

      d.target = { prev, depth };

      const top = prev ? prev.bottom : (next ? next.top : 0);
      const left = this.root.offsetLeft + depth * d.indent;
      Object.assign(this.indicator.style, {
        top: `${top}px`,
        left: `${left}px`,
        width: `${Math.max(40, this.root.clientWidth - depth * d.indent)}px`,
      });
      this.indicator.hidden = false;
    },

    /**
     * Scrolls the tree (or the page) while dragging near an edge.
     */
    autoScroll() {
      const d = this.drag;
      if (!d) return;

      const rect = this.scroller.getBoundingClientRect();
      const speed = (distance) => Math.ceil(Math.min(1, (SCROLL_ZONE - distance) / SCROLL_ZONE) * 18);
      let scrolled = false;

      const scrollable = this.scroller.scrollHeight > this.scroller.clientHeight;
      if (scrollable && d.clientY < rect.top + SCROLL_ZONE && this.scroller.scrollTop > 0) {
        this.scroller.scrollTop -= speed(d.clientY - rect.top);
        scrolled = true;
      }
      else if (scrollable && d.clientY > rect.bottom - SCROLL_ZONE) {
        this.scroller.scrollTop += speed(rect.bottom - d.clientY);
        scrolled = true;
      }
      else if (d.clientY < SCROLL_ZONE) {
        window.scrollBy(0, -speed(d.clientY));
        scrolled = true;
      }
      else if (d.clientY > window.innerHeight - SCROLL_ZONE) {
        window.scrollBy(0, speed(window.innerHeight - d.clientY));
        scrolled = true;
      }

      if (scrolled) this.updateDrag();
      d.raf = requestAnimationFrame(() => this.autoScroll());
    },

    endDrag(drop) {
      const d = this.drag;
      if (!d) return;

      cancelAnimationFrame(d.raf);
      document.removeEventListener('pointermove', this.onMove);
      document.removeEventListener('pointerup', this.onUp);
      document.removeEventListener('pointercancel', this.onUp);
      document.removeEventListener('keydown', this.onKeyCancel);
      if (d.handle.hasPointerCapture(d.pointerId)) d.handle.releasePointerCapture(d.pointerId);

      d.ghost.remove();
      d.li.classList.remove('dragging');
      document.body.classList.remove('subadmin-is-dragging');
      this.indicator.hidden = true;
      this.drag = null;

      if (drop && d.target) {
        this.placeItem(d.li, d.target.prev ? d.target.prev.li : null, d.target.depth);
        d.handle.focus();
      }
    },

    /**
     * Moves an item right after another one, at the given depth.
     *
     * @param {HTMLElement} li
     *   The item to move.
     * @param {HTMLElement|null} prev
     *   The item it follows in the visible order, or null for the first place.
     * @param {number} depth
     *   The nesting level it ends up at.
     */
    placeItem(li, prev, depth) {
      if (!prev) {
        this.root.prepend(li);
      }
      else if (depth === depthOf(prev) + 1) {
        // First child of the previous item.
        let sub = sublistOf(prev);
        if (!sub) {
          sub = document.createElement('ul');
          sub.className = 'subadmin-drag-sublist';
          prev.appendChild(sub);
        }
        this.collapsed.delete(prev.dataset.id);
        sub.prepend(li);
      }
      else {
        let sibling = prev;
        while (depthOf(sibling) > depth) sibling = parentItem(sibling);
        sibling.after(li);
      }
      this.commit();
    },

    // ------------------------------------------------------------------
    // Keyboard.
    // ------------------------------------------------------------------

    onHandleKey(e, li) {
      const keys = ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'];
      if (!keys.includes(e.key) || e.ctrlKey || e.metaKey || e.altKey || this.drag) return;
      if (this.visualContainer.classList.contains('is-filtering')) return;
      e.preventDefault();

      const rtl = document.documentElement.dir === 'rtl';
      const key = rtl && e.key === 'ArrowLeft' ? 'ArrowRight' : (rtl && e.key === 'ArrowRight' ? 'ArrowLeft' : e.key);

      const prevSibling = li.previousElementSibling;
      const nextSibling = li.nextElementSibling;
      const parent = parentItem(li);

      if (key === 'ArrowUp' && prevSibling) {
        prevSibling.before(li);
      }
      else if (key === 'ArrowDown' && nextSibling) {
        nextSibling.after(li);
      }
      else if (key === 'ArrowRight' && prevSibling) {
        // Become the last child of the previous sibling.
        let sub = sublistOf(prevSibling);
        if (!sub) {
          sub = document.createElement('ul');
          sub.className = 'subadmin-drag-sublist';
          prevSibling.appendChild(sub);
        }
        this.collapsed.delete(prevSibling.dataset.id);
        sub.appendChild(li);
      }
      else if (key === 'ArrowLeft' && parent) {
        parent.after(li);
      }
      else {
        return;
      }

      this.commit();
      headerOf(li).querySelector('.subadmin-drag-handle').focus();
      li.scrollIntoView({ block: 'nearest' });

      const siblings = li.parentElement.children;
      Drupal.announce(Drupal.t('@title: position @position of @total, level @level.', {
        '@title': li.nodeData.title || Drupal.t('Untitled'),
        '@position': Array.prototype.indexOf.call(siblings, li) + 1,
        '@total': siblings.length,
        '@level': depthOf(li) + 1,
      }));
    },

    // ------------------------------------------------------------------
    // Editor.
    // ------------------------------------------------------------------

    closeEditor() {
      if (!this.editorWrapper) return;
      this.editorWrapper.hidden = true;
      this.editorWrapper.textContent = '';
      this.editorEmpty.hidden = false;
    },

    openEditor(li, focusTitle = false) {
      const data = li.nodeData;
      const row = data.tr;
      if (!row) return;

      this.root.querySelectorAll('.is-active').forEach((el) => el.classList.remove('is-active'));
      li.classList.add('is-active');
      this.activeEditLi = li;

      const field = (selector) => {
        const input = row.querySelector(selector);
        return input ? input.value : '';
      };
      const iconType = field('select[name$="[icon_type]"]');

      // Checkbox groups (roles, and whatever other modules add) are discovered
      // from the row, so the editor needs no knowledge of them.
      // Drupal copies the attributes of a checkboxes element to its wrapper,
      // inner container and every checkbox: keep only the outermost one.
      const groups = Array.from(row.querySelectorAll('[data-subadmin-group]:not(input)'))
        .filter((el) => !el.parentElement.closest('[data-subadmin-group]'));
      const groupsHTML = groups.map((group) => {
        const name = group.dataset.subadminGroup;
        const options = Array.from(group.querySelectorAll('input[type="checkbox"]')).map((chk) => {
          const label = group.querySelector(`label[for="${CSS.escape(chk.id)}"]`);
          const text = label ? label.textContent : chk.value;
          return `<label class="subadmin-role-item">
            <input type="checkbox" class="subadmin-editor-group-option" data-group="${esc(name)}" value="${esc(chk.value)}" ${chk.checked ? 'checked' : ''}>
            <span>${esc(text)}</span>
          </label>`;
        }).join('');
        const help = group.dataset.subadminGroupHelp;
        return `<div class="subadmin-form-group">
            <span class="subadmin-form-label">${esc(group.dataset.subadminGroupLabel || name)}</span>
            <div class="subadmin-roles-column">${options}</div>
            ${help ? `<p class="subadmin-field-help">${esc(help)}</p>` : ''}
          </div>`;
      }).join('');

      // Only offer the icon families that are available.
      const settings = drupalSettings.subadminToolbar || {};
      const types = settings.availableIconTypes
        || Object.fromEntries(Array.from(row.querySelector('select[name$="[icon_type]"]')?.options || []).map((o) => [o.value, o.text]));
      const selectedType = iconType || 'material-symbols-outlined';
      const iconOptionsHTML = Object.entries(types).map(([value, label]) =>
        `<option value="${esc(value)}" ${value === selectedType ? 'selected' : ''}>${esc(label)}</option>`
      ).join('');

      this.editorEmpty.hidden = true;
      this.editorWrapper.hidden = false;
      this.editorWrapper.innerHTML = `
        <div class="subadmin-editor-form">
          <h4 class="subadmin-editor-heading">
            <span class="material-symbols-outlined" aria-hidden="true">edit</span>
            <span class="subadmin-editor-heading-text">${esc(data.title || Drupal.t('New link'))}</span>
          </h4>
          <div class="subadmin-form-group">
            <label>${Drupal.t('Link title')}
              <input type="text" class="subadmin-editor-title" value="${esc(field('input[name$="[title]"]'))}">
            </label>
          </div>
          <div class="subadmin-form-group">
            <label>${Drupal.t('URL or Drupal path')}
              <input type="text" class="subadmin-editor-url" value="${esc(field('input[name$="[url]"]'))}" placeholder="/admin/content">
            </label>
          </div>
          <div class="subadmin-form-group">
            <label>${Drupal.t('Icon family')}
              <select class="subadmin-editor-icon-type">${iconOptionsHTML}</select>
            </label>
          </div>
          <div class="subadmin-form-group">
            <label for="subadmin-editor-icon">${Drupal.t('Icon name')}</label>
            <div class="subadmin-icon-field">
              <input type="text" id="subadmin-editor-icon" class="subadmin-editor-icon" value="${esc(field('input[name$="[icon]"]'))}" placeholder="settings / home / calendar_month" autocomplete="off" aria-describedby="subadmin-icon-help">
              <span class="subadmin-icon-preview" aria-hidden="true"></span>
            </div>
            <div class="subadmin-icon-grid" role="group" aria-label="${Drupal.t('Suggested icons')}"></div>
            <p class="subadmin-field-help" id="subadmin-icon-help">${Drupal.t('If the preview shows text instead of an icon, that icon name does not exist.')}</p>
          </div>
          ${groupsHTML}
        </div>`;

      const onChange = () => {
        this.applyEditorChanges();
        this.renderIconHelpers();
      };
      this.editorWrapper.querySelectorAll('input, select').forEach((el) => {
        el.addEventListener('input', onChange);
        el.addEventListener('change', onChange);
      });
      this.editorWrapper.querySelector('.subadmin-icon-grid').addEventListener('click', (e) => {
        const button = e.target.closest('button[data-icon]');
        if (!button) return;
        this.editorWrapper.querySelector('.subadmin-editor-icon').value = button.dataset.icon;
        onChange();
      });
      this.renderIconHelpers();

      if (focusTitle) {
        this.editorWrapper.querySelector('.subadmin-editor-title').focus();
      }
      // On narrow screens the editor sits below the list: bring it into view.
      if (window.matchMedia('(max-width: 900px)').matches) {
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.editorWrapper.scrollIntoView({ block: 'start', behavior: reduce ? 'auto' : 'smooth' });
      }
    },

    /**
     * Updates the icon preview and the suggestions that match what was typed.
     */
    renderIconHelpers() {
      const q = (selector) => this.editorWrapper.querySelector(selector);
      const type = q('.subadmin-editor-icon-type').value;
      const icon = q('.subadmin-editor-icon').value.trim();
      q('.subadmin-icon-preview').innerHTML = iconMarkup(type, icon);

      const grid = q('.subadmin-icon-grid');
      // Suggestions are Material names; other families only get the preview.
      grid.hidden = isFontAwesome(type, icon) || !type.startsWith('material');
      if (grid.hidden) return;

      // Rebuilding the buttons while one is being pressed would swallow its
      // click (the input's change event fires on mousedown), so only do it
      // when the filter actually changed.
      const key = `${type}|${icon.toLowerCase()}`;
      if (grid.dataset.key !== key) {
        grid.dataset.key = key;
        grid.textContent = '';
        matchIcons(icon.toLowerCase()).forEach((name) => {
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'subadmin-icon-option';
          button.dataset.icon = name;
          button.title = name;
          button.setAttribute('aria-label', name);
          button.innerHTML = iconMarkup(type, name);
          grid.appendChild(button);
        });
      }
      grid.querySelectorAll('.subadmin-icon-option').forEach((button) => {
        button.setAttribute('aria-pressed', String(button.dataset.icon === icon));
      });
    },

    applyEditorChanges() {
      const li = this.activeEditLi;
      if (!li) return;
      const data = li.nodeData;
      const row = data.tr;
      const q = (selector) => this.editorWrapper.querySelector(selector);

      data.title = q('.subadmin-editor-title').value;
      data.url = q('.subadmin-editor-url').value;
      data.iconType = q('.subadmin-editor-icon-type').value;
      data.icon = q('.subadmin-editor-icon').value;

      this.setTitle(li, data.title);
      q('.subadmin-editor-heading-text').textContent = data.title || Drupal.t('New link');
      headerOf(li).querySelector('.subadmin-item-icon').innerHTML = iconMarkup(data.iconType, data.icon);

      const set = (selector, value) => {
        const input = row.querySelector(selector);
        if (input) input.value = value;
      };
      set('input[name$="[title]"]', data.title);
      set('input[name$="[url]"]', data.url);
      set('select[name$="[icon_type]"]', data.iconType);
      set('input[name$="[icon]"]', data.icon);

      this.editorWrapper.querySelectorAll('.subadmin-editor-group-option').forEach((chk) => {
        const group = CSS.escape(chk.dataset.group);
        const rowChk = row.querySelector(`[data-subadmin-group="${group}"] input[value="${CSS.escape(chk.value)}"]`);
        if (rowChk) rowChk.checked = chk.checked;
      });

      this.markDirty();
      if (this.filterInput.value) this.applyFilter();
    },
  };

})(Drupal, drupalSettings);
