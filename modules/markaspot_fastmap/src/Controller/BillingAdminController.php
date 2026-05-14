<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\markaspot_fastmap\Service\BillingStateResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Operator-only admin listing of jurisdiction tenant billing state.
 *
 * Lists every group of bundle `jur` with its computed lifecycle state, tier,
 * Stripe identifiers, billing email and expiry. Intended for the platform
 * operator (Civic Patches GmbH) so they can see at a glance which tenants
 * are demo / pending checkout / paid / unknown.
 *
 * Permission `view fastmap billing admin` is restrict-access TRUE and is
 * NOT granted to tenant_admin under any circumstance — tenant admins only
 * see their own jurisdiction's billing via BillingController.
 */
class BillingAdminController extends ControllerBase {

  /**
   * Page size for the listing.
   */
  private const PAGE_SIZE = 25;

  /**
   * Allowed state filter values (plus 'all').
   *
   * @var string[]
   */
  private const STATE_FILTERS = [
    'all',
    'demo',
    'pending_checkout',
    'paid',
    'free_permanent',
    'unknown',
  ];

  /**
   * Allowed tier filter values (plus 'all').
   *
   * @var string[]
   */
  private const TIER_FILTERS = ['all', 'free', 'starter', 'pro', 'heart'];

  /**
   * Tailwind-ish badge classes per state (controlled vocabulary).
   *
   * @var array<string, string>
   */
  private const STATE_BADGE_CLASSES = [
    'demo' => 'color-warning',
    'pending_checkout' => 'color-info',
    'paid' => 'color-success',
    'free_permanent' => 'color-success',
    'unknown' => 'color-neutral',
  ];

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The fastmap logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $fastmapLogger;

  /**
   * The billing state resolver service.
   *
   * @var \Drupal\markaspot_fastmap\Service\BillingStateResolver
   */
  protected BillingStateResolver $billingStateResolver;

