/**
 * @file
 * Custom scripts for theme.
 */


(function ($, Drupal, drupalSettings, window, document) {

  // https://www.abeautifulsite.net/whipping-file-inputs-into-shape-with-bootstrap-3
  $(document).on('change', ':file', function () {
    var input = $(this),
      numFiles = input.get(0).files ? input.get(0).files.length : 1,
      label = input.val().replace(/\\/g, '/').replace(/.*\//, '');
    input.trigger('fileselect', [numFiles, label]);
    Pace.restart();
  });
  $(document).ready(function () {

    // https://www.abeautifulsite.net/whipping-file-inputs-into-shape-with-bootstrap-3
    $(':file').on('fileselect', function (event, numFiles, label) {

      var input = $(this).parents('.input-group').find(':text'),
        log = numFiles > 1 ? numFiles + ' files selected' : label;

      if (input.length) {
        input.val(log);
      }

    });


    $notifications = $('.notifications');

    // Taken from http://imakewebthings.com/waypoints/
    function notify(text) {
         var $notification = $('<li />').text(text).css({
        left: 320
      });
      $notifications.append($notification);
      $notification.animate({
        left: 0
      }, 300, function () {
        $(this).delay(6000).animate({
          left: 320
        }, 200, function () {
          $(this).slideUp(100, function () {
            $(this).remove()
          })
        })
      })
    }

    var route = drupalSettings.path.currentPath;

    // Sticky map on top.



    // Add a close button to exposed filter.
    $('.views-exposed-form')
      .append('<a data-toggle="filter" class="btn btn-default close fa fa-close"><span>' + Drupal.t('Close') + '</span></a>')
    var $map = $('#map');
    if ($map.length > 0 && sessionStorage.getItem('removeSticky') !== '1') {
      var $stickyElement = $('.map-request-block');
      if ($stickyElement.length) {
        Drupal.sticky = new Waypoint.Sticky({
          element: $stickyElement[0],
          wrapper: '<div class="sticky-wrapper waypoint" />'
        });
      }
      var mapInview = new Waypoint.Inview({
        element: $map,
        entered: function (direction) {
          $('body').addClass('map-stuck');
        },
        exited: function (direction) {
          if (route === 'requests') {
            "message" in sessionStorage ? 0 :
              notify(Drupal.t('You can follow the requests location while scrolling up and down. Alternatively, tap the markers.')),
              sessionStorage.setItem("message",true);
          }
        }
      })
    }

    var topInview = new Waypoint.Inview({
      element: footer,
      entered: function (direction) {
        $('.mas-action').fadeIn(400);
        $('.scroll-to-top').show().on('click', function (e) {
          var href = $(this).attr('href');
          $('html, body').animate({
            scrollTop: $('body').offset().top
          }, 500);
          e.preventDefault();
        });
      },
      exited: function (direction) {
        if (direction === "up") {
          $('.mas-action, .scroll-to-top').hide();
        }
      }
    });

  });

  // Handle Result filter click.
  $(document).ajaxStop(function () {

    var refineLink = $('[data-toggle="filter"]');
    var exposedFilter =  $('.view__filters');

    refineLink.off('click').on('click', function(){
      $('.view-filters').toggleClass('exposed');
      $('select').selectpicker();

    });

    if ($('[data-drupal-selector="edit-reset"]')[0]) {
      exposedFilter.addClass('exposed ajax');
    }
  });

})(jQuery, Drupal, drupalSettings, this, this.document);
