<?php

namespace Drupal\pinecone_api\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Drupal\node\NodeInterface;

/**
 * Service for interacting with the Python FastAPI Pinecone service.
 */
class PineconeApiClient {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a PineconeApiClient object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(ClientInterface $http_client, ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('pinecone_api');
  }

  /**
   * Gets the FastAPI base URL from configuration.
   *
   * @return string
   *   The FastAPI base URL.
   */
  protected function getBaseUrl(): string {
    $config = $this->configFactory->get('pinecone_api.settings');
    return $config->get('fastapi_base_url') ?: 'http://localhost:8000';
  }

  /**
   * Gets the HTTP timeout from configuration.
   *
   * @return int
   *   The HTTP timeout in seconds.
   */
  protected function getTimeout(): int {
    $config = $this->configFactory->get('pinecone_api.settings');
    return $config->get('timeout') ?: 30;
  }

  /**
   * Converts a Drupal node to the format expected by the Python API.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The Drupal node.
   *
   * @return array
   *   The node data in the format expected by the Python API.
   */
  protected function formatNodeForApi(NodeInterface $node): array {
    $body_value = '';
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $body_value = $node->get('body')->value;
    }

    return [
      'id' => $node->uuid(),
      'type' => 'node--' . $node->bundle(),
      'attributes' => [
        'title' => $node->getTitle(),
        'body' => [
          'value' => $body_value,
          'format' => $node->hasField('body') && !$node->get('body')->isEmpty() ? $node->get('body')->format : 'basic_html',
        ],
        'created' => date('c', $node->getCreatedTime()),
        'changed' => date('c', $node->getChangedTime()),
        'status' => $node->isPublished(),
      ],
    ];
  }

  /**
   * Sends multiple nodes to the Python FastAPI service.
   *
   * @param array $nodes
   *   Array of Drupal node objects.
   *
   * @return array|null
   *   The API response data or NULL on failure.
   */
  public function storeNodes(array $nodes): ?array {
    if (empty($nodes)) {
      return ['message' => 'No nodes provided', 'count' => 0];
    }

    $formatted_nodes = [];
    foreach ($nodes as $node) {
      if ($node instanceof NodeInterface) {
        $formatted_nodes[] = $this->formatNodeForApi($node);
      }
    }

    if (empty($formatted_nodes)) {
      return ['message' => 'No valid nodes to process', 'count' => 0];
    }

    try {
      $response = $this->httpClient->post($this->getBaseUrl() . '/store-nodes', [
        'json' => $formatted_nodes,
        'timeout' => $this->getTimeout(),
        'headers' => [
          'Content-Type' => 'application/json',
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      
      $this->logger->info('Successfully stored @count nodes in Pinecone', [
        '@count' => count($formatted_nodes),
      ]);

      return $data;
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to store nodes in Pinecone: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Searches content using the Python FastAPI service.
   *
   * @param string $query
   *   The search query.
   * @param int $top_k
   *   Number of results to return.
   * @param bool $include_metadata
   *   Whether to include metadata in results.
   *
   * @return array|null
   *   The search results or NULL on failure.
   */
  public function search(string $query, int $top_k = 10, bool $include_metadata = TRUE): ?array {
    if (empty(trim($query))) {
      return ['error' => 'Search query cannot be empty'];
    }

    try {
      $response = $this->httpClient->get($this->getBaseUrl() . '/search', [
        'query' => [
          'query' => $query,
          'top_k' => $top_k,
          'include_metadata' => $include_metadata ? 'true' : 'false',
        ],
        'timeout' => $this->getTimeout(),
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      
      $this->logger->info('Search completed for query: @query', [
        '@query' => $query,
      ]);

      return $data;
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to search Pinecone: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

}