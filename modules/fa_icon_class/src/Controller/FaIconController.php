<?php

namespace Drupal\fa_icon_class\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Returns responses for dblog routes.
 */
class FaIconController extends ControllerBase {

  /**
   * A simple page to explain to the developer what to do.
   */
  public function description() {
    return [
      '#markup' => "The Field Example provides a field composed of an HTML RGB value, like #ff00ff. To use it, add the field to a content type.",
    ];
  }

}
