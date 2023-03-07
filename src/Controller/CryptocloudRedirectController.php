<?php

namespace Drupal\commerce_cryptocloud\Controller;

use Drupal\commerce_order\Entity\Order;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\Config;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use GuzzleHttp\Exception\BadResponseException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Class CryptocloudRedirectController.
 */
class CryptocloudRedirectController implements ContainerInjectionInterface {

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $currentRequest;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new CryptocloudRedirectController object.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   */
  public function __construct(RequestStack $request_stack, EntityTypeManagerInterface $entity_type_manager) {
    $this->currentRequest = $request_stack->getCurrentRequest();
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Callback method which accepts POST.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The Request.
   *
   * @return \Drupal\Core\Routing\TrustedRedirectResponse|\Symfony\Component\HttpFoundation\JsonResponse
   *   Return trusted redirect.
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function index(Request $request) {
    $data = Json::decode($request->getContent());
    if (empty($data)) {
      return new JsonResponse(['message' => 'Bad request'], 500);
    }
    $token = $data['token'];
    $invoice_id = $data['invoice_id'];
    $order_id = $data['order_id'];
    $status = $data['status'];

    if (empty($invoice_id) || empty($order_id)
      || empty($status) || ($status != 'success')) {
      return new JsonResponse(['message' => 'Bad request'], 500);
    }
    $gateway = $this->entityTypeManager->getStorage('commerce_payment_gateway')
      ->loadByProperties([
        'plugin' => 'cryptocloud_offsite_redirect',
      ]);
    /** @var \Drupal\commerce_payment\Entity\PaymentGateway $gateway */
    $gateway = reset($gateway);
    if (empty($gateway)) {
      return new JsonResponse(['message' => 'Bad request'], 500);
    }
    $configuration = $gateway->getPlugin()->getConfiguration();

    if (!empty($configuration['secret_key'])) {
      if (empty($token)) {
        return new JsonResponse(['message' => 'Bad request'], 500);
      }
      if (!$this->check($token, $invoice_id, $configuration['secret_key'])) {
        return new JsonResponse(['message' => 'Bad request'], 500);
      }
    }
    /** @var \Drupal\commerce_order\Entity\Order $order */
    $order = $this->entityTypeManager->getStorage('commerce_order')
      ->load($order_id);
    if (empty($order)) {
      return new JsonResponse(['message' => 'Bad request'], 500);
    }

    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payment = $payment_storage->create([
      'state' => 'completed',
      'amount' => $order->getBalance(),
      'payment_gateway' => $gateway->id(),
      'order_id' => $order->id(),
      'remote_id' => $invoice_id,
    ]);
    $payment->save();

    return new TrustedRedirectResponse('/', 302);
  }

  /**
   * Checking the token for request forgery.
   *
   * @param string $token
   *   The token.
   * @param string $secret_key
   *   The secret key.
   *
   * @return bool
   *   Returns true if the keys match or false otherwise.
   */
  private function check(string $token, string $secret_key): bool {
    $token = explode('.', $token); // explode token based on JWT breaks
    if (!isset($token[1]) && !isset($token[2])) {
      return false; // fails if the header and payload is not set
    }
    $headers = base64_decode($token[0]); // decode header, create variable
    $payload = base64_decode($token[1]); // decode payload, create variable
    $clientSignature = $token[2]; // create variable for signature

    if (!json_decode($payload)) {
      return false; // fails if payload does not decode
    }

    if ((json_decode($payload)->exp - time()) < 0) {
      return false; // fails if expiration is greater than 0, setup for 1 minute
    }

    $base64_header = $this->base64url_encode($headers);
    $base64_payload = $this->base64url_encode($payload);

    $signature = hash_hmac('SHA256', $base64_header . "." . $base64_payload, $secret_key, true);
    $base64_signature = $this->base64url_encode($signature);

    return ($base64_signature === $clientSignature);
  }

  /**
   * Changing a string.
   *
   * @param $str
   *   The replace string.
   *
   * @return string
   *   Upgrade string.
   */
  private function base64url_encode($str): string {
    return rtrim(strtr(base64_encode($str), '+/', '-_'), '=');
  }

}
