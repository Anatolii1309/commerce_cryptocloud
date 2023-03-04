<?php

namespace Drupal\commerce_cryptocloud\PluginForm\OffsiteRedirect;

use Drupal\commerce\Response\NeedsRedirectException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Component\Serialization\Json;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class CryptocloudOffsiteForm extends BasePaymentOffsiteForm implements ContainerInjectionInterface {

  /**
   * List of possible currencies on Cryptocloud.
   */
  const LIST_CURRENCIES = [
    'USD',
    'RUB',
    'EUR',
    'GBP',
  ];

  /**
   * The HTTP client to fetch the feed data with.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a new CryptocloudOffsiteForm.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The Guzzle HTTP client.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(ClientInterface $http_client, LoggerInterface $logger) {
    $this->httpClient = $http_client;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('http_client'),
      $container->get('logger.channel.commerce_payment')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $config = $payment_gateway_plugin->getConfiguration();
    /** @var \Drupal\commerce_price\Price $price */
    $price = $payment->getAmount();
    if (!in_array($price->getCurrencyCode(), self::LIST_CURRENCIES)) {
      $this->logger->error(
        'This currency is not listed: %list',
        ['%list' => implode(', ', self::LIST_CURRENCIES)]);
      return $form;
    }
    /** @var \Drupal\commerce_order\Entity\Order $order */
    $order = $payment->getOrder();
    try {
      $response = $this->httpClient->request(
        'POST',
        'https://api.cryptocloud.plus/v1/invoice/create',
        [
          'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Token ' . $config['api_key'],
          ],
          'json' => [
            'shop_id' => $config['shop_id'],
            'amount' => $price->getNumber(),
            'order_id' => $order->id(),
            'currency' => $price->getCurrencyCode(),
            'email' => $order->getEmail(),
          ],
        ]
      );
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Unable to make a request. Code: %code, error: %message',
        ['%code' => $e->getCode(), '%message' => $e->getMessage()]);

      return $form;
    }

    $result = Json::decode((string) $response->getBody());

    if ($result['status'] == 'success') {
      $redirect_url = Url::fromUri($result['pay_url'], ['absolute' => TRUE])->toString();
      throw new NeedsRedirectException($redirect_url);
    }

    return $form;
  }

}
