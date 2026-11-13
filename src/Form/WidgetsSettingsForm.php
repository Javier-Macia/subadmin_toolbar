<?php

namespace Drupal\subadmin_toolbar\Form;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure widgets (blocks) for Subadmin Toolbar.
 */
class WidgetsSettingsForm extends ToolbarFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    EntityTypeManagerInterface $entity_type_manager,
    ModuleHandlerInterface $module_handler,
    protected BlockManagerInterface $blockManager,
    protected LoggerInterface $logger,
  ) {
    parent::__construct($config_factory, $typed_config_manager, $entity_type_manager, $module_handler);
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
      $container->get('plugin.manager.block'),
      $container->get('logger.channel.subadmin_toolbar'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['subadmin_toolbar.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'subadmin_toolbar_widgets_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    if (!isset($form['#parents'])) {
      $form['#parents'] = [];
    }

    $config = $this->config('subadmin_toolbar.settings');

    $role_options = $this->getRoleOptions();

    $widgets = $form_state->get('widgets');
    if ($widgets === NULL) {
      $widgets = $config->get('widgets') ?: [];
      $form_state->set('widgets', $widgets);
    }

    // Detectar si un selector de bloque (plugin_id) cambió vía AJAX.
    $triggering_element = $form_state->getTriggeringElement();
    $triggering_parents = $triggering_element['#parents'] ?? [];
    if (count($triggering_parents) === 4 && $triggering_parents[0] === 'widgets_wrapper' && $triggering_parents[1] === 'widgets' && $triggering_parents[3] === 'plugin_id') {
      $changed_index = $triggering_parents[2];
      $widgets = $this->updateWidgetsFromSubmittedValues($form_state, $form);
      $new_plugin_id = $form_state->getValue(['widgets_wrapper', 'widgets', $changed_index, 'plugin_id']);
      if (isset($widgets[$changed_index])) {
        $widgets[$changed_index]['plugin_id'] = $new_plugin_id;
        $widgets[$changed_index]['settings'] = [];
      }

      $user_input = $form_state->getUserInput();
      if (isset($user_input['widgets_wrapper']['widgets'][$changed_index]['configuration']['block_settings'])) {
        unset($user_input['widgets_wrapper']['widgets'][$changed_index]['configuration']['block_settings']);
        $form_state->setUserInput($user_input);
      }
      $form_state->set('widgets', $widgets);
    }

    // Obtener lista completa de bloques disponibles en Drupal organizados por categoría.
    $block_options = [];
    foreach ($this->blockManager->getDefinitions() as $plugin_id => $definition) {
      $category = (string) ($definition['category'] ?? $this->t('Other'));
      $block_options[$category][$plugin_id] = (string) ($definition['admin_label'] ?? $plugin_id);
    }
    ksort($block_options);
    foreach ($block_options as &$items) {
      asort($items);
    }
    unset($items);

    $form['#attached']['library'][] = 'core/drupal.dialog.ajax';
    $form['#attached']['library'][] = 'subadmin_toolbar/admin_form';
    $form['#tree'] = TRUE;

    $form['widgets_intro'] = [
      '#type' => 'item',
      '#wrapper_attributes' => ['class' => ['subadmin-widgets-description']],
      '#plain_text' => $this->t('Select the blocks to place as widgets at the right end of the Subadmin Toolbar. You can configure the specific options of each block in its "Options" panel.'),
    ];

    $form['widgets_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'widgets-wrapper'],
    ];

    $form['widgets_wrapper']['widgets'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Block'),
        $this->t('Configuration and options'),
        $this->t('Weight'),
        $this->t('Operations'),
      ],
      '#empty' => $this->t('No widgets configured. Click "Add widget" below.'),
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'widget-weight',
        ],
      ],
    ];

    foreach ($widgets as $index => $item) {
      if (empty($item['id'])) {
        $item['id'] = 'widget_' . uniqid();
      }

      $plugin_id = $item['plugin_id'] ?? '';

      $form['widgets_wrapper']['widgets'][$index] = [
        '#attributes' => ['class' => ['draggable']],
        '#weight' => $item['weight'] ?? $index,
      ];

      $form['widgets_wrapper']['widgets'][$index]['plugin_id'] = [
        '#type' => 'select',
        '#title' => $this->t('Block'),
        '#title_display' => 'invisible',
        '#options' => $block_options,
        '#empty_option' => $this->t('- Select block -'),
        '#default_value' => $plugin_id,
        '#ajax' => [
          'callback' => '::widgetsAjaxCallback',
          'wrapper' => 'widgets-wrapper',
        ],
      ];

      $form['widgets_wrapper']['widgets'][$index]['configuration'] = [
        '#type' => 'details',
        '#title' => $this->t('Options'),
        '#open' => TRUE,
      ];

      $form['widgets_wrapper']['widgets'][$index]['configuration']['id'] = [
        '#type' => 'hidden',
        '#default_value' => $item['id'],
      ];

      $form['widgets_wrapper']['widgets'][$index]['configuration']['status'] = [
        '#type' => 'select',
        '#title' => $this->t('Status'),
        '#options' => [
          'enabled' => $this->t('Enabled'),
          'disabled' => $this->t('Disabled'),
        ],
        '#default_value' => $item['status'] ?? 'enabled',
        '#weight' => -20,
      ];

      $form['widgets_wrapper']['widgets'][$index]['configuration']['label'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Custom title (optional)'),
        '#default_value' => $item['label'] ?? '',
        '#placeholder' => $this->t("Leave empty to use the block's default title"),
        '#weight' => -18,
      ];

      $form['widgets_wrapper']['widgets'][$index]['configuration']['label_display'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Display block title'),
        '#default_value' => !empty($item['label_display']),
        '#description' => $this->t('Block titles are usually hidden in the toolbar.'),
        '#weight' => -16,
      ];

      $default_roles = array_combine($item['roles'] ?? [], $item['roles'] ?? []);

      $form['widgets_wrapper']['widgets'][$index]['configuration']['roles'] = $this->markEditorGroup([
        '#type' => 'checkboxes',
        '#title' => $this->t('Allowed roles'),
        '#options' => $role_options,
        '#default_value' => $default_roles,
        '#description' => $this->t('If no roles are selected, the block will be visible to all users with access to the toolbar.'),
        '#weight' => -10,
      ], 'roles');
      $this->alterItemConfiguration($form['widgets_wrapper']['widgets'][$index]['configuration'], $item, 'widget');

      // Settings form of the block plugin.
      if (!empty($plugin_id) && $this->blockManager->hasDefinition($plugin_id)) {
        try {
          $block_instance = $this->blockManager->createInstance($plugin_id, $item['settings'] ?? []);

          $form['widgets_wrapper']['widgets'][$index]['configuration']['block_settings'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Block configuration (@label)', ['@label' => $block_instance->label()]),
            '#parents' => ['widgets_wrapper', 'widgets', $index, 'configuration', 'block_settings'],
            '#tree' => TRUE,
            '#weight' => 20,
            '#attributes' => ['class' => ['subadmin-block-settings-wrapper']],
          ];

          $subform_state = SubformState::createForSubform(
            $form['widgets_wrapper']['widgets'][$index]['configuration']['block_settings'],
            $form,
            $form_state
          );

          $block_form = $block_instance->blockForm([], $subform_state);
          if (!empty($block_form)) {
            $form['widgets_wrapper']['widgets'][$index]['configuration']['block_settings'] += $block_form;
          }
          else {
            $form['widgets_wrapper']['widgets'][$index]['configuration']['block_settings']['no_settings'] = [
              '#type' => 'item',
              '#plain_text' => $this->t('This block has no additional configuration options.'),
            ];
          }
        }
        catch (\Throwable $e) {
          // Keep the form usable if a block fails to build its settings form.
          $this->logger->error('Settings form of block @plugin failed: @message', [
            '@plugin' => $plugin_id,
            '@message' => $e->getMessage(),
          ]);
        }
      }

      $form['widgets_wrapper']['widgets'][$index]['weight'] = [
        '#type' => 'weight',
        '#title' => $this->t('Weight'),
        '#title_display' => 'invisible',
        '#default_value' => $item['weight'] ?? $index,
        '#delta' => 100,
        '#attributes' => ['class' => ['widget-weight']],
      ];

      $form['widgets_wrapper']['widgets'][$index]['operations'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove'),
        '#name' => 'remove_widget_' . $item['id'],
        '#submit' => ['::removeWidgetSubmit'],
        '#ajax' => [
          'callback' => '::widgetsAjaxCallback',
          'wrapper' => 'widgets-wrapper',
        ],
        '#limit_validation_errors' => [['widgets_wrapper']],
        '#attributes' => ['class' => ['subadmin-btn-remove-row']],
      ];
    }

    $form['widgets_wrapper']['actions']['add'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add widget'),
      '#name' => 'add_widget_button',
      '#submit' => ['::addWidgetSubmit'],
      '#ajax' => [
        'callback' => '::widgetsAjaxCallback',
        'wrapper' => 'widgets-wrapper',
      ],
      '#limit_validation_errors' => [['widgets_wrapper']],
      '#attributes' => ['class' => ['subadmin-btn-add-row']],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Ajax callback for widget table operations.
   */
  public function widgetsAjaxCallback(array &$form, FormStateInterface $form_state): array {
    return $form['widgets_wrapper'];
  }

  /**
   * Helper to normalize submitted widget values and extract block settings.
   */
  protected function updateWidgetsFromSubmittedValues(FormStateInterface $form_state, array &$form = []): array {
    $submitted = $form_state->getValue(['widgets_wrapper', 'widgets']);
    if (!is_array($submitted)) {
      $user_input = $form_state->getUserInput();
      $submitted = $user_input['widgets_wrapper']['widgets'] ?? NULL;
    }
    if (!is_array($submitted)) {
      return $form_state->get('widgets') ?: [];
    }

    $existing_widgets = $form_state->get('widgets') ?: [];
    $existing_by_id = [];
    foreach ($existing_widgets as $ew) {
      if (!empty($ew['id'])) {
        $existing_by_id[$ew['id']] = $ew;
      }
    }

    $widgets = [];
    foreach ($submitted as $index => $row) {
      if (!isset($row['configuration']['id'])) {
        continue;
      }
      $id = $row['configuration']['id'];
      $plugin_id = $row['plugin_id'] ?? '';
      $label = $row['configuration']['label'] ?? '';
      $label_display = !empty($row['configuration']['label_display']);
      $status = $row['configuration']['status'] ?? 'enabled';
      $weight = isset($row['weight']) ? (int) $row['weight'] : count($widgets);

      $selected_roles = [];
      if (!empty($row['configuration']['roles']) && is_array($row['configuration']['roles'])) {
        $selected_roles = array_values(array_filter($row['configuration']['roles']));
      }

      $existing_item = $existing_by_id[$id] ?? ($existing_widgets[$index] ?? []);
      $existing_plugin_id = $existing_item['plugin_id'] ?? '';
      $settings = $existing_item['settings'] ?? [];

      // Si el plugin cambió, resetear opciones anteriores.
      if ($plugin_id !== $existing_plugin_id) {
        $settings = [];
      }

      // Si hay un plugin seleccionado, procesar su blockSubmit y configuraciones.
      if (!empty($plugin_id) && $this->blockManager->hasDefinition($plugin_id)) {
        try {
          $plugin = $this->blockManager->createInstance($plugin_id, $settings);

          if (!empty($form['widgets_wrapper']['widgets'][$index]['configuration']['block_settings'])) {
            $subform = &$form['widgets_wrapper']['widgets'][$index]['configuration']['block_settings'];
            $subform_state = SubformState::createForSubform($subform, $form, $form_state);
            $plugin->blockSubmit($subform, $subform_state);

            $plugin_config = $plugin->getConfiguration();
            $raw_values = $subform_state->getValues();
            unset($raw_values['form_build_id'], $raw_values['form_token'], $raw_values['form_id'], $raw_values['actions']);
            $settings = array_merge($settings, $raw_values, $plugin_config);
          }
          elseif (isset($row['configuration']['block_settings']) && is_array($row['configuration']['block_settings'])) {
            $raw_values = $row['configuration']['block_settings'];
            unset($raw_values['form_build_id'], $raw_values['form_token'], $raw_values['form_id'], $raw_values['actions']);
            $settings = array_merge($settings, $raw_values);
          }
        }
        catch (\Throwable $e) {
          // Keep the existing settings if the block fails.
          $this->logger->error('Saving settings of block @plugin failed: @message', [
            '@plugin' => $plugin_id,
            '@message' => $e->getMessage(),
          ]);
        }
      }

      $widget = [
        'id' => $id,
        'plugin_id' => $plugin_id,
        'label' => $label,
        'label_display' => $label_display,
        'status' => $status,
        'roles' => $selected_roles,
        'weight' => $weight,
        'settings' => $settings,
      ];
      $this->alterItemValues($widget, $row['configuration'], 'widget');
      $widgets[] = $widget;
    }

    usort($widgets, fn(array $a, array $b) => $a['weight'] <=> $b['weight']);

    return $widgets;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'] ?? '';

    // Validar plugin obligatorio y llamadas de validación de bloque solo al guardar.
    if ($button_name !== 'op') {
      return;
    }

    $submitted = $form_state->getValue(['widgets_wrapper', 'widgets']);
    if (!is_array($submitted)) {
      return;
    }

    foreach ($submitted as $index => $row) {
      $plugin_id = $row['plugin_id'] ?? '';
      if (empty($plugin_id)) {
        $form_state->setErrorByName(
          "widgets_wrapper][widgets][$index][plugin_id",
          $this->t('You must select a block for each widget or remove the row.')
        );
        continue;
      }

      if ($this->blockManager->hasDefinition($plugin_id)) {
        if (isset($form['widgets_wrapper']['widgets'][$index]['configuration']['block_settings'])) {
          try {
            $existing_widgets = $form_state->get('widgets') ?: [];
            $settings = $existing_widgets[$index]['settings'] ?? [];
            $plugin = $this->blockManager->createInstance($plugin_id, $settings);

            $subform = &$form['widgets_wrapper']['widgets'][$index]['configuration']['block_settings'];
            $subform_state = SubformState::createForSubform($subform, $form, $form_state);
            $plugin->blockValidate($subform, $subform_state);
          }
          catch (\Throwable $e) {
            $this->logger->error('Validating settings of block @plugin failed: @message', [
              '@plugin' => $plugin_id,
              '@message' => $e->getMessage(),
            ]);
          }
        }
      }
    }
  }

  /**
   * Submit callback for adding a widget.
   */
  public function addWidgetSubmit(array &$form, FormStateInterface $form_state): void {
    $widgets = $this->updateWidgetsFromSubmittedValues($form_state, $form);
    $widgets[] = [
      'id' => 'widget_' . uniqid(),
      'plugin_id' => '',
      'label' => '',
      'label_display' => FALSE,
      'status' => 'enabled',
      'roles' => [],
      'weight' => count($widgets),
      'settings' => [],
    ];

    $this->resetSubmittedInput($form_state, 'widgets_wrapper');

    $form_state->set('widgets', $widgets);
    $form_state->setRebuild();
  }

  /**
   * Submit callback for removing a widget.
   */
  public function removeWidgetSubmit(array &$form, FormStateInterface $form_state): void {
    $removed_id = substr($form_state->getTriggeringElement()['#name'], strlen('remove_widget_'));
    $widgets = array_values(array_filter(
      $this->updateWidgetsFromSubmittedValues($form_state, $form),
      fn(array $widget) => $widget['id'] !== $removed_id,
    ));

    $this->resetSubmittedInput($form_state, 'widgets_wrapper');

    $form_state->set('widgets', $widgets);
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $widgets = $this->updateWidgetsFromSubmittedValues($form_state, $form);

    $this->config('subadmin_toolbar.settings')
      ->set('widgets', $widgets)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
