<?php

declare(strict_types=1);

namespace Drupal\subadmin_toolbar;

/**
 * Validates the appearance settings and maps them to CSS custom properties.
 *
 * Every value is checked against a closed list or a hex color pattern, so
 * only known-safe strings can reach the inline style of the toolbar.
 */
final class Appearance {

  /**
   * The color keys, in the order they are shown.
   */
  public const COLOR_KEYS = ['bg', 'text', 'hover_bg', 'submenu_bg', 'border'];

  /**
   * The color schemes with their own set of colors.
   */
  public const MODES = ['light', 'dark'];

  /**
   * The colors the stylesheet applies when a color is left to inherit.
   */
  public const COLOR_DEFAULTS = [
    'light' => [
      'bg' => '#ffffff',
      'text' => '#000000',
      'hover_bg' => '#eef1f5',
      'submenu_bg' => '#ffffff',
      'border' => '#e6e6e6',
    ],
    'dark' => [
      'bg' => '#111111',
      'text' => '#ffffff',
      'hover_bg' => '#222222',
      'submenu_bg' => '#111111',
      'border' => '#333333',
    ],
  ];

  /**
   * The allowed values of each closed-list option.
   *
   * The first entry of font_family, radius and border_bottom, and the
   * defaults() value of the others, emit nothing.
   */
  public const CHOICES = [
    'height' => [40, 44, 48, 52, 56, 60, 64, 68, 72, 76, 80],
    'item_padding' => [8, 10, 12, 14, 16, 18, 20, 22, 24],
    'submenu_min_width' => [180, 200, 220, 240, 260, 280, 300, 320],
    'font_family' => ['default', 'system', 'humanist', 'serif', 'mono'],
    'font_size' => [12, 13, 14, 15, 16, 17, 18],
    'font_weight' => [300, 400, 500, 600, 700],
    'letter_spacing' => ['-0.01em', '0', '0.01em', '0.02em', '0.04em'],
    'icon_size' => [16, 18, 20, 22, 24, 26, 28, 30, 32],
    'icon_opacity' => ['0.4', '0.5', '0.6', '0.7', '0.8', '0.9', '1'],
    'radius' => ['default', 'square', 'xs', 'sm', 'soft', 'md', 'round', 'lg', 'xl'],
    'shadow' => ['none', 'soft', 'medium', 'strong'],
    'border_bottom' => ['none', 'line'],
    'open_delay' => [0, 50, 100, 150, 200, 300, 400],
    'close_delay' => [0, 100, 200, 300, 400, 600, 800],
    'indicator' => ['caret', 'plus_minus', 'none'],
    'position' => ['top', 'left', 'right'],
    'sidebar_mode' => ['fixed', 'collapsible'],
    'sidebar_width' => [220, 240, 260, 280, 300, 320, 340, 360],
  ];

  /**
   * The font stack of each non-default font family choice.
   *
   * Only fonts that are already on the visitor's system are listed, so no
   * font file is ever loaded.
   */
  public const FONTS = [
    'system' => 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
    'humanist' => '"Segoe UI", Candara, "Gill Sans", Optima, "Trebuchet MS", sans-serif',
    'serif' => 'Georgia, "Times New Roman", Times, serif',
    'mono' => 'ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace',
  ];

  /**
   * The corner radius of each radius choice (the default emits nothing).
   */
  public const RADIUS = [
    'square' => '0px',
    'xs' => '2px',
    'sm' => '4px',
    'soft' => '6px',
    'md' => '8px',
    'round' => '12px',
    'lg' => '16px',
    'xl' => '20px',
  ];

  /**
   * The toolbar and submenu shadows of each non-default shadow choice.
   */
  public const SHADOWS = [
    'none' => ['none', 'none'],
    'medium' => ['0 5px 10px -3px rgba(0, 0, 0, 0.55)', '0 6px 16px rgba(0, 0, 0, 0.5)'],
    'strong' => ['0 6px 12px -3px rgba(0, 0, 0, 0.6)', '0 8px 20px rgba(0, 0, 0, 0.55)'],
  ];

  /**
   * The colors the text must contrast with, in the order they are checked.
   */
  public const CONTRAST_BACKGROUNDS = ['bg', 'hover_bg', 'submenu_bg'];

  /**
   * Returns the default value of every option.
   *
   * @return array
   *   The defaults, which render the same as the stylesheet without options.
   */
  public static function defaults(): array {
    return [
      'height' => 56,
      'item_padding' => 16,
      'submenu_min_width' => 220,
      'font_family' => 'default',
      'font_size' => 14,
      'font_weight' => 500,
      'letter_spacing' => '0',
      'icon_size' => 24,
      'icon_opacity' => '0.7',
      'radius' => 'default',
      'shadow' => 'soft',
      'border_bottom' => 'none',
      'colors' => [
        'light' => array_fill_keys(self::COLOR_KEYS, ''),
        'dark' => array_fill_keys(self::COLOR_KEYS, ''),
      ],
      'open_delay' => 0,
      'close_delay' => 0,
      'show_submenu_icons' => TRUE,
      'indicator' => 'caret',
      'position' => 'top',
      'sidebar_mode' => 'fixed',
      'sidebar_width' => 260,
      'sidebar_start_collapsed' => FALSE,
    ];
  }

