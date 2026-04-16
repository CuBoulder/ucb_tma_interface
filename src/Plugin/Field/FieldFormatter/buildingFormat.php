<?php

namespace Drupal\ucb_tma_interface\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Plugin implementation of the 'field_example_simple_text' formatter.
 *
 * @FieldFormatter(
 *   id = "tma_interface_buildingFormat",
 *   module = "ucb_tma_interface",
 *   label = @Translation("Building Display formatter"),
 *   field_types = {
 *     "field_building"
 *   }
 * )
 */
class buildingFormat extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    foreach ($items as $delta => $item) {
      $elements[$delta] = [
        // We create a render array to produce the desired markup,
        // "<p style="color: #hexcolor">The color code ... #hexcolor</p>".
        // See theme_html_tag().
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('The building in this field is @code', ['@code' => $item->value]),
      ];
    }

    return $elements;
  }

}
