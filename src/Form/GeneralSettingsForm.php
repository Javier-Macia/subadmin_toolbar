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
      '#title' => $this->t('Toolbar Brand Icon (Image)'),
      '#description' => $this->t('Upload an image/logo to replace the default SVG logomark. Recommended size: 24x24px to 48x48px (transparent PNG or SVG).'),
      '#upload_location' => 'public://subadmin_toolbar_icons/',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'png gif jpg jpeg svg'],
      ],
      '#default_value' => $config->get('toolbar_icon') ? [$config->get('toolbar_icon')] : [],
    ];

    $form['hide_icon'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide Toolbar Icon'),
      '#description' => $this->t('Check this to remove the logo/icon entirely from the toolbar brand area.'),
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
      '#title' => $this->t('Icon Packages (Fonts)'),
      '#open' => TRUE,
    ];

    $form['icons_package']['material_icons_status'] = [
      '#type' => 'item',
      '#title' => $this->t('Google Material Icons & Symbols'),
      '#plain_text' => $material_local
        ? $this->t('Local: served via @module', ['@module' => 'native_material'])
        : $this->t('CDN: served via Google Fonts'),
      '#description' => $this->t('Always active for the toolbar. Enable the %sub module to serve these fonts locally.', ['%sub' => 'subadmin_toolbar_local_material_icons']),
    ];

    $form['icons_package']['enable_font_awesome'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Font Awesome'),
      '#description' => $this->t('Check this to allow Font Awesome (fa-*) icons. Status: @status.', [
        '@status' => $fontawesome_local
          ? $this->t('Local: served via @module', ['@module' => 'native_fontawesome'])
          : $this->t('CDN: served via cdnjs.cloudflare.com'),
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

    parent::submitForm($form, $form_state);
  }

}
