<?php

namespace Drupal\markaspot_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Url;
use Drupal\Core\Link;

/**
 * Provides a 'Button Action' Block.
 *
 * @Block(
 *   id = "markaspot_side_block",
 *   admin_label = @Translation("Markaspot Side Action block"),
 * )
 */
class MarkaspotSideBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Generate proper URLs using Drupal's URL system
    $home_link = Link::fromTextAndUrl(
      '<span class="fa fa-home"></span><span class="add">Add service request</span>',
      Url::fromUserInput('/')
    )->toString();

    $report_link = Link::fromTextAndUrl(
      '<span class="fa fa-map-marker"></span><span class="add">Add service request</span>',
      Url::fromUserInput('/report')
    )->toString();

    $requests_link = Link::fromTextAndUrl(
      '<span class="fa fa-check"></span><span class="add">View service requests</span>',
      Url::fromUserInput('/requests')
    )->toString();

    $viz_link = Link::fromTextAndUrl(
      '<span class="fa fa-pie-chart"></span><span class="add">View service requests</span>',
      Url::fromUserInput('/visualization')
    )->toString();

    return [
      '#type' => 'markup',
      '#markup' => '
        <div class="mas-action">
          <ul class="theme-grey orientation-vertical col-3">
            <li class="mas-button"><a class="scroll-to-top" href="#top" data-rel="popup" title="" role="button" aria-label=""><span class="fa fa-arrow-circle-up"></span><span class="add">Scroll to top</span></a></li>
            <li class="mas-button">' . $home_link . '</li>
            <li class="mas-button">' . $report_link . '</li>
            <li class="mas-button">' . $requests_link . '</li>
            <li class="mas-button">' . $viz_link . '</li>
          </ul>
        </div>
       ',
    ];
  }

}
