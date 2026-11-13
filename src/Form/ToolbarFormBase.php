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
   * Marks a checkboxes element as a group the visual builder can edit.
   */
  protected function markEditorGroup(array $element, string $group): array {
    $element['#attributes']['data-subadmin-group'] = $group;
    $element['#attributes']['data-subadmin-group-label'] = (string) $element['#title'];
    if (!empty($element['#description'])) {
      $element['#attributes']['data-subadmin-group-help'] = (string) $element['#description'];
    }
    return $element;
  }

  /**
   * Lets other modules add fields to the settings of an item.
   *
   * @param array $configuration
   *   The "configuration" element of the item row.
   * @param array $item
   *   The stored item the row is built from.
   * @param string $type
   *   Either "link" or "widget".
   */
  protected function alterItemConfiguration(array &$configuration, array $item, string $type): void {
    $this->moduleHandler->alter('subadmin_toolbar_item_configuration', $configuration, $item, $type);
  }

  /**
   * Lets other modules store the values of the fields they added to an item.
   *
   * @param array $item
   *   The item being stored.
   * @param array $configuration
   *   The submitted values of the "configuration" element of the row.
   * @param string $type
   *   Either "link" or "widget".
   */
  protected function alterItemValues(array &$item, array $configuration, string $type): void {
    $this->moduleHandler->alter('subadmin_toolbar_item_values', $item, $configuration, $type);
  }

  /**
   * Material icon names suggested in the visual editor.
   *
   * The bundled catalog (data/material-icons.json) holds every Material
   * Symbols name, so typing any valid name gets a match.
   *
   * @return string[]
   *   The icon names, after hook_subadmin_toolbar_icon_suggestions_alter().
   */
  protected function getIconSuggestions(): array {
    $file = dirname(__DIR__, 2) . '/data/material-icons.json';
    $icons = is_readable($file) ? (json_decode((string) file_get_contents($file), TRUE) ?: []) : [];
    $this->moduleHandler->alter('subadmin_toolbar_icon_suggestions', $icons);
    return array_values(array_unique($icons));
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
