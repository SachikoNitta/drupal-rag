from fastapi import FastAPI, HTTPException
from fastapi.responses import JSONResponse
from pinecone import Pinecone
import os
from dotenv import load_dotenv
from typing import List, Dict, Any, Optional
from pydantic import BaseModel, Field

load_dotenv()

app = FastAPI(title="Drupal to Pinecone API", version="1.0.0")

# Configuration
PINECONE_API_KEY = os.getenv("PINECONE_API_KEY")
PINECONE_INDEX_NAME = os.getenv("PINECONE_INDEX_NAME", "drupal_articles")

# Initialize Pinecone
pc = None
if PINECONE_API_KEY:
    pc = Pinecone(api_key=PINECONE_API_KEY)

# Pydantic models for request validation
class DrupalNodeBody(BaseModel):
    value: str
    format: Optional[str] = None

class DrupalNodeAttributes(BaseModel):
    title: str
    body: Optional[DrupalNodeBody] = None
    created: Optional[str] = None
    changed: Optional[str] = None
    status: Optional[bool] = None

class DrupalNode(BaseModel):
    id: str
    type: str = "node--article"
    attributes: DrupalNodeAttributes

class NodeRequest(BaseModel):
    data: DrupalNode

def prepare_node_for_pinecone(node: DrupalNode) -> Dict[str, Any]:
    """DrupalノードをPinecone形式に変換"""
    # 埋め込み用のテキストコンテンツを抽出
    title = node.attributes.title
    body = ""
    if node.attributes.body:
        body = node.attributes.body.value
    
    # タイトルと本文を結合してテキストコンテンツを作成
    text_content = f"{title}. {body}".strip()
    
    # メタデータを準備
    metadata = {
        "title": title,
        "created": node.attributes.created,
        "changed": node.attributes.changed,
        "status": node.attributes.status,
        "drupal_id": node.id,
        "type": "article"
    }
    
    return {
        "id": node.id,
        "text": text_content,
        "metadata": metadata
    }

async def store_in_pinecone(articles_data: List[Dict[str, Any]]) -> Dict[str, Any]:
    """PineconeのEmbedding APIを使用してPineconeインデックスに記事を保存"""
    if not pc:
        raise HTTPException(status_code=500, detail="Pinecone API key not configured")
    
    try:
        index = pc.Index(PINECONE_INDEX_NAME)
        
        # 埋め込み用のテキストコンテンツを抽出
        texts = [article["text"] for article in articles_data]
        
        # PineconeのEmbedding APIを使用して埋め込みを作成
        # デフォルトの埋め込みモデル（multilingual-e5-large）を使用
        embedding_response = pc.inference.embed(
            model="multilingual-e5-large",
            inputs=texts,
            parameters={"input_type": "passage"}
        )
        
        # アップサート用のベクターを準備
        vectors_to_upsert = []
        for i, article in enumerate(articles_data):
            vectors_to_upsert.append({
                "id": article["id"],
                "values": embedding_response.data[i].values,
                "metadata": article["metadata"]
            })
        
        # Pineconeにベクターをアップサート
        upsert_response = index.upsert(vectors=vectors_to_upsert)
        
        return {
            "success": True,
            "upserted_count": upsert_response.upserted_count,
            "articles_processed": len(articles_data),
            "embedding_model": "multilingual-e5-large"
        }
        
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Error storing in Pinecone: {str(e)}")

@app.get("/")
async def root():
    """ルートエンドポイント"""
    return {"message": "Drupal to Pinecone API is running"}

@app.get("/health")
async def health_check():
    """ヘルスチェックエンドポイント"""
    return {"status": "healthy", "service": "drupal-pinecone-api"}

@app.post("/store-node")
async def store_node(request: NodeRequest):
    """DrupalからJSONデータを受信してPineconeに保存"""
    try:
        # ノードをPinecone形式に変換
        prepared_node = prepare_node_for_pinecone(request.data)
        
        # Pineconeに保存
        result = await store_in_pinecone([prepared_node])
        
        return JSONResponse(
            status_code=200,
            content={
                "message": "Node successfully stored in Pinecone",
                "node_id": request.data.id,
                "result": result
            }
        )
        
    except HTTPException:
        raise
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Unexpected error: {str(e)}")

@app.post("/store-nodes")
async def store_multiple_nodes(nodes: List[DrupalNode]):
    """複数のDrupalノードを受信してPineconeに保存"""
    try:
        if not nodes:
            return JSONResponse(
                status_code=200,
                content={"message": "No nodes provided", "count": 0}
            )
        
        # 全ノードをPinecone形式に変換
        prepared_nodes = [prepare_node_for_pinecone(node) for node in nodes]
        
        # Pineconeに保存
        result = await store_in_pinecone(prepared_nodes)
        
        return JSONResponse(
            status_code=200,
            content={
                "message": "Nodes successfully stored in Pinecone",
                "nodes_count": len(nodes),
                "result": result
            }
        )
        
    except HTTPException:
        raise
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Unexpected error: {str(e)}")


@app.get("/search")
async def search_articles(
    query: str,
    top_k: Optional[int] = 10,
    include_metadata: Optional[bool] = True
):
    """キーワードでPineconeインデックスを検索"""
    if not pc:
        raise HTTPException(status_code=500, detail="Pinecone API key not configured")
    
    if not query.strip():
        raise HTTPException(status_code=400, detail="Search query cannot be empty")
    
    try:
        index = pc.Index(PINECONE_INDEX_NAME)
        
        # クエリを埋め込みベクターに変換
        embedding_response = pc.inference.embed(
            model="multilingual-e5-large",
            inputs=[query],
            parameters={"input_type": "query"}
        )
        
        query_vector = embedding_response.data[0].values
        
        # Pineconeインデックスを検索
        search_response = index.query(
            vector=query_vector,
            top_k=top_k,
            include_metadata=include_metadata
        )
        
        # レスポンスを辞書形式に変換してシリアライゼーションの問題を回避
        results = []
        for match in search_response.matches:
            result = {
                "id": match.id,
                "score": match.score
            }
            if include_metadata and hasattr(match, 'metadata') and match.metadata:
                result["metadata"] = dict(match.metadata)
            results.append(result)
        
        return {
            "query": query,
            "results": results,
            "total_results": len(results)
        }
        
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Error searching Pinecone: {str(e)}")

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8000)