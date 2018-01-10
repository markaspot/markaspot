<?php

namespace Drupal\markaspot_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;

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
    return array(
      '#type' => 'markup',
      '#markup' => '
        <div class="mas-action">
          <ul class="theme-grey orientation-vertical col-3">
            <li class="mas-button"><a class="scroll-to-top" href="#top" data-rel="popup" title=""
             role="button" aria-label=""><span class="fa fa-arrow-circle-up"></span><span class="add">Scroll to top</span></a></li>
            <li class="mas-button"><a href="/" data-rel="popup" title="" role="button" aria-label=""><span class="fa fa-home"></span><span class="add">Add service request</span></a></li>
            <li class="mas-button"><a href="/report" data-rel="popup" title="" role="button" aria-label=""><span class="fa fa-pencil"></span><span class="add">Add service request</span></a></li>
            <li class="mas-button"><a href="/requests" data-rel="popup" title="" role="button" aria-label=""><span class="fa fa-eye"></span><span class="add">View service requestd</span></a></li>
          </ul>
        </div>
       ',
    );
  }

}
