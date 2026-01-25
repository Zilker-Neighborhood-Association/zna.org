<?php

namespace Drupal\Tests\zna_address\Functional;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Form\FormState;
use Drupal\crm\Entity\ContactMethod;
use Drupal\Tests\BrowserTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Tests the Zilker address widget functionality.
 *
 * @group zna_address
 */
class ZilkerAddressWidgetTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'address',
    'taxonomy',
    'crm',
    'zna_address',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Admin user for testing.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * Test street name term.
   *
   * @var \Drupal\taxonomy\TermInterface
   */
  protected $streetNameTerm;

  /**
   * A second street name term for testing.
   *
   * @var \Drupal\taxonomy\TermInterface
   */
  protected $streetNameTerm2;

  /**
   * The widget plugin instance.
   *
   * @var \Drupal\zna_address\Plugin\Field\FieldWidget\ZilkerAddressWidget
   */
  protected $widget;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->adminUser = $this->createUser([
      'administer crm',
      'administer taxonomy',
    ]);
    $this->drupalLogin($this->adminUser);

    // Ensure zilker_street_names vocabulary exists.
    if (!Vocabulary::load('zilker_street_names')) {
      Vocabulary::create([
        'vid' => 'zilker_street_names',
        'name' => 'Zilker Street Names',
      ])->save();
    }

    // Create test street name terms.
    $this->streetNameTerm = Term::create([
      'vid' => 'zilker_street_names',
      'name' => 'Barton Springs Rd',
    ]);
    $this->streetNameTerm->save();

    $this->streetNameTerm2 = Term::create([
      'vid' => 'zilker_street_names',
      'name' => 'Kinney Ave',
    ]);
    $this->streetNameTerm2->save();

    // Configure the widget on the form display.
    $form_display = EntityFormDisplay::load('crm_contact_method.address.default');
    if ($form_display) {
      $form_display->setComponent('address', [
        'type' => 'zilker_address',
        'settings' => [
          'wrapper_type' => 'container',
        ],
      ])->save();
    }

    // Instantiate the widget for direct testing.
    $field_definition = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions('crm_contact_method', 'address')['address'];
    $this->widget = \Drupal::service('plugin.manager.field.widget')
      ->getInstance([
        'field_definition' => $field_definition,
        'form_mode' => 'default',
        'prepare' => TRUE,
        'configuration' => [
          'type' => 'zilker_address',
          'settings' => ['wrapper_type' => 'container'],
          'third_party_settings' => [],
        ],
      ]);
  }

  /**
   * Tests massageFormValues constructs address_line1 for Zilker with unit.
   */
  public function testMassageZilkerWithUnit(): void {
    $values = [
      [
        'address_type' => 'zilker',
        'address_fields' => [
          'street_number' => '2201',
          'street_name' => $this->streetNameTerm->id(),
          'unit_number' => 'A',
          'address' => [
            'langcode' => 'en',
            'country_code' => 'US',
            'administrative_area' => 'TX',
            'locality' => 'Austin',
            'postal_code' => '78704',
            'address_line1' => '',
            'address_line2' => '',
            'address_line3' => '',
          ],
        ],
      ],
    ];

    $form_state = new FormState();
    $result = $this->widget->massageFormValues($values, [], $form_state);

    $this->assertEquals('US', $result[0]['country_code']);
    $this->assertEquals('TX', $result[0]['administrative_area']);
    $this->assertEquals('Austin', $result[0]['locality']);
    $this->assertEquals('78704', $result[0]['postal_code']);
    $this->assertEquals('2201 Barton Springs Rd A', $result[0]['address_line1']);
    $this->assertEquals('', $result[0]['address_line2']);
    $this->assertEquals('', $result[0]['address_line3']);
    $this->assertEquals('en', $result[0]['langcode']);
  }

  /**
   * Tests massageFormValues constructs address_line1 without unit number.
   */
  public function testMassageZilkerWithoutUnit(): void {
    $values = [
      [
        'address_type' => 'zilker',
        'address_fields' => [
          'street_number' => '100',
          'street_name' => $this->streetNameTerm2->id(),
          'unit_number' => '',
          'address' => [
            'langcode' => 'en',
            'country_code' => 'US',
            'administrative_area' => 'TX',
            'locality' => 'Austin',
            'postal_code' => '78704',
            'address_line1' => '',
            'address_line2' => '',
            'address_line3' => '',
          ],
        ],
      ],
    ];

    $form_state = new FormState();
    $result = $this->widget->massageFormValues($values, [], $form_state);

    $this->assertEquals('100 Kinney Ave', $result[0]['address_line1']);
    $this->assertStringNotContainsString('  ', $result[0]['address_line1']);
  }

  /**
   * Tests massageFormValues passes through non-Zilker address values.
   */
  public function testMassageNonZilker(): void {
    $values = [
      [
        'address_type' => 'non_zilker',
        'address_fields' => [
          'address' => [
            'langcode' => 'en',
            'country_code' => 'US',
            'administrative_area' => 'NY',
            'locality' => 'New York',
            'postal_code' => '10001',
            'address_line1' => '789 Broadway',
            'address_line2' => 'Apt 12',
            'address_line3' => '',
          ],
        ],
      ],
    ];

    $form_state = new FormState();
    $result = $this->widget->massageFormValues($values, [], $form_state);

    $this->assertEquals('US', $result[0]['country_code']);
    $this->assertEquals('NY', $result[0]['administrative_area']);
    $this->assertEquals('New York', $result[0]['locality']);
    $this->assertEquals('10001', $result[0]['postal_code']);
    $this->assertEquals('789 Broadway', $result[0]['address_line1']);
    $this->assertEquals('Apt 12', $result[0]['address_line2']);
  }

  /**
   * Tests massageFormValues handles invalid term ID gracefully.
   */
  public function testMassageZilkerInvalidTerm(): void {
    $values = [
      [
        'address_type' => 'zilker',
        'address_fields' => [
          'street_number' => '500',
          'street_name' => '99999',
          'unit_number' => '',
          'address' => [
            'langcode' => 'en',
          ],
        ],
      ],
    ];

    $form_state = new FormState();
    $result = $this->widget->massageFormValues($values, [], $form_state);

    // With an invalid term, address_line1 should only have street number.
    $this->assertEquals('500', $result[0]['address_line1']);
    $this->assertEquals('Austin', $result[0]['locality']);
  }

  /**
   * Tests massageFormValues handles empty street name gracefully.
   */
  public function testMassageZilkerEmptyStreetName(): void {
    $values = [
      [
        'address_type' => 'zilker',
        'address_fields' => [
          'street_number' => '300',
          'street_name' => '',
          'unit_number' => 'B',
          'address' => [
            'langcode' => 'en',
          ],
        ],
      ],
    ];

    $form_state = new FormState();
    $result = $this->widget->massageFormValues($values, [], $form_state);

    $this->assertEquals('300 B', $result[0]['address_line1']);
  }

  /**
   * Tests formElement renders address type radios and Zilker fields.
   */
  public function testFormElementRendersZilkerFields(): void {
    // An address matching Zilker criteria defaults to 'zilker' type.
    $contact_method = ContactMethod::create([
      'type' => 'address',
      'detail' => 'Home',
      'address' => [
        'country_code' => 'US',
        'administrative_area' => 'TX',
        'locality' => 'Austin',
        'postal_code' => '78704',
        'address_line1' => '2201 Barton Springs Rd',
      ],
    ]);
    $contact_method->save();

    $form = [];
    $form_state = new FormState();
    $items = $contact_method->get('address');

    $element = [
      '#field_parents' => [],
    ];

    $result = $this->widget->formElement($items, 0, $element, $form, $form_state);

    // Verify address_type radios exist with correct options.
    $this->assertArrayHasKey('address_type', $result);
    $this->assertEquals('radios', $result['address_type']['#type']);
    $this->assertArrayHasKey('zilker', $result['address_type']['#options']);
    $this->assertArrayHasKey('non_zilker', $result['address_type']['#options']);
    $this->assertEquals('zilker', $result['address_type']['#default_value']);

    // Verify AJAX is configured on the radios.
    $this->assertArrayHasKey('#ajax', $result['address_type']);
    $this->assertEquals('change', $result['address_type']['#ajax']['event']);

    // Verify Zilker fields are rendered (default is 'zilker').
    $this->assertArrayHasKey('address_fields', $result);

    $this->assertArrayHasKey('street_number', $result['address_fields']);
    $this->assertEquals('textfield', $result['address_fields']['street_number']['#type']);
    $this->assertEquals(6, $result['address_fields']['street_number']['#maxlength']);
    $this->assertEquals('2201', $result['address_fields']['street_number']['#default_value']);

    $this->assertArrayHasKey('street_name', $result['address_fields']);
    $this->assertEquals('entity_autocomplete', $result['address_fields']['street_name']['#type']);
    $this->assertEquals('taxonomy_term', $result['address_fields']['street_name']['#target_type']);
    $this->assertNotNull($result['address_fields']['street_name']['#default_value']);
    $this->assertEquals('Barton Springs Rd', $result['address_fields']['street_name']['#default_value']->getName());

    $this->assertArrayHasKey('unit_number', $result['address_fields']);
    $this->assertEquals('textfield', $result['address_fields']['unit_number']['#type']);
    $this->assertEquals(4, $result['address_fields']['unit_number']['#maxlength']);
    $this->assertFalse($result['address_fields']['unit_number']['#required']);
    $this->assertEquals('', $result['address_fields']['unit_number']['#default_value']);

    // Verify the hidden address value element with preset Zilker values.
    $this->assertArrayHasKey('address', $result['address_fields']);
    $this->assertEquals('value', $result['address_fields']['address']['#type']);
    $this->assertEquals('Austin', $result['address_fields']['address']['#value']['locality']);
    $this->assertEquals('78704', $result['address_fields']['address']['#value']['postal_code']);
    $this->assertEquals('TX', $result['address_fields']['address']['#value']['administrative_area']);
    $this->assertEquals('US', $result['address_fields']['address']['#value']['country_code']);
  }

  /**
   * Tests formElement renders standard address widget for non-Zilker.
   */
  public function testFormElementRendersNonZilkerFields(): void {
    // Create a contact method with a non-Zilker address.
    $contact_method = ContactMethod::create([
      'type' => 'address',
      'detail' => 'Work',
      'address' => [
        'country_code' => 'US',
        'administrative_area' => 'CA',
        'locality' => 'San Francisco',
        'postal_code' => '94102',
        'address_line1' => '123 Market Street',
      ],
    ]);
    $contact_method->save();

    $form = [];
    $form_state = new FormState();
    $items = $contact_method->get('address');

    $element = [
      '#field_parents' => [],
    ];

    $result = $this->widget->formElement($items, 0, $element, $form, $form_state);

    // Non-Zilker city should result in non_zilker address type.
    $this->assertEquals('non_zilker', $result['address_type']['#default_value']);

    // Should render the standard address element, not Zilker fields.
    $this->assertArrayHasKey('address', $result['address_fields']);
    $this->assertEquals('address', $result['address_fields']['address']['#type']);
    $this->assertArrayNotHasKey('street_number', $result['address_fields']);
    $this->assertArrayNotHasKey('street_name', $result['address_fields']);
    $this->assertArrayNotHasKey('unit_number', $result['address_fields']);
  }

  /**
   * Tests formElement detects non-Zilker by postal code.
   */
  public function testFormElementDetectsNonZilkerByPostalCode(): void {
    $contact_method = ContactMethod::create([
      'type' => 'address',
      'detail' => 'Home',
      'address' => [
        'country_code' => 'US',
        'administrative_area' => 'TX',
        'locality' => 'Austin',
        'postal_code' => '78701',
        'address_line1' => '100 Congress Ave',
      ],
    ]);
    $contact_method->save();

    $form = [];
    $form_state = new FormState();
    $items = $contact_method->get('address');
    $element = ['#field_parents' => []];

    $result = $this->widget->formElement($items, 0, $element, $form, $form_state);

    // Austin but non-78704 zip should be detected as non-Zilker.
    $this->assertEquals('non_zilker', $result['address_type']['#default_value']);
  }

  /**
   * Tests stored address_type field overrides geographic inference.
   */
  public function testStoredAddressTypeOverridesInference(): void {
    // An Austin 78704 address explicitly marked as non-zilker should remain
    // non-zilker even though it's in the Zilker geographic area.
    $contact_method = ContactMethod::create([
      'type' => 'address',
      'detail' => 'Home',
      'address' => [
        'country_code' => 'US',
        'administrative_area' => 'TX',
        'locality' => 'Austin',
        'postal_code' => '78704',
        'address_line1' => '500 S Lamar Blvd',
      ],
      'address_type' => 'non_zilker',
    ]);
    $contact_method->save();

    $form = [];
    $form_state = new FormState();
    $items = $contact_method->get('address');
    $element = ['#field_parents' => []];

    $result = $this->widget->formElement($items, 0, $element, $form, $form_state);

    // Stored address_type should take priority over geographic inference.
    $this->assertEquals('non_zilker', $result['address_type']['#default_value']);
    $this->assertArrayHasKey('address', $result['address_fields']);
    $this->assertEquals('address', $result['address_fields']['address']['#type']);
    $this->assertArrayNotHasKey('street_number', $result['address_fields']);
  }

  /**
   * Tests AJAX callback returns the address_fields container.
   */
  public function testAjaxCallbackReturnsAddressFields(): void {
    // Build a mock form structure matching what the widget would produce.
    $form = [
      'field_address' => [
        'widget' => [
          0 => [
            'address_type' => [
              '#type' => 'radios',
              '#array_parents' => [
                'field_address',
                'widget',
                0,
                'address_type',
              ],
            ],
            'address_fields' => [
              '#type' => 'container',
              '#prefix' => '<div id="zilker-address-wrapper-0">',
              '#suffix' => '</div>',
              'street_number' => [
                '#type' => 'textfield',
              ],
            ],
          ],
        ],
      ],
    ];

    $form_state = new FormState();
    $form_state->setTriggeringElement($form['field_address']['widget'][0]['address_type']);

    $result = $this->widget->addressTypeAjaxCallback($form, $form_state);

    $this->assertArrayHasKey('street_number', $result);
    $this->assertEquals('container', $result['#type']);
  }

  /**
   * Tests full save roundtrip through the widget for a Zilker address.
   */
  public function testZilkerAddressSaveRoundtrip(): void {
    // Simulate what happens when the form is submitted with Zilker values.
    $values = [
      [
        'address_type' => 'zilker',
        'address_fields' => [
          'street_number' => '1500',
          'street_name' => $this->streetNameTerm->id(),
          'unit_number' => '',
          'address' => [
            'langcode' => 'en',
            'country_code' => 'US',
            'administrative_area' => 'TX',
            'locality' => 'Austin',
            'postal_code' => '78704',
            'address_line1' => '',
            'address_line2' => '',
            'address_line3' => '',
          ],
        ],
      ],
    ];

    $form_state = new FormState();
    $massaged = $this->widget->massageFormValues($values, [], $form_state);

    // Save the entity with the massaged values.
    $contact_method = ContactMethod::create([
      'type' => 'address',
      'detail' => 'Home',
      'address' => $massaged[0],
    ]);
    $contact_method->save();

    // Reload and verify.
    $loaded = ContactMethod::load($contact_method->id());
    $address = $loaded->get('address')->first()->toArray();

    $this->assertEquals('1500 Barton Springs Rd', $address['address_line1']);
    $this->assertEquals('Austin', $address['locality']);
    $this->assertEquals('TX', $address['administrative_area']);
    $this->assertEquals('78704', $address['postal_code']);
    $this->assertEquals('US', $address['country_code']);
    $this->assertEquals('', $address['address_line2']);
  }

  /**
   * Tests require_zilker setting hides address type selector.
   */
  public function testRequireZilkerSettingHidesAddressType(): void {
    // Create a widget with require_zilker enabled.
    $field_definition = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions('crm_contact_method', 'address')['address'];
    $widget = \Drupal::service('plugin.manager.field.widget')->getInstance([
      'field_definition' => $field_definition,
      'form_mode' => 'default',
      'prepare' => TRUE,
      'configuration' => [
        'type' => 'zilker_address',
        'settings' => [
          'wrapper_type' => 'container',
          'require_zilker' => TRUE,
        ],
        'third_party_settings' => [],
      ],
    ]);

    $contact_method = ContactMethod::create([
      'type' => 'address',
      'detail' => 'Home',
      'address' => [
        'country_code' => 'US',
        'administrative_area' => 'TX',
        'locality' => 'Austin',
        'postal_code' => '78704',
        'address_line1' => '',
      ],
    ]);
    $contact_method->save();

    $form = [];
    $form_state = new FormState();
    $items = $contact_method->get('address');
    $element = ['#field_parents' => []];

    $result = $widget->formElement($items, 0, $element, $form, $form_state);

    // Address type should be hidden and forced to 'zilker'.
    $this->assertEquals('hidden', $result['address_type']['#type']);
    $this->assertEquals('zilker', $result['address_type']['#value']);

    // Zilker fields should be rendered.
    $this->assertArrayHasKey('street_number', $result['address_fields']);
    $this->assertArrayHasKey('street_name', $result['address_fields']);
    $this->assertArrayHasKey('unit_number', $result['address_fields']);

    // Standard address widget should NOT be rendered.
    $this->assertNotEquals('address', $result['address_fields']['address']['#type'] ?? NULL);
  }

}
