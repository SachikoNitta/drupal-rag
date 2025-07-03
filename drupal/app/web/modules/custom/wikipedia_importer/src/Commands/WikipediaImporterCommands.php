<?php

namespace Drupal\wikipedia_importer\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\Entity\Node;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Wikipedia Importer Drush commands.
 */
class WikipediaImporterCommands extends DrushCommands {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;



  /**
   * Constructs a WikipediaImporterCommands object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(ClientInterface $http_client, EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_factory) {
    parent::__construct();
    $this->httpClient = $http_client;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Wikipedia記事をインポートします。
   */
  #[CLI\Command(name: 'wikipedia:import', aliases: ['wiki-import'])]
  #[CLI\Argument(name: 'title', description: 'インポートしたいWikipedia記事のタイトル')]
  #[CLI\Usage(name: 'drush wikipedia:import "人工知能"', description: '"人工知能"というタイトルのWikipedia記事をインポートします')]
  public function importWikipediaArticle($title) {
    $this->output()->writeln('Wikipedia記事をインポート中: ' . $title);

    try {
      // Wikipedia APIからデータを取得
      $data = $this->fetchWikipediaData($title);

      if (!$data) {
        $this->output()->writeln('記事が見つかりませんでした: ' . $title);
        return;
      }

      // Articleノードを作成
      $node = $this->createArticleNode($data);

      if ($node) {
        $this->output()->writeln('記事が正常にインポートされました。ノードID: ' . $node->id());
        $this->logger->info('Wikipedia記事がインポートされました: @title (ノードID: @nid)', [
          '@title' => $title,
          '@nid' => $node->id(),
        ]);
      } else {
        $this->output()->writeln('記事の作成に失敗しました。');
      }

    } catch (\Exception $e) {
      $this->output()->writeln('エラーが発生しました: ' . $e->getMessage());
      $this->logger->error('Wikipedia記事のインポートエラー: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * Wikipedia APIからデータを取得します。
   *
   * @param string $title
   *   記事のタイトル
   *
   * @return array|null
   *   記事データまたはnull
   */
  protected function fetchWikipediaData($title) {
    $url = 'https://ja.wikipedia.org/w/api.php';
    $params = [
      'query' => [
        'action' => 'query',
        'format' => 'json',
        'titles' => $title,
        'prop' => 'extracts|info',
        'exintro' => false,
        'explaintext' => true,
        'inprop' => 'url',
      ],
    ];

    try {
      $response = $this->httpClient->request('GET', $url, $params);
      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (isset($data['query']['pages'])) {
        $pages = $data['query']['pages'];
        $page = reset($pages);

        if (isset($page['missing'])) {
          return NULL;
        }

        return [
          'title' => $page['title'] ?? $title,
          'extract' => $page['extract'] ?? '',
          'url' => $page['fullurl'] ?? '',
        ];
      }
    } catch (RequestException $e) {
      $this->logger->error('Wikipedia API リクエストエラー: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }

    return NULL;
  }

  /**
   * Articleノードを作成します。
   *
   * @param array $data
   *   Wikipedia記事データ
   *
   * @return \Drupal\node\Entity\Node|null
   *   作成されたノードまたはnull
   */
  protected function createArticleNode(array $data) {
    try {
      $node = Node::create([
        'type' => 'article',
        'title' => $data['title'],
        'body' => [
          'value' => $data['extract'],
          'format' => 'basic_html',
        ],
        'field_source_url' => $data['url'],
        'status' => 1,
        'uid' => 1,
      ]);

      $node->save();
      return $node;

    } catch (\Exception $e) {
      $this->logger->error('ノード作成エラー: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

}
