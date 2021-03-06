<?php

namespace Drupal\invoice_entity;

use Drupal\e_invoice_cr\Communication;
use Drupal\invoice_entity\Entity\InvoiceEntity;
use Drupal\invoice_entity\Entity\InvoiceEntityInterface;
use Drupal\invoice_email\InvoiceEmailEvent;

/**
 * Class InvoiceService.
 */
class InvoiceService implements InvoiceServiceInterface {

  protected static $invoiceNumber;
  protected static $secureCode;

  /**
   * Constructs a new InvoiceService object.
   */
  public function __construct() {
    self::$invoiceNumber = $this->getInvoiceVariable('invoice_number');
    // It gets a random number.
    self::$secureCode = str_pad(intval(rand(1, 99999999)), 8, '0', STR_PAD_LEFT);
    if (is_null(self::$invoiceNumber)) {
      self::$invoiceNumber = '0000000001';
      $this->updateValues();
    }
  }

  /**
   * Call the validateDocument from Communication and return its result.
   *
   * @param string $key
   *   Key to eval.
   *
   * @return array|null|string
   *   Return the response from the api.
   */
  private function responseForKey($key) {
    $con = new Communication();
    return $con->validateDocument($key);
  }

  /**
   * Increase the current values by one.
   */
  public function increaseValues() {
    self::$invoiceNumber = str_pad(intval(self::$invoiceNumber) + 1, 10, '0', STR_PAD_LEFT);
    self::$secureCode = str_pad(intval(rand(1, 99999999)), 8, '0', STR_PAD_LEFT);
  }

  /**
   * Decrease the current values by one.
   */
  public function decreaseValues() {
    self::$invoiceNumber = str_pad(intval(self::$invoiceNumber) - 1, 10, '0', STR_PAD_LEFT);
    self::$secureCode = str_pad(intval(rand(1, 99999999)), 8, '0', STR_PAD_LEFT);
  }

  /**
   * Update the configuration values.
   */
  public function updateValues() {
    $this->setInvoiceVariable('invoice_number', self::$invoiceNumber);
  }

  /**
   * {@inheritdoc}
   */
  public function checkInvoiceKey($key) {
    $result = $this->responseForKey($key);
    if (is_null($result)) {
      return FALSE;
    }
    else {
      if ($result[2] != 'aceptado') {
        $messages = explode("\n-", $result[3]->DetalleMensaje);
        $messages = array_filter($messages, function ($val) {
          $code = substr($val, 0, 2);
          return $code == '29' || $code == '99';
        });

        return !empty($messages);
      }
      return TRUE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateInvoiceEntity(InvoiceEntity $entity) {
    $key = $entity->get('field_numeric_key')->value;
    $result = $this->responseForKey($key);
    $state = NULL;
    if (!is_null($result)) {
      $state = $result[2] === 'rechazado' ? 'rejected' : 'published';
      $entity->set('moderation_state', $state);
      $entity->save();
      if ($state === 'published') {

        // Load the Symfony event dispatcher object through services.
        $dispatcher = \Drupal::service('event_dispatcher');
        // Creating our event class object.
        $eid = "valid-" . $entity->id();
        $event = new InvoiceEmailEvent($eid, $entity->id());
        // Dispatching the event through the ‘dispatch’  method,
        // Passing event name and event object ‘$event’ as parameters.
        $dispatcher->dispatch(InvoiceEmailEvent::SUBMIT, $event);
      }
    }

    return [
      'state' => $state,
      'response' => $result,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function generateInvoiceKey($type) {
    // Get date information.
    $day = date("d");
    $mouth = date("m");
    $year = date("y");
    // The id user.
    $settings = \Drupal::config('e_invoice_cr.settings');
    $id_user = $settings->get('id');
    $id_user = str_pad($id_user, 12, '0', STR_PAD_LEFT);
    if (is_null($id_user)) {
      return NULL;
    }
    else {
      // Join the key.
      $key = '506' . $day . $mouth . $year . $id_user . $this->generateConsecutive($type) . '1' . self::$secureCode;
      return $key;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function generateConsecutive($type) {
    $document_code = isset(InvoiceEntityInterface::DOCUMENTATIONINFO[$type]) ?
      InvoiceEntityInterface::DOCUMENTATIONINFO[$type]['code'] : '01';

    return '00100001' . $document_code . self::$invoiceNumber;
  }

  /**
   * {@inheritdoc}
   */
  public function getUniqueInvoiceKey($type = 'FE') {
    $current_key = $this->generateInvoiceKey($type);

    if ($current_key != NULL) {
      // Check if the generated key is already use it.
      if ($this->checkInvoiceKey($current_key)) {
        // If is already in use. Increase values and try again.
        $this->increaseValues();
        return $this->getUniqueInvoiceKey($type);
      }
      else {
        return $current_key;
      }
    }

    return $current_key;
  }

  /**
   * {@inheritdoc}
   */
  public static function setInvoiceVariable($variable_name, $value) {
    $config = \Drupal::service('config.factory')->getEditable('invoice_entity.settings');
    $config->set($variable_name, $value)->save();
  }

  /**
   * {@inheritdoc}
   */
  public function getInvoiceVariable($variable_name) {
    $config = \Drupal::config('invoice_entity.settings');
    $value = $config->get($variable_name);
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function checkSettingsData() {
    $settings = \Drupal::config('e_invoice_cr.settings');
    $neededFields = [
      'environment',
      'username',
      'password',
      'id_type',
      'id',
      'name',
      'commercial_name',
      'phone',
      'email',
      'postal_code',
      'address',
      'p12_cert',
      'cert_password',
    ];
    foreach ($neededFields as $field) {
      $value = $settings->get($field);
      if (is_null($value) || empty($value)) {
        return FALSE;
      }
    }
    return TRUE;
  }

}
