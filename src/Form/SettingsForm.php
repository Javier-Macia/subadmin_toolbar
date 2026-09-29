<?php

namespace Drupal\subadmin_toolbar\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\Role;

/**
 * Configure Subadmin Toolbar settings for this site.
 */
class SettingsForm extends ConfigFormBase {

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
    return 'subadmin_toolbar_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('subadmin_toolbar.settings');
    
    $roles = Role::loadMultiple();
    $role_options = [];
    foreach ($roles as $role) {
      if ($role->id() === 'anonymous') {
        continue;
      }
      $role_options[$role->id()] = $role->label();
    }
    
    $links = $form_state->get('links');
    if ($links === NULL) {
      $links = $config->get('links') ?: [];
      $form_state->set('links', $links);
    }

    $general_config = $this->config('subadmin_toolbar.general');
    $enable_fontawesome = (bool) ($general_config->get('enable_font_awesome') ?? FALSE);

    $icon_type_options = [
      'material-symbols-outlined' => (string) $this->t('Material Symbols Outlined'),
      'material-icons' => (string) $this->t('Material Icons'),
      'material-icons-outlined' => (string) $this->t('Material Icons Outlined'),
      'material-icons-round' => (string) $this->t('Material Icons Round'),
      'material-icons-sharp' => (string) $this->t('Material Icons Sharp'),
      'material-icons-two-tone' => (string) $this->t('Material Icons Two-Tone'),
    ];

    if ($enable_fontawesome) {
      $icon_type_options['fas'] = (string) $this->t('FontAwesome Solid (fas)');
      $icon_type_options['fab'] = (string) $this->t('FontAwesome Brands (fab)');
    }

    \Drupal::moduleHandler()->alter('subadmin_toolbar_icon_types', $icon_type_options);

    $form['#attached']['library'][] = 'core/drupal.dialog.ajax';
    $form['#attached']['library'][] = 'subadmin_toolbar/admin_form';
    if ($enable_fontawesome) {
      $form['#attached']['library'][] = 'subadmin_toolbar/font_awesome';
    }
    $form['#attached']['drupalSettings']['subadminToolbar']['availableIconTypes'] = $icon_type_options;
    $form['#tree'] = TRUE;

    $form['links_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'links-wrapper'],
    ];