  /**
   * The pager manager.
   *
   * @var \Drupal\Core\Pager\PagerManagerInterface
   */
  protected PagerManagerInterface $pagerManager;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->database = $container->get('database');
    $instance->fastmapLogger = $container->get('logger.channel.markaspot_fastmap');
    $instance->billingStateResolver = $container->get('markaspot_fastmap.billing_state_resolver');
    $instance->pagerManager = $container->get('pager.manager');
    $instance->dateFormatter = $container->get('date.formatter');
    return $instance;
  }

  /**
   * Lists tenant billing state.
   *
   * Accepts query params:
   *  - state: one of self::STATE_FILTERS, default 'all'.
   *  - tier: one of self::TIER_FILTERS, default 'all'.
   *  - page: pager index (handled by PagerSelectExtender).
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request (injected via routing).
   *
   * @return array
   *   Drupal render array.
   */
  public function listing(Request $request): array {
    $stateFilter = $this->sanitiseFilter(
      $request->query->get('state', 'all'),
      self::STATE_FILTERS
    );
    $tierFilter = $this->sanitiseFilter(
      $request->query->get('tier', 'all'),
      self::TIER_FILTERS
    );

    [$rows, $total, $sqlTotal, $paginated] = $this->buildRows($stateFilter, $tierFilter);

    $this->fastmapLogger->debug(
      'billing.admin_listing_viewed user=@uid ip=@ip count=@n filter_state=@s filter_tier=@t',
      [
        '@uid' => (int) $this->currentUser()->id(),
        '@ip' => $request->getClientIp() ?? '',
        '@n' => $total,
        '@s' => $stateFilter,
        '@t' => $tierFilter,
      ]
    );

    $hasActiveFilter = $stateFilter !== 'all' || $tierFilter !== 'all';
    if ($hasActiveFilter) {
      $clearUrl = Url::fromRoute('markaspot_fastmap.billing_admin')->toString();
      $emptyText = $this->t(
        'No tenants match these filters. <a href=":href">Clear filters</a>.',
        [':href' => $clearUrl]
      );
    }
    else {
      $emptyText = $this->t('No tenants match these filters.');
    }

    if ($paginated) {
      $summaryMarkup = '<p>' . $this->t(
        'Showing @count of @total tenants (filters: state=@state, tier=@tier).',
        [
          '@count' => $total,
          '@total' => $sqlTotal,
          '@state' => $stateFilter,
          '@tier' => $tierFilter,
        ]
      ) . '</p>';
    }
    else {
      $summaryMarkup = '<p>' . $this->t(
        'Showing @count tenants matching state=@state, tier=@tier (no pagination).',
        [
          '@count' => $total,
          '@state' => $stateFilter,
          '@tier' => $tierFilter,
        ]
      ) . '</p>';
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['markaspot-fastmap-billing-admin']],
      '#attached' => ['library' => ['markaspot_fastmap/admin']],
      'filters' => $this->buildFiltersForm($stateFilter, $tierFilter),
      'summary' => [
        '#type' => 'markup',
        '#markup' => $summaryMarkup,
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('ID'),
          $this->t('Label'),
          $this->t('Slug'),
          $this->t('Tier'),
          $this->t('State'),
          $this->t('Stripe customer'),
          $this->t('Billing email'),
          $this->t('Expiry'),
          $this->t('Revisions'),
        ],
        '#rows' => $rows,
        '#empty' => $emptyText,
      ],
      '#cache' => [
        'contexts' => ['url.query_args', 'user.permissions'],
        'tags' => ['group_list:jur'],
        'max-age' => 0,
      ],
    ];

    if ($paginated) {
      $build['pager'] = ['#type' => 'pager'];
    }

    return $build;
  }

  /**
   * Builds the rows array for the table.
   *
   * State is a computed value (resolver output), not a stored column, so SQL
   * WHERE cannot filter on it. To keep counts and pagination semantically
   * correct we use two query paths:
   *
   *  - state=all (no post-resolve filter): paginate via PagerSelectExtender,
   *    25 rows per page. Pager + count are honest.
   *  - state=<specific>: load all matching jur entities (jur count <500 in
   *    practice), resolve, filter, render — no pager. Counts reflect the
   *    true matching set instead of the in-memory page slice.
   *
   * @param string $stateFilter
   *   Sanitised state filter (whitelist member).
   * @param string $tierFilter
   *   Sanitised tier filter (whitelist member).
   *
   * @return array
   *   [$rows, $renderedCount, $sqlTotal, $paginated] tuple.
   */
  private function buildRows(string $stateFilter, string $tierFilter): array {
    $paginated = $stateFilter === 'all';

    $select = $this->database->select('groups', 'g');
    $select->fields('g', ['id']);
    $select->condition('g.type', 'jur');
    $select->orderBy('g.id', 'ASC');

    if ($tierFilter !== 'all') {
      $select->leftJoin('group__field_tier', 'gt', 'gt.entity_id = g.id AND gt.deleted = 0');
      $select->condition('gt.field_tier_value', $tierFilter);
    }

    $sqlTotal = 0;
    if ($paginated) {
      // Count the full SQL match before extending with the pager. The pager
      // extension drives its own count internally for the pager links, but
      // we expose this separately for the "showing N of M" summary text.
      $countSelect = clone $select;
      $sqlTotal = (int) $countSelect->countQuery()->execute()->fetchField();
      // PagerSelectExtender takes the LIMIT off the SQL side.
      $select = $select->extend('Drupal\Core\Database\Query\PagerSelectExtender')
        ->limit(self::PAGE_SIZE);
    }

    $ids = $select->execute()->fetchCol();

    if (!$ids) {
      return [[], 0, $sqlTotal, $paginated];
    }

    $groups = $this->entityTypeManager()->getStorage('group')->loadMultiple($ids);
    $rows = [];

    foreach ($groups as $group) {
      $tier = $this->fieldValue($group, 'field_tier');
      $customer = $this->fieldValue($group, 'field_stripe_customer_id');
      $subscription = $this->fieldValue($group, 'field_stripe_subscription_id');
      $expiryRaw = $this->fieldValue($group, 'field_expiry_date');
      $expiry = $expiryRaw !== NULL ? (int) $expiryRaw : NULL;
      $billingEmail = $this->fieldValue($group, 'field_billing_email');
      $slug = $this->fieldValue($group, 'field_slug');

      $state = $this->billingStateResolver->resolve($tier, $customer, $subscription, $expiry);

      // Apply post-resolve state filter — state is computed, not stored, so
      // it cannot be a SQL WHERE clause.
      if (!$paginated && $state !== $stateFilter) {
        continue;
      }

      $revisionCount = $this->countRevisions((int) $group->id());

      $rows[] = [
        ['data' => (int) $group->id()],
        [
          'data' => Link::fromTextAndUrl(
            $group->label(),
            Url::fromRoute('entity.group.edit_form', ['group' => (int) $group->id()])
          )->toRenderable(),
        ],
        ['data' => ['#plain_text' => $slug ?? '']],
        ['data' => $this->renderTierBadge($tier)],
        ['data' => $this->renderStateBadge($state)],
        ['data' => $this->renderCustomerLink($customer)],
        ['data' => ['#plain_text' => $billingEmail ?? '']],
        [
          'data' => [
            '#plain_text' => $expiry !== NULL && $expiry > 0
              ? $this->dateFormatter->format($expiry, 'short')
              : '',
          ],
        ],
        ['data' => $revisionCount],
      ];
    }

    if (!$paginated) {
      $sqlTotal = count($rows);
    }
    return [$rows, count($rows), $sqlTotal, $paginated];
  }

  /**
   * Returns the first value of an entity field, or NULL.
   */
  private function fieldValue($entity, string $field): ?string {
    if (!$entity->hasField($field) || $entity->get($field)->isEmpty()) {
      return NULL;
    }
    $value = $entity->get($field)->value;
    return $value === NULL ? NULL : (string) $value;
  }

  /**
   * Counts revisions for a group.
   */
  private function countRevisions(int $groupId): int {
    return (int) $this->database->select('groups_revision', 'gr')
      ->condition('gr.id', $groupId)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Renders a tier badge.
   *
   * The badge values flow through a controlled vocabulary so they are safe to
   * insert into the class array — Drupal sanitises class names. Anything off
   * the whitelist falls back to plain text without badge styling.
   */
  private function renderTierBadge(?string $tier): array {
    if ($tier === NULL || $tier === '') {
      return ['#plain_text' => '—'];
    }
    $tier = (string) $tier;
    if (!in_array($tier, ['free', 'starter', 'pro', 'heart'], TRUE)) {
      return ['#plain_text' => $tier];
    }
    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#attributes' => ['class' => ['tier-badge', 'tier-' . $tier]],
      '#value' => $tier,
    ];
  }

  /**
   * Renders a state badge.
   *
   * State is one of self::STATE_BADGE_CLASSES; unknown falls back to neutral.
   */
  private function renderStateBadge(string $state): array {
    if (!array_key_exists($state, self::STATE_BADGE_CLASSES)) {
      $state = 'unknown';
    }
    $class = self::STATE_BADGE_CLASSES[$state];
    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#attributes' => ['class' => ['state-badge', $class]],
      '#value' => $state,
    ];
  }

  /**
   * Renders a Stripe customer ID as a deep-link to the Stripe dashboard.
   *
   * Only links values that match the `cus_*` shape Stripe issues — every
   * other value (empty, malformed) renders as plain text to avoid leaking a
   * mistyped or partial identifier into a clickable URL.
   */
  private function renderCustomerLink(?string $customer): array {
    if ($customer === NULL || $customer === '') {
      return ['#plain_text' => ''];
    }
    if (!str_starts_with($customer, 'cus_')) {
      return ['#plain_text' => $customer];
    }
    return Link::fromTextAndUrl(
      $customer,
      Url::fromUri(
        'https://dashboard.stripe.com/customers/' . $customer,
        ['attributes' => ['target' => '_blank', 'rel' => 'noopener noreferrer']]
      )
    )->toRenderable();
  }

  /**
   * Builds a simple GET filter form (links, not POST).
   */
  private function buildFiltersForm(string $state, string $tier): array {
    $items = [];
    foreach (self::STATE_FILTERS as $candidate) {
      $items[] = Link::fromTextAndUrl(
        $candidate,
        Url::fromRoute('markaspot_fastmap.billing_admin', [], [
          'query' => ['state' => $candidate, 'tier' => $tier],
        ])
      )->toRenderable() + [
        '#attributes' => ['class' => $candidate === $state ? ['is-active'] : []],
      ];
    }
    $tierItems = [];
    foreach (self::TIER_FILTERS as $candidate) {
      $tierItems[] = Link::fromTextAndUrl(
        $candidate,
        Url::fromRoute('markaspot_fastmap.billing_admin', [], [
          'query' => ['state' => $state, 'tier' => $candidate],
        ])
      )->toRenderable() + [
        '#attributes' => ['class' => $candidate === $tier ? ['is-active'] : []],
      ];
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['billing-admin-filters']],
      'state' => [
        '#type' => 'item',
        '#title' => $this->t('Filter by state'),
        'items' => [
          '#theme' => 'item_list',
          '#items' => $items,
        ],
      ],
      'tier' => [
        '#type' => 'item',
        '#title' => $this->t('Filter by tier'),
        'items' => [
          '#theme' => 'item_list',
          '#items' => $tierItems,
        ],
      ],
    ];

    if ($state !== 'all' || $tier !== 'all') {
      $build['clear'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['billing-admin-clear-filters']],
        'link' => Link::createFromRoute(
          $this->t('Clear filters'),
          'markaspot_fastmap.billing_admin'
        )->toRenderable(),
      ];
    }

    return $build;
  }

  /**
   * Sanitises a filter value against a whitelist.
   */
  private function sanitiseFilter(mixed $candidate, array $whitelist): string {
    $candidate = is_string($candidate) ? $candidate : '';
    return in_array($candidate, $whitelist, TRUE) ? $candidate : 'all';
  }

}
