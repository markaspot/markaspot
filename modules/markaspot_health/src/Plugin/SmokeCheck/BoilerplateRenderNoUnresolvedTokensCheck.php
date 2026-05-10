<?php

declare(strict_types=1);

namespace Drupal\markaspot_health\Plugin\SmokeCheck;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\markaspot_health\SmokeCheckPluginBase;
use Drupal\markaspot_health\SmokeCheckResult;
use Drupal\Core\Utility\Token;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders every boilerplate body and flags unresolved [token] literals.
 *
 * Boilerplates are response templates with placeholder tokens like
 * `[node:title]`, `[request:address]`, `[markaspot:tenant_email_footer]`.
 * The Token service substitutes them at send-time. When a template references
 * a token that does not exist (typo, removed module, renamed field), Drupal
 * leaves the literal `[…]` in the rendered output and the citizen receives a
 * mail with broken placeholders. This catches that pre-deploy.
 *
 * Strategy: load every published boilerplate (scoped to context jurisdiction
 * when present), pick a representative service_request from the same scope as
 * the token replacement context, render the body via Token::replace, and scan
 * for residual `[…]` patterns. Tokens that legitimately resolve to an empty
 * string are not flagged because they leave no bracketed text behind.
 *
 * Read-only: nothing is written. Lives next to the mutating F-21 plugin in
 * the smoke catalog (the issue groups editorial-track checks together) but
 * is safe to run in default --mode=read-only and therefore in CI.
 *
 * @SmokeCheck(
 *   id = "boilerplate_render_no_unresolved_tokens",
 *   label = @Translation("Boilerplate rendering: no unresolved tokens"),
 *   severity = "warning",
 *   category = "georeport",
 *   mutates = FALSE,
 *   description = @Translation("Renders every boilerplate body via the Token service and flags any literal [token] strings that did not resolve. Catches typos and tokens removed from a previous release."),
 *   fix_hint = @Translation("Open the offending boilerplate at /admin/content?type=boilerplate and either remove the broken token or replace it with one defined by an enabled module."),
 * )
 */
class BoilerplateRenderNoUnresolvedTokensCheck extends SmokeCheckPluginBase {

  /**
   * Maximum number of offending placeholder rows surfaced in details.
   */
  private const DETAILS_LIMIT = 25;

  /**
   * Token-shaped placeholder pattern.
   *
   * Matches `[entity:field]` and `[entity:field:sub]` style tokens but not
   * arbitrary square-bracket text like `[1]` or `[draft]` — anchoring on a
   * colon plus a strict lowercase-snake-case entity prefix avoids
   * false-positives on prose with bracketed citations like `[ECHR:Article]`,
   * `[See:Section]`, or time references like `[12:30]`. Drupal's Token
   * service emits all tokens in lowercase, so the case-insensitive flag is
   * deliberately absent.
   */
  private const TOKEN_REGEX = '/\[[a-z][a-z0-9_]*:[a-z0-9_:-]+\]/';

  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Token $tokenService,
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
      $container->get('token'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(array $context = []): SmokeCheckResult {
    $mode = $this->mode($context);

    if (!$this->entityTypeManager->hasDefinition('node')) {
      return $this->skip('Node module not enabled; check skipped.', [], $mode);
    }
    $bundles = (array) $this->entityTypeManager->getStorage('node_type')
      ->getQuery()->accessCheck(FALSE)->execute();
    if (!in_array('boilerplate', $bundles, TRUE)) {
      return $this->skip('node:boilerplate bundle not present; check skipped.', [], $mode);
    }

    $boilerplates = $this->loadBoilerplates($context);
    if ($boilerplates === []) {
      return $this->skip('No published boilerplates to render; check skipped.', [], $mode);
    }

    $serviceRequest = $this->loadRepresentativeServiceRequest($context);
    $replacementContext = ['node' => $serviceRequest];

    $details = [];
    $offenders = 0;
    foreach ($boilerplates as $boilerplate) {
      $body = $this->extractBody($boilerplate);
      if ($body === '') {
        continue;
      }
      $rendered = $this->tokenService->replace($body, $replacementContext, ['clear' => FALSE]);
      if (preg_match_all(self::TOKEN_REGEX, $rendered, $matches) <= 0) {
        continue;
      }
      $unique = array_values(array_unique($matches[0]));
      $offenders++;
      if (count($details) < self::DETAILS_LIMIT) {
        $details[] = [
          'boilerplate_id' => (int) $boilerplate->id(),
          'boilerplate_label' => (string) $boilerplate->label(),
          'unresolved_tokens' => $unique,
        ];
      }
    }

    $evidence = [
      'boilerplates_inspected' => count($boilerplates),
      'context_node_id' => $serviceRequest ? (int) $serviceRequest->id() : NULL,
      'unique_offenders' => $offenders,
    ];

    if ($offenders === 0) {
      return $this->pass(
        sprintf('Rendered %d boilerplate(s) with no unresolved tokens.', count($boilerplates)),
        $evidence,
        $mode,
        $context['jurisdiction'] ?? NULL,
      );
    }

    return $this->fail(
      $offenders,
      sprintf(
        '%d boilerplate(s) rendered with literal [token:…] strings still in the output.',
        $offenders,
      ),
      $evidence,
      $mode,
      $details,
      max(0, $offenders - count($details)),
      $context['jurisdiction'] ?? NULL,
    );
  }

  /**
   * Loads published boilerplates, scoped to context['jurisdiction'] when set.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Boilerplate nodes ready for token rendering.
   */
  protected function loadBoilerplates(array $context): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'boilerplate')
      ->condition('status', 1);
    $jur = $context['jurisdiction'] ?? NULL;
    if (is_int($jur) && $jur > 0) {
      $fieldDefs = $this->entityTypeManager->getStorage('field_config')
        ->loadByProperties([
          'entity_type' => 'node',
          'bundle' => 'boilerplate',
          'field_name' => 'field_jurisdiction',
        ]);
      if ($fieldDefs !== []) {
        $query->condition('field_jurisdiction', $jur);
      }
    }
    $ids = (array) $query->execute();
    return $ids === [] ? [] : $storage->loadMultiple($ids);
  }

  /**
   * Picks a service_request for token replacement context.
   *
   * Boilerplate tokens like [node:title] need a node in scope to render. We
   * pick the most recent published service_request, scoped to the same
   * jurisdiction when known, so the replacement context resembles real
   * sending conditions.
   */
  protected function loadRepresentativeServiceRequest(array $context) {
    $bundles = (array) $this->entityTypeManager->getStorage('node_type')
      ->getQuery()->accessCheck(FALSE)->execute();
    if (!in_array('service_request', $bundles, TRUE)) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service_request')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->range(0, 1);
    $jur = $context['jurisdiction'] ?? NULL;
    if (is_int($jur) && $jur > 0) {
      $fieldDefs = $this->entityTypeManager->getStorage('field_config')
        ->loadByProperties([
          'entity_type' => 'node',
          'bundle' => 'service_request',
          'field_name' => 'field_jurisdiction',
        ]);
      if ($fieldDefs !== []) {
        $query->condition('field_jurisdiction', $jur);
      }
    }
    $ids = (array) $query->execute();
    if ($ids === []) {
      return NULL;
    }
    return $storage->load((int) reset($ids));
  }

  /**
   * Extracts the body string from a boilerplate node, handling field shape.
   */
  protected function extractBody($node): string {
    if (!$node->hasField('body') || $node->get('body')->isEmpty()) {
      return '';
    }
    return (string) ($node->get('body')->value ?? '');
  }

}
