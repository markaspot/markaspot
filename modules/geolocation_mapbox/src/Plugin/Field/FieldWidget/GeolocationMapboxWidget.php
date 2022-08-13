<?php

namespace Drupal\geolocation_mapbox\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\Html;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Plugin implementation of the 'geolocation_mapbox_widget' widget.
 *
 * @FieldWidget(
 *   id = "geolocation_mapbox_widget",
 *   label = @Translation("Geolocation Mapbox widget"),
 *   field_types = {
 *     "geolocation"
 *   }
 * )
 */
class GeolocationMapboxWidget extends WidgetBase {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'zoom' => 12,
      'center_lat' => 0,
      'center_lng' => 0,
      'set_address_field' => 0,
      'limit_countrycodes' => '',
      'limit_viewbox' => '',
      'city' => '',
      'mapboxToken' => '',
      'mapboxStyle' => '',
      'tileServerUrl' => 'http://{s}.tile.osm.org/{z}/{x}/{y}.png',
      'wmsLayer' => '',
      'customAttribution' => '&copy; <a href="http://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors, &copy; <a href="https://cartodb.com/attributions">CartoDB</a>',
      'autoLocate' => FALSE,
      'fullscreenControl' => TRUE,
      'streetNumberFormat' => 0,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $uniq_id = Html::getUniqueId('geolocation-mapbox-map');
    $elements = [];
    for ($i = 0; $i <= 18; $i++) {
      $zoom_options[$i] = $i;
    }
    $elements['zoom'] = [
      '#type' => 'select',
      '#title' => $this->t('Zoom level'),
      '#options' => $zoom_options,
      '#default_value' => $this->getSetting('zoom'),
      '#attributes' => [
        'class' =>
        ['geolocation-widget-zoom', 'for--' . $uniq_id],
      ],
    ];
    $elements['center_lat'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Center (Latitude)'),
      '#default_value' => $this->getSetting('center_lat'),
      '#attributes' => [
        'class' => ['geolocation-widget-lat', 'for--' . $uniq_id],
      ],
    ];
    $elements['center_lng'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Center (Longitude)'),
      '#default_value' => $this->getSetting('center_lng'),
      '#attributes' => [
        'class' =>
        ['geolocation-widget-lng', 'for--' . $uniq_id,
        ],
      ],
    ];
    $elements['limit_countrycodes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Limit search results to one or more countries'),
      '#default_value' => $this->getSetting('limit_countrycodes'),
      '#description' => $this->t('Optionally enter a comma-seperated list 2-letter country codes to limit search results.'),
    ];
    $elements['limit_viewbox'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Limit search results to a specific area'),
      '#default_value' => $this->getSetting('limit_viewbox'),
      '#description' => $this->t('Optionally enter a bounding-box (minLon,minLat,maxLon,maxLat) to limit search results.'),
    ];
    $elements['city'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Limit search to a specific city'),
      '#default_value' => $this->getSetting('city'),
      '#description' => $this->t('Optionally enter a city to try and limit any further.'),
    ];
    $elements['set_address_field'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Populate address field (experimental)'),
      '#default_value' => $this->getSetting('set_address_field'),
      '#description' => $this->t('Experimental feature: Populate an address field with the geocoding results. This works only if the form has one field of type address (https://www.drupal.org/project/address) and might not cover all countries and circumnstances. NOTE: The address form fields will be populated even if they already contain default values. Use with care and not yet in production.'),
    ];
    $elements['tileServerUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default map tile server url'),
      '#default_value' => $this->getSetting('tileServerUrl'),
      '#description' => $this->t('Choose a tileserver url like "http://{s}.tile.osm.org/{z}/{x}/{y}.png". or a WMS Service URL'),
    ];
    $elements['wmsLayer'] = [
      '#type' => 'textfield',
      '#title' => $this->t('WMS Layer ID'),
      '#default_value' => $this->getSetting('wmsLayer'),
      '#description' => $this->t('Enter the layer ID like "layer:layer"'),
    ];
    $elements['customAttribution'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Add a custom attribution'),
      '#default_value' => $this->getSetting('customAttribution'),
      '#description' => $this->t('Check your Tile Service Provider for policy'),
    ];
    $elements['mapboxToken'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mapbox Token'),
      '#default_value' => $this->getSetting('mapboxToken'),
      '#description' => $this->t('Enter your personal Mapbox Token'),
    ];
    $elements['mapboxStyle'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mapbox Style'),
      '#default_value' => $this->getSetting('mapboxStyle'),
      '#description' => $this->t('Enter a Mapbox Style Url'),
    ];

    $elements['autoLocate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Autolocate user'),
      '#description' => $this->t('Autolocate the user via GPS on widget display?'),
      '#default_value' => $this->getSetting('autoLocate'),
    ];
    $elements['fullscreenControl'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Fullscreen Control Widget'),
      '#description' => $this->t('Show a fullscreen control on the map?'),
      '#default_value' => $this->getSetting('fullscreenControl'),
    ];

    $elements['streetNumberFormat'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Address formatting (street search)'),
      '#description' => $this->t('Check to use street name + building number format'),
      '#default_value' => $this->getSetting('streetNumberFormat'),
    ];
    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $uniq_id = Html::getUniqueId('geolocation-mapbox-map');

    $element['lat'] = [
      '#type' => 'hidden',
      '#empty_value' => '',
      '#default_value' => (isset($items[$delta]->lat)) ? $items[$delta]->lat : NULL,
      '#attributes' => [
        'class' =>
        ['geolocation-widget-lat', 'for--' . $uniq_id],
      ],
    ];

    $element['lng'] = [
      '#type' => 'hidden',
      '#empty_value' => '',
      '#default_value' => (isset($items[$delta]->lng)) ? $items[$delta]->lng : NULL,
      '#attributes' => [
        'class' =>
        ['geolocation-widget-lng', 'for--' . $uniq_id],
      ],
    ];

    $element['map_container'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Map'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
      'map' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'id' => $uniq_id,
          'style' => 'width: 100%; height: 400px',
        ],
      ],
    ];

    $label = '';
    if ($items->getEntity()->label()) {
      $label = $items->getEntity()->label();
    }

    $element['#attached'] = [
      'library' => [
        'geolocation_mapbox/leaflet',
        'geolocation_mapbox/mapbox',
        'geolocation_mapbox/leaflet-locatecontrol',
        'geolocation_mapbox/leaflet-geosearch',
        'geolocation_mapbox/leaflet.fullscreen',
        'geolocation_mapbox/geolocation-mapbox-widget',
      ],
      'drupalSettings' => [
        'geolocationMapbox' => [
          'widgetMaps' => [
            $uniq_id => [
              'id' => $uniq_id,
              'centerLat' => !empty($element['lat']['#default_value']) ? $element['lat']['#default_value'] : $this->getSetting('center_lat'),
              'centerLng' => !empty($element['lng']['#default_value']) ? $element['lng']['#default_value'] : $this->getSetting('center_lng'),
              'zoom' => $this->getSetting('zoom'),
              'lat' => (float) $element['lat']['#default_value'],
              'lng' => (float) $element['lng']['#default_value'],
              'label' => $label,
              'setAddressField' => $this->getSetting('set_address_field'),
              'limitCountryCodes'  => $this->getSetting('limit_countrycodes'),
              'limitViewbox'  => $this->getSetting('limit_viewbox'),
              'city'  => $this->getSetting('city'),
              'mapboxToken'  => $this->getSetting('mapboxToken'),
              'mapboxStyle'  => $this->getSetting('mapboxStyle'),
              'tileServerUrl'  => $this->getSetting('tileServerUrl'),
              'wmsLayer'  => $this->getSetting('wmsLayer'),
              'customAttribution'  => $this->getSetting('customAttribution'),
              'autoLocate' => $this->getSetting('autoLocate'),
              'fullscreenControl' => $this->getSetting('fullscreenControl'),
              'streetNumberFormat' => $this->getSetting('streetNumberFormat'),
            ],
          ],
        ],
      ],
    ];

    // Wrap the whole form in a container.
    $element += [
      '#type' => 'item',
      '#title' => $element['#title'],
    ];
    return $element;
  }

}
