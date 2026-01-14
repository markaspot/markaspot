<?php

namespace Drupal\markaspot_cap\Encoder;

use Symfony\Component\Serializer\Encoder\EncoderInterface;
use Symfony\Component\Serializer\Encoder\DecoderInterface;

/**
 * Adds CAP 1.2 XML support for serializer.
 *
 * This encoder generates CAP (Common Alerting Protocol) 1.2 compliant XML
 * for emergency citizen reports.
 */
class CapEncoder implements EncoderInterface, DecoderInterface {

  /**
   * The XML document.
   *
   * @var \DOMDocument
   */
  private $dom;

  /**
   * The format.
   *
   * @var string
   */
  private $format;

  /**
   * Options that the encoder has access to.
   *
   * @var array
   */
  private $context;

  /**
   * Root node name.
   *
   * @var string
   */
  private $rootNodeName = 'alert';

  /**
   * CAP 1.2 namespace.
   */
  const CAP_NAMESPACE = 'urn:oasis:names:tc:emergency:cap:1.2';

  /**
   * {@inheritdoc}
   */
  public function encode(mixed $data, string $format, array $context = []): string {
    if ($data instanceof \DOMDocument) {
      return $data->saveXML();
    }

    $this->dom = $this->createDomDocument($context);
    $this->format = $format;
    $this->context = $context;

    // Create root alert element with namespace.
    $root = $this->dom->createElementNS(self::CAP_NAMESPACE, 'alert');
    $this->dom->appendChild($root);

    if (NULL !== $data && !is_scalar($data)) {
      $this->buildCapXml($root, $data);
    }

    return $this->dom->saveXML();
  }

  /**
   * {@inheritdoc}
   */
  public function decode(string $data, string $format, array $context = []): mixed {
    if ('' === trim($data)) {
      throw new \UnexpectedValueException('Invalid CAP XML data, it cannot be empty.');
    }

    libxml_clear_errors();

    $dom = new \DOMDocument();
    $dom->loadXML($data, LIBXML_NONET | LIBXML_NOBLANKS);

    if ($error = libxml_get_last_error()) {
      libxml_clear_errors();
      throw new \UnexpectedValueException($error->message);
    }

    $rootNode = $dom->firstChild;

    if ($rootNode->hasChildNodes()) {
      return $this->parseXml($rootNode);
    }

    return $rootNode->nodeValue;
  }

  /**
   * {@inheritdoc}
   */
  public function supportsEncoding(string $format): bool {
    return 'cap' === $format;
  }

  /**
   * {@inheritdoc}
   */
  public function supportsDecoding(string $format): bool {
    return 'cap' === $format;
  }

  /**
   * Build CAP XML structure from data array.
   *
   * @param \DOMNode $parentNode
   *   The parent node.
   * @param array $data
   *   The data to encode.
   */
  private function buildCapXml(\DOMNode $parentNode, array $data) {
    // Handle single alert or multiple alerts.
    if (isset($data['identifier'])) {
      // Single alert - build directly under root.
      $this->buildAlertElements($parentNode, $data);
    }
    elseif (isset($data[0]) && is_array($data[0])) {
      // Multiple alerts - create Atom feed wrapper.
      $this->buildAtomFeed($parentNode, $data);
    }
  }

  /**
   * Build Atom feed for multiple CAP alerts.
   *
   * @param \DOMNode $parentNode
   *   The parent alert node (will be replaced).
   * @param array $alerts
   *   Array of alert data.
   */
  private function buildAtomFeed(\DOMNode $parentNode, array $alerts) {
    // Remove the alert element and create an Atom feed instead.
    $dom = $parentNode->ownerDocument;
    $dom->removeChild($parentNode);

    // Create Atom feed root element.
    $feed = $dom->createElementNS('http://www.w3.org/2005/Atom', 'feed');
    $feed->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cap', self::CAP_NAMESPACE);
    $dom->appendChild($feed);

    // Add feed metadata.
    $this->appendAtomElement($feed, 'title', 'CAP Alert Feed');
    $this->appendAtomElement($feed, 'updated', gmdate('Y-m-d\TH:i:s\Z'));
    $this->appendAtomElement($feed, 'id', 'urn:uuid:' . uniqid());

    // Add each alert as an Atom entry.
    foreach ($alerts as $alertData) {
      if (isset($alertData['identifier'])) {
        $this->buildAtomEntry($feed, $alertData);
      }
    }
  }

