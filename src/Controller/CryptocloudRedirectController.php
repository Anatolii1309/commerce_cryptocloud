<?php

namespace Drupal\commerce_cryptocloud\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

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
      $data = $this->data_json($request->getContent());
    }
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
      if (!$this->check($token, $configuration['secret_key'])) {
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

    return new JsonResponse(['status' => 'completed'], 200);
  }

  /**
   * Decode json.
   *
   * @param string $json
   *   The josn string.
   *
   * @return array
   *   Json decode array.
   */
  public function data_json(string $json) {
    $json = substr($json, 0, -1);
    $json = substr($json, 1);
    $data = explode(', ', $json);
    if (empty($data)) {
      return [];
    }
    $result = [];
    foreach ($data as $values) {
      $massive = explode(': ', $values);
      if (empty($massive[1])) {
        $massive = explode(':', $values);
      }
      $name = str_replace('"', '', $massive[0]);
      $first = mb_substr($massive[1], 0, 1);
      $last = mb_substr($massive[1], -1);
      $value = $first == $last && ($last == '"')
        ? str_replace('"', '', $massive[1]) : $massive[1];
      $result[$name] = $value;
    }

    return $result;
  }

  /**
   * Checking the token for request forgery.
   *
   * @param string $jwtToken
   *   The token.
   * @param string $secretKey
   *   The secret key.
   *
   * @return bool
   *   Returns true if the keys match or false otherwise.
   */
  private function check(string $jwtToken, string $secretKey): bool {
    $jwtToken = substr($jwtToken,1);
    $jwtToken = str_replace('"', "", $jwtToken);
    $jwtParts = explode('.', $jwtToken);
    if (count($jwtParts) !== 3) {
      return FALSE;
    }
    $payload = base64_decode($jwtParts[1]);
    $signature = $jwtParts[2];
    $payloadData = json_decode($payload);
    if (!$payloadData || !isset($payloadData->exp) || $payloadData->exp < time()) {
      return FALSE;
    }
    $data = $jwtParts[0] . '.' . $jwtParts[1];
    $hash = hash_hmac('sha256', $data, $secretKey, true);
    $base64 = base64_encode($hash);
    $urlSafe = str_replace(['+', '/'], ['-', '_'], $base64);
    $encodedHash = rtrim($urlSafe, '=');

    return hash_equals($encodedHash, $signature);
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
