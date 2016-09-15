<?php

namespace Drupal\commerce_product\Plugin\Field\FieldWidget;

use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Event\ProductEvents;
use Drupal\commerce_product\Event\ProductVariationAjaxChangeEvent;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'commerce_product_variation_title' widget.
 *
 * The widget form depends on the 'product' being present in $form_state.
 *
 * @see \Drupal\commerce_product\Plugin\Field\FieldFormatter\AddToCartFormatter::viewElements().
 *
 * @FieldWidget(
 *   id = "commerce_product_variation_title",
 *   label = @Translation("Product variation title"),
 *   field_types = {
 *     "entity_reference"
 *   }
 * )
 */
class ProductVariationTitleWidget extends WidgetBase implements ContainerFactoryPluginInterface {

  /**
   * The product variation storage.
   *
   * @var \Drupal\commerce_product\ProductVariationStorageInterface
   */
  protected $variationStorage;

  /**
   * The product attribute storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $attributeStorage;

  /**
   * Constructs a new ProductVariationTitleWidget object.
   *
   * @param string $plugin_id
   *   The plugin_id for the widget.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the widget is associated.
   * @param array $settings
   *   The widget settings.
   * @param array $third_party_settings
   *   Any third party settings.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);

    $this->variationStorage = $entity_type_manager->getStorage('commerce_product_variation');
    $this->attributeStorage = $entity_type_manager->getStorage('commerce_product_attribute');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition) {
    $entity_type = $field_definition->getTargetEntityTypeId();
    $field_name = $field_definition->getName();
    return $entity_type == 'commerce_line_item' && $field_name == 'purchased_entity';
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'label_display' => TRUE,
      'label_text' => 'Variation:',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $formState) {
    $element = [];
    $element['label_display'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Display label'),
      '#default_value' => $this->getSetting('label_display'),
    ];
    $element['label_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label text'),
      '#default_value' => $this->getSetting('label_text'),
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
    $summary[] = $this->getSetting('label_display') ? $this->t('Display label: yes') : $this->t('Display label: no');
    if ($this->getSetting('label_display') && $this->getSetting('label_text')) {
      $summary[] = $this->t('Label text: @text', ['@text' => $this->getSetting('label_text')]);
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = $form_state->get('product');
    /** @var \Drupal\commerce_product\Entity\ProductVariationInterface[] $variations */
    $variations = $this->variationStorage->loadEnabled($product);
    if (count($variations) === 0) {
      // Nothing to purchase, tell the parent form to hide itself.
      $form_state->set('hide_form', TRUE);
      $element['variation'] = [
        '#type' => 'value',
        '#value' => 0,
      ];
      return $element;
    }
    elseif (count($variations) === 1) {
      /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $selected_variation */
      $selected_variation = reset($variations);
      $element['variation'] = [
        '#type' => 'value',
        '#value' => $selected_variation->id(),
      ];
      return $element;
    }

    // Build the variation options form.
    $wrapper_id = Html::getUniqueId('commerce-product-add-to-cart-form');
    $form += [
      '#wrapper_id' => $wrapper_id,
      '#prefix' => '<div id="' . $wrapper_id . '">',
      '#suffix' => '</div>',
    ];
    $parents = array_merge($element['#field_parents'], [$items->getName(), $delta]);
    $user_input = (array) NestedArray::getValue($form_state->getUserInput(), $parents);
    if (!empty($user_input)) {
      $selected_variation = $this->selectVariationFromUserInput($variations, $user_input);
    }
    else {
      $selected_variation = $this->variationStorage->loadFromContext($product);
      // The returned variation must also be enabled.
      if (!in_array($selected_variation, $variations)) {
        $selected_variation = reset($variations);
      }
    }

    // Set the selected variation in the form state for our AJAX callback.
    $form_state->set('selected_variation', $selected_variation->id());

    $variation_options = [];
    foreach ($variations as $option) {
      $variation_options[$option->id()] = $option->label();
    }
    $element['variation'] = [
      '#type' => 'select',
      '#title' => $this->getSetting('label_display') && $this->getSetting('label_text') ? $this->getSetting('label_text') : NULL,
      '#options' => $variation_options,
      '#required' => TRUE,
      '#default_value' => $selected_variation->id(),
      '#ajax' => [
        'callback' => [get_class($this), 'ajaxRefresh'],
        'wrapper' => $form['#wrapper_id'],
      ],
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    // Map the variation form value to the expected field structure.
    foreach ($values as $key => $value) {
      $values[$key] = [
        'target_id' => $value['variation'],
      ];
    }

    return $values;
  }

  /**
   * Selects a product variation based on user input having selected a variation.
   *
   * If there's no user input (form viewed for the first time), the default
   * variation is returned.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface[] $variations
   *   An array of product variations.
   * @param array $user_input
   *   The user input.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface
   *   The selected variation.
   */
  protected function selectVariationFromUserInput(array $variations, array $user_input) {
    $current_variation = reset($variations);
    if (!empty($user_input['variation']) && $variations[$user_input['variation']]) {
      $current_variation = $variations[$user_input['variation']];
    }

    return $current_variation;
  }

  /**
   * Ajax callback.
   */
  public static function ajaxRefresh(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\Core\Render\MainContent\MainContentRendererInterface $ajax_renderer */
    $ajax_renderer = \Drupal::service('main_content_renderer.ajax');
    $request = \Drupal::request();
    $route_match = \Drupal::service('current_route_match');
    /** @var \Drupal\Core\Ajax\AjaxResponse $response */
    $response = $ajax_renderer->renderResponse($form, $request, $route_match);

    $variation = ProductVariation::load($form_state->get('selected_variation'));
    /** @var \Drupal\commerce_product\ProductVariationFieldRendererInterface $variation_field_renderer */
    $variation_field_renderer = \Drupal::service('commerce_product.variation_field_renderer');
    $view_mode = $form_state->get('form_display')->getMode();
    $variation_field_renderer->replaceRenderedFields($response, $variation, $view_mode);
    // Allow modules to add arbitrary ajax commands to the response.
    $event = new ProductVariationAjaxChangeEvent($variation, $response, $view_mode);
    $event_dispatcher = \Drupal::service('event_dispatcher');
    $event_dispatcher->dispatch(ProductEvents::PRODUCT_VARIATION_AJAX_CHANGE, $event);

    return $response;
  }

}
