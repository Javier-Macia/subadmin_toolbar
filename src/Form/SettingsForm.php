<?php

declare(strict_types=1);

namespace Drupal\subadmin_toolbar\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure the menu links of the Subadmin Toolbar.
 */
class SettingsForm extends ToolbarFormBase {

  /**
   * Maximum nesting depth when drawing the tabledrag indentation.
   */
  protected const MAX_DEPTH = 20;

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    EntityTypeManagerInterface $entity_type_manager,
    ModuleHandlerInterface $module_handler,
    protected RendererInterface $renderer,
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
      $container->get('renderer'),
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
    return 'subadmin_toolbar_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $role_options = $this->getRoleOptions();

    $links = $form_state->get('links');
    if ($links === NULL) {
      $links = $this->config('subadmin_toolbar.settings')->get('links') ?: [];
      $form_state->set('links', $links);
    }

    $enable_fontawesome = (bool) $this->config('subadmin_toolbar.general')->get('enable_font_awesome');
    $icon_type_options = $this->getIconTypeOptions($enable_fontawesome);

    $form['#attached']['library'][] = 'core/drupal.dialog.ajax';
    $form['#attached']['library'][] = 'subadmin_toolbar/admin_form';
    if ($enable_fontawesome) {
      $form['#attached']['library'][] = 'subadmin_toolbar/font_awesome';
    }
    $form['#attached']['drupalSettings']['subadminToolbar']['availableIconTypes'] = $icon_type_options;
    $form['#attached']['drupalSettings']['subadminToolbar']['iconSuggestions'] = $this->getIconSuggestions();
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

    $parent_by_id = [];
    foreach ($links as $link) {
      if (!empty($link['id'])) {
        $parent_by_id[$link['id']] = $link['pid'] ?? '';
      }
    }

