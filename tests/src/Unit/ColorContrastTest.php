<?php

declare(strict_types=1);

namespace Drupal\Tests\subadmin_toolbar\Unit;

use Drupal\subadmin_toolbar\ColorContrast;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the WCAG contrast helper.
 */
#[Group('subadmin_toolbar')]
class ColorContrastTest extends UnitTestCase {

  /**
   * Known ratios are calculated, whatever the order of the colors.
   */
  #[DataProvider('ratioProvider')]
  public function testRatio(string $first, string $second, float $expected): void {
    $this->assertEqualsWithDelta($expected, ColorContrast::ratio($first, $second), 0.01);
    $this->assertEqualsWithDelta($expected, ColorContrast::ratio($second, $first), 0.01);
  }

  /**
   * Provides color pairs and their ratio.
   */
  public static function ratioProvider(): array {
    return [
      'black on white' => ['#000000', '#ffffff', 21.0],
      'same color' => ['#336699', '#336699', 1.0],
      'grey on white' => ['#777777', '#ffffff', 4.48],
      'uppercase' => ['#FFFFFF', '#000000', 21.0],
    ];
  }

  /**
   * Values that are not #rrggbb give no ratio.
   */
  #[DataProvider('invalidProvider')]
  public function testInvalidColor(string $invalid): void {
    $this->assertNull(ColorContrast::ratio($invalid, '#ffffff'));
    $this->assertNull(ColorContrast::ratio('#ffffff', $invalid));
  }

  /**
   * Provides values that are not valid colors.
   */
  public static function invalidProvider(): array {
    return [
      'empty' => [''],
      'short hex' => ['#fff'],
      'name' => ['red'],
      'no hash' => ['ffffff'],
      'bad digit' => ['#12345g'],
    ];
  }

  /**
   * The minimum ratio constant is the WCAG AA value for normal text.
   */
  public function testMinimumRatio(): void {
    $this->assertSame(4.5, ColorContrast::MINIMUM_TEXT_RATIO);
    $this->assertLessThan(ColorContrast::MINIMUM_TEXT_RATIO, ColorContrast::ratio('#777777', '#ffffff'));
    $this->assertGreaterThan(ColorContrast::MINIMUM_TEXT_RATIO, ColorContrast::ratio('#595959', '#ffffff'));
  }

}
