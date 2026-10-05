<?php

namespace Drupal\subadmin_toolbar\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\user\Entity\Role;

/**
 * Configure widgets (blocks) for Subadmin Toolbar.
 */
class WidgetsSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['subadmin_toolbar.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'subadmin_toolbar_widgets_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    if (!isset($form['#parents'])) {
      $form['#parents'] = [];
    }

    $config = $this->config('subadmin_toolbar.settings');

    $roles = Role::loadMultiple();
    $role_options = [];
    foreach ($roles as $role) {
      if ($role->id() === 'anonymous') {
        continue;
      }
      $role_options[$role->id()] = $role->label();
    }

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
    $block_manager = NULL;
    if (\Drupal::hasService('plugin.manager.block')) {
      /** @var \Drupal\Core\Block\BlockManagerInterface $block_manager */
      $block_manager = \Drupal::service('plugin.manager.block');
      $definitions = $block_manager->getDefinitions();
      foreach ($definitions as $plugin_id => $definition) {
        $category = (string) ($definition['category'] ?? $this->t('Other'));
        $label = (string) ($definition['admin_label'] ?? $plugin_id);
        $block_options[$category][$plugin_id] = $label;
      }
      ksort($block_options);
      foreach ($block_options as $cat => &$items) {
        asort($items);
      }
    }

    $form['#attached']['library'][] = 'core/drupal.dialog.ajax';
    $form['#attached']['library'][] = 'subadmin_toolbar/admin_form';
    $form['#tree'] = TRUE;

    $form['widgets_intro'] = [
      '#type' => 'item',
      '#markup' => '<div class="subadmin-widgets-description"><p>' . $this->t('Selecciona los bloques que deseas colocar como widgets en el extremo derecho de la barra Subadmin Toolbar. Puedes configurar las opciones específicas de cada bloque desplegando el panel "Opciones".') . '</p></div>',
    ];

    $form['widgets_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'widgets-wrapper'],
    ];

    $form['widgets_wrapper']['widgets'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Bloque'),
        $this->t('Configuración y opciones'),
        $this->t('Peso'),
        $this->t('Operaciones'),
      ],
      '#empty' => $this->t('No hay widgets configurados. Haz clic en "Añadir widget" abajo.'),
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
        '#title' => $this->t('Bloque'),
        '#title_display' => 'invisible',
        '#options' => $block_options,
        '#empty_option' => $this->t('- Seleccionar bloque -'),
        '#default_value' => $plugin_id,
        '#ajax' => [
          'callback' => '::widgetsAjaxCallback',
          'wrapper' => 'widgets-wrapper',
        ],
      ];

      $form['widgets_wrapper']['widgets'][$index]['configuration'] = [
        '#type' => 'details',
        '#title' => $this->t('Opciones'),
        '#open' => TRUE,
      ];

      $form['widgets_wrapper']['widgets'][$index]['configuration']['id'] = [
        '#type' => 'hidden',
        '#default_value' => $item['id'],
      ];

      $form['widgets_wrapper']['widgets'][$index]['configuration']['status'] = [
        '#type' => 'select',
        '#title' => $this->t('Estado'),
        '#options' => [
          'enabled' => $this->t('Activado'),
          'disabled' => $this->t('Desactivado'),
        ],
        '#default_value' => $item['status'] ?? 'enabled',
        '#weight' => -20,
      ];

      $form['widgets_wrapper']['widgets'][$index]['configuration']['label'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Título personalizado (opcional)'),
        '#default_value' => $item['label'] ?? '',
        '#placeholder' => $this->t('Dejar vacío para usar el título predeterminado del bloque'),
        '#weight' => -18,
      ];

      $form['widgets_wrapper']['widgets'][$index]['configuration']['label_display'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Mostrar título del bloque'),
        '#default_value' => !empty($item['label_display']),
        '#description' => $this->t('Por defecto en la barra de herramientas los títulos de los bloques suelen ocultarse.'),
        '#weight' => -16,
      ];

      $default_roles = [];
      if (!empty($item['roles']) && is_array($item['roles'])) {
        foreach ($item['roles'] as $role) {
          $default_roles[$role] = $role;
        }
      }

      $form['widgets_wrapper']['widgets'][$index]['configuration']['roles'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Roles permitidos'),
        '#options' => $role_options,
        '#default_value' => $default_roles,
        '#description' => $this->t('Si no se selecciona ninguno, el bloque estará visible para todos los usuarios con acceso a la barra.'),
        '#weight' => -10,
      ];

      // Formulario de configuración específico del plugin del bloque.
      if (!empty($plugin_id) && $block_manager && $block_manager->hasDefinition($plugin_id)) {
        try {
          $block_instance = $block_manager->createInstance($plugin_id, $item['settings'] ?? []);

          $form['widgets_wrapper']['widgets'][$index]['configuration']['block_settings'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Configuración del bloque (@label)', ['@label' => $block_instance->label()]),
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
              '#markup' => '<p class="description"><em>' . $this->t('Este bloque no tiene opciones de configuración adicionales.') . '</em></p>',
            ];
          }
        }
        catch (\Throwable $e) {
          // Si el bloque falla al instanciarse o renderizar su form, mantenemos el formulario estable.
        }
      }

      $form['widgets_wrapper']['widgets'][$index]['weight'] = [
        '#type' => 'weight',
        '#title' => $this->t('Peso'),
        '#title_display' => 'invisible',
        '#default_value' => $item['weight'] ?? $index,
        '#delta' => 100,
        '#attributes' => ['class' => ['widget-weight']],
      ];

      $form['widgets_wrapper']['widgets'][$index]['operations'] = [
        '#type' => 'submit',
        '#value' => $this->t('Eliminar'),
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
      '#value' => $this->t('Añadir widget'),
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
  public function widgetsAjaxCallback(array &$form, FormStateInterface $form_state) {
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

    /** @var \Drupal\Core\Block\BlockManagerInterface|null $block_manager */
    $block_manager = \Drupal::hasService('plugin.manager.block') ? \Drupal::service('plugin.manager.block') : NULL;

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
      if (!empty($plugin_id) && $block_manager && $block_manager->hasDefinition($plugin_id)) {
        try {
          $plugin = $block_manager->createInstance($plugin_id, $settings);

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
          // Mantener configuración existente si falla.
        }
      }

      $widgets[] = [
        'id' => $id,
        'plugin_id' => $plugin_id,
        'label' => $label,
        'label_display' => $label_display,
        'status' => $status,
        'roles' => $selected_roles,
        'weight' => $weight,
        'settings' => $settings,
      ];
    }

    usort($widgets, function ($a, $b) {
      return ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0);
    });

    return $widgets;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
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

    /** @var \Drupal\Core\Block\BlockManagerInterface|null $block_manager */
    $block_manager = \Drupal::hasService('plugin.manager.block') ? \Drupal::service('plugin.manager.block') : NULL;

    foreach ($submitted as $index => $row) {
      $plugin_id = $row['plugin_id'] ?? '';
      if (empty($plugin_id)) {
        $form_state->setErrorByName(
          "widgets_wrapper][widgets][$index][plugin_id",
          $this->t('Debes seleccionar un bloque para cada widget o eliminar la fila.')
        );
        continue;
      }

      if ($block_manager && $block_manager->hasDefinition($plugin_id)) {
        if (isset($form['widgets_wrapper']['widgets'][$index]['configuration']['block_settings'])) {
          try {
            $existing_widgets = $form_state->get('widgets') ?: [];
            $settings = $existing_widgets[$index]['settings'] ?? [];
            $plugin = $block_manager->createInstance($plugin_id, $settings);

            $subform = &$form['widgets_wrapper']['widgets'][$index]['configuration']['block_settings'];
            $subform_state = SubformState::createForSubform($subform, $form, $form_state);
            $plugin->blockValidate($subform, $subform_state);
          }
          catch (\Throwable $e) {
            // Ignorar errores internos de plugins si no son críticos.
          }
        }
      }
    }
  }

  /**
   * Submit callback for adding a widget.
   */
  public function addWidgetSubmit(array &$form, FormStateInterface $form_state) {
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

    $user_input = $form_state->getUserInput();
    unset($user_input['widgets_wrapper']);
    $form_state->setUserInput($user_input);

    $form_state->set('widgets', $widgets);
    $form_state->setRebuild();
  }

  /**
   * Submit callback for removing a widget.
   */
  public function removeWidgetSubmit(array &$form, FormStateInterface $form_state) {
    $user_input = $form_state->getUserInput();
    $triggering_element = $form_state->getTriggeringElement();
    $removed_id = NULL;

    if (isset($triggering_element['#name']) && strpos($triggering_element['#name'], 'remove_widget_') === 0) {
      $removed_id = substr($triggering_element['#name'], strlen('remove_widget_'));
    }

    if (!$removed_id && is_array($user_input)) {
      foreach ($user_input as $key => $value) {
        if (strpos($key, 'remove_widget_') === 0) {
          $removed_id = substr($key, strlen('remove_widget_'));
          break;
        }
      }
    }

    $widgets = $this->updateWidgetsFromSubmittedValues($form_state, $form);

    if ($removed_id) {
      foreach ($widgets as $k => $v) {
        if ($v['id'] === $removed_id) {
          unset($widgets[$k]);
        }
      }
      $widgets = array_values($widgets);
    }

    $user_input = $form_state->getUserInput();
    unset($user_input['widgets_wrapper']);
    $form_state->setUserInput($user_input);

    $form_state->set('widgets', $widgets);
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $widgets = $this->updateWidgetsFromSubmittedValues($form_state, $form);

    $this->config('subadmin_toolbar.settings')
      ->set('widgets', $widgets)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
