<?php

namespace Drupal\markaspot_cap\Encoder;

use Symfony\Component\Serializer\Encoder\EncoderInterface;
use Symfony\Component\Serializer\Encoder\DecoderInterface;

/**
 * Adds CAP 1.2 XML support for serializer.
 *
 * This encoder generates CAP (Common Alerting Protocol) 1.2 compliant XML
 * for emergency citizen reports.
 *
 * All text is inserted via createTextNode() so the DOM handles escaping
 * internally -- there is NO pre-escaping via htmlspecialchars(), which would
 * cause double-encoding (e.g. "&" -> "&amp;amp;").
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

    // Reject DTDs entirely: LIBXML_NOENT *enables* entity substitution and is
    // therefore the wrong flag here. Use LIBXML_NONET | LIBXML_NOBLANKS only,
    // and refuse documents that declare a DOCTYPE to prevent XXE attacks.
    if (stripos($data, '<!DOCTYPE') !== FALSE) {
      throw new \UnexpectedValueException('DTDs are not allowed in CAP documents.');
    }

    $dom = new \DOMDocument();
    $prevUseInternal = libxml_use_internal_errors(TRUE);
    $dom->loadXML($data, LIBXML_NONET | LIBXML_NOBLANKS);
    $error = libxml_get_last_error();
    libxml_clear_errors();
    libxml_use_internal_errors($prevUseInternal);

    if ($error) {
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
    $dom = $parentNode->ownerDocument;
    $dom->removeChild($parentNode);

    $feed = $dom->createElementNS('http://www.w3.org/2005/Atom', 'feed');
    $feed->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cap', self::CAP_NAMESPACE);
    $dom->appendChild($feed);

    $this->appendAtomElement($feed, 'title', 'CAP Alert Feed');
    $this->appendAtomElement($feed, 'updated', gmdate('Y-m-d\TH:i:s\Z'));
    // Feed IRI is unique per installation. Callers pass 'cap_feed_id' in the
    // encode context (e.g. 'urn:markaspot:cap:feed:<site-uuid>'). Fall back to
    // the module-namespace IRI when the context key is absent.
    $feedId = $this->context['cap_feed_id'] ?? 'urn:markaspot:cap:feed';
    $this->appendAtomElement($feed, 'id', $feedId);

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

    // Deterministic entry ID based on the alert identifier (not random).
    $this->appendAtomElement($entry, 'id', 'urn:markaspot:cap:alert:' . $alertData['identifier']);
    $this->appendAtomElement($entry, 'title', $alertData['info']['headline'] ?? 'Alert ' . $alertData['identifier']);
    $this->appendAtomElement($entry, 'updated', $alertData['sent'] ?? gmdate('Y-m-d\TH:i:s\Z'));

    $content = $this->dom->createElement('content');
    $content->setAttribute('type', 'application/cap+xml');
    $entry->appendChild($content);

    $alert = $this->dom->createElementNS(self::CAP_NAMESPACE, 'cap:alert');
    $content->appendChild($alert);
    $this->buildAlertElements($alert, $alertData);
  }

  /**
   * Append Atom element to node using createTextNode (no double-escaping).
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
    // DOM escapes text content automatically; no htmlspecialchars() needed.
    $element->appendChild($this->dom->createTextNode($value));
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

    $requiredElements = ['category', 'event', 'urgency', 'severity', 'certainty'];
    foreach ($requiredElements as $element) {
      if (isset($info[$element])) {
        $this->appendElement($infoNode, $element, $info[$element]);
      }
    }

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

    if (isset($info['resource'])) {
      $resources = is_array($info['resource']) && isset($info['resource'][0]) ? $info['resource'] : [$info['resource']];
      foreach ($resources as $resource) {
        $this->buildResourceElement($infoNode, $resource);
      }
    }

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

    foreach (['resourceDesc', 'mimeType', 'size', 'uri', 'derefUri', 'digest'] as $element) {
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

    foreach (['polygon', 'circle', 'geocode', 'altitude', 'ceiling'] as $element) {
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
   * Append a text element to a node using createTextNode.
   *
   * The DOM API escapes text content automatically. We must NOT call
   * htmlspecialchars() here -- doing so would double-encode characters like
   * "&" to "&amp;amp;" in the final serialized XML.
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
      $element->appendChild($this->dom->createTextNode((string) $value));
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
