<?php

namespace Drupal\search_api_solr_datasource\Plugin\DataType;

use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\Core\TypedData\TypedData;
use Drupal\search_api\Item\FieldInterface;

/**
 * Defines the "Solr field" data type.
 *
 * Instances of this class wrap Search API Field objects and allow to deal with
 * fields based upon the Typed Data API.
 *
 * @DataType(
 *   id = "solr_field",
 *   label = @Translation("Solr field"),
 *   description = @Translation("Fields from a Solr document."),
 *   definition_class = "\Drupal\search_api_solr_datasource\SolrFieldDefinition"
 * )
 */
class SolrField extends TypedData implements \IteratorAggregate, TypedDataInterface {

  /**
   * The wrapped Search API field.
   *
   * @var \Drupal\search_api\Item\FieldInterface|null
   */
  protected $field;

  /**
   * Creates an instance wrapping the given Field.
   *
   * @param \Drupal\search_api\Item\FieldInterface $field
   *   The Field object to wrap.
   * @param string $name
   *   The name of the wrapped field.
   * @param \Drupal\Core\TypedData\TypedDataInterface $parent
   *   The parent object of the wrapped field, which should be a Solr document.
   *
   * @return static
   */
  public static function createFromField(FieldInterface $field, $name, TypedDataInterface $parent) {
    // Get the Solr field definition from the SolrFieldManager.
    /** @var \Drupal\search_api_solr_datasource\SolrFieldManagerInterface $field_manager */
    $field_manager = \Drupal::getContainer()->get('solr_field.manager');
    $server_id = $field->getIndex()->getServerInstance()->id();
    $field_id = $field->getFieldIdentifier();
    $definition = $field_manager->getFieldDefinitions($server_id)[$field_id];
    $instance = new static($definition, $name, $parent);
    $instance->setValue($field);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getValue() {
    return $this->field ? $this->field->getValues() : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($field, $notify = TRUE) {
    $this->field = $field;
    // Notify the parent of any changes.
    if ($notify && isset($this->parent)) {
      $this->parent->onChange($this->name);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getIterator() {
    if ($this->field instanceof \Iterator) {
      return $this->field;
    }
    if ($this->field instanceof \IteratorAggregate) {
      return $this->field->getIterator();
    }
    return new \ArrayIterator([]);
  }

}
