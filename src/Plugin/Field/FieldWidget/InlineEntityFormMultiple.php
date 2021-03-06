<?php

/**
 * @file
 * Contains \Drupal\inline_entity_form\Plugin\Field\FieldWidget\InlineEntityFormMultiple.
 */

namespace Drupal\inline_entity_form\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\SortArray;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\field\Entity\FieldInstance;

/**
 * Multiple value widget.
 *
 * @FieldWidget(
 *   id = "inline_entity_form_multiple",
 *   label = @Translation("Inline entity form - Multiple value"),
 *   field_types = {
 *     "entity_reference"
 *   },
 *   multiple_values = true
 * )
 */
class InlineEntityFormMultiple extends WidgetBase {

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * The inline entity from handler.
   *
   * @var \Drupal\inline_entity_form\InlineEntityFormHandlerInterface
   */
  protected $iefHandler;

  protected $iefId;

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return array(
      "allow_existing" => FALSE,
      "match_operator" => "CONTAINS",
      "delete_references" => FALSE,
      "override_labels" => FALSE,
      "label_singular" => "",
      "label_plural" => "",
    );
  }

  /**
   * Returns the settings form for the current entity type.
   *
   * The settings form is embedded into the IEF widget settings form.
   * Settings are later injected into the controller through $this->settings.
   *
   * @param $field
   *   The definition of the reference field used by IEF.
   * @param $instance
   *   The definition of the reference field instance.
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $labels = $this->labels();
    $states_prefix = 'instance[widget][settings][type_settings]';

    $element['allow_existing'] = array(
      '#type' => 'checkbox',
      '#title' => t('Allow users to add existing @label.', array('@label' => $labels['plural'])),
      '#default_value' => $this->settings['allow_existing'],
    );
    $element['match_operator'] = array(
      '#type' => 'select',
      '#title' => t('Autocomplete matching'),
      '#default_value' => $this->settings['match_operator'],
      '#options' => array(
        'STARTS_WITH' => t('Starts with'),
        'CONTAINS' => t('Contains'),
      ),
      '#description' => t('Select the method used to collect autocomplete suggestions. Note that <em>Contains</em> can cause performance issues on sites with thousands of nodes.'),
      '#states' => array(
        'visible' => array(
          ':input[name="' . $states_prefix . '[allow_existing]"]' => array('checked' => TRUE),
        ),
      ),
    );
    // The single widget doesn't offer autocomplete functionality.
    if ($form_state->get('widget')['type'] == 'inline_entity_form_single') {
      $form['allow_existing']['#access'] = FALSE;
      $form['match_operator']['#access'] = FALSE;
    }

    $element['delete_references'] = array(
      '#type' => 'checkbox',
      '#title' => t('Delete referenced @label when the parent entity is deleted.', array('@label' => $labels['plural'])),
      '#default_value' => $this->settings['delete_references'],
    );

    $element['override_labels'] = array(
      '#type' => 'checkbox',
      '#title' => t('Override labels'),
      '#default_value' => $this->settings['override_labels'],
    );
    $element['label_singular'] = array(
      '#type' => 'textfield',
      '#title' => t('Singular label'),
      '#default_value' => $this->settings['label_singular'],
      '#states' => array(
        'visible' => array(
          ':input[name="' . $states_prefix . '[override_labels]"]' => array('checked' => TRUE),
        ),
      ),
    );
    $element['label_plural'] = array(
      '#type' => 'textfield',
      '#title' => t('Plural label'),
      '#default_value' => $this->settings['label_plural'],
      '#states' => array(
        'visible' => array(
          ':input[name="' . $states_prefix . '[override_labels]"]' => array('checked' => TRUE),
        ),
      ),
    );

    return $element;
  }

  /**
   * @param mixed $iefId
   */
  public function setIefId($iefId) {
    $this->iefId = $iefId;
  }

  /**
   * @return mixed
   */
  public function getIefId() {
    return $this->iefId;
  }

  function initializeIefController() {
    if (!isset($this->iefHandler)) {
      $this->iefHandler = inline_entity_form_get_controller($this->fieldDefinition);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $this->entityManager = \Drupal::entityManager();
    $settings = $this->getFieldSettings();

    $cardinality = $this->fieldDefinition->getFieldStorageDefinition()->getCardinality();
    $this->initializeIefController();

    if (!$this->iefHandler) {
      return $element;
    }

    // Get the entity type labels for the UI strings.
    $labels = $this->labels();

    // Build a parents array for this element's values in the form.
    $parents = array_merge($element['#field_parents'], array(
      $items->getName(),
      'form',
    ));

    // Assign a unique identifier to each IEF widget.
    // Since $parents can get quite long, sha1() ensures that every id has
    // a consistent and relatively short length while maintaining uniqueness.
    $this->setIefId(sha1(implode('-', $parents)));

    // Get the langcode of the parent entity.
    $parent_langcode = $items->getParent()->getValue()->language()->getId();

    // Determine the wrapper ID for the entire element.
    $wrapper = 'inline-entity-form-' . $this->getIefId();

    $element = array(
        '#type' => 'fieldset',
        '#tree' => TRUE,
        '#description' => NULL,
        '#prefix' => '<div id="' . $wrapper . '">',
        '#suffix' => '</div>',
        '#ief_id' => $this->getIefId(),
        '#ief_root' => TRUE,
      ) + $element;

    $element['#attached']['library'][] = 'inline_entity_form/widget';

    // Initialize the IEF array in form state.
    if (!$form_state->has(['inline_entity_form', $this->getIefId(), 'settings'])) {
      $form_state->set(['inline_entity_form', $this->getIefId(), 'settings'], $settings);
    }

    if (!$form_state->has(['inline_entity_form', $this->getIefId(), 'instance'])) {
      $form_state->set(['inline_entity_form', $this->getIefId(), 'instance'], $this->fieldDefinition);
    }

    if (!$form_state->has(['inline_entity_form', $this->getIefId(), 'form'])) {
      $form_state->set(['inline_entity_form', $this->getIefId(), 'form'], NULL);
    }

    if (!$form_state->has(['inline_entity_form', $this->getIefId(), 'array_parents'])) {
      $form_state->set(['inline_entity_form', $this->getIefId(), 'array_parents'], $parents);
    }

    $entities = $form_state->get(['inline_entity_form', $this->getIefId(), 'entities']);
    if (!isset($entities)) {
      // Load the entities from the $items array and store them in the form
      // state for further manipulation.
      $form_state->set(['inline_entity_form', $this->getIefId(), 'entities'], array());

      if (count($items)) {
        foreach ($items as $delta => $item) {
          if ($item->entity && is_object($item->entity)) {
            $form_state->set(['inline_entity_form', $this->getIefId(), 'entities', $delta], array(
              'entity' => $item->entity,
              '_weight' => $delta,
              'form' => NULL,
              'needs_save' => FALSE,
            ));
          }
        }
      }
    }

    // Build the "Multiple value" widget.
    $element['#element_validate'] = array('inline_entity_form_update_row_weights');
    // Add the required element marker & validation.
    if ($element['#required']) {
      $element['#title'] .= ' ' . _theme('form_required_marker', array('element' => $element));
      $element['#element_validate'][] = 'inline_entity_form_required_field';
    }

    $element['entities'] = array(
      '#tree' => TRUE,
      '#theme' => 'inline_entity_form_entity_table',
      '#entity_type' => $settings['target_type'],
    );

    // Get the fields that should be displayed in the table.
    $fields = $this->iefHandler->tableFields($settings['handler_settings']['target_bundles']);
    $context = array(
      'parent_entity_type' => $this->fieldDefinition->entity_type,
      'parent_bundle' => $this->fieldDefinition->bundle,
      'field_name' => $this->fieldDefinition->getName(),
      'entity_type' => $settings['target_type'],
      'allowed_bundles' => $settings['handler_settings']['target_bundles'],
    );
    \Drupal::moduleHandler()->alter('inline_entity_form_table_fields', $fields, $context);
    $element['entities']['#table_fields'] = $fields;

    $items_count = count($form_state->get(['inline_entity_form', $this->getIefId(), 'entities']));
    if ($items_count < 10) {
      $items_count = 10;
    }
    foreach ($form_state->get(['inline_entity_form', $this->getIefId(), 'entities']) as $key => $value) {
      if (!isset($value['entity'])) {
        continue;
      }

      // Data used by theme_inline_entity_form_entity_table().
      /** @var \Drupal\Core\Entity\EntityInterface $entity */
      $entity = $value['entity'];
      $element['entities'][$key]['#entity'] = $value['entity'];
      $element['entities'][$key]['#item'] = $items->offsetGet($key);
      $element['entities'][$key]['#needs_save'] = $value['needs_save'];

      // Handle row weights.
      $element['entities'][$key]['#weight'] = $value['_weight'];

      // First check to see if this entity should be displayed as a form.
      if (!empty($value['form'])) {
        $element['entities'][$key]['title'] = array();
        $element['entities'][$key]['delta'] = array(
          '#type' => 'value',
          '#value' => $value['_weight'],
        );
        $element['entities'][$key]['form'] = array(
          '#type' => 'container',
          '#attributes' => array('class' => array('ief-form', 'ief-form-row')),
          '#op' => $value['form'],
          // Used by Field API and controller methods to find the relevant
          // values in $form_state.
          '#parents' => array_merge($parents, array('entities', $key, 'form')),
          // Store the entity on the form, later modified in the controller.
          '#entity' => $entity,
          '#entity_type' => $settings['target_type'],
          // Pass the langcode of the parent entity,
          '#parent_language' => $parent_langcode,
          // Identifies the IEF widget to which the form belongs.
          '#ief_id' => $this->getIefId(),
          // Identifies the table row to which the form belongs.
          '#ief_row_delta' => $key,
          // Add the pre_render callback that powers the #fieldset form element key,
          // which moves the element to the specified fieldset without modifying its
          // position in $form_state['values'].
          '#pre_render' => ['inline_entity_form_pre_render_add_fieldset_markup'],
        );
        // Prepare data for the form callbacks.
        $form = & $element['entities'][$key]['form'];

        // Add the appropriate form.
        if ($value['form'] == 'edit') {
          $form += $this->iefHandler->entityForm($form, $form_state);
          $this->buildEntityFormActions($form);
        }
        elseif ($value['form'] == 'remove') {
          $this->buildRemoveForm($form);
        }
      }
      else {
        $row = &$element['entities'][$key];
        $row['title'] = array();
        $row['delta'] = array(
          '#type' => 'weight',
          '#delta' => $items_count,
          '#default_value' => $value['_weight'],
          '#attributes' => array('class' => array('ief-entity-delta')),
        );
        // Add an actions container with edit and delete buttons for the entity.
        $row['actions'] = array(
          '#type' => 'container',
          '#attributes' => array('class' => array('ief-entity-operations')),
        );

        // Make sure entity_access is not checked for unsaved entities.
        $entity_id = $entity->id();
        if (empty($entity_id) || $entity->access('update')) {
          $row['actions']['ief_entity_edit'] = array(
            '#type' => 'submit',
            '#value' => t('Edit'),
            '#name' => 'ief-' . $this->getIefId() . '-entity-edit-' . $key,
            '#limit_validation_errors' => array(),
            '#ajax' => array(
              'callback' => 'inline_entity_form_get_element',
              'wrapper' => $wrapper,
            ),
            '#submit' => array('inline_entity_form_open_row_form'),
            '#ief_row_delta' => $key,
            '#ief_row_form' => 'edit',
          );
        }

        // If 'allow_existing' is on, the default removal operation is unlink
        // and the access check for deleting happens inside the controller
        // removeForm() method.
        if (empty($entity_id) || $this->settings['allow_existing'] || $entity->access('delete')) {
          $row['actions']['ief_entity_remove'] = array(
            '#type' => 'submit',
            '#value' => t('Remove'),
            '#name' => 'ief-' . $this->getIefId() . '-entity-remove-' . $key,
            '#limit_validation_errors' => array(),
            '#ajax' => array(
              'callback' => 'inline_entity_form_get_element',
              'wrapper' => $wrapper,
            ),
            '#submit' => array('inline_entity_form_open_row_form'),
            '#ief_row_delta' => $key,
            '#ief_row_form' => 'remove',
          );
        }
      }
    }

    $entity_count = count($form_state->get(['inline_entity_form', $this->getIefId(), 'entities']));
    if ($cardinality > 1) {
      // Add a visual cue of cardinality count.
      $message = t('You have added @entities_count out of @cardinality_count allowed @label.', array(
        '@entities_count' => $entity_count,
        '@cardinality_count' => $cardinality,
        '@label' => $labels['plural'],
      ));
      $element['cardinality_count'] = array(
        '#markup' => '<div class="ief-cardinality-count">' . $message . '</div>',
      );
    }
    // Do not return the rest of the form if cardinality count has been reached.
    if ($cardinality > 0 && $entity_count == $cardinality) {
      return $element;
    }

    // Try to open the add form (if it's the only allowed action, the
    // field is required and empty, and there's only one allowed bundle).
    $entities = $form_state->get(['inline_entity_form', $this->getIefId(), 'entities']);
    if (empty($entities)) {
      if (count($settings['handler_settings']['target_bundles']) == 1 && $this->fieldDefinition->isRequired() && !$this->settings['allow_existing']) {
        $bundle = reset($settings['handler_settings']['target_bundles']);

        // The parent entity type and bundle must not be the same as the inline
        // entity type and bundle, to prevent recursion.
        if ($element['#entity_type'] != $settings['target_type'] || $element['#bundle'] != $bundle) {
          $form_state['inline_entity_form'][$this->getIefId()]['form'] = 'add';
          $form_state['inline_entity_form'][$this->getIefId()]['form settings'] = array(
            'bundle' => $bundle,
          );
        }
      }
    }

    // If no form is open, show buttons that open one.
    $inline_entity_form_form = $form_state->get(['inline_entity_form', $this->getIefId(), 'form']);
    if (empty($inline_entity_form_form)) {
      $element['actions'] = array(
        '#attributes' => array('class' => array('container-inline')),
        '#type' => 'container',
        '#weight' => 100,
      );

      // The user is allowed to create an entity of at least one bundle.
      if (count($settings['handler_settings']['target_bundles'])) {
        // Let the user select the bundle, if multiple are available.
        if (count($settings['handler_settings']['target_bundles']) > 1) {
          $bundles = array();
          foreach ($this->entityManager->getBundleInfo($settings['target_type']) as $bundle_name => $bundle_info) {
            if (in_array($bundle_name, $settings['handler_settings']['target_bundles'])) {
              $bundles[$bundle_name] = $bundle_info['label'];
            }
          }

          $element['actions']['bundle'] = array(
            '#type' => 'select',
            '#options' => $bundles,
          );
        }
        else {
          $element['actions']['bundle'] = array(
            '#type' => 'value',
            '#value' => reset($settings['handler_settings']['target_bundles']),
          );
        }

        $element['actions']['ief_add'] = array(
          '#type' => 'submit',
          '#value' => t('Add new @type_singular', array('@type_singular' => $labels['singular'])),
          '#name' => 'ief-' . $this->getIefId() . '-add',
          '#limit_validation_errors' => array(array_merge($parents, array('actions'))),
          '#ajax' => array(
            'callback' => 'inline_entity_form_get_element',
            'wrapper' => $wrapper,
          ),
          '#submit' => array('inline_entity_form_open_form'),
          '#ief_form' => 'add',
        );
      }

      if ($this->settings['allow_existing']) {
        $element['actions']['ief_add_existing'] = array(
          '#type' => 'submit',
          '#value' => t('Add existing @type_singular', array('@type_singular' => $labels['singular'])),
          '#name' => 'ief-' . $this->getIefId() . '-add-existing',
          '#limit_validation_errors' => array(array_merge($parents, array('actions'))),
          '#ajax' => array(
            'callback' => 'inline_entity_form_get_element',
            'wrapper' => $wrapper,
          ),
          '#submit' => array('inline_entity_form_open_form'),
          '#ief_form' => 'ief_add_existing',
        );
      }
    }
    else {
      // There's a form open, show it.
      $element['form'] = array(
        '#type' => 'fieldset',
        '#attributes' => array('class' => array('ief-form', 'ief-form-bottom')),
        // Identifies the IEF widget to which the form belongs.
        '#ief_id' => $this->getIefId(),
        // Used by Field API and controller methods to find the relevant
        // values in $form_state.
        '#parents' => array_merge($parents),
        // Pass the current entity type.
        '#entity_type' => $settings['target_type'],
        // Pass the langcode of the parent entity,
        '#parent_language' => $parent_langcode,
        // Add the pre_render callback that powers the #fieldset form element key,
        // which moves the element to the specified fieldset without modifying its
        // position in $form_state['values'].
        '#pre_render' => ['inline_entity_form_pre_render_add_fieldset_markup'],
    );

      if ($form_state->get(['inline_entity_form', $this->getIefId(), 'form']) == 'add') {
        $element['form']['#op'] = 'add';
        $element['form'] += $this->iefHandler->entityForm($element['form'], $form_state);
        $this->buildEntityFormActions($element['form']);

        // Hide the cancel button if the reference field is required but
        // contains no values. That way the user is forced to create an entity.
        if (!$this->settings['allow_existing'] && $this->fieldDefinition->isRequired()
          && empty($form_state->get('inline_entity_form')[$this->getIefId()]['entities'])
          && count($settings['handler_settings']['target_bundles']) == 1
        ) {
          $element['form']['actions']['ief_add_cancel']['#access'] = FALSE;
        }
      }
      elseif ($form_state->get('inline_entity_form')[$this->getIefId()]['form'] == 'ief_add_existing') {
        $element['form'] += inline_entity_form_reference_form($this->iefHandler, $element['form'], $form_state);
      }

      // No entities have been added. Remove the outer fieldset to reduce
      // visual noise caused by having two titles.
      if (empty($form_state->get('inline_entity_form')[$this->getIefId()]['entities'])) {
        $element['#type'] = 'container';
      }
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function extractFormValues(FieldItemListInterface $items, array $form, FormStateInterface $form_state) {
    $this->initializeIefController();
    $field_name = $this->fieldDefinition->getName();
    if (isset($form['#ief_parents'])) {
      $parents = $form['#ief_parents'];
    }
    else {
      $parents = array($field_name, 'form');
    }
    $ief_id = sha1(implode('-', $parents));
    $this->setIefId($ief_id);

    $inline_entity_form_state = $form_state->get('inline_entity_form');
    if (isset($inline_entity_form_state[$this->getIefId()])) {
      $values = $inline_entity_form_state[$this->getIefId()];
      $key_exists = TRUE;
    }
    else {
      $values = [];
      $key_exists = FALSE;
    }

    if ($key_exists) {
      $values = $values['entities'];

      // Account for drag-and-drop reordering if needed.
      if (!$this->handlesMultipleValues()) {
        // Remove the 'value' of the 'add more' button.
        unset($values['add_more']);

        // The original delta, before drag-and-drop reordering, is needed to
        // route errors to the corect form element.
        foreach ($values as $delta => &$value) {
          $value['_original_delta'] = $delta;
        }

        usort($values, function ($a, $b) {
          return SortArray::sortByKeyInt($a, $b, '_weight');
        });
      }

      foreach ($values as $delta => &$item) {
        /** @var \Drupal\Core\Entity\EntityInterface $entity */
        $entity = $item['entity'];
        if (!empty($item['needs_save'])) {
          $entity->save();
        }
        if (!empty($item['delete'])) {
          $entity->delete();
          unset($items[$delta]);
        }
      }

      // Let the widget massage the submitted values.
      $values = $this->massageFormValues($values, $form, $form_state);

      // Assign the values and remove the empty ones.
      $items->setValue($values);
      $items->filterEmptyItems();

      // Put delta mapping in $form_state, so that flagErrors() can use it.
      $field_state = WidgetBase::getWidgetState($form['#parents'], $field_name, $form_state);
      foreach ($items as $delta => $item) {
        $field_state['original_deltas'][$delta] = isset($item->_original_delta) ? $item->_original_delta : $delta;
        unset($item->_original_delta, $item->_weight);
      }

      WidgetBase::setWidgetState($form['#parents'], $field_name, $form_state, $field_state);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $items = array();

    // Convert form values to actual entity reference values.
    foreach ($values as $value) {
      $item = $value;
      if (isset($item['entity'])) {
        $item['target_id'] = $item['entity']->id();
        $items[] = $item;
      }
    }

    // Sort items by _weight.
    usort($items, function ($a, $b) {
      return SortArray::sortByKeyInt($a, $b, '_weight');
    });

    return $items;
  }

  /**
   * Adds actions to the inline entity form.
   *
   * @param array $form
   *   Form array structure.
   */
  protected function buildEntityFormActions(&$form) {
    $labels = $this->labels();

    // Build a delta suffix that's appended to button #name keys for uniqueness.
    $delta = $form['#ief_id'];
    if ($form['#op'] == 'add') {
      $save_label = t('Create @type_singular', ['@type_singular' => $labels['singular']]);
    }
    else {
      $delta .= '-' . $form['#ief_row_delta'];
      $save_label = t('Update @type_singular', ['@type_singular' => $labels['singular']]);
    }

    // Add action submit elements.
    $form['actions'] = [
      '#type' => 'container',
      '#weight' => 100,
    ];
    $form['actions']['ief_' . $form['#op'] . '_save'] = [
      '#type' => 'submit',
      '#value' => $save_label,
      '#name' => 'ief-' . $form['#op'] . '-submit-' . $delta,
      '#limit_validation_errors' => [$form['#parents']],
      '#attributes' => ['class' => ['ief-entity-submit']],
      '#ajax' => [
        'callback' => 'inline_entity_form_get_element',
        'wrapper' => 'inline-entity-form-' . $form['#ief_id'],
      ],
    ];
    $form['actions']['ief_' . $form['#op'] . '_cancel'] = [
      '#type' => 'submit',
      '#value' => t('Cancel'),
      '#name' => 'ief-' . $form['#op'] . '-cancel-' . $delta,
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => 'inline_entity_form_get_element',
        'wrapper' => 'inline-entity-form-' . $form['#ief_id'],
      ],
    ];

    // Add submit handlers depending on operation.
    if ($form['#op'] == 'add') {
      $form['actions']['ief_add_save']['#submit'] = [
        'inline_entity_form_trigger_submit',
        'inline_entity_form_close_child_forms',
        'inline_entity_form_close_form',
      ];
      $form['actions']['ief_add_cancel']['#submit'] = [
        'inline_entity_form_close_child_forms',
        'inline_entity_form_close_form',
        'inline_entity_form_cleanup_form_state',
      ];
    }
    else {
      $form['actions']['ief_edit_save']['#ief_row_delta'] = $form['#ief_row_delta'];
      $form['actions']['ief_edit_cancel']['#ief_row_delta'] = $form['#ief_row_delta'];

      $form['actions']['ief_edit_save']['#submit'] = [
        'inline_entity_form_trigger_submit',
        'inline_entity_form_close_child_forms',
        'inline_entity_form_close_row_form',
      ];
      $form['actions']['ief_edit_cancel']['#submit'] = [
        'inline_entity_form_close_child_forms',
        'inline_entity_form_close_row_form',
        'inline_entity_form_cleanup_row_form_state',
      ];
    }
  }

  /**
   * Builds remove form.
   *
   * @param array $form
   *   Form array structure.
   */
  protected function buildRemoveForm(&$form) {
    /** @var \Drupal\Core\Entity\EntityInterface $entity */
    $entity = $form['#entity'];
    $entity_id = $entity->id();
    $entity_label = $entity->label();
    $labels = $this->labels();

    if ($entity_label) {
      $message = t('Are you sure you want to remove %label?', ['%label' => $entity_label]);
    }
    else {
      $message = t('Are you sure you want to remove this %entity_type?', ['%entity_type' => $labels['singular']]);
    }

    $form['message'] = [
      '#theme_wrappers' => ['container'],
      '#markup' => $message,
    ];

    if (!empty($entity_id) && $this->settings['allow_existing'] && $entity->access('delete')) {
      $form['delete'] = [
        '#type' => 'checkbox',
        '#title' => t('Delete this @type_singular from the system.', array('@type_singular' => $labels['singular'])),
      ];
    }

    // Build a deta suffix that's appended to button #name keys for uniqueness.
    $delta = $form['#ief_id'] . '-' . $form['#ief_row_delta'];

    // Add actions to the form.
    $form['actions'] = [
      '#type' => 'container',
      '#weight' => 100,
    ];
    $form['actions']['ief_remove_confirm'] = [
      '#type' => 'submit',
      '#value' => t('Remove'),
      '#name' => 'ief-remove-confirm-' . $delta,
      '#limit_validation_errors' => [$form['#parents']],
      '#ajax' => [
        'callback' => 'inline_entity_form_get_element',
        'wrapper' => 'inline-entity-form-' . $form['#ief_id'],
      ],
      '#submit' => [[get_class($this), 'submitConfirmRemove']],
      '#ief_row_delta' => $form['#ief_row_delta'],
    ];
    $form['actions']['ief_remove_cancel'] = [
      '#type' => 'submit',
      '#value' => t('Cancel'),
      '#name' => 'ief-remove-cancel-' . $delta,
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => 'inline_entity_form_get_element',
        'wrapper' => 'inline-entity-form-' . $form['#ief_id'],
      ],
      '#submit' => [[get_class($this), 'submitCloseRow']],
      '#ief_row_delta' => $form['#ief_row_delta'],
    ];
  }

  /**
   * Button #submit callback: Closes a row form in the IEF widget.
   *
   * @param $form
   *   The complete parent form.
   * @param $form_state
   *   The form state of the parent form.
   *
   * @see inline_entity_form_open_row_form().
   */
  public static function submitCloseRow($form, FormStateInterface $form_state) {
    $element = inline_entity_form_get_element($form, $form_state);
    $ief_id = $element['#ief_id'];
    $delta = $form_state->getTriggeringElement()['#ief_row_delta'];

    $form_state->setRebuild();
    $form_state->set(['inline_entity_form', $ief_id, 'entities', $delta, 'form'], NULL);
  }


  /**
   * Remove form submit callback.
   *
   * The row is identified by #ief_row_delta stored on the triggering
   * element.
   * This isn't an #element_validate callback to avoid processing the
   * remove form when the main form is submitted.
   *
   * @param $form
   *   The complete parent form.
   * @param $form_state
   *   The form state of the parent form.
   */
  public static function submitConfirmRemove($form, FormStateInterface $form_state) {
    $element = inline_entity_form_get_element($form, $form_state);
    $delta = $form_state->getTriggeringElement()['#ief_row_delta'];

    /** @var \Drupal\Core\Field\FieldDefinitionInterface $instance */
    $instance = $form_state->get(['inline_entity_form', $element['#ief_id'], 'instance']);

    /** @var \Drupal\Core\Entity\EntityInterface $entity */
    $entity = $element['entities'][$delta]['form']['#entity'];
    $entity_id = $entity->id();

    $widget = \Drupal::entityManager()
      ->getStorage('entity_form_display')
      ->load($entity->getEntityTypeId() . '.' . $entity->bundle() . '.default')
      ->getComponent($instance->getName());

    $form_values = NestedArray::getValue($form_state->getValues(), $element['entities'][$delta]['form']['#parents']);
    $form_state->setRebuild();

    // This entity hasn't been saved yet, we can just unlink it.
    if (empty($entity_id) || ($widget['settings']['allow_existing'] && empty($form_values['delete']))) {
      $form_state->set(['inline_entity_form', $element['#ief_id'], 'entities', $delta], NULL);
    }
    else {
      $delete = $form_state->get(['inline_entity_form', $element['#ief_id'], 'delete']);
      $delete['delete'][] = $entity_id;
      $form_state->set(['inline_entity_form', $element['#ief_id'], 'delete'], $delete);
      $form_state->set(['inline_entity_form', $element['#ief_id'], 'entities', $delta], NULL);
    }
  }

  /**
   * Returns an array of entity type labels (singular, plural) fit to be
   * included in the UI text.
   *
   * @TODO - This could be shared with InlineEntityFormSingle. Let's see if we
   * base one of the widgets out of the other one. We could use a trait if that
   * won't be possible.
   *
   * @return array
   *   Array containing two values:
   *     - singular: label for singular form,
   *     - plural: label for plural form.
   */
  protected function labels() {
    // The admin has specified the exact labels that should be used.
    if ($this->settings['override_labels']) {
      return [
        'singular' => $this->settings['label_singular'],
        'plural' => $this->settings['label_plural'],
      ];
    }
    else {
      $this->initializeIefController();
      return $this->iefHandler->labels();
    }
  }

}

