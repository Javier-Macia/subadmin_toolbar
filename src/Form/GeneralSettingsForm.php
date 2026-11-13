<?php

declare(strict_types=1);

namespace Drupal\subadmin_toolbar\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileUsage\FileUsageInterface;
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

    return parent::buildForm($form, $form_state);
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

    $config
      ->set('toolbar_title', $form_state->getValue('toolbar_title'))
      ->set('accent_color', $form_state->getValue('accent_color'))
      ->set('toolbar_icon', $new_fid ? (int) $new_fid : NULL)
      ->set('toolbar_title_link', trim((string) $form_state->getValue('toolbar_title_link')))
      ->set('hide_icon', (bool) $form_state->getValue('hide_icon'))
      ->set('enable_font_awesome', (bool) $form_state->getValue('enable_font_awesome'))
      ->save();

    if (self::contrastAgainstWhite((string) $form_state->getValue('accent_color')) < 3) {
      $this->messenger()->addWarning($this->t('The accent color has low contrast against the white toolbar, so the focus ring and hover state may be hard to see. Choose a darker color.'));
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * Calculates the WCAG contrast ratio of a hex color against white.
   */
  protected static function contrastAgainstWhite(string $hex): float {
    if (!preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $m)) {
      return 21.0;
    }
    $luminance = 0.0;
    foreach ([0.2126, 0.7152, 0.0722] as $i => $weight) {
      $c = hexdec($m[$i + 1]) / 255;
      $luminance += $weight * ($c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4);
    }
    return 1.05 / ($luminance + 0.05);
  }

}
