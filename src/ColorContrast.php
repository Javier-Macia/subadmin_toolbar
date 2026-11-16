<?php

declare(strict_types=1);

namespace Drupal\subadmin_toolbar;

/**
 * Calculates WCAG contrast ratios between hex colors.
 */
final class ColorContrast {

  /**
   * The minimum ratio of WCAG AA for normal text.
   */
  public const MINIMUM_TEXT_RATIO = 4.5;

  /**
   * Calculates the contrast ratio of two #rrggbb colors.
   *
   * @return float|null
   *   A value between 1 and 21, or NULL when a color is not a valid #rrggbb.
   */
  public static function ratio(string $first, string $second): ?float {
    $a = self::luminance($first);
    $b = self::luminance($second);
    if ($a === NULL || $b === NULL) {
      return NULL;
    }
    return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
  }

  /**
   * Calculates the relative luminance of a #rrggbb color.
   */
  public static function luminance(string $hex): ?float {
    if (!preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $m)) {
      return NULL;
    }
    $luminance = 0.0;
    foreach ([0.2126, 0.7152, 0.0722] as $i => $weight) {
      $c = hexdec($m[$i + 1]) / 255;
      $luminance += $weight * ($c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4);
    }
    return $luminance;
  }

}
