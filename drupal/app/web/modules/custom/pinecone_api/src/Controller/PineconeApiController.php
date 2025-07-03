<?php

namespace Drupal\pinecone_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\pinecone_api\Service\PineconeApiClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\node\NodeInterface;

/**
 * Controller for Pinecone API endpoints.
 */
class PineconeApiController extends ControllerBase {

  /**
   * The Pinecone API client service.
   *
   * @var \Drupal\pinecone_api\Service\PineconeApiClient
   */
  protected $pineconeClient;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a PineconeApiController object.
   *
   * @param \Drupal\pinecone_api\Service\PineconeApiClient $pinecone_client
   *   The Pinecone API client service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(PineconeApiClient $pinecone_client, EntityTypeManagerInterface $entity_type_manager) {
    $this->pineconeClient = $pinecone_client;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('pinecone_api.client'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Syncs published article nodes to Pinecone.
   *
   * POST /api/pinecone/sync-nodes
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with sync results.
   */
  public function syncNodes(Request $request): JsonResponse {
    try {
      // Parse request body for optional parameters
      $data = json_decode($request->getContent(), TRUE) ?: [];
      $node_type = $data['node_type'] ?? 'article';
      $limit = isset($data['limit']) ? (int) $data['limit'] : NULL;
      $node_ids = $data['node_ids'] ?? NULL;

      // Build query for nodes
      $node_storage = $this->entityTypeManager->getStorage('node');
      $query = $node_storage->getQuery()
        ->condition('type', $node_type)
        ->condition('status', 1) // Published only
        ->accessCheck(FALSE)
        ->sort('changed', 'DESC');

      // Apply filters
      if ($node_ids && is_array($node_ids)) {
        $query->condition('nid', $node_ids, 'IN');
      }

      if ($limit) {
        $query->range(0, $limit);
      }

      $node_ids_result = $query->execute();

      if (empty($node_ids_result)) {
        return new JsonResponse([
          'success' => TRUE,
          'message' => 'No nodes found to sync',
          'count' => 0,
        ]);
      }

      // Load nodes
      $nodes = $node_storage->loadMultiple($node_ids_result);

      // Send to Pinecone via Python API
      $result = $this->pineconeClient->storeNodes($nodes);

      if ($result === NULL) {
        return new JsonResponse([
          'success' => FALSE,
          'message' => 'Failed to sync nodes to Pinecone',
        ], 500);
      }

      return new JsonResponse([
        'success' => TRUE,
        'message' => 'Nodes successfully synced to Pinecone',
        'drupal_nodes_processed' => count($nodes),
        'pinecone_result' => $result,
      ]);

    }
    catch (\Exception $e) {
      $this->getLogger('pinecone_api')->error('Error syncing nodes: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Internal server error: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Searches content using Pinecone.
   *
   * GET/POST /api/pinecone/search
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with search results.
   */
  public function search(Request $request): JsonResponse {
    try {
      // Get search parameters from query string or POST body
      if ($request->isMethod('POST')) {
        $data = json_decode($request->getContent(), TRUE) ?: [];
        $query = $data['query'] ?? '';
        $top_k = $data['top_k'] ?? 10;
        $include_metadata = $data['include_metadata'] ?? TRUE;
      }
      else {
        $query = $request->query->get('query', '');
        $top_k = (int) $request->query->get('top_k', 10);
        $include_metadata = $request->query->get('include_metadata', 'true') !== 'false';
      }

      // Validate query
      if (empty(trim($query))) {
        return new JsonResponse([
          'success' => FALSE,
          'message' => 'Search query is required',
        ], 400);
      }

      // Validate top_k
      if ($top_k < 1 || $top_k > 100) {
        $top_k = 10;
      }

      // Perform search via Python API
      $result = $this->pineconeClient->search($query, $top_k, $include_metadata);

      if ($result === NULL) {
        return new JsonResponse([
          'success' => FALSE,
          'message' => 'Failed to perform search',
        ], 500);
      }

      // If search was successful, enhance results with Drupal node data
      if (isset($result['results']) && $include_metadata) {
        $enhanced_results = $this->enhanceSearchResults($result['results']);
        $result['results'] = $enhanced_results;
      }

      return new JsonResponse([
        'success' => TRUE,
        'search_query' => $query,
        'search_results' => $result,
      ]);

    }
    catch (\Exception $e) {
      $this->getLogger('pinecone_api')->error('Error performing search: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Internal server error: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Enhances search results with Drupal node data.
   *
   * @param array $results
   *   The search results from Pinecone.
   *
   * @return array
   *   Enhanced results with Drupal node data.
   */
  protected function enhanceSearchResults(array $results): array {
    $enhanced = [];
    $node_storage = $this->entityTypeManager->getStorage('node');

    foreach ($results as $result) {
      $enhanced_result = $result;
      
      // Try to load the corresponding Drupal node by UUID
      if (isset($result['id'])) {
        $nodes = $node_storage->loadByProperties(['uuid' => $result['id']]);
        if (!empty($nodes)) {
          $node = reset($nodes);
          if ($node instanceof NodeInterface && $node->access('view')) {
            $enhanced_result['drupal_node'] = [
              'nid' => $node->id(),
              'title' => $node->getTitle(),
              'url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
              'type' => $node->bundle(),
              'published' => $node->isPublished(),
              'created' => date('c', $node->getCreatedTime()),
              'changed' => date('c', $node->getChangedTime()),
            ];
          }
        }
      }
      
      $enhanced[] = $enhanced_result;
    }

    return $enhanced;
  }

}