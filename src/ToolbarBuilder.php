<?php

declare(strict_types=1);

namespace Drupal\subadmin_toolbar;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Plugin\Context\ContextHandlerInterface;
use Drupal\Core\Plugin\Context\ContextRepositoryInterface;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the render array of the Subadmin Toolbar for the current user.
 */
class ToolbarBuilder {

  /**
   * Default accent color, also used when the stored value is not valid.
   */
  public const DEFAULT_ACCENT_COLOR = '#0073ff';

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected AccountInterface $currentUser,
    protected BlockManagerInterface $blockManager,
    protected ContextRepositoryInterface $contextRepository,
    protected ContextHandlerInterface $contextHandler,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Builds the toolbar render array.
   *
   * @return array
   *   A render array. It only carries cacheability metadata when the user has
   *   no access or there is nothing to show.
   */
  public function build(): array {
    $build = [
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => ['config:subadmin_toolbar.settings', 'config:subadmin_toolbar.general'],
      ],
    ];

    if (!$this->currentUser->hasPermission('access subadmin toolbar')) {
      return $build;
    }

    $build['#cache']['contexts'][] = 'user.roles';
    $config = $this->configFactory->get('subadmin_toolbar.settings');
    $roles = $this->currentUser->getRoles();

    // Collects what other modules' visibility rules depend on.
    $metadata = CacheableMetadata::createFromRenderArray($build);
    $links = $this->buildLinks($config->get('links') ?: [], $roles, $metadata);
    $widgets = $this->buildWidgets($config->get('widgets') ?: [], $roles, $metadata);
    if (!$links && !$widgets) {
      $metadata->applyTo($build);
      return $build;
    }

    $general = $this->configFactory->get('subadmin_toolbar.general');

    $icon_url = NULL;
    if ($fid = $general->get('toolbar_icon')) {
      $file = $this->entityTypeManager->getStorage('file')->load($fid);
      if ($file) {
        $icon_url = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
        $metadata->addCacheableDependency($file);
      }
    }

    $title = $general->get('toolbar_title');
    $accent_color = (string) $general->get('accent_color');

    $build += [
      '#theme' => 'subadmin_toolbar',
      '#links' => $links,
      '#widgets' => $widgets,
      '#toolbar_title' => $title ?? 'Gestión',
      '#toolbar_icon_url' => $icon_url,
      '#accent_color' => preg_match('/^#[0-9a-f]{6}$/i', $accent_color) ? $accent_color : self::DEFAULT_ACCENT_COLOR,
      '#toolbar_title_link' => UrlHelper::stripDangerousProtocols((string) $general->get('toolbar_title_link')),
      '#hide_icon' => (bool) $general->get('hide_icon'),
      '#attached' => [
        'library' => array_filter([
          'subadmin_toolbar/toolbar',
          $general->get('enable_font_awesome') ? 'subadmin_toolbar/font_awesome' : NULL,
        ]),
      ],
      '#weight' => -100,
    ];
    $metadata->applyTo($build);