  /**
   * Validates stored or submitted appearance values.
   *
   * @param mixed $raw
   *   The raw values; anything that is not valid falls back to its default.
   *
   * @return array
   *   The values, with the same structure and types as defaults().
   */
  public static function normalize(mixed $raw): array {
    $raw = is_array($raw) ? $raw : [];
    $values = self::defaults();

    foreach (self::CHOICES as $key => $allowed) {
      $values[$key] = self::pick($raw[$key] ?? NULL, $allowed, $values[$key]);
    }

    foreach (self::MODES as $mode) {
      foreach (self::COLOR_KEYS as $color) {
        $color_value = $raw['colors'][$mode][$color] ?? '';
        $values['colors'][$mode][$color] = is_string($color_value) && preg_match('/^#[0-9a-f]{6}$/i', $color_value)
          ? strtolower($color_value)
          : '';
      }
    }

    foreach (['show_submenu_icons', 'sidebar_start_collapsed'] as $flag) {
      $value = $raw[$flag] ?? NULL;
      if (is_bool($value) || in_array($value, [0, 1, '0', '1'], TRUE)) {
        $values[$flag] = (bool) $value;
      }
    }

    return $values;
  }

  /**
   * Maps validated values to CSS custom properties.
   *
   * Options left at their default are not emitted, so the stylesheet defaults
   * keep applying.
   *
   * @param array $values
   *   The output of normalize().
   *
   * @return string[]
   *   The custom property values, keyed by property name.
   */
  public static function styleVars(array $values): array {
    $defaults = self::defaults();
    $vars = [];

    $units = [
      'height' => ['--subadmin-toolbar-height', 'px'],
      'item_padding' => ['--subadmin-toolbar-item-padding', 'px'],
      'submenu_min_width' => ['--subadmin-toolbar-submenu-min-width', 'px'],
      'font_size' => ['--subadmin-toolbar-font-size', 'px'],
      'font_weight' => ['--subadmin-toolbar-font-weight', ''],
      'letter_spacing' => ['--subadmin-toolbar-letter-spacing', ''],
      'icon_size' => ['--subadmin-toolbar-icon-size', 'px'],
      'icon_opacity' => ['--subadmin-toolbar-icon-opacity', ''],
      'open_delay' => ['--subadmin-toolbar-open-delay', 'ms'],
      'close_delay' => ['--subadmin-toolbar-close-delay', 'ms'],
    ];
    if ($values['position'] !== 'top') {
      $units['sidebar_width'] = ['--subadmin-toolbar-sidebar-width', 'px'];
    }
    foreach ($units as $key => [$property, $unit]) {
      if ($values[$key] !== $defaults[$key]) {
        $vars[$property] = $values[$key] . $unit;
      }
    }

    if (isset(self::FONTS[$values['font_family']])) {
      $vars['--subadmin-toolbar-font'] = self::FONTS[$values['font_family']];
    }
    if ($values['border_bottom'] === 'line') {
      $vars['--subadmin-toolbar-border-bottom'] = '1px solid var(--subadmin-toolbar-border)';
    }
    if (isset(self::RADIUS[$values['radius']])) {
      $vars['--subadmin-toolbar-radius'] = self::RADIUS[$values['radius']];
    }
    if (isset(self::SHADOWS[$values['shadow']])) {
      [$vars['--subadmin-toolbar-shadow'], $vars['--subadmin-toolbar-submenu-shadow']] = self::SHADOWS[$values['shadow']];
    }

    foreach (self::MODES as $mode) {
      foreach (self::COLOR_KEYS as $color) {
        if ($values['colors'][$mode][$color] !== '') {
          $vars['--subadmin-toolbar-' . $mode . '-' . str_replace('_', '-', $color)] = $values['colors'][$mode][$color];
        }
      }
    }

    return $vars;
  }

  /**
   * Finds the text and background colors that contrast too little.
   *
   * @param array $values
   *   The output of normalize().
   *
   * @return array[]
   *   One entry per failing pair, with the keys mode, background (a color
   *   key) and ratio.
   */
  public static function contrastIssues(array $values): array {
    $issues = [];
    foreach (self::MODES as $mode) {
      $colors = array_filter($values['colors'][$mode], static fn (string $color): bool => $color !== '') + self::COLOR_DEFAULTS[$mode];
      foreach (self::CONTRAST_BACKGROUNDS as $background) {
        $ratio = ColorContrast::ratio($colors['text'], $colors[$background]);
        if ($ratio !== NULL && $ratio < ColorContrast::MINIMUM_TEXT_RATIO) {
          $issues[] = ['mode' => $mode, 'background' => $background, 'ratio' => $ratio];
        }
      }
    }
    return $issues;
  }

  /**
   * Returns the value of the closed list that matches, or the default.
   */
  protected static function pick(mixed $value, array $allowed, int|string $default): int|string {
    if (!is_scalar($value) || is_bool($value)) {
      return $default;
    }
    foreach ($allowed as $candidate) {
      if ((string) $candidate === (string) $value) {
        return $candidate;
      }
    }
    return $default;
  }

}
