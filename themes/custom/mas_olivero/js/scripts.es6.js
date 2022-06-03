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
  Drupal.behaviors.mas_olivero_navigation = {
    attach(context) {

      // console.log(siteHeaderFixable);
      const stickyHeaderState = localStorage.getItem(
        'Drupal.olivero.stickyHeaderState',
      );
      // console.log(JSON.parse(stickyHeaderState).value);

      const body = context.querySelector('body');

      if (body.classList.contains('path-frontpage') == false && body.classList.contains('page-node-type-page') == false) {
        const map = context.querySelector(
          '[data-drupal-selector="map-request-block"]',
        );
        console.log(map)
        Drupal.sticky = new Waypoint.Sticky({
          element: map,
          wrapper: '<div class="sticky-wrapper waypoint" />'
        });
        if (JSON.parse(stickyHeaderState).value == true) {
          map.classList.add("stuck-nav");
        }

      }

    }
  }
  // Make map sticky only on requests list


  const observer = new MutationObserver(function(mutations) {
    const startBlock = document.getElementById('block-markaspotfrontaction');
    const fieldsetMap = document.getElementById('geolocation-nominatim-map')
    mutations.forEach(function(mutation) {
      if (mutation.type === "attributes" && mutation.target.className.indexOf('overlay-active') != -1) {
        fieldsetMap ? fieldsetMap.style.display = 'none': false;
        startBlock ? startBlock.style.display = 'none' : false;
      } else {
        fieldsetMap ? fieldsetMap.style.display = 'block': false;
        startBlock ? startBlock.style.display = 'block' : false;
      }
    });
  });



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
  /*
  let map = document.getElementById('map');
  if (map !== null) {
    let observer = new MutationObserver(mutations => Drupal.Markaspot.maps[0].invalidateSize());
    observer.observe(map, config);
  } */
})(Drupal);