    return $build;
  }

  /**
   * Whether an item is enabled and allowed for the current user.
   *
   * Other modules can refine the role check, which is made for the current
   * user, with hook_subadmin_toolbar_item_visibility_alter().
   */
  protected function isVisible(array $item, array $user_roles, CacheableMetadata $cacheability): bool {
    if (($item['status'] ?? 'enabled') === 'disabled') {
      return FALSE;
    }
    $allowed_roles = array_filter($item['roles'] ?? []);
    $visible = !$allowed_roles || array_intersect($allowed_roles, $user_roles);
    $this->moduleHandler->alter('subadmin_toolbar_item_visibility', $visible, $item, $cacheability);
    return (bool) $visible;
  }

  /**
   * Filters the stored links by role and arranges them as a tree.
   */
  protected function buildLinks(array $stored_links, array $user_roles, CacheableMetadata $cacheability): array {
    $links = [];
    foreach ($stored_links as $index => $link) {
      if (empty($link['title']) || !$this->isVisible($link, $user_roles, $cacheability)) {
        continue;
      }
      $id = !empty($link['id']) ? $link['id'] : 'link_' . $index;
      $links[$id] = [
        'id' => $id,
        'pid' => $link['pid'] ?? '',
        'title' => $link['title'],
        'url' => empty($link['url']) ? '#' : UrlHelper::stripDangerousProtocols($link['url']),
        'icon' => $link['icon'] ?? '',
        'icon_type' => $link['icon_type'] ?? 'material-symbols-outlined',
      ];
    }

    // Links whose parent is missing (or hidden) are promoted to the root.
    $children = [];
    foreach ($links as $id => $link) {
      $parent = $link['pid'] !== $id && isset($links[$link['pid']]) ? $link['pid'] : '';
      $children[$parent][] = $id;
    }
    return $this->buildTree($links, $children, '');
  }

  /**
   * Recursively nests links under their parents.
   */
  protected function buildTree(array $links, array $children, string $parent): array {
    $tree = [];
    foreach ($children[$parent] ?? [] as $id) {
      $tree[$id] = $links[$id] + ['below' => $this->buildTree($links, $children, $id)];
    }
    return $tree;
  }

  /**
   * Renders the widgets visible to the given roles.
   */
  protected function buildWidgets(array $stored_widgets, array $user_roles, CacheableMetadata $cacheability): array {
    $widgets = [];
    foreach ($stored_widgets as $index => $widget) {
      if (!$this->isVisible($widget, $user_roles, $cacheability)) {
        continue;
      }
      if ($render = $this->buildWidget($widget, 'widget_' . $index)) {
        $widgets[] = $render;
      }
    }
    return $widgets;
  }

  /**
   * Renders an existing block plugin as a toolbar widget.
   */
  protected function buildWidget(array $widget, string $fallback_id): array {
    $plugin_id = $widget['plugin_id'] ?? '';
    if (!$plugin_id || !$this->blockManager->hasDefinition($plugin_id)) {
      return [];
    }

    $configuration = $widget['settings'] ?? [];
    $configuration['label_display'] = !empty($widget['label_display']) ? 'visible' : FALSE;
    if (!empty($widget['label'])) {
      $configuration['label'] = $widget['label'];
    }

    try {
      /** @var \Drupal\Core\Block\BlockPluginInterface $plugin */
      $plugin = $this->blockManager->createInstance($plugin_id, $configuration);

      if ($plugin instanceof ContextAwarePluginInterface) {
        $contexts = $this->contextRepository->getRuntimeContexts($plugin->getContextMapping());
        $this->contextHandler->applyContextMapping($plugin, $contexts);
      }

      $access = $plugin->access($this->currentUser, TRUE);
      if (!$access->isAllowed()) {
        return [];
      }

      $content = $plugin->build();
      if (Element::isEmpty($content)) {
        return [];
      }

      $build = [
        '#theme' => 'block',
        '#attributes' => [
          'class' => [
            'subadmin-toolbar-widget',
            'subadmin-widget-' . Html::cleanCssIdentifier($plugin_id),
          ],
        ],
        '#configuration' => $plugin->getConfiguration(),
        '#plugin_id' => $plugin->getPluginId(),
        '#base_plugin_id' => $plugin->getBaseId(),
        '#derivative_plugin_id' => $plugin->getDerivativeId(),
        '#id' => 'sat_' . ($widget['id'] ?? $fallback_id),
        'content' => $content,
      ];
      CacheableMetadata::createFromObject($access)
        ->merge(CacheableMetadata::createFromObject($plugin))
        ->applyTo($build);

      return $build;
    }
    catch (\Throwable $e) {
      $this->logger->error('Widget @plugin could not be rendered: @message', [
        '@plugin' => $plugin_id,
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

}
