<?php

declare(strict_types=1);

namespace Drupal\markaspot_moderation\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\markaspot_group\Service\TenantAdminHelper;
use Drupal\markaspot_moderation\Service\ModerationServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin controller for content moderation overview.
 *
 * Renders a Drupal table listing flagged service requests with pagination
 * and operation links for dismiss, hide, and delete actions.
 */
class ModerationAdminController extends ControllerBase {

  /**
   * The moderation service.
   *
   * @var \Drupal\markaspot_moderation\Service\ModerationServiceInterface
   */
  protected ModerationServiceInterface $moderationService;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * The pager manager.
   *
   * @var \Drupal\Core\Pager\PagerManagerInterface
   */
  protected PagerManagerInterface $pagerManager;

  /**
   * Constructs a ModerationAdminController.
   *
   * @param \Drupal\markaspot_moderation\Service\ModerationServiceInterface $moderationService
   *   The moderation service.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter service.
   * @param \Drupal\Core\Pager\PagerManagerInterface $pagerManager
   *   The pager manager.
   */
  public function __construct(
    ModerationServiceInterface $moderationService,
    DateFormatterInterface $dateFormatter,
    PagerManagerInterface $pagerManager,
  ) {
    $this->moderationService = $moderationService;
    $this->dateFormatter = $dateFormatter;
    $this->pagerManager = $pagerManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('markaspot_moderation.service'),
      $container->get('date.formatter'),
      $container->get('pager.manager'),
    );
  }

  /**
   * Renders the flagged content overview table.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return array
   *   A render array with the flagged content table and pager.
   */
  public function adminOverview(Request $request): array {
    $jurisdictionIds = $this->resolveJurisdictionIds();
    $limit = 50;
    $page = (int) $request->query->get('page', 0);
    $offset = $page * $limit;

    $rows = [];
    $total = 0;

    if (!empty($jurisdictionIds)) {
      $flaggedRequests = $this->moderationService->getFlaggedRequests(
        $jurisdictionIds,
        [],
        $limit,
        $offset,
      );
      $total = $this->moderationService->getFlagCountForJurisdictions($jurisdictionIds);

      $canDelete = $this->currentUser()->hasPermission('administer all flags');

      $reasonLabels = [
        'spam' => $this->t('Spam'),
        'offensive' => $this->t('Offensive'),
        'personal' => $this->t('Personal data'),
        'location' => $this->t('Location'),
        'other' => $this->t('Other'),
      ];

      foreach ($flaggedRequests as $item) {
        $nid = $item['nid'];

        $operations = [
          '#type' => 'dropbutton',
          '#links' => [
            'dismiss' => [
              'title' => $this->t('Dismiss Flags'),
              'url' => Url::fromRoute('markaspot_moderation.admin_dismiss', ['nid' => $nid]),
            ],
            'hide' => [
              'title' => $this->t('Hide Request'),
              'url' => Url::fromRoute('markaspot_moderation.admin_hide', ['nid' => $nid]),
            ],
          ],
        ];

        if ($canDelete) {
          $operations['#links']['delete'] = [
            'title' => $this->t('Delete Request'),
            'url' => Url::fromRoute('markaspot_moderation.admin_delete', ['nid' => $nid]),
          ];
        }

        $topReason = $item['top_reason'] ?? '';
        $reasonLabel = $reasonLabels[$topReason] ?? $topReason;

        $publishedStatus = $item['is_published']
          ? $this->t('Published')
          : $this->t('Unpublished');

        $rows[] = [
          [
            'data' => [
              '#type' => 'link',
              '#title' => $item['title'],
              '#url' => Url::fromRoute('entity.node.canonical', ['node' => $nid]),
            ],
          ],
          $item['service_request_id'],
          $item['flag_count'],
          $reasonLabel,
          $this->dateFormatter->format($item['last_flag_date'], 'short'),
          $publishedStatus,
          ['data' => $operations],
        ];
      }
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Title'),
        $this->t('Request ID'),
        $this->t('Flags'),
        $this->t('Top Reason'),
        $this->t('Last Flag'),
        $this->t('Status'),
        $this->t('Operations'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No flagged content found.'),
    ];

    // Initialize the pager and add the pager element.
    $this->pagerManager->createPager($total, $limit);
    $build['pager'] = [
      '#type' => 'pager',
    ];

    $build['#cache'] = [
      'max-age' => 0,
    ];

    return $build;
  }

  /**
   * Resolves jurisdiction IDs based on the current user's permissions.
   *
   * Users with 'administer all flags' get all jurisdictions.
   * Tenant admins are scoped to their group memberships.
   *
   * @return int[]
   *   Array of jurisdiction group IDs.
   */
  protected function resolveJurisdictionIds(): array {
    $currentUser = $this->currentUser();

    if ($currentUser->hasPermission('administer all flags')) {
      $groupStorage = $this->entityTypeManager()->getStorage('group');
      $ids = $groupStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'jur')
        ->execute();
      return array_map('intval', $ids);
    }

    return TenantAdminHelper::getUserJurisdictionIds($currentUser);
  }

}
