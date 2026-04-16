<?php
/**
 * @file
 * Defines a dropdown widget for integer fields.
 */

namespace Drupal\ucb_tma_interface\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldFilteredMarkup;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Field\WidgetInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ucb_tma_interface\InterfaceController\TmaFrontController;

/**
 * Implements simple dropdown widget for integer fields using min/max values.
 *
 * @FieldWidget(
 *   id = "BuildingDropdownWidget",
 *   label = @Translation("Building Select"),
 *   field_types = {
 *     "text"
 *   }
 * )
 */
class BuildingDropdownWidget extends WidgetBase implements WidgetInterface {
  /**
   * Implements custom form element.
   *
   * @inheritdoc
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    // Pull together info we need to build the element.
    $value = isset($items[$delta]->value) ? $items[$delta]->value : NULL;
    $field_settings = $this->getFieldSettings();

		$tma_controller = new TmaFrontController();
		$buildings = $tma_controller->getBuilding();


    // Build the element render array.
    $element += array(
      '#type' => 'select',
      '#default_value' => $value,
      '#options' => $buildings,
      '#empty_option' => '--',
    );

    // Add prefix and suffix.
	/*
    if ($field_settings['prefix']) {
      $prefixes = explode('|', $field_settings['prefix']);
      $element['#field_prefix'] = FieldFilteredMarkup::create(array_pop($prefixes));
    }
    if ($field_settings['suffix']) {
      $suffixes = explode('|', $field_settings['suffix']);
      $element['#field_suffix'] = FieldFilteredMarkup::create(array_pop($suffixes));
    }
	*/

    return array('value' => $element);
  }

}