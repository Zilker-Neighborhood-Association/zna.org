<?php

namespace Drupal\zna_address\Plugin\Field\FieldWidget;

use Drupal\address\Plugin\Field\FieldWidget\AddressDefaultWidget;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Plugin implementation of the 'zilker_address' widget.
 *
 * @FieldWidget(
 *   id = "zilker_address",
 *   label = @Translation("Zilker Address"),
 *   field_types = {
 *     "address"
 *   },
 * )
 */
class ZilkerAddressWidget extends AddressDefaultWidget {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'require_zilker' => FALSE,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element = parent::settingsForm($form, $form_state);

    $element['require_zilker'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require Zilker address'),
      '#description' => $this->t('When enabled, only Zilker addresses can be entered and the address type selector is hidden.'),
      '#default_value' => $this->getSetting('require_zilker'),
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();

    if ($this->getSetting('require_zilker')) {
      $summary[] = $this->t('Zilker address required');
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $item = $items[$delta];
    $value = $item->toArray();
    $value['langcode'] = $item->initializeLangcode();

    // If cardinality is multiple then there is no need to set address field
    // as required since $element handles this already.
    $required = FALSE;
    if ($this->fieldDefinition->getFieldStorageDefinition()->getCardinality() == 1) {
      $required = $this->fieldDefinition->isRequired();
    }

    $element += [
      '#type' => $this->getSetting('wrapper_type'),
      '#open' => TRUE,
    ];

    // Build form element wrapper.
    $wrapper_id = 'zilker-address-wrapper-' . $delta;

    // Determine current address type from form state or user input.
    $parents = array_merge($element['#field_parents'], [$this->fieldDefinition->getName(), $delta, 'address_type']);
    $address_type = $form_state->getValue($parents);

    // During form rebuild, getValue() may not be populated yet. Check raw user
    // input as a fallback to ensure the user's radio selection is respected.
    if (empty($address_type)) {
      $user_input = $form_state->getUserInput() ?? [];
      if (!empty($user_input)) {
        $address_type = NestedArray::getValue($user_input, $parents) ?? '';
      }
    }

    // If still no value, determine from the entity's address_type field or
    // infer from stored address data.
    $parsed_address = NULL;
    if (empty($address_type)) {
      $entity = $items->getEntity();
      $stored_type = $entity->hasField('address_type') ? $entity->get('address_type')->value : NULL;

      if (!empty($stored_type)) {
        $address_type = $stored_type;
        if ($address_type === 'zilker' && !empty($value['address_line1'])) {
          $parsed_address = $this->parseZilkerAddress($value['address_line1']);
        }
      }
      elseif (!empty($value['address_line1'])) {
        // No stored type (legacy data): infer from geography and street name.
        $is_zilker_area = (empty($value['locality']) || $value['locality'] === 'Austin')
          && (empty($value['postal_code']) || $value['postal_code'] === '78704');
        if ($is_zilker_area) {
          $parsed_address = $this->parseZilkerAddress($value['address_line1']);
          $address_type = $parsed_address ? 'zilker' : 'non_zilker';
        }
        else {
          $address_type = 'non_zilker';
        }
      }
      else {
        $address_type = 'zilker';
      }
    }

    // Check if Zilker address is required (hides address type selector).
    $require_zilker = $this->getSetting('require_zilker');
    if ($require_zilker) {
      $address_type = 'zilker';
    }

    // Address type selector.
    $element['address_type'] = [
      '#type' => $require_zilker ? 'hidden' : 'radios',
      '#title' => $this->t('Address Type'),
      '#options' => [
        'zilker' => $this->t('Zilker address'),
        'non_zilker' => $this->t('Non-Zilker address'),
      ],
      '#default_value' => $address_type,
      '#value' => $require_zilker ? 'zilker' : NULL,
      '#required' => !$require_zilker,
      '#ajax' => [
        'callback' => [$this, 'addressTypeAjaxCallback'],
        'wrapper' => $wrapper_id,
        'event' => 'change',
      ],
    ];

    // Container for address fields.
    $element['address_fields'] = [
      '#type' => 'container',
      '#prefix' => '<div id="' . $wrapper_id . '">',
      '#suffix' => '</div>',
    ];

    if ($address_type === 'zilker') {
      // Zilker address fields.
      $street_number_parents = array_merge($element['#field_parents'], [$this->fieldDefinition->getName(), $delta, 'address_fields', 'street_number']);
      $street_name_parents = array_merge($element['#field_parents'], [$this->fieldDefinition->getName(), $delta, 'address_fields', 'street_name']);
      $unit_number_parents = array_merge($element['#field_parents'], [$this->fieldDefinition->getName(), $delta, 'address_fields', 'unit_number']);

      // Determine default values from form state or parsed stored address.
      $default_street_number = $form_state->getValue($street_number_parents) ?? '';
      $default_street_name_term = NULL;
      $default_unit_number = $form_state->getValue($unit_number_parents) ?? '';

      $street_name_value = $form_state->getValue($street_name_parents);
      if (!empty($street_name_value)) {
        $default_street_name_term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($street_name_value);
      }

      // If no form state values, use parsed address from stored data.
      if (empty($default_street_number) && $parsed_address) {
        $default_street_number = $parsed_address['street_number'];
        $default_street_name_term = $parsed_address['street_name_term'];
        $default_unit_number = $parsed_address['unit_number'];
      }

      $element['address_fields']['street_number'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Street Number'),
        '#maxlength' => 6,
        '#required' => $required,
        '#default_value' => $default_street_number,
      ];

      $element['address_fields']['street_name'] = [
        '#type' => 'entity_autocomplete',
        '#title' => $this->t('Street Name'),
        '#target_type' => 'taxonomy_term',
        '#selection_settings' => [
          'target_bundles' => ['zilker_street_names'],
        ],
        '#required' => $required,
        '#default_value' => $default_street_name_term,
      ];

      $element['address_fields']['unit_number'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Unit Number'),
        '#maxlength' => 4,
        '#required' => FALSE,
        '#default_value' => $default_unit_number,
      ];

      // Hidden fields for standard address components (will be populated on submit).
      $element['address_fields']['address'] = [
        '#type' => 'value',
        '#value' => [
          'langcode' => $value['langcode'],
          'country_code' => 'US',
          'administrative_area' => 'TX',
          'locality' => 'Austin',
          'postal_code' => '78704',
          'address_line1' => '',
          'address_line2' => '',
          'address_line3' => '',
        ],
      ];
    }
    else {
      // Non-Zilker address: Use standard address widget.
      $element['address_fields']['address'] = [
        '#type' => 'address',
        '#default_value' => $value,
        '#required' => $required,
        '#available_countries' => $item->getAvailableCountries(),
        '#field_overrides' => $item->getFieldOverrides(),
      ];
    }

    // Make sure no properties are required on the default value widget.
    if ($this->isDefaultValueWidget($form_state)) {
      if (isset($element['address_fields']['address'])) {
        $element['address_fields']['address']['#after_build'][] = [
          AddressDefaultWidget::class,
          'makeFieldsOptional',
        ];
      }
      if (isset($element['address_fields']['street_number'])) {
        $element['address_fields']['street_number']['#required'] = FALSE;
      }
      if (isset($element['address_fields']['street_name'])) {
        $element['address_fields']['street_name']['#required'] = FALSE;
      }
    }

    return $element;
  }

  /**
   * AJAX callback for address type change.
   */
  public function addressTypeAjaxCallback(array &$form, FormStateInterface $form_state): array {
    $triggering_element = $form_state->getTriggeringElement();
    $array_parents = $triggering_element['#array_parents'];

    // Find 'address_type' in the parents and navigate to its sibling.
    $index = array_search('address_type', $array_parents);
    if ($index !== FALSE) {
      $widget_parents = array_slice($array_parents, 0, $index);
      $widget_parents[] = 'address_fields';
      return NestedArray::getValue($form, $widget_parents);
    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function errorElement(array $element, ConstraintViolationInterface $violation, array $form, FormStateInterface $form_state) {
    $address_type = $element['address_type']['#default_value'] ?? 'zilker';

    if ($address_type === 'zilker') {
      // For Zilker addresses, map address violations to Zilker fields.
      $property_path = $violation->getPropertyPath();
      if (str_contains($property_path, 'address_line1')) {
        return $element['address_fields']['street_number'] ?? FALSE;
      }
      return FALSE;
    }

    // For non-Zilker, navigate to the address sub-element.
    $property_path_array = explode('.', $violation->getPropertyPath());
    if (isset($property_path_array[1])) {
      $error_element = NestedArray::getValue(
        $element['address_fields']['address'],
        [$property_path_array[1]]
      );
      return is_array($error_element) ? $error_element : FALSE;
    }

    return FALSE;
  }

  /**
   * Parses an address_line1 string against the zilker_street_names vocabulary.
   *
   * @param string $address_line1
   *   The address line to parse.
   *
   * @return array|null
   *   An array with keys 'street_number', 'street_name_term', and
   *   'unit_number', or NULL if the address doesn't match any known street.
   */
  protected function parseZilkerAddress(string $address_line1): ?array {
    if (empty($address_line1)) {
      return NULL;
    }

    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'zilker_street_names']);

    if (empty($terms)) {
      return NULL;
    }

    // Sort by name length descending to match longest name first.
    usort($terms, fn($a, $b) => mb_strlen($b->getName()) - mb_strlen($a->getName()));

    foreach ($terms as $term) {
      $name = $term->getName();
      $pos = strpos($address_line1, $name);
      if ($pos !== FALSE) {
        $street_number = trim(substr($address_line1, 0, $pos));
        $unit_number = trim(substr($address_line1, $pos + strlen($name)));

        // Validate that the part before the street name looks like a number.
        if ($street_number !== '' && preg_match('/^\d+[A-Za-z]?$/', $street_number)) {
          return [
            'street_number' => $street_number,
            'street_name_term' => $term,
            'unit_number' => $unit_number,
          ];
        }
      }
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $new_values = [];

    foreach ($values as $delta => $value) {
      $fields = $value['address_fields'] ?? [];
      if (!empty($value['address_type']) && $value['address_type'] === 'zilker') {
        // Build the address from Zilker components.
        $street_number = $fields['street_number'] ?? '';
        $street_name_tid = $fields['street_name'] ?? '';
        $unit_number = $fields['unit_number'] ?? '';

        // Load the street name term.
        $street_name = '';
        if (!empty($street_name_tid)) {
          $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($street_name_tid);
          if ($term) {
            $street_name = $term->getName();
          }
        }

        // Build address_line1.
        $address_line1_parts = array_filter([$street_number, $street_name]);
        $address_line1 = implode(' ', $address_line1_parts);

        if (!empty($unit_number)) {
          $address_line1 .= ' ' . $unit_number;
        }

        $new_values[$delta] = [
          'langcode' => $fields['address']['langcode'] ?? 'en',
          'country_code' => 'US',
          'administrative_area' => 'TX',
          'locality' => 'Austin',
          'postal_code' => '78704',
          'address_line1' => $address_line1,
          'address_line2' => '',
          'address_line3' => '',
        ];
      }
      else {
        // Non-Zilker address: Use the standard address values.
        $new_values[$delta] = $fields['address'] ?? [];
      }
    }

    return $new_values;
  }

  /**
   * {@inheritdoc}
   */
  public function extractFormValues(FieldItemListInterface $items, array $form, FormStateInterface $form_state) {
    parent::extractFormValues($items, $form, $form_state);

    // Save the address_type to the entity's dedicated field.
    $entity = $items->getEntity();
    if ($entity->hasField('address_type')) {
      $field_name = $this->fieldDefinition->getName();
      $path = array_merge($form['#parents'], [$field_name]);
      $values = NestedArray::getValue($form_state->getValues(), $path);
      if (!empty($values[0]['address_type'])) {
        $entity->set('address_type', $values[0]['address_type']);
      }
    }
  }

}
