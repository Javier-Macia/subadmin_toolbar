<?php

declare(strict_types=1);

namespace Drupal\Tests\subadmin_toolbar\Kernel;

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

}
