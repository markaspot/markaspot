<?php

namespace Drupal\markaspot_open311\Encoder;

use Symfony\Component\Serializer\Encoder\EncoderInterface;
use Symfony\Component\Serializer\Encoder\DecoderInterface;

/**
 * Adds XML support for serializer.
 *
 * This acts as a wrapper class for Symfony's XmlEncoder so that it is not
 * implementing NormalizationAwareInterface, and can be normalized externally.
 */
class Open311Encoder implements EncoderInterface, DecoderInterface {
  /**
   * The XML document.
   *
   * @var \DOMDocument
   */
  private $dom;
  /**
   * The format.
   *
   * @var format
   */
  private $format;
  /**
   * Options that the encoder has access to.
   *
   * @var context
   */
  private $context;
  /**
   * Set root node name.
   *
   * @var null
   */
  private $rootNodeName = NULL;

  /**
   * Get all possible nodes.
   */
  protected function getNodes() {
    $nodes = [
      'errors' => 'error',
      'services' => 'service',
      'service_requests' => 'request',
      'discovery' => 'discovery',
    ];

    return $nodes;
  }

  /**
   * {@inheritdoc}
   */
  public function encode(mixed $data, string $format, array $context = []): string {
    if ($data instanceof \DOMDocument) {
      return $data->saveXML();
    }
    // Checking passed data for keywords resulting in different root_nodes.
    if (NULL != array_key_exists('error', $data)) {
      $context['xml_root_node_name'] = "errors";

    }
    elseif (isset($data[0]) && array_key_exists('metadata', $data[0])) {
      $context['xml_root_node_name'] = "services";

    }
    elseif (array_key_exists('changeset', $data)) {
      $context['xml_root_node_name'] = "discovery";
    }
    else {
      $context['xml_root_node_name'] = "service_requests";
    }

    $xmlRootNodeName = $this->resolveXmlRootName($context);

    $this->dom = $this->createDomDocument($context);
    $this->format = $format;
    $this->context = $context;

    if (NULL !== $data && !is_scalar($data)) {
      $root = $this->dom->createElement($xmlRootNodeName);
      $this->dom->appendChild($root);
      $this->buildXml($root, $data, $xmlRootNodeName);
    }
    else {
      $this->appendNode($this->dom, $data, $xmlRootNodeName);
    }

    return $this->dom->saveXML();
  }

