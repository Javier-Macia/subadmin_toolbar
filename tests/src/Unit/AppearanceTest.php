<?php

declare(strict_types=1);

namespace Drupal\Tests\subadmin_toolbar\Unit;

use Drupal\subadmin_toolbar\Appearance;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the validation of the appearance values.
 */
#[Group('subadmin_toolbar')]
class AppearanceTest extends UnitTestCase {

  /**
   * Anything that is not on a closed list falls back to the default.
   */
  public function testGarbageFallsBack(): void {
    $values = Appearance::normalize([
      'height' => 'red; }',
      'font_size' => [],
      'font_weight' => TRUE,
      'icon_opacity' => '0.55',
      'item_padding' => 15,
      'submenu_min_width' => 'wide',
      'font_family' => 'Comic Sans; }',
      'letter_spacing' => '5em',
      'border_bottom' => 'url(x)',
      'radius' => '50%',
      'shadow' => 'url(x)',
      'show_submenu_icons' => 'maybe',
      'indicator' => '<script>',
      'colors' => ['light' => ['bg' => 'javascript:x', 'text' => '#12345']],
    ]);

    $this->assertSame(Appearance::defaults(), $values);
    $this->assertSame([], Appearance::styleVars($values));
  }

  /**
   * Non-array input gives the defaults.
   */
  public function testNonArrayInput(): void {
    $this->assertSame(Appearance::defaults(), Appearance::normalize(NULL));
    $this->assertSame(Appearance::defaults(), Appearance::normalize('x'));
  }

  /**
   * Numeric strings from a submitted form match the closed lists.
   */
  public function testSubmittedStringsAreAccepted(): void {
    $values = Appearance::normalize([
      'height' => '48',
      'open_delay' => '100',
      'icon_opacity' => '0.6',
      'show_submenu_icons' => '0',
      'colors' => ['dark' => ['border' => '#AABBCC']],
    ]);

    $this->assertSame(48, $values['height']);
    $this->assertSame(100, $values['open_delay']);
    $this->assertFalse($values['show_submenu_icons']);
    $this->assertSame(
      [
        '--subadmin-toolbar-height' => '48px',
        '--subadmin-toolbar-icon-opacity' => '0.6',
        '--subadmin-toolbar-open-delay' => '100ms',
        '--subadmin-toolbar-dark-border' => '#aabbcc',
      ],
      Appearance::styleVars($values),
    );
  }

  /**
   * Every widened and new option accepts all of its listed values.
   */
  public function testEveryChoiceIsAccepted(): void {
    foreach (Appearance::CHOICES as $key => $allowed) {
      foreach ($allowed as $choice) {
        $values = Appearance::normalize([$key => (string) $choice]);
        $this->assertSame($choice, $values[$key], "$key accepts $choice");
      }
    }
  }

  /**
   * The ranges requested for the numeric options are complete.
   */
  public function testRanges(): void {
    $this->assertSame(range(40, 80, 4), Appearance::CHOICES['height']);
    $this->assertSame(range(12, 18), Appearance::CHOICES['font_size']);
    $this->assertSame(range(300, 700, 100), Appearance::CHOICES['font_weight']);
    $this->assertSame(range(16, 32, 2), Appearance::CHOICES['icon_size']);
    $this->assertSame(range(8, 24, 2), Appearance::CHOICES['item_padding']);
    $this->assertSame(range(180, 320, 20), Appearance::CHOICES['submenu_min_width']);
    $this->assertSame(['0.4', '0.5', '0.6', '0.7', '0.8', '0.9', '1'], Appearance::CHOICES['icon_opacity']);
    $this->assertSame([], array_diff(array_keys(Appearance::CHOICES), array_keys(Appearance::defaults())), 'Reset restores every closed-list option.');
    foreach (Appearance::CHOICES as $key => $allowed) {
      $this->assertContains(Appearance::defaults()[$key], $allowed, "The default of $key is a choice.");
      $this->assertLessThanOrEqual(11, count($allowed), "$key keeps its select list short.");
    }
  }

