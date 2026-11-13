<?php

declare(strict_types=1);

namespace Drupal\Tests\subadmin_toolbar_subroles\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\subroles\Entity\Subrole;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the subrole restrictions of the Subadmin Toolbar items.
 */
#[Group('subadmin_toolbar_subroles')]
#[RunTestsInSeparateProcesses]
class SubroleVisibilityTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'block',
    'subroles',
    'subadmin_toolbar',
    'subadmin_toolbar_subroles',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['user', 'subadmin_toolbar']);

    $role = Role::create(['id' => 'editor', 'label' => 'Editor']);
    $role->grantPermission('access subadmin toolbar');
    $role->save();
    Role::create(['id' => 'other', 'label' => 'Other'])->save();
    Subrole::create(['id' => 'publisher', 'label' => 'Publisher', 'role' => 'editor', 'permissions' => []])->save();

    // UID 1 is the super user, so reserve it.
    User::create(['name' => 'root', 'mail' => 'root@example.com'])->save();

    $this->config('subadmin_toolbar.settings')->set('links', [
      ['id' => 'all', 'title' => 'All', 'url' => '/all'],
      ['id' => 'sub', 'title' => 'Sub', 'url' => '/sub', 'subroles' => ['publisher']],
      ['id' => 'both', 'title' => 'Both', 'url' => '/both', 'roles' => ['other'], 'subroles' => ['publisher']],
      ['id' => 'role', 'title' => 'Role', 'url' => '/role', 'roles' => ['editor']],
    ])->save();
  }

  /**
   * Builds the toolbar as a user and returns the visible link IDs.
   */
  protected function visibleLinks(array $values): array {
    $name = $this->randomMachineName();
    $user = User::create(['name' => $name, 'mail' => $name . '@example.com'] + $values);
    $user->save();
    $this->container->get('current_user')->setAccount($user);
    $build = $this->container->get('subadmin_toolbar.builder')->build();
    return array_keys($build['#links'] ?? []);
  }

  /**
   * A subrole shows the links restricted to it, on top of the role ones.
   */
  public function testSubroleGrantsVisibility(): void {
    $values = ['roles' => ['editor'], 'subrole' => 'publisher'];
    $this->assertSame(['all', 'sub', 'both', 'role'], $this->visibleLinks($values));
    $this->assertSame(['all', 'role'], $this->visibleLinks(['roles' => ['editor']]));
  }

  /**
   * A subrole without its parent role has no effect.
   */
  public function testSubroleNeedsParentRole(): void {
    $role = Role::load('other');
    $role->grantPermission('access subadmin toolbar')->save();
    // 'both' is visible through the role 'other', not through the subrole.
    $this->assertSame(['all', 'both'], $this->visibleLinks(['roles' => ['other'], 'subrole' => 'publisher']));
  }

  /**
   * The cache depends on the user and on their subroles.
   */
  public function testCacheability(): void {
    $user = User::create([
      'name' => 'u',
      'mail' => 'u@example.com',
      'roles' => ['editor'],
      'subrole' => 'publisher',
    ]);
    $user->save();
    $this->container->get('current_user')->setAccount($user);
    $build = $this->container->get('subadmin_toolbar.builder')->build();
    $this->assertContains('user', $build['#cache']['contexts']);
    $this->assertContains('user:' . $user->id(), $build['#cache']['tags']);
    $this->assertContains('config:subroles.subrole.publisher', $build['#cache']['tags']);
  }

  /**
   * The subroles are stored with the item and removed with the subrole.
   */
  public function testSubroleDeletionCleansConfig(): void {
    Subrole::load('publisher')->delete();
    $links = $this->config('subadmin_toolbar.settings')->get('links');
    $this->assertSame([], $links[1]['subroles']);
  }

  /**
   * The settings schema accepts the subroles key.
   */
  public function testSchema(): void {
    $definition = $this->container->get('config.typed')->getDefinition('subadmin_toolbar.settings');
    $this->assertArrayHasKey('subroles', $definition['mapping']['links']['sequence']['mapping']);
    $this->assertArrayHasKey('subroles', $definition['mapping']['widgets']['sequence']['mapping']);
  }

}
