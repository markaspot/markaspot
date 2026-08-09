<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\HealthCheck;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\markaspot_health\HealthCheckPluginBase;
use Drupal\markaspot_health\HealthCheckResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Detects jurisdictions the shared api_user cannot read.
 *
 * The headless frontend never talks to Drupal as an anonymous visitor. The
 * Nuxt proxy attaches the tenant API key, so every public map request arrives
 * as the shared api_user account, and the readable scope is derived from that
 * account's jurisdiction memberships.
 *
 * A jurisdiction without such a membership is invisible to citizens even
 * though its reports are published and the workspace is public. The failure is
 * silent by construction: with a jurisdiction_id the endpoint answers 403, and
 * without one it answers 200 with an empty list. Neither reaches a log, the
 * map simply looks like a place where nobody has reported anything.
 *
 * That state ran unnoticed for days on a live platform because every smoke
 * test and every boot probe authenticates, and an authenticated read uses a
 * different code path. This check exists to make the citizen's view
 * measurable from the inside.
 *
 * @HealthCheck(
 *   id = "api_user_jurisdiction_scope",
 *   label = @Translation("api_user jurisdiction read scope"),
 *   severity = "error",
 *   description = @Translation("Lists jurisdictions the shared api_user is not a member of; their reports are invisible to anonymous visitors on the public map."),
 *   fix_hint = @Translation("Run the markaspot_group backfill (update 11947) or add api_user to the jurisdiction with the {type}-member role. A membership without a role is not enough for the dashboard path."),
 * )
 */
final class ApiUserJurisdictionScopeCheck extends HealthCheckPluginBase {

  /**
   * The account name the Nuxt proxy authenticates as.
   */
  private const API_USER_NAME = 'api_user';

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): HealthCheckResult {
    $groups = $this->entityTypeManager->getStorage('group')
      ->loadByProperties(['type' => 'jur']);
    if ($groups === []) {
      return $this->pass('No jurisdiction groups on this install; check skipped.');
    }

    $accounts = $this->entityTypeManager->getStorage('user')
      ->loadByProperties(['name' => self::API_USER_NAME]);
    $account = reset($accounts);
    if (!$account) {
      // Single-tenant installs may serve the frontend without a proxy account.
      // Reporting that as a failure would be noise, not a finding.
      return $this->pass('No api_user account on this install; check skipped.');
    }

    $relationships = $this->entityTypeManager->getStorage('group_relationship')
      ->loadByProperties([
        'entity_id' => $account->id(),
        'plugin_id' => 'group_membership',
      ]);
    $memberGids = [];
    foreach ($relationships as $relationship) {
      $memberGids[(int) $relationship->getGroup()->id()] = TRUE;
    }

    $unreadable = [];
    foreach ($groups as $group) {
      if (isset($memberGids[(int) $group->id()])) {
        continue;
      }
      // Report the label, not just the ID: an operator reading the health
      // output needs to recognise the tenant, not look it up first.
      $unreadable[] = sprintf('%s (%s)', $group->label(), $group->id());
    }

    if ($unreadable === []) {
      return $this->pass(sprintf(
        'api_user can read all %d jurisdiction(s).',
        count($groups),
      ));
    }

    return $this->fail(
      count($unreadable),
      sprintf(
        '%d of %d jurisdiction(s) invisible to anonymous visitors because api_user is not a member: %s.',
        count($unreadable),
        count($groups),
        implode(', ', $unreadable),
      ),
    );
  }

}