  /**
   * {@inheritdoc}
   */
  public function decode($data, $format, array $context = []) {
    if ('' === trim($data)) {
      throw new \UnexpectedValueException('Invalid XML data, it can not be empty.');
    }
    if (function_exists('libxml_use_entity_loader') && \PHP_VERSION_ID < 80000) {
      $internalErrors = libxml_use_internal_errors(TRUE);
      libxml_use_internal_errors($internalErrors);

    }

    libxml_clear_errors();

    $dom = new \DOMDocument();
    $dom->loadXML($data, LIBXML_NONET | LIBXML_NOBLANKS);

    if ($error = libxml_get_last_error()) {
      libxml_clear_errors();

      throw new \UnexpectedValueException($error->message);
    }

    foreach ($dom->childNodes as $child) {
      if ($child->nodeType === XML_DOCUMENT_TYPE_NODE) {
        throw new \UnexpectedValueException('Document types are not allowed.');
      }
    }

    $rootNode = $dom->firstChild;

    // @todo throw an exception if the root node name is not correctly configured (bc)
    if ($rootNode->hasChildNodes()) {
      $xpath = new \DOMXPath($dom);
      $data = [];
      foreach ($xpath->query('namespace::*', $dom->documentElement) as $nsNode) {
        $data['@' . $nsNode->nodeName] = $nsNode->nodeValue;
      }

      unset($data['@xmlns:xml']);

      if (empty($data)) {
        return $this->parseXml($rootNode);
      }

      return array_merge($data, (array) $this->parseXml($rootNode));
    }

    if (!$rootNode->hasAttributes()) {
      return $rootNode->nodeValue;
    }

    $data = [];

    foreach ($rootNode->attributes as $attrKey => $attr) {
      $data['@' . $attrKey] = $attr->nodeValue;
    }

    $data['#'] = $rootNode->nodeValue;

    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function supportsEncoding(string $format): bool {
    return 'xml' === $format;
  }

  /**
   * {@inheritdoc}
   */
  public function supportsDecoding($format) {
    return 'xml' === $format;
  }

  /**
   * Sets the root node name.
   *
   * @param string $name
   *   Root node name.
   */
  public function setRootNodeName($name) {
    $this->rootNodeName = $name;
  }

  /**
   * Returns the root node name.
   *
   * @return string
   *   Returns root node name.
   */
  public function getRootNodeName() {
    return $this->rootNodeName;
  }

  /**
   * Append XML String.
   *
   * @param \DOMNode $node
   *   The node.
   * @param string $val
   *   XML to append.
   *
   * @return bool
   *   Return value.
   */
  final protected function appendXmlString(\DOMNode $node, $val) {
    if (strlen($val) > 0) {
      $frag = $this->dom->createDocumentFragment();
      $frag->appendXML($val);
      $node->appendChild($frag);

      return TRUE;
    }

    return FALSE;
  }

  /**
   * Append text to xml.
   *
   * @param \DOMNode $node
   *   The node.
   * @param string $val
   *   Text to append.
   *
   * @return bool
   *   Return value.
   */
  final protected function appendText(\DOMNode $node, $val) {
    $nodeText = $this->dom->createTextNode($val);
    $node->appendChild($nodeText);

    return TRUE;
  }

  /**
   * Append CDATA to node.
   *
   * @param \DOMNode $node
   *   The node.
   * @param string $val
   *   String to append as CDATA.
   *
   * @return bool
   *   Return value.
   */
  final protected function appendCData(\DOMNode $node, $val) {
    $nodeText = $this->dom->createCDATASection($val);
    $node->appendChild($nodeText);
    return TRUE;
  }

  /**
   * Append fragment.
   *
   * @param \DOMNode $node
   *   The node.
   * @param \DOMDocumentFragment $fragment
   *   Fragement to append.
   *
   * @return bool
   *   Return value.
   */
  final protected function appendDocumentFragment(\DOMNode $node, $fragment) {
    if ($fragment instanceof \DOMDocumentFragment) {
      $node->appendChild($fragment);

      return TRUE;
    }

    return FALSE;
  }

  /**
   * Checks the name is a valid xml element name.
   *
   * @param string $name
   *   Name of element.
   *
   * @return bool
   *   Return value.
   */
  final protected function isElementNameValid($name) {
    return $name &&
    FALSE === strpos($name, ' ') &&
    preg_match('#^[\pL_][\pL0-9._:-]*$#ui', $name);
  }

  /**
   * Parse the input DOMNode into an array or a string.
   *
   * @param \DOMNode $node
   *   The xml to parse.
   *
   * @return array|string
   *   Return xml.
   */
  private function parseXml(\DOMNode $node) {
    $data = $this->parseXmlAttributes($node);

    $value = $this->parseXmlValue($node);

    if (!count($data)) {
      return $value;
    }

    if (!is_array($value)) {
      $data['#'] = $value;

      return $data;
    }

    if (1 === count($value) && key($value)) {
      $data[key($value)] = current($value);

      return $data;
    }

    foreach ($value as $key => $val) {
      $data[$key] = $val;
    }

    return $data;
  }

  /**
   * Parse the input DOMNode attributes into an array.
   *
   * @param \DOMNode $node
   *   The xml to parse.
   *
   * @return array
   *   Return attributes.
   */
  private function parseXmlAttributes(\DOMNode $node) {
    if (!$node->hasAttributes()) {
      return [];
    }

    $data = [];

    foreach ($node->attributes as $attr) {
      if (ctype_digit($attr->nodeValue)) {
        $data['@' . $attr->nodeName] = (int) $attr->nodeValue;
      }
      else {
        $data['@' . $attr->nodeName] = $attr->nodeValue;
      }
    }

    return $data;
  }

  /**
   * Parse the input DOMNode value (content and children).
   *
   * @param \DOMNode $node
   *   The xml to parse.
   *
   * @return array|string
   *   return value.
   */
  private function parseXmlValue(\DOMNode $node) {
    if (!$node->hasChildNodes()) {
      return $node->nodeValue;
    }

    if (1 === $node->childNodes->length && in_array($node->firstChild->nodeType,
        [XML_TEXT_NODE, XML_CDATA_SECTION_NODE]
      )) {
      return $node->firstChild->nodeValue;
    }

    $value = [];

    foreach ($node->childNodes as $subnode) {
      $val = $this->parseXml($subnode);

      if ('item' === $subnode->nodeName && isset($val['@key'])) {
        if (isset($val['#'])) {
          $value[$val['@key']] = $val['#'];
        }
        else {
          $value[$val['@key']] = $val;
        }
      }
      else {
        $value[$subnode->nodeName][] = $val;
      }
    }

    foreach ($value as $key => $val) {
      if (is_array($val) && 1 === count($val)) {
        $value[$key] = current($val);
      }
    }

    return $value;
  }

  /**
   * Parse the data and convert it to DOMElements.
   *
   * @param \DOMNode $parentNode
   *   The parent node.
   * @param array|object $data
   *   The data to be parsed and added.
   * @param string|null $xmlRootNodeName
   *   The root node.
   *
   * @return bool
   *   Return success or ...
   *
   * @throws UnexpectedValueException
   *   Exception to throw.
   */
  private function buildXml(\DOMNode $parentNode, $data, $xmlRootNodeName = NULL) {
    $append = TRUE;

    if (is_array($data) || $data instanceof \Traversable) {
      foreach ($data as $key => $data) {
        // Ah this is the magic @ attribute types.
        if (0 === strpos($key, '@') && is_scalar($data) &&
          $this->isElementNameValid($attributeName = substr($key, 1))) {
          $parentNode->setAttribute($attributeName, $data);
        }
        elseif ($key === '#') {
          $append = $this->selectNodeType($parentNode, $data);
        }
        elseif (is_array($data) && FALSE === is_numeric($key)) {
          // Is this array fully numeric keys?
          if (ctype_digit(implode('', array_keys($data)))) {
            foreach ($data as $subData) {
              $append = $this->appendNode($parentNode, $subData, $key);
            }
          }
          else {
            $append = $this->appendNode($parentNode, $data, $key);
          }
        }
        elseif (is_numeric($key) || !$this->isElementNameValid($key)) {
          // Checking passed data for keywords resulting in different
          // root_nodes.
          if (NULL != array_key_exists('error', $data)) {
            $append = $this->appendNode($parentNode, $data, 'error', $key);

          }
          elseif (array_key_exists('metadata', $data)) {
            $append = $this->appendNode($parentNode, $data, 'service', $key);
          }
          elseif (array_key_exists('changeset', $data)) {
            $append = $this->appendNode($parentNode, $data, 'endpoint', $key);
          }
          else {
            $append = $this->appendNode($parentNode, $data, 'request', $key);
          }
        }
        else {
          $append = $this->appendNode($parentNode, $data, $key);
        }
      }

      return $append;
    }

    if (is_object($data)) {
      $data = $this->serializer->normalize($data, $this->format, $this->context);
      if (NULL !== $data && !is_scalar($data)) {
        return $this->buildXml($parentNode, $data, $xmlRootNodeName);
      }

      // Top level data object was normalized into a scalar.
      if (!$parentNode->parentNode->parentNode) {
        $root = $parentNode->parentNode;
        $root->removeChild($parentNode);

        return $this->appendNode($root, $data, $xmlRootNodeName);
      }

      return $this->appendNode($parentNode, $data, 'data');
    }

    throw new \UnexpectedValueException(sprintf('An unexpected value could not be serialized: %s', var_export($data, TRUE)));
  }

  /**
   * Selects the type of node to create and appends it to the parent.
   *
   * @param \DOMNode $parentNode
   *   The parend node.
   * @param array|object $data
   *   Data to append.
   * @param string $nodeName
   *   Node name.
   * @param string $key
   *   Attribute to set.
   *
   * @return bool|mixed
   *   Return value.
   */
  private function appendNode(\DOMNode $parentNode, $data, $nodeName, $key = NULL) {
    $node = $this->dom->createElement($nodeName);
    if (NULL !== $key) {
      $node->setAttribute('key', $key);
    }
    $appendNode = $this->selectNodeType($node, $data);
    // We always append the node, regardless of the selectNodeType result
    $parentNode->appendChild($node);
    return $appendNode;
  }

  /**
   * Checks if a value contains any characters which require CDATA wrapping.
   *
   * @param string $val
   *   String to check.
   *
   * @return bool
   *   Result of check.
   */
  private function needsCdataWrapping($val) {
    return preg_match('/[<>&]/', $val);
  }

  /**
   * Tests the value being passed and decide what sort of element to create.
   *
   * @param \DOMNode $node
   *   The node.
   * @param mixed $val
   *   Mixed value.
   *
   * @return mixed
   *   Return value.
   */
  private function selectNodeType(\DOMNode $node, $val) {
    if (is_array($val)) {
      return $this->buildXml($node, $val);
    }
    elseif ($val instanceof \SimpleXMLElement) {
      $child = $this->dom->importNode(dom_import_simplexml($val), TRUE);
      $node->appendChild($child);
    }
    elseif ($val instanceof \Traversable) {
      $this->buildXml($node, $val);
    }
    elseif (is_object($val)) {
      return $this->buildXml($node, $this->serializer->normalize($val, $this->format, $this->context));
    }
    elseif (is_numeric($val)) {
      return $this->appendText($node, (string) $val);
    }
    elseif (is_string($val) && $this->needsCdataWrapping($val)) {
      return $this->appendCData($node, $val);
    }
    elseif (is_string($val)) {
      return $this->appendText($node, $val);
    }
    elseif (is_bool($val)) {
      return $this->appendText($node, (int) $val);
    }
    elseif ($val instanceof \DOMNode) {
      $child = $this->dom->importNode($val, TRUE);
      $node->appendChild($child);
    }

    return TRUE;
  }

  /**
   * Get real XML root node name, taking serializer options into account.
   *
   * @param array $context
   *   The context.
   *
   * @return string
   *   Return root name.
   */
  private function resolveXmlRootName(array $context = []) {
    return $context['xml_root_node_name'] ?? $this->rootNodeName;
  }

  /**
   * Create a DOM document, taking serializer options into account.
   *
   * @param array $context
   *   Options that the encoder has access to.
   *
   * @return \DOMDocument
   *   Return xml.
   */
  private function createDomDocument(array $context) {
    $document = new \DOMDocument();

    // Set an attribute on the DOM document specifying, as part of the XML declaration,.
    $xmlOptions = [
      // Nicely formats output with indentation and extra space.
      'xml_format_output' => 'formatOutput',
      // The version number of the document.
      'xml_version' => 'xmlVersion',
      // The encoding of the document.
      'xml_encoding' => 'encoding',
      // Whether the document is standalone.
      'xml_standalone' => 'xmlStandalone',
    ];
    foreach ($xmlOptions as $xmlOption => $documentProperty) {
      if (isset($context[$xmlOption])) {
        $document->$documentProperty = $context[$xmlOption];
      }
    }

    return $document;
  }

}
