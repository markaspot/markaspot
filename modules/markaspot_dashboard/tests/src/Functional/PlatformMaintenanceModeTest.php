<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_dashboard\Functional;

use Drupal\Core\Test\AssertMailTrait;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;

/**
 * Exercises Core maintenance-mode routing through the real HTTP stack.
 *
 * This test intentionally uses the Mark-a-Spot installation profile. The
 * dashboard and passwordless routes are profile modules, and the recovery
 * behavior must be verified with their real route definitions and services,
 * not a hand-built RouteCollection.
 */
#[Group('markaspot_dashboard')]
class PlatformMaintenanceModeTest extends BrowserTestBase {

  use AssertMailTrait;

  /**
   * {@inheritdoc}
   */
  protected static $configSchemaCheckerExclusions = [
    'core.entity_form_display.media.catalog_image.default',
    'core.entity_form_display.media.request_image.default',
    'core.entity_form_display.node.page.default',
    'core.entity_form_display.node.service_request.default',
    'core.entity_form_display.node.service_request.management',
    'core.entity_form_display.taxonomy_term.internal_status.default',
    'core.entity_form_display.taxonomy_term.service_category.default',
    'core.entity_form_display.taxonomy_term.service_status.default',
    'core.entity_view_display.media.request_image.default',
    'core.entity_view_display.taxonomy_term.service_status.default',
    'core.entity_view_mode.media.full',
    'field.field.media.catalog_image.field_definition_group',
    'field.field.media.catalog_image.field_media_image',
    'field.field.media.request_image.field_media_image',
    'field.field.group.jur.field_service_categories',
    'field.field.group.jur.field_service_statuses',
    'field.field.node.page.field_page_icon',
    'field.field.node.service_request.field_district',
    'field.field.node.service_request.field_geolocation',
    'field.field.node.service_request.field_request_media',
    'field.field.node.service_request.field_status',
    'field.field.node.service_request.field_sublocality',
    'field.field.paragraph.status.field_status_term',
    'field.field.taxonomy_term.service_category.field_category_hex',
    'field.field.taxonomy_term.service_category.field_category_icon',
    'field.field.taxonomy_term.service_status.field_status_icon',
    'field.storage.media.field_definition_group',
    'field.storage.media.field_media_image',
    'field.storage.node.field_page_icon',
    'field.storage.node.field_request_media',
    'language.content_settings.media.request_image',
    'markaspot_icons.settings',
    'markaspot_request_id.settings',
    'markaspot_validation.settings',
    'media.type.catalog_image',
    'media.type.request_image',
    'phpmailer_smtp.format',
    'phpmailer_smtp.settings',
    'search_api.index.service_requests',
    'views.view.categories',
    'views.view.group_nodes',
    'views.view.management',
    'views.view.stats',
    'views.view.stats_requests',
  ];

