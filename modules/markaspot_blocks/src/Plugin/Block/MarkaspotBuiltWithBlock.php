<?php

namespace Drupal\markaspot_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a 'Powered/Built with Mark-a-Spot block' block.
 *
 * @Block(
 *   id = "Markaspot Built With Block",
 *   admin_label = @Translation("Mark-a-Spot: Built With block")
 * )
 */
class MarkaspotBuiltWithBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    return array(
      '#type' => 'markup',
      '#markup' => '
         <div class="built-with">
             Built with <a class="mas" href="http://mark-a-spot.com"><span>Mark-a-Spot</span></a>
         </div>
       ',
      '#attached' => array(
        'library' => array(
          'markaspot_blocks/markaspot_blocks',
        ),
      ),
    );
  }

}
