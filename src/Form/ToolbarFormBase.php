<?php

declare(strict_types=1);

namespace Drupal\subadmin_toolbar\Form;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shared dependencies and helpers for the Subadmin Toolbar settings forms.
 */
abstract class ToolbarFormBase extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
    );
  }

  /**
   * Returns the selectable roles (all but anonymous), keyed by role ID.
   */
  protected function getRoleOptions(): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('user_role')->loadMultiple() as $role) {
      if ($role->id() !== 'anonymous') {
        $options[$role->id()] = $role->label();
      }
    }
    return $options;
  }

  /**
   * Whether a URL is empty, an internal path (/, #, ?) or a safe external URL.
   */
  protected function isValidUrl(string $url): bool {
    if ($url === '' || in_array($url[0], ['/', '#', '?'], TRUE)) {
      return TRUE;
    }
    return UrlHelper::isExternal($url) && UrlHelper::isValid($url, TRUE);
  }

  /**
   * Drops the submitted input of a table.
   *
   * The rebuilt form then uses the values stored in the form state instead.
   */
  protected function resetSubmittedInput(FormStateInterface $form_state, string $wrapper): void {
    $user_input = $form_state->getUserInput();
    unset($user_input[$wrapper]);
    $form_state->setUserInput($user_input);
  }

}
