<?php

declare(strict_types=1);

namespace Drupal\Tests\subadmin_toolbar\Kernel;

use Drupal\subadmin_toolbar\Appearance;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the toolbar render array built for the current user.
 */
#[Group('subadmin_toolbar')]
#[RunTestsInSeparateProcesses]
class ToolbarBuilderTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'block', 'subadmin_toolbar'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['subadmin_toolbar']);
  }

  /**
   * Stores the given links in the module configuration.
   */
  protected function setLinks(array $links): void {
    $this->config('subadmin_toolbar.settings')->set('links', $links)->save();
  }

  /**
   * Users without the permission get no toolbar.
   */
  public function testNoPermissionNoToolbar(): void {
    $this->setUpCurrentUser();
    $this->setLinks([['id' => 'a', 'title' => 'A', 'url' => '/a']]);

    $build = $this->container->get('subadmin_toolbar.builder')->build();
    $this->assertArrayNotHasKey('#theme', $build);
    $this->assertContains('user.permissions', $build['#cache']['contexts']);
  }

  /**
   * Links are nested, filtered by role and sanitized.
   */
  public function testLinksTreeRolesAndSanitizing(): void {
    $this->setUpCurrentUser([], ['access subadmin toolbar']);
    $roles = $this->container->get('current_user')->getRoles();
    $this->setLinks([
      ['id' => 'parent', 'pid' => '', 'title' => 'Parent', 'url' => ''],
      ['id' => 'child', 'pid' => 'parent', 'title' => 'Child', 'url' => 'javascript:alert(1)'],
      ['id' => 'off', 'pid' => '', 'title' => 'Off', 'url' => '/off', 'status' => 'disabled'],
      ['id' => 'other', 'pid' => '', 'title' => 'Other role', 'url' => '/o', 'roles' => ['nobody']],
      ['id' => 'mine', 'pid' => '', 'title' => 'Mine', 'url' => '/m', 'roles' => $roles],
      ['id' => 'orphan', 'pid' => 'missing', 'title' => 'Orphan', 'url' => '/x'],
    ]);

    $build = $this->container->get('subadmin_toolbar.builder')->build();
    $links = $build['#links'];

    $this->assertSame(['parent', 'mine', 'orphan'], array_keys($links));
    $this->assertSame('#', $links['parent']['url']);
    $this->assertSame(['child'], array_keys($links['parent']['below']));
    $this->assertStringNotContainsString('javascript', $links['parent']['below']['child']['url']);
    $this->assertContains('user.roles', $build['#cache']['contexts']);
  }

  /**
   * An invalid accent color falls back to the default.
   */
  public function testInvalidAccentColorFallsBack(): void {
    $this->setUpCurrentUser([], ['access subadmin toolbar']);
    $this->setLinks([['id' => 'a', 'title' => 'A', 'url' => '/a']]);
    $this->config('subadmin_toolbar.general')->set('accent_color', 'red;}</style>')->save();

    $build = $this->container->get('subadmin_toolbar.builder')->build();
    $this->assertSame('#0073ff', $build['#accent_color']);
  }

  /**
   * Valid appearance values reach the style variables and the flags.
   */
  public function testAppearanceValuesReachStyleVars(): void {
    $this->setUpCurrentUser([], ['access subadmin toolbar']);
    $this->setLinks([['id' => 'a', 'title' => 'A', 'url' => '/a']]);
    $this->config('subadmin_toolbar.general')->set('appearance', [
      'height' => 64,
      'font_size' => 15,
      'font_weight' => 600,
      'icon_size' => 28,
      'icon_opacity' => '1',
      'item_padding' => 24,
      'submenu_min_width' => 300,
      'font_family' => 'serif',
      'letter_spacing' => '0.02em',
      'border_bottom' => 'line',
      'radius' => 'round',
      'shadow' => 'none',
      'colors' => [
        'light' => ['bg' => '#ABCDEF', 'text' => '#112233'],
        'dark' => ['bg' => '#010203', 'hover_bg' => '#0a0b0c'],
      ],
      'open_delay' => 200,
      'close_delay' => 400,
      'show_submenu_icons' => FALSE,
      'indicator' => 'plus_minus',
    ])->save();

    $build = $this->container->get('subadmin_toolbar.builder')->build();
    $vars = $build['#style_vars'];

    $this->assertSame('64px', $vars['--subadmin-toolbar-height']);
    $this->assertSame('15px', $vars['--subadmin-toolbar-font-size']);
    $this->assertSame('600', $vars['--subadmin-toolbar-font-weight']);
    $this->assertSame('28px', $vars['--subadmin-toolbar-icon-size']);
    $this->assertSame('1', $vars['--subadmin-toolbar-icon-opacity']);
    $this->assertSame('24px', $vars['--subadmin-toolbar-item-padding']);
    $this->assertSame('300px', $vars['--subadmin-toolbar-submenu-min-width']);
    $this->assertSame(Appearance::FONTS['serif'], $vars['--subadmin-toolbar-font']);
    $this->assertSame('0.02em', $vars['--subadmin-toolbar-letter-spacing']);
    $this->assertSame('1px solid var(--subadmin-toolbar-border)', $vars['--subadmin-toolbar-border-bottom']);
    $this->assertSame('12px', $vars['--subadmin-toolbar-radius']);
    $this->assertSame('none', $vars['--subadmin-toolbar-shadow']);
    $this->assertSame('none', $vars['--subadmin-toolbar-submenu-shadow']);
    $this->assertSame('200ms', $vars['--subadmin-toolbar-open-delay']);
    $this->assertSame('400ms', $vars['--subadmin-toolbar-close-delay']);
    $this->assertSame('#abcdef', $vars['--subadmin-toolbar-light-bg']);
    $this->assertSame('#112233', $vars['--subadmin-toolbar-light-text']);
    $this->assertSame('#010203', $vars['--subadmin-toolbar-dark-bg']);
    $this->assertSame('#0a0b0c', $vars['--subadmin-toolbar-dark-hover-bg']);
    $this->assertSame(['indicator' => 'plus_minus', 'submenu_icons' => FALSE], $build['#appearance']);
  }

  /**
   * Invalid appearance values fall back to the defaults and are not emitted.
   */
  public function testInvalidAppearanceFallsBack(): void {
    $this->setUpCurrentUser([], ['access subadmin toolbar']);
    $this->setLinks([['id' => 'a', 'title' => 'A', 'url' => '/a']]);
    $this->config('subadmin_toolbar.general')->set('appearance', [
      'height' => 999,
      'font_size' => 11,
      'font_weight' => 800,
      'item_padding' => 7,
      'submenu_min_width' => 340,
      'font_family' => 'url(x)',
      'letter_spacing' => '1em',
      'border_bottom' => 'dashed',
      'icon_size' => 0,
      'icon_opacity' => '0.55',
      'radius' => 'blob',
      'shadow' => '0 0 99px red',
      'colors' => [
        'light' => ['bg' => 'red', 'text' => '#fff', 'border' => 'url(x)'],
        'dark' => ['bg' => '#12345g', 'hover_bg' => ''],
      ],
      'open_delay' => 5000,
      'close_delay' => -1,
      'show_submenu_icons' => TRUE,
      'indicator' => 'arrow',
    ])->save();

    $build = $this->container->get('subadmin_toolbar.builder')->build();

    $this->assertSame([], $build['#style_vars']);
    $this->assertSame(['indicator' => 'caret', 'submenu_icons' => TRUE], $build['#appearance']);
  }

  /**
   * Unset or empty colors are not emitted, and the defaults emit nothing.
   */
  public function testDefaultsAndEmptyColorsEmitNothing(): void {
    $this->setUpCurrentUser([], ['access subadmin toolbar']);
    $this->setLinks([['id' => 'a', 'title' => 'A', 'url' => '/a']]);

    $build = $this->container->get('subadmin_toolbar.builder')->build();
    $this->assertSame([], $build['#style_vars']);

    $this->config('subadmin_toolbar.general')->clear('appearance')->save();
    $build = $this->container->get('subadmin_toolbar.builder')->build();
    $this->assertSame([], $build['#style_vars']);
    $this->assertSame('caret', $build['#appearance']['indicator']);
  }

  /**
   * The update hook adds the new options and keeps the stored values.
   */
  public function testUpdate10002KeepsStoredValues(): void {
    $this->container->get('module_handler')->loadInclude('subadmin_toolbar', 'install');
    $stored = [
      'height' => 64,
      'radius' => 'soft',
      'colors' => ['light' => ['bg' => '#abcdef']],
    ];
    $this->config('subadmin_toolbar.general')->set('appearance', $stored)->save();

    subadmin_toolbar_update_10002();

    $appearance = $this->config('subadmin_toolbar.general')->get('appearance');
    $this->assertSame(64, $appearance['height']);
    $this->assertSame('soft', $appearance['radius']);
    $this->assertSame(['light' => ['bg' => '#abcdef']], $appearance['colors']);
    $added = [
      'item_padding',
      'submenu_min_width',
      'font_family',
      'letter_spacing',
      'border_bottom',
      'font_size',
      'open_delay',
    ];
    foreach ($added as $key) {
      $this->assertSame(Appearance::defaults()[$key], $appearance[$key], $key);
    }
    $this->assertSame([
      '--subadmin-toolbar-height' => '64px',
      '--subadmin-toolbar-radius' => '6px',
      '--subadmin-toolbar-light-bg' => '#abcdef',
    ], Appearance::styleVars(Appearance::normalize($appearance)));
  }

}