  /**
   * Every non-default choice of the new options reaches its variable.
   */
  public function testNewOptionsMapToVariables(): void {
    $vars = Appearance::styleVars(Appearance::normalize([
      'item_padding' => '24',
      'submenu_min_width' => '320',
      'font_family' => 'mono',
      'letter_spacing' => '0.04em',
      'border_bottom' => 'line',
      'radius' => 'xl',
      'shadow' => 'medium',
    ]));

    $this->assertSame('24px', $vars['--subadmin-toolbar-item-padding']);
    $this->assertSame('320px', $vars['--subadmin-toolbar-submenu-min-width']);
    $this->assertSame(Appearance::FONTS['mono'], $vars['--subadmin-toolbar-font']);
    $this->assertSame('0.04em', $vars['--subadmin-toolbar-letter-spacing']);
    $this->assertSame('1px solid var(--subadmin-toolbar-border)', $vars['--subadmin-toolbar-border-bottom']);
    $this->assertSame('20px', $vars['--subadmin-toolbar-radius']);
    $this->assertSame(Appearance::SHADOWS['medium'][0], $vars['--subadmin-toolbar-shadow']);
    $this->assertSame(Appearance::SHADOWS['medium'][1], $vars['--subadmin-toolbar-submenu-shadow']);
  }

  /**
   * Radius keys stored by the previous version keep their meaning.
   */
  public function testPreviousRadiusKeysStillWork(): void {
    $this->assertSame('6px', Appearance::styleVars(Appearance::normalize(['radius' => 'soft']))['--subadmin-toolbar-radius']);
    $this->assertSame('12px', Appearance::styleVars(Appearance::normalize(['radius' => 'round']))['--subadmin-toolbar-radius']);
    $this->assertSame('0px', Appearance::styleVars(Appearance::normalize(['radius' => 'square']))['--subadmin-toolbar-radius']);
  }

  /**
   * The contrast check covers the hover and submenu backgrounds per mode.
   */
  public function testContrastIssues(): void {
    $this->assertSame([], Appearance::contrastIssues(Appearance::defaults()));

    $issues = Appearance::contrastIssues(Appearance::normalize([
      'colors' => ['dark' => ['hover_bg' => '#ffffff', 'submenu_bg' => '#eeeeee']],
    ]));
    $this->assertCount(2, $issues);
    $this->assertSame(['dark', 'hover_bg'], [$issues[0]['mode'], $issues[0]['background']]);
    $this->assertSame(['dark', 'submenu_bg'], [$issues[1]['mode'], $issues[1]['background']]);
    $this->assertLessThan(4.5, $issues[0]['ratio']);
  }

  /**
   * The layout options default to the top toolbar.
   */
  public function testLayoutDefaults(): void {
    $defaults = Appearance::defaults();
    $this->assertSame('top', $defaults['position']);
    $this->assertSame('fixed', $defaults['sidebar_mode']);
    $this->assertSame(260, $defaults['sidebar_width']);
    $this->assertFalse($defaults['sidebar_start_collapsed']);
    $this->assertSame(['top', 'left', 'right'], Appearance::CHOICES['position']);
    $this->assertSame([220, 240, 260, 280, 300, 320, 340, 360], Appearance::CHOICES['sidebar_width']);
  }

  /**
   * Layout values outside the closed lists fall back to the defaults.
   */
  public function testLayoutRejectsInvalid(): void {
    $values = Appearance::normalize([
      'position' => 'bottom',
      'sidebar_width' => 250,
      'sidebar_mode' => 'x',
      'sidebar_start_collapsed' => 'yes',
    ]);
    $this->assertSame('top', $values['position']);
    $this->assertSame(260, $values['sidebar_width']);
    $this->assertSame('fixed', $values['sidebar_mode']);
    $this->assertFalse($values['sidebar_start_collapsed']);

    $values = Appearance::normalize([
      'position' => 'right',
      'sidebar_mode' => 'collapsible',
      'sidebar_start_collapsed' => '1',
    ]);
    $this->assertSame('right', $values['position']);
    $this->assertSame('collapsible', $values['sidebar_mode']);
    $this->assertTrue($values['sidebar_start_collapsed']);
  }

  /**
   * The sidebar width is only emitted for a sidebar at a non-default width.
   */
  public function testSidebarWidthVariable(): void {
    $vars = Appearance::styleVars(Appearance::normalize(['position' => 'left', 'sidebar_width' => 300]));
    $this->assertSame('300px', $vars['--subadmin-toolbar-sidebar-width']);

    $vars = Appearance::styleVars(Appearance::normalize(['position' => 'top', 'sidebar_width' => 300]));
    $this->assertArrayNotHasKey('--subadmin-toolbar-sidebar-width', $vars);

    $vars = Appearance::styleVars(Appearance::normalize(['position' => 'right']));
    $this->assertArrayNotHasKey('--subadmin-toolbar-sidebar-width', $vars);
  }

}
