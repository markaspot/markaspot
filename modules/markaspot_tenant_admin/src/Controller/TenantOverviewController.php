<?php

declare(strict_types=1);

namespace Drupal\markaspot_tenant_admin\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\markaspot_tenant_admin\TenantAdminHelper;

/**
 * Controller for the tenant admin overview page.
 */
class TenantOverviewController extends ControllerBase {

  /**
   * Renders the tenant administration overview.
   *
   * Shows links to manage categories and statuses for the user's
   * jurisdiction(s).
   *
   * @return array
   *   A render array.
   */
  public function overview(): array {
    $jur_ids = TenantAdminHelper::getUserJurisdictionIds($this->currentUser());

    // Resolve jurisdiction names for display.
    $jurisdictions = [];
    if (!empty($jur_ids)) {
      $groups = $this->entityTypeManager()->getStorage('group')->loadMultiple($jur_ids);
      foreach ($groups as $group) {
        $jurisdictions[] = $group->label();
      }
    }

    $build = [];

    if (!empty($jurisdictions)) {
      $build['info'] = [
        '#markup' => '<p>' . $this->t('Managing taxonomy for: <strong>@jurisdictions</strong>', [
          '@jurisdictions' => implode(', ', $jurisdictions),
        ]) . '</p>',
      ];
    }

    $build['links'] = [
      '#theme' => 'item_list',
      '#items' => [
        [
          '#type' => 'link',
          '#title' => $this->t('Categories (Service Types)'),
          '#url' => Url::fromRoute('entity.taxonomy_vocabulary.overview_form', [
            'taxonomy_vocabulary' => 'service_category',
          ]),
          '#attributes' => ['class' => ['admin-item']],
        ],
        [
          '#type' => 'link',
          '#title' => $this->t('Statuses'),
          '#url' => Url::fromRoute('entity.taxonomy_vocabulary.overview_form', [
            'taxonomy_vocabulary' => 'service_status',
          ]),
          '#attributes' => ['class' => ['admin-item']],
        ],
      ],
      '#attributes' => ['class' => ['admin-list']],
    ];

    return $build;
  }

}