  /**
   * {@inheritdoc}
   */
  protected $profile = 'markaspot';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'markaspot_dashboard',
    'markaspot_passwordless',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Core blocks ordinary routes but preserves only the declared recovery set.
   */
  public function testCoreMaintenanceRoutingAndPlatformSwitchAccess(): void {
    $this->setMaintenanceMode(TRUE);

    $this->drupalGet('/system/404');
    $this->assertSession()->statusCodeEquals(503);

    $this->drupalGet('/api/platform/maintenance');
    $this->assertSession()->statusCodeEquals(200);
    self::assertSame(['maintenance' => TRUE], json_decode($this->getSession()->getPage()->getContent(), TRUE));
    self::assertSame('no-store, private', $this->getSession()->getResponseHeader('Cache-Control'));

    $this->drupalGet('/api/platform/maintenance/access');
    $this->assertSession()->statusCodeEquals(200);
    self::assertSame(['maintenance_access' => FALSE], json_decode($this->getSession()->getPage()->getContent(), TRUE));

    $anonymous_csrf = $this->drupalGet('/session/token');
    $this->assertSession()->statusCodeEquals(200);
    self::assertNotSame('', $anonymous_csrf);

    // An allowlisted OTP-adjacent route reaches its controller, whereas the
    // SSO handoff route remains behind Core's global 503.
    $otp_response = $this->jsonRequest('POST', '/api/auth/request-code', [
      'email' => 'nobody@example.com',
    ]);
    self::assertSame(200, $otp_response->getStatusCode());
    $status_response = $this->jsonRequest('GET', '/api/auth/status', []);
    self::assertSame(200, $status_response->getStatusCode());
    $this->drupalGet('/api/auth/session-handoff/start');
    $this->assertSession()->statusCodeEquals(503);

    // Accounts without Core's maintenance exemption never reach the PATCH
    // access checks, regardless of cookie authentication or a CSRF token.
    self::assertSame(503, $this->maintenancePatch(['maintenance' => FALSE])->getStatusCode());
    self::assertSame(503, $this->maintenancePatch(['maintenance' => FALSE], 'not-a-token')->getStatusCode());
    self::assertSame(503, $this->maintenancePatch(['maintenance' => FALSE], $anonymous_csrf)->getStatusCode());

    $tenant_admin = $this->createUserWithRole('tenant_admin');
    $this->setMaintenanceMode(FALSE);
    $this->drupalLogin($tenant_admin);
    $this->setMaintenanceMode(TRUE);
    $tenant_csrf = $this->drupalGet('/session/token');
    self::assertSame(503, $this->maintenancePatch(['maintenance' => FALSE], $tenant_csrf)->getStatusCode());

    // The dashboard gets a deliberately minimal capability answer for an
    // existing Core-maintenance-exempt session. This is not an admin grant.
    $maintenance_exempt = $this->drupalCreateUser(['access site in maintenance mode']);
    $this->setMaintenanceMode(FALSE);
    $this->drupalResetSession();
    $this->drupalLogin($maintenance_exempt);
    $this->setMaintenanceMode(TRUE);
    $this->drupalGet('/api/platform/maintenance/access');
    $this->assertSession()->statusCodeEquals(200);
    self::assertSame(['maintenance_access' => TRUE], json_decode($this->getSession()->getPage()->getContent(), TRUE));

    $administrator = $this->createUserWithRole('administrator');
    $this->setMaintenanceMode(FALSE);
    $this->drupalResetSession();
    $this->drupalLogin($administrator);
    $this->setMaintenanceMode(TRUE);
    $administrator_csrf = $this->drupalGet('/session/token');
    $administrator_response = $this->maintenancePatch(['maintenance' => FALSE], $administrator_csrf);
    self::assertSame(200, $administrator_response->getStatusCode(), (string) $administrator_response->getBody());
    self::assertSame(['maintenance' => FALSE], json_decode((string) $administrator_response->getBody(), TRUE));
    self::assertFalse((bool) $this->container->get('state')->get('system.maintenance_mode'));

    // UID 1 is the second deliberately narrow escape hatch. Log in before
    // turning maintenance back on because logout is not an allowlisted route.
    $this->drupalResetSession();
    $this->drupalLogin($this->rootUser);
    $this->setMaintenanceMode(TRUE);
    $root_csrf = $this->drupalGet('/session/token');
    self::assertSame(200, $this->maintenancePatch(['maintenance' => FALSE], $root_csrf)->getStatusCode());
  }

