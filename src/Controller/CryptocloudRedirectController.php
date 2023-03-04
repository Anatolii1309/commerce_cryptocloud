<?php

namespace Drupal\commerce_cryptocloud\Controller;

use Drupal\commerce_order\Entity\Order;
use Drupal\Core\Config\Config;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use GuzzleHttp\Exception\BadResponseException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
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
   * @return \Drupal\Core\Routing\TrustedRedirectResponse|\Symfony\Component\HttpFoundation\JsonResponse
   *   Return trusted redirect.
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function index() {
    $token = $this->currentRequest->request->get('token');
    $invoice_id = $this->currentRequest->request->get('invoice_id');
    $order_id = $this->currentRequest->request->get('order_id');
    $status = $this->currentRequest->request->get('status');

    if (empty($token) || empty($invoice_id) || empty($order_id)
      || empty($status) || ($status == 'success')) {
      return new JsonResponse(['message' => 'Bad request'], 500);
    }
    /** @var \Drupal\commerce_order\Entity\Order $order */
    $order = $this->entityTypeManager->getStorage('commerce_order')
      ->load($order_id);
    if (empty($order)) {
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
    if (empty($configuration['secret_key'])) {
      return new JsonResponse(['message' => 'Bad request'], 500);
    }
    if (!$this->check($token, $invoice_id, $configuration['secret_key'])) {
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
   * @param string $invoice_id
   *   The invoice ID.
   * @param string $secret_key
   *   The secret key.
   *
   * @return bool
   *   Returns true if the keys match or false otherwise.
   */
  private function check(string $token, string $invoice_id, string $secret_key): bool {
    $headers = [
      'alg'=>'HS256',
      'typ'=>'JWT',
    ];
    $payload = [
      'id' => $invoice_id,
      'exp' => (time() + 300),
    ];

    $jwt = $this->generate_jwt($headers, $payload, $secret_key);

    return $token == $jwt;
  }

  /**
   * Getting JWT token.
   *
   * @param array $headers
   *   The header.
   * @param array $payload
   *   The Payload.
   * @param string $secret
   *   The secret key.
   *
   * @return string
   *   Return token.
   */
  private function generate_jwt(array $headers, array $payload, string $secret): string {
    $headers_encoded = $this->base64url_encode(json_encode($headers));

    $payload_encoded = $this->base64url_encode(json_encode($payload));

    $signature = hash_hmac('SHA256', "$headers_encoded.$payload_encoded", $secret, TRUE);
    $signature_encoded = $this->base64url_encode($signature);

    $jwt = "$headers_encoded.$payload_encoded.$signature_encoded";

    return $jwt;
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
