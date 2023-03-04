<?php

namespace Drupal\commerce_cryptocloud\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the Off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "cryptocloud_offsite_redirect",
 *   label = "Cryptocloud (Off-site redirect)",
 *   display_label = "Cryptocloud",
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_cryptocloud\PluginForm\OffsiteRedirect\CryptocloudOffsiteForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "maestro", "mastercard", "visa",
 *   },
 * )
 */
class CryptocloudOffsiteRedirect extends OffsitePaymentGatewayBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'api_key' => '',
      'shop_id' => '',
      'secret_key' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API key'),
      '#default_value' => $this->configuration['api_key'],
      '#required' => TRUE,
      '#description' => $this->t('You can get the @key in your account.',
        ['@key => <a href="https://app.cryptocloud.plus/?ref=0GQ2JDPCLT">API key</a>']),
    ];

    $form['shop_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Shop ID'),
      '#default_value' => $this->configuration['shop_id'],
      '#required' => TRUE,
    ];

    $form['secret_key'] = [
      '#type' => 'password',
      '#title' => $this->t('Secret key'),
      '#description' => $this->t('The secret key allows you to verify the
        authenticity of the source of the response and protects you from forging a response from the service..'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['api_key'] = $values['api_key'];
      $this->configuration['shop_id'] = $values['shop_id'];
      if (!empty($values['secret_key'])) {
        $this->configuration['secret_key'] = $values['secret_key'];
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payment = $payment_storage->create([
      'state' => 'draft',
      'amount' => $order->getBalance(),
      'payment_gateway' => $this->parentEntity->id(),
      'order_id' => $order->id(),
      'remote_id' => $request->request->get('invoice_id'),
    ]);
    $payment->save();
  }

}
