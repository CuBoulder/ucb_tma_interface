<?php

namespace Drupal\ucb_tma_interface\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the 'field_building' field type.
 *
 * @FieldType(
 *   id = "field_building",
 *   label = @Translation("Building Selector"),
 *   module = "ucb_tma_interface",
 *   description = @Translation("Select a Building from TMA."),
 *   default_widget = "BuildingDropdownWidget",
 *   default_formatter = "tma_interface_buildingFormat"
 * )
 */
class tma_building extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'value' => [
          'type' => 'text',
          'size' => 'tiny',
          'not null' => FALSE,
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $value = $this->get('value')->getValue();
    return $value === NULL || $value === '';
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['value'] = DataDefinition::create('string')
      ->setLabel(t('Building'));

    return $properties;
  }

}
