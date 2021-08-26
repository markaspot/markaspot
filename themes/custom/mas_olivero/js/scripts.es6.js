/**
 * @file
 * Controls the visibility of desktop navigation.
 *
 * Shows and hides the desktop navigation based on scroll position and controls
 * the functionality of the button that shows/hides the navigation.
 */

/* eslint-disable no-inner-declarations */
((Drupal) => {
  /**
   * Olivero helper functions.
   *
   * @namespace
   */
  Drupal.mas_olivero = {};

  // Make map sticky only on requests list
  const body = document.querySelector('body');
  if (body.classList.contains('path-frontpage') == false && body.classList.contains('page-node-type-page') == false) {
    const map = document.getElementById('#map');
    const stickyElement = document.getElementsByClassName('map-request-block');
    if (stickyElement.length) {
      Drupal.sticky = new Waypoint.Sticky({
        element: stickyElement[0],
        wrapper: '<div class="sticky-wrapper waypoint" />'
      });
    }
  }

  let heatMapButton = document.querySelector('.heatmap a');
  if (heatMapButton !== null) {
    heatMapButton.onclick = function () {
      this.classList.toggle('is-active');
      if (this.classList.contains('is-active')) {
        Drupal.markaspot_map.showHeatMap()
      } else {
        Drupal.markaspot_map.hideHeatMap()
      }
    };
  }
  let timeControlButton = document.querySelector('.time-control a');
  if (timeControlButton !== null) {
    timeControlButton.onclick = function () {
      this.classList.toggle('is-active');
      if (this.classList.contains('is-active')) {
        Drupal.markaspot_map.showTimeControl()
      } else {
        Drupal.markaspot_map.hideTimeControl()
      }
    };
  }

  let config = {
    attributes: true,
    childList: false,
    characterData: false,
    subtree: false
  };



  let map = document.getElementById('map');
  if (map !== null) {
    let observer = new MutationObserver(mutations => Drupal.Markaspot.maps[0].invalidateSize());
    observer.observe(map, config);
  }



})(Drupal);

