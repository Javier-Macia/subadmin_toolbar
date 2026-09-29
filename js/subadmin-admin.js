(function (Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.subadminToolbarAdmin = {
    attach: function (context, settings) {
      const linksWrapper = (context === document ? document : context).querySelector('#links-wrapper');
      if (!linksWrapper) return;

      const form = linksWrapper.closest('form');
      const self = Drupal.behaviors.subadminToolbarAdmin;

      const activeView = localStorage.getItem('subadmin_toolbar_view_pref') || 'visual';
      const previousCount = document.querySelectorAll('#subadmin-drag-root .subadmin-drag-item').length;

      if (!self.initialized || !document.getElementById('subadmin-drag-root')) {
        self.init(form);
        self.initialized = true;
      } else {
        self.table = form.querySelector('#links-wrapper table');
        self.renderVisualItems();
      }

      self.switchView(activeView);

      const currentItems = document.querySelectorAll('#subadmin-drag-root .subadmin-drag-item');
      if (self.initialized && self.wasAddingItem && currentItems.length > previousCount) {
        self.wasAddingItem = false;
        const newItem = currentItems[currentItems.length - 1];
        if (newItem) {
          self.openEditor(newItem);
        }
      }
    },

    init: function (form) {
      this.form = form;
      this.table = form.querySelector('#links-wrapper table');

      this.buildUI();
      this.bindEvents();

      const activeView = localStorage.getItem('subadmin_toolbar_view_pref') || 'visual';
      this.switchView(activeView);
    },

    buildUI: function () {
      const headerHTML = `
        <div class="subadmin-admin-header">
          <h3><span class="material-symbols-outlined">widgets</span> Configuración de Subadmin Toolbar</h3>
          <div class="subadmin-view-switcher">
            <button type="button" class="subadmin-btn-toggle" data-view="visual">
              <span class="material-symbols-outlined">dashboard</span> Vista moderna
            </button>
            <button type="button" class="subadmin-btn-toggle" data-view="classic">
              <span class="material-symbols-outlined">table_rows</span> Vista Clásica
            </button>
          </div>
        </div>
      `;

      const builderHTML = `
        <div class="subadmin-visual-builder-container subadmin-view-hidden">
          <div class="subadmin-builder-preview">
            <div class="subadmin-preview-title">
              <span>Navegación Toolbar</span>
              <button type="button" class="subadmin-btn-add-item" title="Añadir enlace">
                <span class="material-symbols-outlined">add</span> Añadir
              </button>
            </div>
            <ul class="subadmin-drag-list" id="subadmin-drag-root"></ul>
          </div>
          <div class="subadmin-builder-editor">
            <div class="subadmin-editor-empty">
              <span class="material-symbols-outlined" style="font-size: 2.5rem; margin-bottom: 0.5rem; opacity: 0.5;">ads_click</span>
              <p>Selecciona un bloque a la izquierda para editar sus propiedades o arrastra para reordenar.</p>
            </div>
            <div class="subadmin-editor-form-wrapper" style="display: none;"></div>
          </div>
        </div>
      `;

      this.form.insertAdjacentHTML('afterbegin', headerHTML);
      const linksWrapper = this.form.querySelector('#links-wrapper');
      linksWrapper.insertAdjacentHTML('beforebegin', builderHTML);

      this.visualContainer = this.form.querySelector('.subadmin-visual-builder-container');
      this.editorEmpty = this.form.querySelector('.subadmin-editor-empty');
      this.editorWrapper = this.form.querySelector('.subadmin-editor-form-wrapper');

      this.renderVisualItems();
    },

    switchView: function (viewMode) {
      localStorage.setItem('subadmin_toolbar_view_pref', viewMode);

      this.form.querySelectorAll('.subadmin-btn-toggle').forEach(btn => btn.classList.remove('active'));
      const activeBtn = this.form.querySelector(`.subadmin-btn-toggle[data-view="${viewMode}"]`);
      if (activeBtn) activeBtn.classList.add('active');

      const linksWrapper = document.getElementById('links-wrapper');
      if (viewMode === 'visual') {
        if (linksWrapper) linksWrapper.classList.add('subadmin-view-hidden');
        if (this.visualContainer) this.visualContainer.classList.remove('subadmin-view-hidden');
        this.renderVisualItems();
      } else {
        this.syncVisualToClassic();
        if (this.visualContainer) this.visualContainer.classList.add('subadmin-view-hidden');
        if (linksWrapper) linksWrapper.classList.remove('subadmin-view-hidden');
      }
    },

    renderVisualItems: function () {
      const root = this.form.querySelector('#subadmin-drag-root');
      if (root) root.innerHTML = '';

      const items = this.extractItemsFromTable();
      const itemsMap = {};
      const rootItems = [];

      items.forEach(item => {
        itemsMap[item.id] = { ...item, children: [] };
      });

      items.forEach(item => {
        if (item.pid && itemsMap[item.pid]) {
          itemsMap[item.pid].children.push(itemsMap[item.id]);
        } else {
          rootItems.push(itemsMap[item.id]);
        }
      });

      const renderTree = (nodes, targetUl) => {
        nodes.forEach(node => {
          const li = this.createItemElement(node);
          targetUl.appendChild(li);
          if (node.children && node.children.length > 0) {
            const subUl = document.createElement('ul');
            subUl.className = 'subadmin-drag-sublist';
            li.appendChild(subUl);
            renderTree(node.children, subUl);
          }
        });
      };

      if (root) renderTree(rootItems, root);
      this.initDragAndDrop();
    },

    createItemElement: function (node) {
      const isDisabled = node.status === 'disabled';
      const iconType = node.iconType || 'material-symbols-outlined';
      const isFa = (iconType && (iconType.startsWith('fa') || iconType === 'fas' || iconType === 'fab')) || (node.icon && node.icon.startsWith('fa'));

      let iconMarkup = '';
      if (isFa) {
        const faClass = node.icon || 'fas fa-link';
        iconMarkup = `<i class="${faClass}"></i>`;
      } else {
        const matClass = iconType || 'material-symbols-outlined';
        const matName = node.icon || 'link';
        iconMarkup = `<span class="${matClass}">${matName}</span>`;
      }

      const li = document.createElement('li');
      li.className = `subadmin-drag-item ${isDisabled ? 'disabled-item' : ''}`;
      li.setAttribute('data-id', node.id);
      li.setAttribute('data-row-index', node.rowIndex);

      li.innerHTML = `
        <div class="subadmin-drag-item-header">
          <div class="subadmin-drag-item-left">
            <span class="material-symbols-outlined subadmin-drag-handle">drag_indicator</span>
            <span class="subadmin-item-icon">
              ${iconMarkup}
            </span>
            <span class="subadmin-item-title-text">${node.title || '<em>Sin título</em>'}</span>
          </div>
          <div class="subadmin-drag-item-actions">
            <label class="subadmin-toggle-switch" title="Activar/Desactivar">
              <input type="checkbox" class="subadmin-status-toggle" ${!isDisabled ? 'checked' : ''}>
              <span class="subadmin-slider"></span>
            </label>
            <button type="button" class="subadmin-icon-btn subadmin-btn-edit" title="Editar">
              <span class="material-symbols-outlined">edit</span>
            </button>
            <button type="button" class="subadmin-icon-btn subadmin-btn-delete" title="Eliminar">
              <span class="material-symbols-outlined">delete</span>
            </button>
          </div>
        </div>
      `;

      li.nodeData = node;
      return li;
    },

    extractItemsFromTable: function () {
      const items = [];
      if (!this.table) return items;

      const rows = Array.from(this.table.querySelectorAll('tbody > tr'));
      rows.forEach((tr, index) => {
        const idInput = tr.querySelector('.link-id');
        const id = idInput ? idInput.value : null;

        const pidInput = tr.querySelector('.link-pid');
        const pid = pidInput ? pidInput.value : '';

        const titleInput = tr.querySelector('input[name$="[title]"]');
        const title = titleInput ? titleInput.value : '';

        const urlInput = tr.querySelector('input[name$="[url]"]');
        const url = urlInput ? urlInput.value : '';

        const iconTypeInput = tr.querySelector('select[name$="[icon_type]"]');
        const iconType = iconTypeInput ? iconTypeInput.value : '';

        const iconInput = tr.querySelector('input[name$="[icon]"]');
        const icon = iconInput ? iconInput.value : '';

        const weightInput = tr.querySelector('select[name$="[weight]"], input[name$="[weight]"]');
        const weight = weightInput ? weightInput.value : index;

        const statusInput = tr.querySelector('input[name$="[status]"]');
        const status = statusInput ? statusInput.value : 'enabled';

        const roles = [];
        tr.querySelectorAll('input[name*="[roles]"]:checked').forEach(chk => {
          roles.push(chk.value);
        });

        if (id) {
          items.push({
            id: id,
            pid: pid,
            title: title,
            url: url,
            iconType: iconType,
            icon: icon,
            roles: roles,
            weight: parseInt(weight, 10),
            status: status,
            rowIndex: index,
            tr: tr
          });
        }
      });
      return items;
    },

    initDragAndDrop: function () {
      const self = this;
      let draggedItem = null;

      if (!this.visualContainer) return;
      this.visualContainer.querySelectorAll('.subadmin-drag-item').forEach(item => {
        item.setAttribute('draggable', 'true');
      });

      // Remove existing handlers
      const clone = document.body.cloneNode(false);

      // Since we want document level delegates, we should ensure we only bind once
      if (document.subadminDragBound) return;
      document.subadminDragBound = true;

      document.addEventListener('dragstart', function (e) {
        if (!e.target.closest('.subadmin-drag-item')) return;
        const item = e.target.closest('.subadmin-drag-item');
        e.stopPropagation();
        draggedItem = item;
        item.classList.add('dragging');
        if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move';
      });

      document.addEventListener('dragover', function (e) {
        if (!e.target.closest('.subadmin-drag-item') && !e.target.closest('#subadmin-drag-root') && !e.target.closest('.subadmin-drag-sublist')) return;
        e.preventDefault();
        e.stopPropagation();
        if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
      });

      document.addEventListener('drop', function (e) {
        let target = e.target;
        if (!target.closest('.subadmin-drag-item') && !target.closest('#subadmin-drag-root') && !target.closest('.subadmin-drag-sublist')) return;

        e.preventDefault();
        e.stopPropagation();

        if (!draggedItem) return;

        if (!target.classList.contains('subadmin-drag-item') && target.id !== 'subadmin-drag-root' && !target.classList.contains('subadmin-drag-sublist')) {
          target = target.closest('.subadmin-drag-item');
        }

        if (!target) return;

        if (target === draggedItem || draggedItem.contains(target)) {
          return;
        }

        if (target.id === 'subadmin-drag-root' || target.classList.contains('subadmin-drag-sublist')) {
          target.appendChild(draggedItem);
        } else if (target.classList.contains('subadmin-drag-item')) {
          const header = target.querySelector(':scope > .subadmin-drag-item-header') || target;
          const rect = header.getBoundingClientRect();
          const next = (e.clientY - rect.top) / rect.height > 0.5;
          const isOffsetRight = (e.clientX - rect.left) > 40;

          if (isOffsetRight) {
            let sublist = target.querySelector(':scope > .subadmin-drag-sublist');
            if (!sublist) {
              sublist = document.createElement('ul');
              sublist.className = 'subadmin-drag-sublist';
              target.appendChild(sublist);
            }
            sublist.appendChild(draggedItem);
          } else {
            if (next) {
              target.after(draggedItem);
            } else {
              target.before(draggedItem);
            }
          }
        }

        self.updateHierarchyFromDOM();
        self.syncVisualToClassic();
      });

      document.addEventListener('dragend', function (e) {
        if (!e.target.closest('.subadmin-drag-item')) return;
        if (draggedItem) {
          draggedItem.classList.remove('dragging');
        }
        draggedItem = null;
      });
    },

    updateHierarchyFromDOM: function () {
      let globalWeight = 0;

      const processList = (ul, pid = '') => {
        const items = ul.querySelectorAll(':scope > .subadmin-drag-item');
        items.forEach(li => {
          const nodeData = li.nodeData;
          if (nodeData) {
            nodeData.pid = pid;
            nodeData.weight = globalWeight++;
            li.nodeData = nodeData;
          }

          const subUl = li.querySelector(':scope > .subadmin-drag-sublist');
          if (subUl) {
            if (subUl.children.length === 0) {
              subUl.remove();
            } else {
              processList(subUl, nodeData.id);
            }
          }
        });
      };

      const root = document.getElementById('subadmin-drag-root');
      if (root) processList(root);
    },

    bindEvents: function () {
      const self = this;

      if (!this.form.subadminEventsBound) {
        this.form.addEventListener('click', function (e) {
          const btn = e.target.closest('.subadmin-btn-toggle');
          if (btn) {
            const view = btn.getAttribute('data-view');
            self.switchView(view);
          }
        });

        // Add item
        document.addEventListener('click', function (e) {
          const btn = e.target.closest('.subadmin-btn-add-item');
          if (!btn) return;

          e.preventDefault();
          e.stopPropagation();

          self.wasAddingItem = true;
          self.syncVisualToClassic();

          const linksWrapper = document.getElementById('links-wrapper');
          let addBtn = linksWrapper.querySelector('.subadmin-btn-add-row, input[name="add_link_button"]');

          if (!addBtn) {
            const submits = Array.from(linksWrapper.querySelectorAll('input[type="submit"]'));
            addBtn = submits.find(el => {
              const val = (el.value || '').toLowerCase();
              return val.includes('add') || val.includes('añadir');
            });
            if (!addBtn && submits.length) addBtn = submits[submits.length - 1];
          }

          if (addBtn) {
            addBtn.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
            addBtn.click();
          }
        });

        this.form.subadminEventsBound = true;
      }

      if (this.visualContainer && !this.visualContainer.subadminEventsBound) {
        this.visualContainer.addEventListener('change', function (e) {
          const toggle = e.target.closest('.subadmin-status-toggle');
          if (toggle) {
            const li = toggle.closest('.subadmin-drag-item');
            const isChecked = toggle.checked;
            const newStatus = isChecked ? 'enabled' : 'disabled';

            const toggleItemAndDescendants = (itemLi, state, checked) => {
              const nodeData = itemLi.nodeData;
              if (nodeData) {
                nodeData.status = state;
                itemLi.nodeData = nodeData;
              }
              itemLi.classList.toggle('disabled-item', !checked);

              const headerToggle = itemLi.querySelector(':scope > .subadmin-drag-item-header .subadmin-status-toggle');
              if (headerToggle) headerToggle.checked = checked;

              itemLi.querySelectorAll(':scope > .subadmin-drag-sublist > .subadmin-drag-item').forEach(childLi => {
                toggleItemAndDescendants(childLi, state, checked);
              });
            };

            toggleItemAndDescendants(li, newStatus, isChecked);
            self.syncVisualToClassic();
          }
        });

        this.visualContainer.addEventListener('click', function (e) {
          const editBtn = e.target.closest('.subadmin-btn-edit') || e.target.closest('.subadmin-item-title-text');
          if (editBtn) {
            e.stopPropagation();
            const li = editBtn.closest('.subadmin-drag-item');
            self.openEditor(li);
            return;
          }

          const delBtn = e.target.closest('.subadmin-btn-delete');
          if (delBtn) {
            e.stopPropagation();
            const li = delBtn.closest('.subadmin-drag-item');
            const nodeData = li ? li.nodeData : null;
            const title = (nodeData && nodeData.title) ? nodeData.title : 'este enlace';

            if (confirm(`¿Seguro que deseas eliminar ${title}?`)) {
              if (nodeData && nodeData.tr) {
                const removeBtn = nodeData.tr.querySelector('.subadmin-btn-remove-row');
                if (removeBtn) {
                  removeBtn.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
                  removeBtn.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
                }
              }
            }
          }
        });

        this.visualContainer.subadminEventsBound = true;
      }
    },

    openEditor: function (li) {
      const self = this;
      const nodeData = li.nodeData;
      this.activeEditLi = li;

      if (this.editorEmpty) this.editorEmpty.style.display = 'none';
      if (this.editorWrapper) {
        this.editorWrapper.innerHTML = '';
        this.editorWrapper.style.display = 'block';
      }

      const row = nodeData.tr;

      const titleInput = row.querySelector('input[name$="[title]"]');
      const urlInput = row.querySelector('input[name$="[url]"]');
      const iconTypeInput = row.querySelector('select[name$="[icon_type]"]');
      const iconInput = row.querySelector('input[name$="[icon]"]');

      const title = titleInput ? titleInput.value : '';
      const url = urlInput ? urlInput.value : '';
      const iconType = iconTypeInput ? iconTypeInput.value : '';
      const icon = iconInput ? iconInput.value : '';

      let rolesHTML = '';
      row.querySelectorAll('input[name*="[roles]"]').forEach(chk => {
        const roleId = chk.value;
        const labelEl = chk.nextElementSibling;
        const roleLabel = (labelEl && labelEl.tagName.toLowerCase() === 'label') ? labelEl.textContent : roleId;
        const isChecked = chk.checked;

        rolesHTML += `
          <label class="subadmin-role-item">
            <input type="checkbox" class="subadmin-editor-role" value="${roleId}" ${isChecked ? 'checked' : ''}>
            <span>${roleLabel}</span>
          </label>
        `;
      });

      // Only show installed/enabled font families
      let iconOptionsHTML = '';
      const availableTypes = (window.drupalSettings && drupalSettings.subadminToolbar && drupalSettings.subadminToolbar.availableIconTypes) || null;

      if (availableTypes && typeof availableTypes === 'object') {
        Object.entries(availableTypes).forEach(([val, label]) => {
          const selected = (iconType === val || (!iconType && val === 'material-symbols-outlined')) ? 'selected' : '';
          iconOptionsHTML += `<option value="${val}" ${selected}>${label}</option>`;
        });
      } else if (iconTypeInput && iconTypeInput.options && iconTypeInput.options.length > 0) {
        Array.from(iconTypeInput.options).forEach(opt => {
          const selected = (iconType === opt.value || (!iconType && opt.value === 'material-symbols-outlined')) ? 'selected' : '';
          iconOptionsHTML += `<option value="${opt.value}" ${selected}>${opt.text}</option>`;
        });
      } else {
        iconOptionsHTML = `
          <option value="material-symbols-outlined" ${(!iconType || iconType === 'material-symbols-outlined') ? 'selected' : ''}>Material Symbols Outlined</option>
          <option value="material-icons" ${iconType === 'material-icons' ? 'selected' : ''}>Material Icons</option>
        `;
      }

      const editorFormHTML = `
        <div class="subadmin-editor-form">
          <h4 style="margin:0 0 0.5rem 0; color:var(--subadmin-text); display:flex; align-items:center; gap:6px;">
            <span class="material-symbols-outlined" style="font-size: 1.25rem;">edit</span>
            <span>${title || 'Nuevo Enlace'}</span>
          </h4>
          <div class="subadmin-form-group">
            <label>Título del Enlace</label>
            <input type="text" class="subadmin-editor-title" value="${title || ''}">
          </div>
          <div class="subadmin-form-group">
            <label>URL o Ruta de Drupal</label>
            <input type="text" class="subadmin-editor-url" value="${url || ''}" placeholder="/admin/content">
          </div>
          <div class="subadmin-form-group">
            <label>Familia de Icono</label>
            <select class="subadmin-editor-icon-type">
              ${iconOptionsHTML}
            </select>
          </div>
          <div class="subadmin-form-group">
            <label>Nombre del Icono</label>
            <input type="text" class="subadmin-editor-icon" value="${icon || ''}" placeholder="settings / home / calendar_month">
          </div>
          <div class="subadmin-form-group">
            <label>Roles Permitidos</label>
            <div class="subadmin-roles-column">${rolesHTML}</div>
          </div>
        </div>
      `;

      if (this.editorWrapper) {
        this.editorWrapper.innerHTML = editorFormHTML;

        this.editorWrapper.querySelectorAll('input, select').forEach(el => {
          el.addEventListener('input', function () { self.applyEditorChanges(); });
          el.addEventListener('change', function () { self.applyEditorChanges(); });
        });
      }
    },

    applyEditorChanges: function () {
      if (!this.activeEditLi) return;
      const li = this.activeEditLi;
      const nodeData = li.nodeData;
      const row = nodeData.tr;

      const title = this.editorWrapper.querySelector('.subadmin-editor-title').value;
      const url = this.editorWrapper.querySelector('.subadmin-editor-url').value;
      const iconType = this.editorWrapper.querySelector('.subadmin-editor-icon-type').value;
      const icon = this.editorWrapper.querySelector('.subadmin-editor-icon').value;

      nodeData.title = title;
      nodeData.url = url;
      nodeData.iconType = iconType;
      nodeData.icon = icon;

      const header = li.querySelector(':scope > .subadmin-drag-item-header');
      if (header) {
        const titleText = header.querySelector('.subadmin-item-title-text');
        if (titleText) titleText.innerHTML = title || '<em>Sin título</em>';

        const isFa = (iconType && (iconType.startsWith('fa') || iconType === 'fas' || iconType === 'fab')) || (icon && icon.startsWith('fa'));
        const iconContainer = header.querySelector('.subadmin-item-icon');

        if (iconContainer) {
          if (isFa) {
            const faClass = icon || 'fas fa-link';
            iconContainer.innerHTML = `<i class="${faClass}"></i>`;
          } else {
            const matClass = iconType || 'material-symbols-outlined';
            const matName = icon || 'link';
            iconContainer.innerHTML = `<span class="${matClass}">${matName}</span>`;
          }
        }
      }

      const titleInput = row.querySelector('input[name$="[title]"]');
      if (titleInput) titleInput.value = title;

      const urlInput = row.querySelector('input[name$="[url]"]');
      if (urlInput) urlInput.value = url;

      const iconTypeInput = row.querySelector('select[name$="[icon_type]"]');
      if (iconTypeInput) iconTypeInput.value = iconType;

      const iconInput = row.querySelector('input[name$="[icon]"]');
      if (iconInput) iconInput.value = icon;

      this.editorWrapper.querySelectorAll('.subadmin-editor-role').forEach(chk => {
        const roleVal = chk.value;
        const isChecked = chk.checked;
        const rowChk = row.querySelector(`input[name*="[roles]"][value="${roleVal}"]`);
        if (rowChk) rowChk.checked = isChecked;
      });

      li.nodeData = nodeData;
    },

    syncVisualToClassic: function () {
      this.updateHierarchyFromDOM();

      const tbody = this.table ? this.table.querySelector('tbody') : null;
      if (!tbody || !this.visualContainer) return;

      this.visualContainer.querySelectorAll('.subadmin-drag-item').forEach(li => {
        const nodeData = li.nodeData;
        const row = nodeData.tr;

        if (row) {
          const pidInput = row.querySelector('.link-pid');
          if (pidInput) pidInput.value = nodeData.pid;

          const statusInput = row.querySelector('input[name$="[status]"]');
          if (statusInput) statusInput.value = nodeData.status || 'enabled';

          const weightField = row.querySelector('.link-weight');
          if (weightField) {
            weightField.value = nodeData.weight;
            // Trigger change event just in case
            weightField.dispatchEvent(new Event('change'));
          }

          tbody.appendChild(row);
        }
      });
    }
  };

})(Drupal, drupalSettings);