  /**
   * Build Atom entry for a single CAP alert.
   *
   * @param \DOMNode $feed
   *   The Atom feed node.
   * @param array $alertData
   *   The alert data.
   */
  private function buildAtomEntry(\DOMNode $feed, array $alertData) {
    $entry = $this->dom->createElement('entry');
    $feed->appendChild($entry);

    // Entry metadata.
    $this->appendAtomElement($entry, 'id', 'urn:uuid:' . $alertData['identifier']);
    $this->appendAtomElement($entry, 'title', $alertData['info']['headline'] ?? 'Alert ' . $alertData['identifier']);
    $this->appendAtomElement($entry, 'updated', $alertData['sent'] ?? gmdate('Y-m-d\TH:i:s\Z'));

    // Create CAP alert as content.
    $content = $this->dom->createElement('content');
    $content->setAttribute('type', 'application/cap+xml');
    $entry->appendChild($content);

    // Build the CAP alert inside content.
    $alert = $this->dom->createElementNS(self::CAP_NAMESPACE, 'cap:alert');
    $content->appendChild($alert);
    $this->buildAlertElements($alert, $alertData);
  }

  /**
   * Append Atom element to node.
   *
   * @param \DOMNode $node
   *   The node.
   * @param string $name
   *   Element name.
   * @param string $value
   *   Element value.
   */
  private function appendAtomElement(\DOMNode $node, string $name, string $value) {
    $element = $this->dom->createElement($name);
    $element->nodeValue = htmlspecialchars($value, ENT_XML1, 'UTF-8');
    $node->appendChild($element);
  }

  /**
   * Build CAP alert elements.
   *
   * @param \DOMNode $parentNode
   *   The parent alert node.
   * @param array $alert
   *   The alert data.
   */
  private function buildAlertElements(\DOMNode $parentNode, array $alert) {
    // Required CAP elements.
    $requiredElements = [
      'identifier',
      'sender',
      'sent',
      'status',
      'msgType',
      'scope',
    ];

    foreach ($requiredElements as $element) {
      if (isset($alert[$element])) {
        $this->appendElement($parentNode, $element, $alert[$element]);
      }
    }

    // Optional elements.
    $optionalElements = [
      'source',
      'restriction',
      'addresses',
      'code',
      'note',
      'references',
      'incidents',
    ];

    foreach ($optionalElements as $element) {
      if (isset($alert[$element])) {
        $this->appendElement($parentNode, $element, $alert[$element]);
      }
    }

    // Info element (required, can be multiple).
    if (isset($alert['info'])) {
      $infoList = is_array($alert['info']) && isset($alert['info'][0]) ? $alert['info'] : [$alert['info']];
      foreach ($infoList as $info) {
        $this->buildInfoElement($parentNode, $info);
      }
    }
  }

  /**
   * Build CAP info element.
   *
   * @param \DOMNode $parentNode
   *   The parent node.
   * @param array $info
   *   The info data.
   */
  private function buildInfoElement(\DOMNode $parentNode, array $info) {
    $infoNode = $this->dom->createElement('info');
    $parentNode->appendChild($infoNode);

    // Required info elements.
    $requiredElements = ['category', 'event', 'urgency', 'severity', 'certainty'];
    foreach ($requiredElements as $element) {
      if (isset($info[$element])) {
        $this->appendElement($infoNode, $element, $info[$element]);
      }
    }

    // Optional info elements.
    $optionalElements = [
      'language',
      'audience',
      'eventCode',
      'effective',
      'onset',
      'expires',
      'senderName',
      'headline',
      'description',
      'instruction',
      'web',
      'contact',
      'parameter',
    ];

    foreach ($optionalElements as $element) {
      if (isset($info[$element])) {
        if ($element === 'parameter' && is_array($info[$element])) {
          foreach ($info[$element] as $param) {
            $this->buildParameterElement($infoNode, $param);
          }
        }
        else {
          $this->appendElement($infoNode, $element, $info[$element]);
        }
      }
    }

    // Resource element (optional, can be multiple).
    if (isset($info['resource'])) {
      $resources = is_array($info['resource']) && isset($info['resource'][0]) ? $info['resource'] : [$info['resource']];
      foreach ($resources as $resource) {
        $this->buildResourceElement($infoNode, $resource);
      }
    }

    // Area element (optional, can be multiple).
    if (isset($info['area'])) {
      $areas = is_array($info['area']) && isset($info['area'][0]) ? $info['area'] : [$info['area']];
      foreach ($areas as $area) {
        $this->buildAreaElement($infoNode, $area);
      }
    }
  }