    $form['links_wrapper']['links'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Link Title'),
        $this->t('Configuration'),
        $this->t('Weight'),
        $this->t('Operations'),
      ],
      '#empty' => $this->t('No links added yet. Click "Add link" below.'),
      '#tabledrag' => [
        [
          'action' => 'match',
          'relationship' => 'parent',
          'group' => 'link-pid',
          'subgroup' => 'link-pid',
          'source' => 'link-id',
          'hidden' => FALSE,
          'limit' => 0,
        ],
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'link-weight',
        ],
      ],
    ];
    
    foreach ($links as $index => $item) {
      if (empty($item['id'])) {
        $item['id'] = 'link_' . uniqid();
      }

      // Calculate depth for tabledrag visual indentation
      $depth = 0;
      $current_pid = $item['pid'] ?? '';
      $loop_prevent = 0;
      while (!empty($current_pid) && $loop_prevent < 20) {
        $depth++;
        $found = false;
        foreach ($links as $p_item) {
          if (($p_item['id'] ?? '') === $current_pid) {
            $current_pid = $p_item['pid'] ?? '';
            $found = true;
            break;
          }
        }
        if (!$found) break;
        $loop_prevent++;
      }
      
      $indentation = '';
      if ($depth > 0) {
        $indent = [
          '#theme' => 'indentation',
          '#size' => $depth,
        ];
        $indentation = (string) \Drupal::service('renderer')->renderPlain($indent);
      }

      $form['links_wrapper']['links'][$index] = [
        '#attributes' => ['class' => ['draggable']],
        '#weight' => $item['weight'] ?? $index,
      ];
      
      $form['links_wrapper']['links'][$index]['title'] = [
        '#prefix' => $indentation,
        '#type' => 'textfield',
        '#title' => $this->t('Title'),
        '#title_display' => 'invisible',
        '#default_value' => $item['title'] ?? '',
        '#placeholder' => $this->t('Link Title'),
      ];
      
      // Configuration details wrapper
      $form['links_wrapper']['links'][$index]['configuration'] = [
        '#type' => 'details',
        '#title' => $this->t('Settings'),
        '#open' => empty($item['title']),
      ];

      $form['links_wrapper']['links'][$index]['configuration']['id'] = [
        '#type' => 'hidden',
        '#default_value' => $item['id'],
        '#attributes' => ['class' => ['link-id']],
      ];
      $form['links_wrapper']['links'][$index]['configuration']['pid'] = [
        '#type' => 'hidden',
        '#default_value' => $item['pid'] ?? '',
        '#attributes' => ['class' => ['link-pid']],
      ];
      $form['links_wrapper']['links'][$index]['configuration']['status'] = [
        '#type' => 'hidden',
        '#default_value' => $item['status'] ?? 'enabled',
        '#attributes' => ['class' => ['link-status']],
      ];
      $form['links_wrapper']['links'][$index]['configuration']['url'] = [
        '#type' => 'textfield',
        '#title' => $this->t('URL'),
        '#default_value' => $item['url'] ?? '',
        '#placeholder' => $this->t('e.g., /node/add'),
        '#description' => $this->t('Drupal path or full URL. Leave empty for parent container.'),
      ];
      $form['links_wrapper']['links'][$index]['configuration']['icon_type'] = [
        '#type' => 'select',
        '#title' => $this->t('Icon Font Family'),
        '#options' => $icon_type_options,
        '#default_value' => $item['icon_type'] ?? 'material-symbols-outlined',
      ];
      $form['links_wrapper']['links'][$index]['configuration']['icon'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Icon Name'),
        '#default_value' => $item['icon'] ?? '',
        '#placeholder' => $this->t('e.g., settings (or fa-cog)'),
      ];
      
      $default_roles = [];
      if (!empty($item['roles']) && is_array($item['roles'])) {
        foreach ($item['roles'] as $role) {
          $default_roles[$role] = $role;
        }
      }

      $form['links_wrapper']['links'][$index]['configuration']['roles'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Allowed Roles'),
        '#options' => $role_options,
        '#default_value' => $default_roles,
        '#description' => $this->t('If no roles are selected, the link will be visible to all authorized users.'),
      ];
      
      $form['links_wrapper']['links'][$index]['weight'] = [
        '#type' => 'weight',
        '#title' => $this->t('Weight for @title', ['@title' => $item['title'] ?? 'New link']),
        '#title_display' => 'invisible',
        '#default_value' => $item['weight'] ?? $index,
        '#delta' => 100,
        '#attributes' => ['class' => ['link-weight']],
      ];
      
      $form['links_wrapper']['links'][$index]['operations'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove'),
        '#name' => 'remove_link_' . $item['id'],
        '#submit' => ['::removeLinkSubmit'],
        '#ajax' => [
          'callback' => '::linksAjaxCallback',
          'wrapper' => 'links-wrapper',
        ],
        '#limit_validation_errors' => [['links_wrapper']],
        '#attributes' => ['class' => ['subadmin-btn-remove-row']],
      ];
    }
    
    $form['links_wrapper']['actions']['add'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add link'),
      '#name' => 'add_link_button',
      '#submit' => ['::addLinkSubmit'],
      '#ajax' => [
        'callback' => '::linksAjaxCallback',
        'wrapper' => 'links-wrapper',
      ],
      '#limit_validation_errors' => [['links_wrapper']],
      '#attributes' => ['class' => ['subadmin-btn-add-row']],
    ];

    return parent::buildForm($form, $form_state);
  }

  public function linksAjaxCallback(array &$form, FormStateInterface $form_state) {
    return $form['links_wrapper'];
  }

  protected function updateLinksFromSubmittedValues(FormStateInterface $form_state) {
    $submitted = $form_state->getValue(['links_wrapper', 'links']);
    if (!is_array($submitted)) {
      $submitted = [];
    }
    
    $links = [];
    foreach ($submitted as $index => $item) {
      if (is_numeric($index)) {
        $config = $item['configuration'] ?? [];
        $roles = isset($config['roles']) ? array_filter($config['roles']) : [];
        $links[] = [
          'id' => $config['id'] ?? '',
          'pid' => $config['pid'] ?? '',
          'status' => $config['status'] ?? 'enabled',
          'title' => $item['title'] ?? '',
          'url' => $config['url'] ?? '',
          'icon_type' => $config['icon_type'] ?? '',
          'icon' => $config['icon'] ?? '',
          'roles' => array_values($roles),
          'weight' => $item['weight'] ?? 0,
        ];
      }
    }

    
    // Sort links hierarchically and by weight so AJAX rebuilds maintain tree
    $links = $this->sortLinksTree($links);
    
    // Update the state with current values so we don't lose typed text
    $form_state->set('links', $links);
    return $links;
  }

  /**
   * Sorts a flat array of links into a flat array ordered hierarchically.
   */
  protected function sortLinksTree(array $links) {
    if (empty($links)) {
      return [];
    }
    
    $tree = [];
    foreach ($links as $link) {
      $pid = $link['pid'] ?? '';
      $tree[$pid][] = $link;
    }
    
    $flat_links = [];
    $process_level = function($pid) use (&$tree, &$flat_links, &$process_level) {
      if (isset($tree[$pid])) {
        $level = $tree[$pid];
        usort($level, function($a, $b) {
          return ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0);
        });
        foreach ($level as $item) {
          $flat_links[] = $item;
          $process_level($item['id']);
        }
      }
    };
    $process_level('');
    
    return $flat_links;
  }

  public function addLinkSubmit(array &$form, FormStateInterface $form_state) {
    $links = $this->updateLinksFromSubmittedValues($form_state);
    $links[] = [
      'id' => 'link_' . uniqid(),
      'pid' => '',
      'status' => 'enabled',
      'title' => '',
      'url' => '',
      'icon_type' => 'material-symbols-outlined',
      'icon' => '',
      'roles' => [],
      'weight' => count($links),
    ];
    
    // Limpiar el user_input para que FormBuilder use los nuevos #default_value
    $user_input = $form_state->getUserInput();
    unset($user_input['links_wrapper']);
    $form_state->setUserInput($user_input);

    $form_state->set('links', $links);
    $form_state->setRebuild();
  }

  public function removeLinkSubmit(array &$form, FormStateInterface $form_state) {
    $user_input = $form_state->getUserInput();
    $triggering_element = $form_state->getTriggeringElement();
    
    // Obtener el ID único del enlace directamente desde el nombre del botón activado
    $removed_id = NULL;
    
    if (isset($triggering_element['#name']) && strpos($triggering_element['#name'], 'remove_link_') === 0) {
      $removed_id = substr($triggering_element['#name'], strlen('remove_link_'));
    }
    
    if (!$removed_id && is_array($user_input)) {
      foreach ($user_input as $key => $value) {
        if (strpos($key, 'remove_link_') === 0) {
          $removed_id = substr($key, strlen('remove_link_'));
          break;
        }
      }
    }
    
    // Fallback mediante la estructura de padres
    if (!$removed_id) {
      $parents = $triggering_element['#array_parents'] ?? $triggering_element['#parents'] ?? [];
      $row_key = $parents[2] ?? NULL;
      $submitted = $form_state->getValue(['links_wrapper', 'links']);
      if ($row_key !== NULL && isset($submitted[$row_key]['configuration']['id'])) {
        $removed_id = $submitted[$row_key]['configuration']['id'];
      }
    }

    $links = $this->updateLinksFromSubmittedValues($form_state);
    
    if ($removed_id) {
      foreach ($links as $k => $v) {
        if ($v['id'] === $removed_id || $v['pid'] === $removed_id) {
          unset($links[$k]);
        }
      }
      $links = array_values($links);
    }
    
    // Limpiar el user_input de la tabla de enlaces para obligar a FormBuilder 
    // a usar los valores actualizados de $links (#default_value) y no los valores enviados previamente.
    $user_input = $form_state->getUserInput();
    unset($user_input['links_wrapper']);
    $form_state->setUserInput($user_input);

    $form_state->set('links', $links);
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $links = $this->updateLinksFromSubmittedValues($form_state);

    $this->config('subadmin_toolbar.settings')
      ->set('links', $links)
      ->save();

    parent::submitForm($form, $form_state);
  }

}

