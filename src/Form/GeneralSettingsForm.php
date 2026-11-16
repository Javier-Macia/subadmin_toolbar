<?php

declare(strict_types=1);

namespace Drupal\subadmin_toolbar\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\subadmin_toolbar\Appearance;
use Drupal\subadmin_toolbar\ColorContrast;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure global settings (appearance) for Subadmin Toolbar.
 */
class GeneralSettingsForm extends ToolbarFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    EntityTypeManagerInterface $entity_type_manager,
    ModuleHandlerInterface $module_handler,
    protected FileUsageInterface $fileUsage,
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
      $container->get('file.usage'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['subadmin_toolbar.general'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'subadmin_toolbar_general_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('subadmin_toolbar.general');

    $form['toolbar_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Toolbar Brand Title'),
      '#description' => $this->t('The main title of the toolbar on the left. Leave empty to hide the text.'),
      '#default_value' => $config->get('toolbar_title') ?? 'Gestión',
    ];

    $form['accent_color'] = [
      '#type' => 'color',
      '#title' => $this->t('Accent Color'),
      '#description' => $this->t('The primary hover/accent color of the toolbar.'),
      '#default_value' => $config->get('accent_color') ?? '#0073ff',
    ];

    $form['toolbar_icon'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Toolbar logo'),
      '#description' => $this->t('Upload a logo to replace the default one. Recommended size: between 24x24 and 48x48 pixels (PNG or SVG with transparent background).'),
      '#upload_location' => 'public://subadmin_toolbar_icons/',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'png gif jpg jpeg svg'],
      ],
      '#default_value' => $config->get('toolbar_icon') ? [$config->get('toolbar_icon')] : [],
    ];

    $form['hide_icon'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide the logo'),
      '#description' => $this->t('Show only the title, without any logo.'),
      '#default_value' => (bool) $config->get('hide_icon'),
    ];

    $form['toolbar_title_link'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Toolbar Title Link'),
      '#description' => $this->t('The URL the toolbar title links to. Leave empty to disable the link.'),
      '#default_value' => $config->get('toolbar_title_link') ?? '',
    ];

    $material_local = $this->moduleHandler->moduleExists('subadmin_toolbar_local_material_icons');
    $fontawesome_local = $this->moduleHandler->moduleExists('subadmin_toolbar_local_fontawesome');

    $form['icons_package'] = [
      '#type' => 'details',
      '#title' => $this->t('Icon fonts'),
      '#open' => TRUE,
    ];

    $form['icons_package']['material_icons_status'] = [
      '#type' => 'item',
      '#title' => $this->t('Google Material Icons & Symbols'),
      '#plain_text' => $material_local
        ? $this->t('Served from this site (@module)', ['@module' => 'native_material'])
        : $this->t('Loaded from Google Fonts'),
      '#description' => $this->t('Always on. To load these icons from this site instead of Google, which is better for privacy, enable the %sub module.', ['%sub' => 'subadmin_toolbar_local_material_icons']),
    ];

    $form['icons_package']['enable_font_awesome'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Font Awesome'),
      '#description' => $this->t('Lets you use Font Awesome icons in the links. They are currently @status.', [
        '@status' => $fontawesome_local
          ? $this->t('served from this site (@module)', ['@module' => 'native_fontawesome'])
          : $this->t('loaded from cdnjs.cloudflare.com'),
      ]),
      '#default_value' => (bool) $config->get('enable_font_awesome'),
    ];

    $this->buildAppearance($form, Appearance::normalize($config->get('appearance')));

    return parent::buildForm($form, $form_state);
  }

  /**
   * Adds the appearance section, with a live preview, to the form.
   *
   * @param array $form
   *   The form.
   * @param array $values
   *   The validated appearance values.
   */
  protected function buildAppearance(array &$form, array $values): void {
    $form['#attached']['library'][] = 'subadmin_toolbar/admin_form';
    $form['#attached']['drupalSettings']['subadminToolbarPreview'] = [
      'radius' => Appearance::RADIUS,
      'shadow' => array_map(static fn (array $pair): string => $pair[1], Appearance::SHADOWS),
      'fonts' => Appearance::FONTS,
      'colorDefaults' => Appearance::COLOR_DEFAULTS,
      'minimumRatio' => ColorContrast::MINIMUM_TEXT_RATIO,
      'contrastMessage' => (string) $this->t('In @mode mode, the text color has a contrast ratio of @ratio:1 against the @pair, below the recommended 4.5:1.'),
      'modeNames' => $this->modeNames(TRUE),
      'pairNames' => array_map(
        static fn (string $label): string => mb_strtolower($label),
        array_intersect_key($this->colorLabels(), array_flip(Appearance::CONTRAST_BACKGROUNDS)),
      ),
    ];

    $form['appearance'] = [
      '#type' => 'details',
      '#title' => $this->t('Appearance'),
      '#description' => $this->t('Fine-tune how the toolbar looks and behaves. Every option has a default that matches the stylesheet.'),
      '#open' => FALSE,
      '#tree' => TRUE,
    ];
    $group = &$form['appearance'];

    $group['preview'] = [
      '#type' => 'inline_template',
      '#template' => '<div class="subadmin-toolbar-preview-group">{% for mode, label in modes %}<div class="sat-preview" data-preview-mode="{{ mode }}" aria-hidden="true"><div class="sat-preview__bar"><span class="sat-preview__brand">{{ label }}</span><span class="sat-preview__item">{{ link }}</span><span class="sat-preview__item sat-preview__item--hover">{{ link }}<span class="sat-preview__caret material-symbols-outlined" translate="no">expand_more</span></span></div><div class="sat-preview__submenu"><span class="sat-preview__subitem"><span class="sat-preview__icon material-symbols-outlined" translate="no">home</span>{{ sub }}</span><span class="sat-preview__subitem"><span class="sat-preview__icon material-symbols-outlined" translate="no">settings</span>{{ sub }}</span></div></div>{% endfor %}</div>',
      '#context' => [
        'modes' => $this->modeNames(),
        'link' => $this->t('Link'),
        'sub' => $this->t('Submenu item'),
      ],
    ];

    $select = fn (string $title, string $key, array $options) => [
      '#type' => 'select',
      '#title' => $title,
      '#options' => $options,
      '#default_value' => (string) $values[$key],
    ];
    $pixels = static function (string $key): array {
      $options = [];
      foreach (Appearance::CHOICES[$key] as $value) {
        $options[(string) $value] = $value . ' px';
      }
      return $options;
    };

    $group['layout'] = [
      '#type' => 'details',
      '#title' => $this->t('Layout'),
      '#description' => $this->t('The toolbar sits at the top of the page, or becomes a sidebar on the left or right.'),
      '#open' => TRUE,
    ];
    $group['layout']['position'] = $select((string) $this->t('Position'), 'position', [
      'top' => $this->t('Top'),
      'left' => $this->t('Left'),
      'right' => $this->t('Right'),
    ]);
    $sidebar_only = [':input[name="appearance[layout][position]"]' => ['!value' => 'top']];
    $group['layout']['sidebar_mode'] = $select((string) $this->t('Sidebar mode'), 'sidebar_mode', [
      'fixed' => $this->t('Fixed'),
      'collapsible' => $this->t('Collapsible'),
    ]);
    $group['layout']['sidebar_mode']['#states'] = ['visible' => $sidebar_only];
    $group['layout']['sidebar_width'] = $select((string) $this->t('Sidebar width'), 'sidebar_width', $pixels('sidebar_width'));
    $group['layout']['sidebar_width']['#states'] = ['visible' => $sidebar_only];
    $group['layout']['sidebar_start_collapsed'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Start the sidebar collapsed'),
      '#default_value' => $values['sidebar_start_collapsed'],
      '#states' => ['visible' => $sidebar_only + [':input[name="appearance[layout][sidebar_mode]"]' => ['value' => 'collapsible']]],
    ];

    $group['dimensions'] = [
      '#type' => 'details',
      '#title' => $this->t('Dimensions'),
      '#open' => TRUE,
    ];
    $height = $pixels('height');
    $height['48'] = $this->t('Compact (48 px)');
    $height['56'] = $this->t('Normal (56 px)');
    $height['64'] = $this->t('Roomy (64 px)');
    $group['dimensions']['height'] = $select((string) $this->t('Height'), 'height', $height);
    $group['dimensions']['item_padding'] = $select((string) $this->t('Horizontal padding of top-level items'), 'item_padding', $pixels('item_padding'));
    $group['dimensions']['submenu_min_width'] = $select((string) $this->t('Minimum width of submenus'), 'submenu_min_width', $pixels('submenu_min_width'));
    $group['dimensions']['icon_size'] = $select((string) $this->t('Icon size'), 'icon_size', $pixels('icon_size'));
    $group['dimensions']['icon_opacity'] = $select((string) $this->t('Icon opacity'), 'icon_opacity', array_combine(
      Appearance::CHOICES['icon_opacity'],
      array_map(static fn (string $opacity): string => ((float) $opacity * 100) . '%', Appearance::CHOICES['icon_opacity']),
    ));

    $group['typography'] = [
      '#type' => 'details',
      '#title' => $this->t('Typography'),
      '#description' => $this->t("Only fonts already installed on the visitor's device are used; no font file is downloaded."),
      '#open' => FALSE,
    ];
    $group['typography']['font_family'] = $select((string) $this->t('Font family'), 'font_family', [
      'default' => $this->t('Default'),
      'system' => $this->t('System sans-serif'),
      'humanist' => $this->t('Humanist sans-serif'),
      'serif' => $this->t('Serif'),
      'mono' => $this->t('Monospace'),
    ]);
    $group['typography']['font_size'] = $select((string) $this->t('Font size'), 'font_size', $pixels('font_size'));
    $group['typography']['font_weight'] = $select((string) $this->t('Font weight'), 'font_weight', [
      '300' => $this->t('Light (300)'),
      '400' => $this->t('Regular (400)'),
      '500' => $this->t('Medium (500)'),
      '600' => $this->t('Semibold (600)'),
      '700' => $this->t('Bold (700)'),
    ]);
    $group['typography']['letter_spacing'] = $select((string) $this->t('Letter spacing'), 'letter_spacing', [
      '-0.01em' => $this->t('Tight'),
      '0' => $this->t('Normal'),
      '0.01em' => $this->t('Slightly wide'),
      '0.02em' => $this->t('Wide'),
      '0.04em' => $this->t('Very wide'),
    ]);

    $group['shape'] = [
      '#type' => 'details',
      '#title' => $this->t('Shape and depth'),
      '#open' => FALSE,
    ];
    $group['shape']['radius'] = $select((string) $this->t('Corner radius of submenus and split buttons'), 'radius', [
      'default' => $this->t('Default'),
      'square' => $this->t('Square (0 px)'),
      'xs' => '2 px',
      'sm' => '4 px',
      'soft' => $this->t('Soft (6 px)'),
      'md' => '8 px',
      'round' => $this->t('Round (12 px)'),
      'lg' => '16 px',
      'xl' => '20 px',
    ]);
    $group['shape']['shadow'] = $select((string) $this->t('Shadow'), 'shadow', [
      'none' => $this->t('None'),
      'soft' => $this->t('Soft'),
      'medium' => $this->t('Medium'),
      'strong' => $this->t('Strong'),
    ]);
    $group['shape']['border_bottom'] = $select((string) $this->t('Line below the toolbar'), 'border_bottom', [
      'none' => $this->t('None'),
      'line' => $this->t('Thin line'),
    ]);

    $labels = $this->colorLabels();
    $group['colors'] = [
      '#type' => 'details',
      '#title' => $this->t('Colors'),
      '#description' => $this->t('Leave a color on "Inherit" to keep the toolbar default. The dark colors apply when the site uses its dark mode.'),
      '#open' => FALSE,
    ];
    foreach (Appearance::MODES as $mode) {
      $group['colors'][$mode] = [
        '#type' => 'details',
        '#title' => $mode === 'light' ? $this->t('Light mode colors') : $this->t('Dark mode colors'),
        '#open' => FALSE,
      ];
      foreach (Appearance::COLOR_KEYS as $key) {
        $custom = $values['colors'][$mode][$key];
        $checkbox_name = 'appearance[colors][' . $mode . '][' . $key . '][inherit]';
        $group['colors'][$mode][$key] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['subadmin-appearance-color']],
          'value' => [
            '#type' => 'color',
            '#title' => $labels[$key],
            '#default_value' => $custom !== '' ? $custom : Appearance::COLOR_DEFAULTS[$mode][$key],
            '#states' => ['disabled' => [':input[name="' . $checkbox_name . '"]' => ['checked' => TRUE]]],
          ],
          'inherit' => [
            '#type' => 'checkbox',
            '#title' => $this->t('Inherit'),
            '#default_value' => $custom === '',
          ],
        ];
      }
    }

    $group['colors']['contrast_hint'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['subadmin-contrast-hint', 'messages', 'messages--warning'],
        'role' => 'status',
        'hidden' => TRUE,
      ],
      '#weight' => 10,
    ];

    $group['hover'] = [
      '#type' => 'details',
      '#title' => $this->t('Hover behavior'),
      '#description' => $this->t('Applies to submenus on desktop screens.'),
      '#open' => FALSE,
    ];
    $none = $this->t('None');
    $delays = static function (string $key) use ($none): array {
      $options = [];
      foreach (Appearance::CHOICES[$key] as $value) {
        $options[(string) $value] = $value === 0 ? (string) $none : $value . ' ms';
      }
      return $options;
    };
    $group['hover']['open_delay'] = $select((string) $this->t('Open delay'), 'open_delay', $delays('open_delay'));
    $group['hover']['close_delay'] = $select((string) $this->t('Close delay'), 'close_delay', $delays('close_delay'));
    $group['hover']['show_submenu_icons'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show icons inside submenus'),
      '#default_value' => $values['show_submenu_icons'],
    ];

    $group['indicator_group'] = [
      '#type' => 'details',
      '#title' => $this->t('Parent indicator'),
      '#open' => FALSE,
    ];
    $group['indicator_group']['indicator'] = $select((string) $this->t('Indicator style for items with a submenu'), 'indicator', [
      'caret' => $this->t('Caret'),
      'plus_minus' => $this->t('Plus and minus'),
      'none' => $this->t('None (split buttons show a text label)'),
    ]);
    $group['indicator_group']['indicator']['#description'] = $this->t('With "None", items that only open a submenu and have no link of their own show no cue at all that they contain a submenu.');

    $group['reset_appearance'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset appearance to defaults'),
      '#submit' => ['::resetAppearance'],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['button--danger', 'subadmin-reset-appearance'],
        'data-confirm' => (string) $this->t('Reset every appearance option to its default? Unsaved changes in this section are lost.'),
      ],
    ];
  }

  /**
   * Reads the submitted appearance values and validates them.
   */
  protected function getSubmittedAppearance(FormStateInterface $form_state): array {
    $raw = (array) $form_state->getValue('appearance');
    foreach (['layout', 'dimensions', 'typography', 'shape', 'hover'] as $section) {
      foreach ((array) ($raw[$section] ?? []) as $key => $value) {
        $raw[$key] = $value;
      }
    }
    $raw['indicator'] = $raw['indicator_group']['indicator'] ?? NULL;
    foreach (Appearance::MODES as $mode) {
      foreach (Appearance::COLOR_KEYS as $key) {
        $field = $raw['colors'][$mode][$key] ?? [];
        $raw['colors'][$mode][$key] = empty($field['inherit']) ? (string) ($field['value'] ?? '') : '';
      }
    }
    return Appearance::normalize($raw);
  }

  /**
   * Form submission handler: restores the default appearance.
   */
  public function resetAppearance(array &$form, FormStateInterface $form_state): void {
    $this->config('subadmin_toolbar.general')
      ->set('appearance', Appearance::defaults())
      ->save();
    $this->messenger()->addStatus($this->t('The appearance has been reset to its defaults.'));
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    if (!$this->isValidUrl(trim((string) $form_state->getValue('toolbar_title_link')))) {
      $form_state->setErrorByName('toolbar_title_link', $this->t('Enter a path starting with "/" or a valid external URL.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('subadmin_toolbar.general');
    $old_fid = $config->get('toolbar_icon');
    $new_fid = $form_state->getValue(['toolbar_icon', 0]);

    if ($new_fid != $old_fid) {
      $file_storage = $this->entityTypeManager->getStorage('file');
      if ($old_fid && ($old_file = $file_storage->load($old_fid))) {
        $this->fileUsage->delete($old_file, 'subadmin_toolbar', 'config', $old_fid);
      }
      if ($new_fid && ($new_file = $file_storage->load($new_fid))) {
        $new_file->setPermanent();
        $new_file->save();
        $this->fileUsage->add($new_file, 'subadmin_toolbar', 'config', $new_fid);
      }
    }

    $appearance = $this->getSubmittedAppearance($form_state);
    $config
      ->set('toolbar_title', $form_state->getValue('toolbar_title'))
      ->set('accent_color', $form_state->getValue('accent_color'))
      ->set('toolbar_icon', $new_fid ? (int) $new_fid : NULL)
      ->set('toolbar_title_link', trim((string) $form_state->getValue('toolbar_title_link')))
      ->set('hide_icon', (bool) $form_state->getValue('hide_icon'))
      ->set('enable_font_awesome', (bool) $form_state->getValue('enable_font_awesome'))
      ->set('appearance', $appearance)
      ->save();

    $modes = $this->modeNames(TRUE);
    $labels = $this->colorLabels();
    foreach (Appearance::contrastIssues($appearance) as $issue) {
      $this->messenger()->addWarning($this->t('In @mode mode, the text color has a contrast ratio of @ratio:1 against the @pair, below the recommended 4.5:1, so the text may be hard to read.', [
        '@mode' => $modes[$issue['mode']],
        '@ratio' => number_format($issue['ratio'], 2),
        '@pair' => mb_strtolower((string) $labels[$issue['background']]),
      ]));
    }

    if (self::contrastAgainstWhite((string) $form_state->getValue('accent_color')) < 3) {
      $this->messenger()->addWarning($this->t('The accent color has low contrast against the white toolbar, so the focus ring and hover state may be hard to see. Choose a darker color.'));
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * Returns the names of the color schemes.
   *
   * @param bool $lowercase
   *   Whether to return the lowercase variants used inside sentences.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]|string[]
   *   The names, keyed by mode.
   */
  protected function modeNames(bool $lowercase = FALSE): array {
    $options = ['context' => 'Color scheme'];
    if ($lowercase) {
      return [
        'light' => (string) $this->t('light', [], $options),
        'dark' => (string) $this->t('dark', [], $options),
      ];
    }
    return [
      'light' => $this->t('Light', [], $options),
      'dark' => $this->t('Dark', [], $options),
    ];
  }

  /**
   * Returns the labels of the colors, keyed by color key.
   */
  protected function colorLabels(): array {
    return [
      'bg' => $this->t('Background'),
      'text' => $this->t('Text'),
      'hover_bg' => $this->t('Hover background'),
      'submenu_bg' => $this->t('Submenu background'),
      'border' => $this->t('Border'),
    ];
  }

  /**
   * Calculates the WCAG contrast ratio of a hex color against white.
   */
  protected static function contrastAgainstWhite(string $hex): float {
    return ColorContrast::ratio($hex, '#ffffff') ?? 21.0;
  }

}