    foreach ($links as $index => $item) {
      if (empty($item['id'])) {
        $item['id'] = $this->generateId();
      }

      $row = &$form['links_wrapper']['links'][$index];
      $row = [
        '#attributes' => ['class' => ['draggable']],
        '#weight' => $item['weight'] ?? $index,
      ];

      $row['title'] = [
        '#prefix' => $this->buildIndentation($item['pid'] ?? '', $parent_by_id),
        '#type' => 'textfield',
        '#title' => $this->t('Title'),
        '#title_display' => 'invisible',
        '#default_value' => $item['title'] ?? '',
        '#placeholder' => $this->t('Link Title'),
      ];

      $row['configuration'] = [
        '#type' => 'details',
        '#title' => $this->t('Settings'),
        '#open' => empty($item['title']),
        'id' => [
          '#type' => 'hidden',
          '#default_value' => $item['id'],
          '#attributes' => ['class' => ['link-id']],
        ],
        'pid' => [
          '#type' => 'hidden',
          '#default_value' => $item['pid'] ?? '',
          '#attributes' => ['class' => ['link-pid']],
        ],
        'status' => [
          '#type' => 'hidden',
          '#default_value' => $item['status'] ?? 'enabled',
          '#attributes' => ['class' => ['link-status']],
        ],
        'url' => [
          '#type' => 'textfield',
          '#title' => $this->t('URL'),
          '#default_value' => $item['url'] ?? '',
          '#placeholder' => $this->t('e.g., /node/add'),
          '#description' => $this->t('Drupal path starting with "/" or full URL. Leave empty for parent container.'),
        ],
        'icon_type' => [
          '#type' => 'select',
          '#title' => $this->t('Icon Font Family'),
          '#options' => $icon_type_options,
          '#default_value' => $item['icon_type'] ?? 'material-symbols-outlined',
        ],
        'icon' => [
          '#type' => 'textfield',
          '#title' => $this->t('Icon Name'),
          '#default_value' => $item['icon'] ?? '',
          '#placeholder' => $this->t('e.g., settings (or fa-cog)'),
        ],
        'roles' => $this->markEditorGroup([
          '#type' => 'checkboxes',
          '#title' => $this->t('Allowed Roles'),
          '#options' => $role_options,
          '#default_value' => array_combine($item['roles'] ?? [], $item['roles'] ?? []),
          '#description' => $this->t('If no roles are selected, the link will be visible to all authorized users.'),
        ], 'roles'),
      ];
      $this->alterItemConfiguration($row['configuration'], $item, 'link');

      $row['weight'] = [
        '#type' => 'weight',
        '#title' => $this->t('Weight for @title', ['@title' => $item['title'] ?? 'New link']),
        '#title_display' => 'invisible',
        '#default_value' => $item['weight'] ?? $index,
        '#delta' => 100,
        '#attributes' => ['class' => ['link-weight']],
      ];

      $row['operations'] = [
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
      unset($row);
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

  /**
   * Ajax callback for the link table operations.
   */
  public function linksAjaxCallback(array &$form, FormStateInterface $form_state): array {
    return $form['links_wrapper'];
  }

  /**
   * Returns the icon families available in the link form.
   */
  protected function getIconTypeOptions(bool $enable_fontawesome): array {
    $options = [
      'material-symbols-outlined' => (string) $this->t('Material Symbols Outlined'),
      'material-icons' => (string) $this->t('Material Icons'),
      'material-icons-outlined' => (string) $this->t('Material Icons Outlined'),
      'material-icons-round' => (string) $this->t('Material Icons Round'),
      'material-icons-sharp' => (string) $this->t('Material Icons Sharp'),
      'material-icons-two-tone' => (string) $this->t('Material Icons Two-Tone'),
    ];
    if ($enable_fontawesome) {
      $options['fas'] = (string) $this->t('FontAwesome Solid (fas)');
      $options['fab'] = (string) $this->t('FontAwesome Brands (fab)');
    }
    $this->moduleHandler->alter('subadmin_toolbar_icon_types', $options);
    return $options;
  }

  /**
   * Renders the tabledrag indentation for the given parent chain.
   *
   * @param string $pid
   *   The parent ID of the row.
   * @param string[] $parent_by_id
   *   Parent ID of every link, keyed by link ID.
   */
  protected function buildIndentation(string $pid, array $parent_by_id): string {
    $depth = 0;
    while ($pid !== '' && isset($parent_by_id[$pid]) && $depth < self::MAX_DEPTH) {
      $depth++;
      $pid = $parent_by_id[$pid];
    }
    if (!$depth) {
      return '';
    }
    $indent = ['#theme' => 'indentation', '#size' => $depth];
    return (string) $this->renderer->renderInIsolation($indent);
  }

  /**
   * Generates an ID for a new link.
   */
  protected function generateId(): string {
    return 'link_' . uniqid();
  }

  /**
   * Normalizes the submitted rows into the stored structure.
   */
  protected function updateLinksFromSubmittedValues(FormStateInterface $form_state): array {
    $submitted = $form_state->getValue(['links_wrapper', 'links']);
    $links = [];
    foreach (is_array($submitted) ? $submitted : [] as $index => $item) {
      if (!is_numeric($index)) {
        continue;
      }
      $config = $item['configuration'] ?? [];
      $link = [
        'id' => $config['id'] ?? '',
        'pid' => $config['pid'] ?? '',
        'status' => $config['status'] ?? 'enabled',
        'title' => $item['title'] ?? '',
        'url' => trim($config['url'] ?? ''),
        'icon_type' => $config['icon_type'] ?? '',
        'icon' => $config['icon'] ?? '',
        'roles' => array_values(array_filter($config['roles'] ?? [])),
        'weight' => (int) ($item['weight'] ?? 0),
      ];
      $this->alterItemValues($link, $config, 'link');
      $links[] = $link;
    }

    // Keep the tree order so AJAX rebuilds don't lose the hierarchy, and keep
    // the typed values so they survive the rebuild.
    $links = $this->sortLinksTree($links);
    $form_state->set('links', $links);
    return $links;
  }

  /**
   * Sorts a flat list of links hierarchically and by weight.
   */
  protected function sortLinksTree(array $links): array {
    $children = [];
    foreach ($links as $link) {
      $children[$link['pid'] ?? ''][] = $link;
    }

    $sorted = [];
    $visit = function (string $pid) use (&$visit, &$children, &$sorted): void {
      $level = $children[$pid] ?? [];
      // Each branch is visited once, which also guards against cycles.
      unset($children[$pid]);
      usort($level, fn(array $a, array $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));
      foreach ($level as $item) {
        $sorted[] = $item;
        $visit((string) $item['id']);
      }
    };
    $visit('');

    return $sorted;
  }

  /**
   * Submit callback for adding a link.
   */
  public function addLinkSubmit(array &$form, FormStateInterface $form_state): void {
    $links = $this->updateLinksFromSubmittedValues($form_state);
    $links[] = [
      'id' => $this->generateId(),
      'pid' => '',
      'status' => 'enabled',
      'title' => '',
      'url' => '',
      'icon_type' => 'material-symbols-outlined',
      'icon' => '',
      'roles' => [],
      'weight' => count($links),
    ];

    $this->resetSubmittedInput($form_state, 'links_wrapper');
    $form_state->set('links', $links);
    $form_state->setRebuild();
  }

  /**
   * Submit callback for removing a link and all of its descendants.
   */
  public function removeLinkSubmit(array &$form, FormStateInterface $form_state): void {
    $removed = [substr($form_state->getTriggeringElement()['#name'], strlen('remove_link_'))];
    $links = $this->updateLinksFromSubmittedValues($form_state);

    // Collect the removed link and its descendants.
    do {
      $found = FALSE;
      foreach ($links as $link) {
        if (in_array($link['pid'], $removed, TRUE) && !in_array($link['id'], $removed, TRUE)) {
          $removed[] = $link['id'];
          $found = TRUE;
        }
      }
    } while ($found);

    $links = array_values(array_filter($links, fn(array $link) => !in_array($link['id'], $removed, TRUE)));

    $this->resetSubmittedInput($form_state, 'links_wrapper');
    $form_state->set('links', $links);
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Only validate the URLs when saving, not on the add/remove AJAX actions.
    if (($form_state->getTriggeringElement()['#name'] ?? '') !== 'op') {
      return;
    }
    foreach ($form_state->getValue(['links_wrapper', 'links']) ?? [] as $index => $item) {
      if (!$this->isValidUrl(trim($item['configuration']['url'] ?? ''))) {
        $form_state->setErrorByName(
          "links_wrapper][links][$index][configuration][url",
          $this->t('The URL of %title must start with "/" or be a valid external URL.', ['%title' => $item['title'] ?? ''])
        );
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('subadmin_toolbar.settings')
      ->set('links', $this->updateLinksFromSubmittedValues($form_state))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
