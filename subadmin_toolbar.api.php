<?php

/**
 * @file
 * Hooks provided by the Subadmin Toolbar module.
 */

use Drupal\Core\Cache\CacheableMetadata;

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alters the visibility of a toolbar link or widget for the current user.
 *
 * It runs after the status (disabled items never get here) and the role check.
 *
 * @param bool $visible
 *   Whether the item is visible: TRUE when the item has no roles or the user
 *   has one of them.
 * @param array $item
 *   The stored link or widget.
 * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
 *   Add what the decision depends on, as the toolbar is cached.
 */
function hook_subadmin_toolbar_item_visibility_alter(bool &$visible, array $item, CacheableMetadata $cacheability): void {
  if (!empty($item['only_weekdays'])) {
    $visible = $visible && date('N') < 6;
    $cacheability->setCacheMaxAge(3600);
  }
}

/**
 * Alters the settings of a link or widget in the configuration forms.
 *
 * Add the form elements for the values you want to keep with the item. To show
 * a group of check boxes in the visual builder, give the element the
 * attributes data-subadmin-group (a machine name), data-subadmin-group-label
 * and, optionally, data-subadmin-group-help. Store the values with
 * hook_subadmin_toolbar_item_values_alter().
 *
 * @param array $configuration
 *   The "configuration" element of the row.
 * @param array $item
 *   The stored link or widget the row shows.
 * @param string $type
 *   Either "link" or "widget".
 */
function hook_subadmin_toolbar_item_configuration_alter(array &$configuration, array $item, string $type): void {
  $configuration['only_weekdays'] = [
    '#type' => 'checkbox',
    '#title' => t('Only on weekdays'),
    '#default_value' => !empty($item['only_weekdays']),
  ];
}

/**
 * Alters a link or widget before it is stored.
 *
 * Copy the values of the elements added in
 * hook_subadmin_toolbar_item_configuration_alter(). Only the keys that are
 * set here are stored, and they need to be added to the config schema with
 * hook_config_schema_info_alter().
 *
 * @param array $item
 *   The item to store.
 * @param array $configuration
 *   The submitted values of the "configuration" element of the row.
 * @param string $type
 *   Either "link" or "widget".
 */
function hook_subadmin_toolbar_item_values_alter(array &$item, array $configuration, string $type): void {
  $item['only_weekdays'] = !empty($configuration['only_weekdays']);
}

/**
 * Alter the icon names suggested in the visual editor.
 *
 * @param string[] $icons
 *   Material icon names.
 */
function hook_subadmin_toolbar_icon_suggestions_alter(array &$icons): void {
  $icons[] = 'sports_soccer';
}

/**
 * @} End of "addtogroup hooks".
 */
