<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\SmokeCheck;

use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\markaspot_health\SmokeCheckPluginBase;
use Drupal\markaspot_health\SmokeCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Verifies the Drupal admin login page responds 200.
 *
 * The /user/login route is one of the simplest controllers that touches
 * the form system. A type-variance fatal in any FormBase subclass loaded
 * through the form_builder service trickles down to this route in some
 * cases, so this check doubles as a backstop for the
 * DrushCrSubprocessCheck.
 *
 * @SmokeCheck(
 *   id = "http_backend_login_page",
 *   label = @Translation("HTTP /user/login"),
 *   severity = "error",
 *   category = "http_sanity",
 *   description = @Translation("GET /user/login via HttpKernel must respond 200."),
 *   fix_hint = @Translation("Check FormBase subclasses for typed promoted properties that conflict with parent untyped properties."),
 * )
 */
class HttpBackendLoginPageCheck extends SmokeCheckPluginBase {

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected HttpKernelInterface $httpKernel,
    protected AccountSwitcherInterface $accountSwitcher,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_kernel'),
      $container->get('account_switcher'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): SmokeCheckResult {
    $mode = $this->mode($context);
    $request = Request::create('/user/login', 'GET');
    $this->accountSwitcher->switchTo(new AnonymousUserSession());
    try {
      $response = $this->httpKernel->handle($request, HttpKernelInterface::SUB_REQUEST);
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
    $status = $response->getStatusCode();
    $evidence = ['status_code' => $status];

    if ($status === 200) {
      return $this->pass('GET /user/login responded 200.', $evidence, $mode);
    }
    return $this->fail(1, sprintf('GET /user/login responded %d, expected 200.', $status), $evidence, $mode);
  }

}
