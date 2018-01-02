/**
 * @file
 * Custom scripts for theme.
 */

// Taking care of flickering controls on touch devices.
// https://github.com/Leaflet/Leaflet/issues/1198
window.L_DISABLE_3D = 'ontouchstart' in document.documentElement;
var slideout = new Slideout({
  'panel': document.getElementById('page-content-wrapper'),
  'menu': document.getElementById('sidebar-wrapper'),
  'padding': 256,
  'tolerance': 70,
  'side': 'right'
});

var MutationObserver = window.MutationObserver || window.WebKitMutationObserver || window.MozMutationObserver;
var target = document.querySelector('[data-drupal-views-infinite-scroll-content-wrapper]');

// create an observer instance
var observer = new MutationObserver(function(mutations) {
  mutations.forEach(function(mutation) {
    if (mutation.type === 'childList') {
      Pace.restart();
    }
  });
});

var config = { attributes: true, childList: true, characterData: true };
// pass in the target node, as well as the observer options
if (target) {
  observer.observe(target, config);
}

(function ($, Drupal, drupalSettings, window, document) {

  function toDesktop(width){
    var nav = $(".navbar-default");
    var branding = $(".block--masradix-sitebranding");
    if (width >= 1200) {
      $(".navbar-default > div").removeClass(".navbar-left");
      $("a.navbar-brand").prependTo(nav);
      nav.addClass("container");
      $(".fixed-header").hide();
    } else {
      $("a.navbar-brand").prependTo(branding);
      // nav.removeClass("container");
      $(".fixed-header").show();
    }

  }
  // https://www.abeautifulsite.net/whipping-file-inputs-into-shape-with-bootstrap-3
  $(document).on('change', ':file', function () {
    var input = $(this),
      numFiles = input.get(0).files ? input.get(0).files.length : 1,
      label = input.val().replace(/\\/g, '/').replace(/.*\//, '');
    input.trigger('fileselect', [numFiles, label]);
    Pace.restart();
  });
  $(document).ready(function () {

    toDesktop($(window).width());
    $(window).resize(function() {
      toDesktop($(this).width());
    });
    // https://www.abeautifulsite.net/whipping-file-inputs-into-shape-with-bootstrap-3
    $(':file').on('fileselect', function (event, numFiles, label) {

      var input = $(this).parents('.input-group').find(':text'),
        log = numFiles > 1 ? numFiles + ' files selected' : label;

      if (input.length) {
        input.val(log);
      }

    });

    // Toggle button.
    document.querySelector('.toggle-button').addEventListener('click', function () {
      slideout.toggle();
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

    var fixed = document.querySelector('.fixed-header');

    slideout.on('translate', function (translated) {
      fixed.style.transform = 'translateX(' + translated + 'px)';
    });

    slideout.on('beforeopen', function () {
      fixed.style.transition = 'transform 300ms ease';
      fixed.style.transform = 'translateX(-256px)';
    });

    slideout.on('beforeclose', function () {
      fixed.style.transition = 'transform 300ms ease';
      fixed.style.transform = 'translateX(0px)';
    });

    slideout.on('open', function () {
      fixed.style.transition = '';
    });

    slideout.on('close', function () {
      fixed.style.transition = '';
    });

    $('.btn-default, .add-block p a').click(function (e) {
      var rippler = $(this);
      // Create .ink element if it doesn't exist.
      if (rippler.find(".ink").length == 0) {
        rippler.append("<span class='ink'></span>");
      }

      var ink = rippler.find(".ink");

      // Prevent quick double clicks.
      ink.removeClass("animate");

      // Set .ink diametr.
      if (!ink.height() && !ink.width()) {
      var d = Math.max(rippler.outerWidth(), rippler.outerHeight());
        ink.css({height: d, width: d});
      }

      // Get click coordinates.
      var x = e.pageX - rippler.offset().left - ink.width() / 2;
      var y = e.pageY - rippler.offset().top - ink.height() / 2;

      // Set .ink position and add class .animate.
      ink.css({
        top: y + 'px',
        left:x + 'px'
      }).addClass("animate");
    });



    var route = drupalSettings.path.currentPath;

    // Sticky map on top.

    var $stickyElement = $('#map');
    if ($stickyElement.length) {
      var sticky = new Waypoint.Sticky({
        element: $stickyElement[0],
        wrapper: '<div class="sticky-wrapper waypoint" />'
      });
    }

    // Add a close button to exposed filter.
    $('.views-exposed-form')
      .append('<a data-toggle="filter" class="btn btn-default close fa fa-close"><span>' + Drupal.t('Close') + '</span></a>')
    if ($('#map').length > 0) {
      var mapInview = new Waypoint.Inview({
        element: $('#map'),
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

    // Map resizing:
    var map = $('div#geolocation-nominatim-map');
    var form = $('.node-service-request-form input');
    var search = $('.leaflet-control-geocoder.leaflet-bar input');
    var locateControl = $('.leaflet-control-locate a');
    map.height('210px');

    search.click(function () {
      map.animate({height:'400px'}, 100);
    });

    search.blur(function () {
      map.height('210px');
    });
    form.focus(function () {
      map.height('210px');
    });

  });

  // Handle Result filter click.
  $(document).ajaxStop(function () {

    var refineLink = $('[data-toggle="filter"]');
    var exposedFilter =  $('.view__filters');

    refineLink.off('click').on('click', function(){
      $('.view-filters').toggleClass('exposed');
    });

    if ($('[data-drupal-selector="edit-reset"]')[0]) {
      exposedFilter.addClass('exposed ajax');
    }

    // Add a button to toggle map display;.
    $('#map').once().each(function () {
      // Need this for later.
    });

  });

  $('[data-toggle="offcanvas"]').click(function () {
    $('#wrapper').toggleClass('toggled');
  });

})(jQuery, Drupal, drupalSettings, this, this.document);