  /**
   * Recovery OTP bypasses a disabled normal feature without auto-registering.
   */
  public function testBreakGlassOtpOnlyAuthenticatesExistingMaintenanceUsers(): void {
    $this->config('markaspot_nuxt.settings')
      ->set('platform_features.passwordless', FALSE)
      ->save();
    $this->config('markaspot_passwordless.settings')
      ->set('auto_register', TRUE)
      ->save();
    $this->setMaintenanceMode(TRUE);

    $eligible = $this->drupalCreateUser(['access site in maintenance mode']);
    $eligible_email = $eligible->getEmail();
    $noneligible = $this->drupalCreateUser([]);

    $before = count($this->getMails());
    $noneligible_response = $this->jsonRequest('POST', '/api/auth/request-code', [
      'email' => $noneligible->getEmail(),
    ]);
    self::assertSame(200, $noneligible_response->getStatusCode());
    self::assertSame([
      'success' => TRUE,
      'message' => 'If the account is eligible, a verification code has been sent.',
      'expiresIn' => 600,
    ], json_decode((string) $noneligible_response->getBody(), TRUE));
    self::assertCount($before, $this->getMails());

    $unknown_email = 'not-yet-a-user@example.com';
    $unknown_response = $this->jsonRequest('POST', '/api/auth/request-code', [
      'email' => $unknown_email,
    ]);
    self::assertSame(200, $unknown_response->getStatusCode());
    self::assertSame(
      json_decode((string) $noneligible_response->getBody(), TRUE),
      json_decode((string) $unknown_response->getBody(), TRUE),
    );
    self::assertCount($before, $this->getMails());
    self::assertSame([], $this->container->get('entity_type.manager')
      ->getStorage('user')
      ->loadByProperties(['mail' => $unknown_email]));

    $eligible_response = $this->jsonRequest('POST', '/api/auth/request-code', [
      'email' => $eligible_email,
    ]);
    self::assertSame(200, $eligible_response->getStatusCode());
    $mails = $this->getMails();
    self::assertCount($before + 1, $mails);
    $mail = end($mails);
    self::assertIsArray($mail);
    $body = implode("\n", (array) $mail['body']);
    self::assertMatchesRegularExpression('/\b(\d{6})\b/', $body);
    preg_match('/\b(\d{6})\b/', $body, $matches);

    $verify_response = $this->jsonRequest('POST', '/api/auth/verify-code', [
      'email' => $eligible_email,
      'code' => $matches[1],
    ]);
    self::assertSame(200, $verify_response->getStatusCode());
    $verify_payload = json_decode((string) $verify_response->getBody(), TRUE);
    self::assertTrue($verify_payload['success']);
    self::assertSame((int) $eligible->id(), (int) $verify_payload['user']['uid']);

    // The newly created session must carry Drupal Core's exemption too. A
    // normal route would still be a 503 for every nonexempt account.
    $this->drupalGet('/system/404');
    $this->assertSession()->statusCodeEquals(404);

    $noneligible_verify = $this->jsonRequest('POST', '/api/auth/verify-code', [
      'email' => $noneligible->getEmail(),
      'code' => '123456',
    ]);
    self::assertSame(401, $noneligible_verify->getStatusCode());
    self::assertSame(['error' => 'Invalid verification code'], json_decode((string) $noneligible_verify->getBody(), TRUE));
  }

  /**
   * Creates a site-wide role fixture with no maintenance permission.
   */
  private function createUserWithRole(string $role_id) {
    if (!Role::load($role_id)) {
      Role::create([
        'id' => $role_id,
        'label' => $role_id,
      ])->save();
    }

    $account = $this->drupalCreateUser();
    $account->addRole($role_id);
    $account->save();

    return $account;
  }

  /**
   * Sends a JSON request through BrowserTestBase's authenticated HTTP client.
   */
  private function jsonRequest(string $method, string $path, array $payload, ?string $csrf_token = NULL): ResponseInterface {
    $headers = [
      'Accept' => 'application/json',
      'Content-Type' => 'application/json',
    ];
    if ($csrf_token !== NULL) {
      $headers['X-CSRF-Token'] = trim($csrf_token);
    }

    $cookies = $this->getSessionCookies();
    $response = $this->getHttpClient()->request($method, $this->getAbsoluteUrl($path), [
      'body' => json_encode($payload, JSON_THROW_ON_ERROR),
      'cookies' => $cookies,
      'headers' => $headers,
      'http_errors' => FALSE,
    ]);
    foreach ($cookies->toArray() as $cookie) {
      if ($cookie['Name'] === $this->getSessionName()) {
        $this->getSession()->setCookie($this->getSessionName(), $cookie['Value']);
      }
    }
    $this->refreshVariables();
    return $response;
  }

  /**
   * Sends the cookie and CSRF guarded global-maintenance mutation.
   */
  private function maintenancePatch(array $payload, ?string $csrf_token = NULL): ResponseInterface {
    return $this->jsonRequest('PATCH', '/api/platform/maintenance', $payload, $csrf_token);
  }

  /**
   * Changes Core's global maintenance state in the current test site.
   */
  private function setMaintenanceMode(bool $enabled): void {
    $this->container->get('state')->set('system.maintenance_mode', $enabled);
  }

}
