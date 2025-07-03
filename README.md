# Drupal RAG システム

DrupalとPineconeを使用したRAG（Retrieval-Augmented Generation）システムです。Wikipedia記事をインポートし、Pineconeベクトルデータベースで検索を行います。

## システム構成

- **Drupal**: コンテンツ管理システム
- **Python FastAPI**: Pineconeとの連携API
- **Pinecone**: ベクトルデータベース
- **Wikipedia API**: 記事データのソース

## 使用方法

### 1. Wikipedia記事のインポート

`wikipedia_importer` モジュールを使用してWikipedia記事をDrupalにインポートします。

```bash
# 単一記事のインポート
drush wikipedia:import "人工知能"

# 複数記事のインポート
drush wikipedia:import "機械学習"
drush wikipedia:import "深層学習"
```

### 2. Python FastAPIサーバーの起動

```bash
cd python
python main.py
```

FastAPIサーバーが `http://localhost:8000` で起動します。

### 3. Pineconeへの記事保存

#### 3.1 Drupal REST APIを使用

```bash
# 全ての公開記事をPineconeに同期
curl -X POST http://localhost/api/pinecone/sync-nodes \
  -H "Content-Type: application/json" \
  -d '{
    "node_type": "article",
    "limit": 10
  }'

# 特定の記事のみ同期
curl -X POST http://localhost/api/pinecone/sync-nodes \
  -H "Content-Type: application/json" \
  -d '{
    "node_ids": ["1", "2", "3"]
  }'
```

#### 3.2 Python APIを直接使用

```bash
# 単一記事の保存
curl -X POST http://localhost:8000/store-node \
  -H "Content-Type: application/json" \
  -d '{
    "data": {
      "id": "article-uuid",
      "type": "node--article",
      "attributes": {
        "title": "記事のタイトル",
        "body": {
          "value": "記事の本文"
        }
      }
    }
  }'

# 複数記事の保存
curl -X POST http://localhost:8000/store-nodes \
  -H "Content-Type: application/json" \
  -d '[
    {
      "id": "article-uuid-1",
      "type": "node--article",
      "attributes": {
        "title": "記事1のタイトル",
        "body": {
          "value": "記事1の本文"
        }
      }
    }
  ]'
```

### 4. 記事の検索

#### 4.1 Drupal REST APIを使用

```bash
# キーワード検索
curl -X GET "http://localhost/api/pinecone/search?query=人工知能&top_k=5"

# POST リクエストでの検索
curl -X POST http://localhost/api/pinecone/search \
  -H "Content-Type: application/json" \
  -d '{
    "query": "機械学習",
    "top_k": 10,
    "include_metadata": true
  }'
```

#### 4.2 Python APIを直接使用

```bash
# キーワード検索
curl -X GET "http://localhost:8000/search?query=人工知能&top_k=5"
```

## 環境設定

### 必要な環境変数

```env
# .env ファイルを作成
PINECONE_API_KEY=your-pinecone-api-key
PINECONE_INDEX_NAME=drupal_articles
```

### Drupal設定

1. カスタムモジュールを有効化:
   ```bash
   drush en pinecone_api wikipedia_importer
   ```

2. 必要なフィールドを作成:
   - `field_source_url`: Wikipedia記事のURL用

### Python依存関係

```bash
cd python
pip install -r requirements.txt
```

## APIエンドポイント

### Drupal REST API

- `POST /api/pinecone/sync-nodes`: 記事をPineconeに同期
- `GET/POST /api/pinecone/search`: 記事を検索

### Python FastAPI

- `GET /`: サーバー状態確認
- `GET /health`: ヘルスチェック
- `POST /store-node`: 単一記事の保存
- `POST /store-nodes`: 複数記事の保存
- `GET /search`: 記事の検索

## 使用例

### 完全なワークフロー

1. **Wikipedia記事のインポート**:
   ```bash
   drush wikipedia:import "自然言語処理"
   ```

2. **Pineconeへの保存**:
   ```bash
   curl -X POST http://localhost/api/pinecone/sync-nodes \
     -H "Content-Type: application/json" \
     -d '{"limit": 1}'
   ```

3. **検索の実行**:
   ```bash
   curl -X GET "http://localhost/api/pinecone/search?query=言語処理&top_k=3"
   ```

### 検索結果の例

```json
{
  "success": true,
  "search_query": "人工知能",
  "search_results": {
    "query": "人工知能",
    "results": [
      {
        "id": "article-uuid",
        "score": 0.85,
        "metadata": {
          "title": "人工知能",
          "type": "article"
        },
        "drupal_node": {
          "nid": 1,
          "title": "人工知能",
          "url": "http://localhost/node/1"
        }
      }
    ]
  }
}
```

## トラブルシューティング

### よくある問題

1. **Pinecone API接続エラー**:
   - API キーが正しく設定されているか確認
   - インデックス名が正しいか確認

2. **記事が見つからない**:
   - Wikipedia記事のタイトルが正確か確認
   - 記事が公開状態になっているか確認

3. **検索結果が空**:
   - 記事がPineconeに保存されているか確認
   - 検索クエリが適切か確認