  /**
   * Build CAP parameter element.
   *
   * @param \DOMNode $parentNode
   *   The parent node.
   * @param array $param
   *   The parameter data.
   */
  private function buildParameterElement(\DOMNode $parentNode, array $param) {
    $paramNode = $this->dom->createElement('parameter');
    $parentNode->appendChild($paramNode);

    if (isset($param['valueName'])) {
      $this->appendElement($paramNode, 'valueName', $param['valueName']);
    }
    if (isset($param['value'])) {
      $this->appendElement($paramNode, 'value', $param['value']);
    }
  }

  /**
   * Build CAP resource element.
   *
   * @param \DOMNode $parentNode
   *   The parent node.
   * @param array $resource
   *   The resource data.
   */
  private function buildResourceElement(\DOMNode $parentNode, array $resource) {
    $resourceNode = $this->dom->createElement('resource');
    $parentNode->appendChild($resourceNode);

    $resourceElements = [
      'resourceDesc',
      'mimeType',
      'size',
      'uri',
      'derefUri',
      'digest',
    ];

    foreach ($resourceElements as $element) {
      if (isset($resource[$element])) {
        $this->appendElement($resourceNode, $element, $resource[$element]);
      }
    }
  }

  /**
   * Build CAP area element.
   *
   * @param \DOMNode $parentNode
   *   The parent node.
   * @param array $area
   *   The area data.
   */
  private function buildAreaElement(\DOMNode $parentNode, array $area) {
    $areaNode = $this->dom->createElement('area');
    $parentNode->appendChild($areaNode);

    if (isset($area['areaDesc'])) {
      $this->appendElement($areaNode, 'areaDesc', $area['areaDesc']);
    }

    $areaElements = ['polygon', 'circle', 'geocode', 'altitude', 'ceiling'];
    foreach ($areaElements as $element) {
      if (isset($area[$element])) {
        if ($element === 'geocode' && is_array($area[$element])) {
          foreach ($area[$element] as $geocode) {
            $this->buildGeocodeElement($areaNode, $geocode);
          }
        }
        else {
          $this->appendElement($areaNode, $element, $area[$element]);
        }
      }
    }
  }

  /**
   * Build CAP geocode element.
   *
   * @param \DOMNode $parentNode
   *   The parent node.
   * @param array $geocode
   *   The geocode data.
   */
  private function buildGeocodeElement(\DOMNode $parentNode, array $geocode) {
    $geocodeNode = $this->dom->createElement('geocode');
    $parentNode->appendChild($geocodeNode);

    if (isset($geocode['valueName'])) {
      $this->appendElement($geocodeNode, 'valueName', $geocode['valueName']);
    }
    if (isset($geocode['value'])) {
      $this->appendElement($geocodeNode, 'value', $geocode['value']);
    }
  }

  /**
   * Append element to node.
   *
   * @param \DOMNode $node
   *   The node.
   * @param string $name
   *   Element name.
   * @param mixed $value
   *   Element value.
   */
  private function appendElement(\DOMNode $node, string $name, $value) {
    if (is_scalar($value)) {
      $element = $this->dom->createElement($name);
      $element->nodeValue = htmlspecialchars((string) $value, ENT_XML1, 'UTF-8');
      $node->appendChild($element);
    }
  }

  /**
   * Parse XML to array.
   *
   * @param \DOMNode $node
   *   The node to parse.
   *
   * @return array|string
   *   Parsed data.
   */
  private function parseXml(\DOMNode $node) {
    if (!$node->hasChildNodes()) {
      return $node->nodeValue;
    }

    $data = [];
    foreach ($node->childNodes as $child) {
      $value = $this->parseXml($child);
      $data[$child->nodeName][] = $value;
    }

    return $data;
  }

  /**
   * Create a DOM document.
   *
   * @param array $context
   *   Options that the encoder has access to.
   *
   * @return \DOMDocument
   *   The DOM document.
   */
  private function createDomDocument(array $context): \DOMDocument {
    $document = new \DOMDocument('1.0', 'UTF-8');
    $document->formatOutput = TRUE;
    return $document;
  }

}
