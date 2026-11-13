<?php

declare(strict_types=1);

namespace Drupal\subadmin_toolbar_subroles\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\subroles\Entity\SubroleInterface;

/**
 * Adds subrole restrictions to the links and widgets of the Subadmin Toolbar.
 *
 * An item with subroles is visible to the users who have one of its roles or
 * one of its subroles. Without roles and subroles it is visible to everyone
 * with access to the toolbar, as usual.
 */
class SubadminToolbarSubrolesHooks {

  use StringTranslationTrait;

  /**
   * Subroles that apply to each user ID, for the current request.
   *
   * @var \Drupal\subroles\Entity\SubroleInterface[][]
   */
  protected array $effectiveSubroles = [];

  /**
   * Selectable subroles, for the current request.
   *
   * @var string[]|null
   */
  protected ?array $subroleOptions = NULL;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected AccountInterface $currentUser,
  ) {}

  /**
   * Implements hook_subadmin_toolbar_item_configuration_alter().
   */
  #[Hook('subadmin_toolbar_item_configuration_alter')]
  public function alterConfiguration(array &$configuration, array $item, string $type): void {
    $options = $this->getSubroleOptions();
    if (!$options) {
      return;
    }

    $selected = array_values(array_intersect($item['subroles'] ?? [], array_keys($options)));
    $help = $type === 'link'
      ? $this->t('The link is also visible to users with any of these subroles. If a subrole is selected and no role is, only the users with the subrole see it.')
      : $this->t('The block is also visible to users with any of these subroles. If a subrole is selected and no role is, only the users with the subrole see it.');
    $title = $this->t('Allowed subroles');
    $configuration['subroles'] = [
      '#type' => 'checkboxes',
      '#title' => $title,
      '#options' => $options,
      '#default_value' => array_combine($selected, $selected),
      '#description' => $help,
      '#weight' => -9,
      '#attributes' => [
        'data-subadmin-group' => 'subroles',
        'data-subadmin-group-label' => (string) $title,
        'data-subadmin-group-help' => (string) $help,
      ],
    ];
  }

  /**
   * Implements hook_subadmin_toolbar_item_values_alter().
   */
  #[Hook('subadmin_toolbar_item_values_alter')]
  public function alterValues(array &$item, array $configuration, string $type): void {
    if (isset($configuration['subroles']) && is_array($configuration['subroles'])) {
      $item['subroles'] = array_values(array_filter($configuration['subroles']));
    }
  }

  /**
   * Implements hook_subadmin_toolbar_item_visibility_alter().
   */
  #[Hook('subadmin_toolbar_item_visibility_alter')]
  public function alterVisibility(bool &$visible, array $item, CacheableMetadata $cacheability): void {
    $allowed_subroles = array_filter($item['subroles'] ?? []);
    if (!$allowed_subroles) {
      return;
    }

    $roles_restricted = (bool) array_filter($item['roles'] ?? []);
    $matches_subrole = (bool) array_intersect($allowed_subroles, $this->getEffectiveSubroles($this->currentUser, $cacheability));
    // The role check already ran: $visible tells whether a role matched, but
    // it is also TRUE when no roles are set, which a subrole list overrides.
    $visible = $matches_subrole || ($roles_restricted && $visible);
  }

  /**
   * Implements hook_config_schema_info_alter().
   */
  #[Hook('config_schema_info_alter')]
  public function alterSchema(array &$definitions): void {
    $subroles = [
      'type' => 'sequence',
      'label' => 'Subroles',
      'sequence' => [
        'type' => 'string',
        'label' => 'Subrole ID',
      ],
    ];
    foreach (['links', 'widgets'] as $key) {
      if (isset($definitions['subadmin_toolbar.settings']['mapping'][$key]['sequence']['mapping'])) {
        $definitions['subadmin_toolbar.settings']['mapping'][$key]['sequence']['mapping']['subroles'] = $subroles;
      }
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for subroles.
   *
   * Removes the deleted subrole from the links and widgets that use it.
   */
  #[Hook('subrole_delete')]
  public function onSubroleDelete(SubroleInterface $subrole): void {
    $config = $this->configFactory->getEditable('subadmin_toolbar.settings');
    $changed = FALSE;
    foreach (['links', 'widgets'] as $key) {
      $items = $config->get($key) ?: [];
      foreach ($items as &$item) {
        if (in_array($subrole->id(), $item['subroles'] ?? [], TRUE)) {
          $item['subroles'] = array_values(array_diff($item['subroles'], [$subrole->id()]));
          $changed = TRUE;
        }
      }
      unset($item);
      $config->set($key, $items);
    }
    if ($changed) {
      $config->save();
    }
  }

  /**
   * Returns the subroles that can be selected, labelled with their role.
   *
   * @return string[]
   *   Labels keyed by subrole ID, ordered by role and weight.
   */
  protected function getSubroleOptions(): array {
    return $this->subroleOptions ??= $this->loadSubroleOptions();
  }

  /**
   * Loads the selectable subroles.
   *
   * @return string[]
   *   Labels keyed by subrole ID, ordered by role and weight.
   */
  protected function loadSubroleOptions(): array {
    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();
    /** @var \Drupal\subroles\Entity\SubroleInterface[] $subroles */
    $subroles = $this->entityTypeManager->getStorage('subrole')->loadMultiple();
    uasort($subroles, fn(SubroleInterface $a, SubroleInterface $b) => [
      $a->getRole(),
      $a->getWeight(),
    ] <=> [$b->getRole(), $b->getWeight()]);

    $options = [];
    foreach ($subroles as $subrole) {
      $role = $roles[$subrole->getRole()] ?? NULL;
      $options[$subrole->id()] = $role ? $role->label() . ' › ' . $subrole->label() : $subrole->label();
    }
    return $options;
  }

  /**
   * Returns the IDs of the subroles that apply to an account.
   *
   * Like the access policy of subroles, a subrole only counts while the user
   * has its parent role.
   *
   * @return string[]
   *   The subrole IDs.
   */
  protected function getEffectiveSubroles(AccountInterface $account, CacheableMetadata $cacheability): array {
    $cacheability->addCacheContexts(['user']);
    if (!$account->id()) {
      return [];
    }
    $cacheability->addCacheTags(['user:' . $account->id()]);

    if (!isset($this->effectiveSubroles[$account->id()])) {
      /** @var \Drupal\user\UserInterface|null $user */
      $user = $this->entityTypeManager->getStorage('user')->load($account->id());
      $this->effectiveSubroles[$account->id()] = $user?->get('subrole')->referencedEntities() ?? [];
    }

    $ids = [];
    foreach ($this->effectiveSubroles[$account->id()] as $subrole) {
      $cacheability->addCacheableDependency($subrole);
      if (in_array($subrole->getRole(), $account->getRoles(), TRUE)) {
        $ids[] = $subrole->id();
      }
    }
    return $ids;
  }

}
