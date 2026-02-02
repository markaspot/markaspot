<?php

namespace Drupal\fa_icon_class\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\iconify_field\Service\IconResolverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for icon field operations.
 */
class FaIconController extends ControllerBase {

  /**
   * The icon resolver service.
   *
   * @var \Drupal\iconify_field\Service\IconResolverInterface|null
   */
  protected ?IconResolverInterface $iconResolver;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected RendererInterface $renderer;

  /**
   * FaIconController constructor.
   */
  public function __construct(?IconResolverInterface $icon_resolver, RendererInterface $renderer) {
    $this->iconResolver = $icon_resolver;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $icon_resolver = NULL;
    if ($container->has('iconify_field.icon_resolver')) {
      $icon_resolver = $container->get('iconify_field.icon_resolver');
    }

    return new static(
      $icon_resolver,
      $container->get('renderer')
    );
  }

  /**
   * A simple page to explain to the developer what to do.
   */
  public function description() {
    return [
      '#markup' => "The Field Example provides a field composed of an HTML RGB value, like #ff00ff. To use it, add the field to a content type.",
    ];
  }

  /**
   * Render an icon as SVG.
   *
   * @param string $icon
   *   The icon name in format collection:name (e.g., lucide:tree-pine).
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The SVG response.
   */
  public function renderIcon(string $icon): Response {
    if (!$this->iconResolver) {
      return new Response('Icon resolver not available', 503, ['Content-Type' => 'text/plain']);
    }

    // Decode the icon name (may be URL encoded).
    $icon = urldecode($icon);

    // Validate icon name format: must be collection:name pattern.
    // Only allow alphanumeric characters, dashes, and underscores.
    if (!preg_match('/^[a-z0-9_-]+:[a-z0-9_-]+$/i', $icon)) {
      return new Response('Invalid icon format', 400, ['Content-Type' => 'text/plain']);
    }

    // Get the rendered icon.
    $render_array = $this->iconResolver->getIcon($icon, ['width' => '24', 'height' => '24']);
    $svg = $this->renderer->renderRoot($render_array);

    // Basic SVG validation - ensure we got valid SVG output.
    if (empty($svg) || strpos((string) $svg, '<svg') === FALSE) {
      return new Response('Icon not found', 404, ['Content-Type' => 'text/plain']);
    }

    return new Response($svg, 200, [
      'Content-Type' => 'image/svg+xml',
      'Cache-Control' => 'public, max-age=86400',
    ]);
  }

}
