/**
 * @file
 * Live preview of the appearance options of the general settings form.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  var COLOR_KEYS = ['bg', 'text', 'hover_bg', 'submenu_bg', 'border'];
  var GLYPHS = { caret: 'expand_more', plus_minus: 'add', none: '' };

  /** The numeric options: field name, preview variable and unit. */
  var UNITS = [
    ['dimensions][height', '--sat-preview-height', 'px'],
    ['dimensions][item_padding', '--sat-preview-item-padding', 'px'],
    ['typography][font_size', '--sat-preview-font-size', 'px'],
    ['typography][font_weight', '--sat-preview-font-weight', ''],
    ['typography][letter_spacing', '--sat-preview-letter-spacing', ''],
    ['dimensions][icon_size', '--sat-preview-icon-size', 'px'],
    ['dimensions][icon_opacity', '--sat-preview-icon-opacity', '']
  ];

  /** The text color is checked against each of these background colors. */
  var CONTRAST_KEYS = ['bg', 'hover_bg', 'submenu_bg'];

  function field(form, name) {
    return form.querySelector('[name="appearance[' + name + ']"]');
  }

  function setVar(el, name, value) {
    if (value === null || value === '') {
      el.style.removeProperty(name);
    }
    else {
      el.style.setProperty(name, value);
    }
  }

  function update(form, previews) {
    var settings = drupalSettings.subadminToolbarPreview || {};
    var radius = field(form, 'shape][radius');
    var shadow = field(form, 'shape][shadow');
    var line = field(form, 'shape][border_bottom');
    var family = field(form, 'typography][font_family');
    var indicator = field(form, 'indicator_group][indicator');
    var icons = field(form, 'hover][show_submenu_icons');

    previews.forEach(function (preview) {
      var mode = preview.getAttribute('data-preview-mode');

      UNITS.forEach(function (unit) {
        var input = field(form, unit[0]);
        setVar(preview, unit[1], input ? input.value + unit[2] : null);
      });

      setVar(preview, '--sat-preview-radius', radius && settings.radius ? settings.radius[radius.value] || null : null);
      setVar(preview, '--sat-preview-shadow', shadow && settings.shadow ? settings.shadow[shadow.value] || null : null);

      setVar(preview, '--sat-preview-font', family && settings.fonts ? settings.fonts[family.value] || null : null);
      preview.classList.toggle('sat-preview--line', !!line && line.value === 'line');

      COLOR_KEYS.forEach(function (key) {
        var base = 'colors][' + mode + '][' + key + '][';
        var inherit = field(form, base + 'inherit');
        var input = field(form, base + 'value');
        var custom = input && inherit && !inherit.checked ? input.value : null;
        setVar(preview, '--sat-preview-' + key.replace('_', '-'), custom);
      });

      var glyph = GLYPHS[indicator ? indicator.value : 'caret'];
      preview.querySelectorAll('.sat-preview__caret').forEach(function (caret) {
        caret.textContent = glyph === undefined ? GLYPHS.caret : glyph;
      });
      preview.classList.toggle('sat-preview--no-icons', !!icons && !icons.checked);
    });
  }

  /** The effective color of a mode: the custom one, or the stylesheet default. */
  function effectiveColor(form, settings, mode, key) {
    var base = 'colors][' + mode + '][' + key + '][';
    var inherit = field(form, base + 'inherit');
    var input = field(form, base + 'value');
    if (input && inherit && !inherit.checked && /^#[0-9a-f]{6}$/i.test(input.value)) {
      return input.value.toLowerCase();
    }
    return (settings.colorDefaults[mode] || {})[key];
  }

  function luminance(hex) {
    var total = 0;
    [0.2126, 0.7152, 0.0722].forEach(function (weight, i) {
      var c = parseInt(hex.substr(1 + i * 2, 2), 16) / 255;
      total += weight * (c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4));
    });
    return total;
  }

  function ratio(first, second) {
    var a = luminance(first);
    var b = luminance(second);
    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
  }

  /** Lists, without blocking, the text/background pairs below the minimum. */
  function updateContrast(form, hint) {
    var settings = drupalSettings.subadminToolbarPreview || {};
    if (!settings.colorDefaults) return;
    var messages = [];
    Object.keys(settings.colorDefaults).forEach(function (mode) {
      var text = effectiveColor(form, settings, mode, 'text');
      CONTRAST_KEYS.forEach(function (key) {
        var background = effectiveColor(form, settings, mode, key);
        if (!text || !background) return;
        var value = ratio(text, background);
        if (value < settings.minimumRatio) {
          messages.push(Drupal.formatString(settings.contrastMessage, {
            '@mode': settings.modeNames[mode],
            '@ratio': value.toFixed(2),
            '@pair': settings.pairNames[key]
          }));
        }
      });
    });
    hint.textContent = '';
    messages.forEach(function (message) {
      var line = document.createElement('p');
      line.textContent = message;
      hint.appendChild(line);
    });
    hint.hidden = messages.length === 0;
  }

  Drupal.behaviors.subadminToolbarReset = {
    attach: function (context) {
      once('sat-reset', '.subadmin-reset-appearance', context).forEach(function (button) {
        button.addEventListener('click', function (e) {
          if (!window.confirm(button.getAttribute('data-confirm'))) {
            e.preventDefault();
          }
        });
      });
    }
  };

  Drupal.behaviors.subadminToolbarPreview = {
    attach: function (context) {
      once('sat-preview', '.subadmin-toolbar-preview-group', context).forEach(function (group) {
        var form = group.closest('form');
        if (!form) return;
        var previews = Array.from(group.querySelectorAll('.sat-preview'));
        var hint = form.querySelector('.subadmin-contrast-hint');
        var refresh = function () {
          update(form, previews);
          if (hint) updateContrast(form, hint);
        };
        form.addEventListener('input', refresh);
        form.addEventListener('change', refresh);
        refresh();
      });
    }
  };

})(Drupal, drupalSettings, once);
