<?php

namespace Drupal\subadmin_toolbar\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;

/**
 * Configure global settings (appearance) for Subadmin Toolbar.
 */
class GeneralSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['subadmin_toolbar.general'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'subadmin_toolbar_general_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('subadmin_toolbar.general');

    $form['toolbar_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Toolbar Brand Title'),
      '#description' => $this->t('The main title of the toolbar on the left. Leave empty to hide the text.'),
      '#default_value' => $config->get('toolbar_title') !== NULL ? $config->get('toolbar_title') : 'Gestión',
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
      '#default_value' => $config->get('hide_icon') ?? FALSE,
    ];

    $form['toolbar_title_link'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Toolbar Title Link'),
      '#description' => $this->t('The URL the toolbar title links to. Leave empty to disable the link.'),
      '#default_value' => $config->get('toolbar_title_link') ?? '',
    ];

    $module_handler = \Drupal::moduleHandler();
    $material_local = $module_handler->moduleExists('subadmin_toolbar_local_material_icons');
    $fontawesome_local = $module_handler->moduleExists('subadmin_toolbar_local_fontawesome');

    $form['icons_package'] = [
      '#type' => 'details',
      '#title' => $this->t('Icon Packages (Fonts)'),
      '#open' => TRUE,
    ];

    $form['icons_package']['material_icons_status'] = [
      '#type' => 'item',
      '#title' => $this->t('Google Material Icons & Symbols'),
      '#markup' => $material_local
        ? '<span style="color: #2e7d32; font-weight: 600;">✔ ' . $this->t('Local: Served via @module', ['@module' => 'native_material']) . '</span>'
        : '<span style="color: #ed6c02; font-weight: 500;">🌐 ' . $this->t('CDN: Served via Google Fonts CDN') . '</span>',
      '#description' => $this->t('Always active for the toolbar. Enable the %sub module to serve these fonts locally.', ['%sub' => 'subadmin_toolbar_local_material_icons']),
    ];

    $form['icons_package']['enable_font_awesome'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Font Awesome'),
      '#description' => $this->t('Check this to allow Font Awesome (fa-*) icons. Status: @status.', [
        '@status' => $fontawesome_local
          ? $this->t('Local: Served via @module', ['@module' => 'native_fontawesome'])
          : $this->t('CDN: Served via cdnjs.cloudflare.com'),
      ]),
      '#default_value' => (bool) ($config->get('enable_font_awesome') ?? FALSE),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('subadmin_toolbar.general');
    
    $config->set('toolbar_title', $form_state->getValue('toolbar_title'));
    $config->set('accent_color', $form_state->getValue('accent_color'));
    
    // Process the managed file
    $icon_file = $form_state->getValue('toolbar_icon');
    if (!empty($icon_file[0])) {
      $file = \Drupal\file\Entity\File::load($icon_file[0]);
      if ($file && !$file->isPermanent()) {
        $file->setPermanent();
        $file->save();
        
        $file_usage = \Drupal::service('file.usage');
        $file_usage->add($file, 'subadmin_toolbar', 'config', $file->id());
      }
      $config->set('toolbar_icon', $icon_file[0]);
    } else {
      // User removed the file
      $old_fid = $config->get('toolbar_icon');
      if ($old_fid) {
        $file = \Drupal\file\Entity\File::load($old_fid);
        if ($file) {
          $file_usage = \Drupal::service('file.usage');
          $file_usage->delete($file, 'subadmin_toolbar', 'config', $file->id());
        }
      }
      $config->set('toolbar_icon', NULL);
    }
    
    $config->set('toolbar_title_link', $form_state->getValue('toolbar_title_link'));
    $config->set('hide_icon', $form_state->getValue('hide_icon'));
    $config->set('enable_font_awesome', (bool) $form_state->getValue('enable_font_awesome'));
    
    $config->save();
    parent::submitForm($form, $form_state);
  }

}
