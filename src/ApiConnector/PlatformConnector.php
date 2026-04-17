<?php

namespace Drupal\ucb_tma_interface\ApiConnector;

use Drupal\Core\Cache\CacheBackendInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

/**
 * Minimal Platform API v7 connector (JWT Bearer).
 *
 * Used for seeding and location lookups.
 */
final class PlatformConnector {

  private const CACHE_CID_TOKEN = 'ucb_tma_interface:platform_jwt';

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly CacheBackendInterface $cache,
  ) {}

  /**
   * @return array|\Psr\Http\Message\ResponseInterface
   */
  public function get(string $relativePathWithQuery): array|ResponseInterface {
    return $this->request('GET', $relativePathWithQuery, NULL);
  }

  /**
   * @param array<string, mixed>|list<mixed> $json
   * @return array|\Psr\Http\Message\ResponseInterface
   */
  public function postJson(string $relativePathWithQuery, array $json): array|ResponseInterface {
    return $this->request('POST', $relativePathWithQuery, $json);
  }

  /**
   * @param array<string, mixed>|list<mixed>|null $json
   * @return array|\Psr\Http\Message\ResponseInterface
   */
  private function request(string $method, string $relativePathWithQuery, ?array $json): array|ResponseInterface {
    $config = \Drupal::config('ucb_tma_interface.settings');
    $base = rtrim((string) $config->get('base_url'), '/');
    if ($base === '') {
      return $this->errorMarkup('Missing base_url.');
    }

    $token = $this->getBearerToken();
    if ($token === NULL) {
      return $this->errorMarkup('Unable to authenticate to Platform API.');
    }

    $path = str_starts_with($relativePathWithQuery, '/') ? $relativePathWithQuery : '/' . $relativePathWithQuery;
    $url = $base . $path;

    $options = [
      'headers' => [
        'Accept' => 'application/json',
        'Authorization' => 'Bearer ' . $token,
      ],
      'http_errors' => FALSE,
      // Avoid hanging forever when the server or network stalls (default is no timeout).
      'connect_timeout' => 15,
      'timeout' => 180,
    ];
    if ($json !== NULL) {
      $options['headers']['Content-Type'] = 'application/json';
      $options['body'] = json_encode($json);
    }

    try {
      return $this->httpClient->request($method, $url, $options);
    }
    catch (RequestException $e) {
      \Drupal::logger('ucb_tma_interface')->error('Platform HTTP error: @msg', ['@msg' => $e->getMessage()]);
      return $this->errorMarkup('Platform HTTP request failed.');
    }
  }

  private function getBearerToken(): ?string {
    if ($cache = $this->cache->get(self::CACHE_CID_TOKEN)) {
      if (is_string($cache->data) && $cache->data !== '') {
        return $cache->data;
      }
    }

    $config = \Drupal::config('ucb_tma_interface.settings');
    $base = rtrim((string) $config->get('base_url'), '/');
    $user = (string) $config->get('authentication_user');
    $pass = (string) $config->get('authentication_pass');
    $client = (string) $config->get('authentication_client_name');
    if ($base === '' || $user === '' || $pass === '' || $client === '') {
      \Drupal::logger('ucb_tma_interface')->error('Platform auth config missing (base/user/pass/client).');
      return NULL;
    }

    $url = $base . '/v2/Users/Authenticate';
    $payload = [
      'userName' => $user,
      'password' => $pass,
      'clientName' => $client,
    ];

    try {
      $resp = $this->httpClient->request('POST', $url, [
        'headers' => [
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
        ],
        'body' => json_encode($payload),
        'http_errors' => FALSE,
        'connect_timeout' => 15,
        'timeout' => 120,
      ]);
    }
    catch (RequestException $e) {
      \Drupal::logger('ucb_tma_interface')->error('Platform authenticate failed: @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }

    $raw = (string) $resp->getBody();
    $data = json_decode($raw, TRUE);
    if (!is_array($data)) {
      \Drupal::logger('ucb_tma_interface')->error('Platform authenticate returned non-JSON.');
      return NULL;
    }
    $token = $data['Token'] ?? $data['token'] ?? NULL;
    if (!is_string($token) || $token === '') {
      \Drupal::logger('ucb_tma_interface')->error('Platform authenticate missing Token. Keys: @keys', [
        '@keys' => implode(', ', array_keys($data)),
      ]);
      return NULL;
    }

    // Cache for a short period; Platform returns ExpiredTime but we keep it simple.
    $this->cache->set(self::CACHE_CID_TOKEN, $token, time() + 300);
    return $token;
  }

  /**
   * @return array<string, mixed>
   */
  private function errorMarkup(string $message): array {
    return [
      '#title' => 'TMA Platform API error',
      '#markup' => $message,
    ];
  }

}

