from fastapi import FastAPI, HTTPException, Request
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field
from typing import List, Dict, Any, Optional
import os
import logging
import time
import uuid
import json
import requests

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

app = FastAPI(
    title="Support System AI Service",
    description="AI service for MediaWiki Support System extension",
    version="1.0.0"
)

# Add CORS middleware
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Models for requests and responses
class SearchQuery(BaseModel):
    query: str
    context: Optional[List[Dict[str, Any]]] = Field(default_factory=list)
    user_id: Optional[str] = None

class Source(BaseModel):
    title: str
    id: str
    score: float
    url: Optional[str] = None

class AISearchResponse(BaseModel):
    answer: str
    sources: List[Source] = Field(default_factory=list)
    success: bool

MEDIAWIKI_URL = os.environ.get('MEDIAWIKI_URL', 'http://mediawiki/api.php')
REDMINE_URL = os.environ.get('REDMINE_URL', 'http://redmine:3000')
STORAGE_PATH = os.environ.get('STORAGE_PATH', '/app/data')

os.makedirs(STORAGE_PATH, exist_ok=True)

@app.middleware("http")
async def add_request_id(request: Request, call_next):
    request_id = str(uuid.uuid4())
    start_time = time.time()
    
    logger.info(f"Request {request_id} started: {request.method} {request.url.path}")
    
    response = await call_next(request)
    
    process_time = time.time() - start_time
    logger.info(f"Request {request_id} completed in {process_time:.4f}s")
    
    return response

@app.exception_handler(Exception)
async def global_exception_handler(request: Request, exc: Exception):
    logger.error(f"Unhandled exception: {str(exc)}", exc_info=True)
    return JSONResponse(
        status_code=500,
        content={"detail": "An internal server error occurred"}
    )

@app.get("/", tags=["Health"])
async def health_check():
    return {"status": "ok", "message": "AI service is running"}

class QueryHistoryManager:
    def __init__(self):
        self.history_file = os.path.join(STORAGE_PATH, "query_history.json")
        self.frequent_queries_file = os.path.join(STORAGE_PATH, "frequent_queries.json")
        self.load_history()
        self.load_frequent_queries()
    
    def load_history(self):
        try:
            if os.path.exists(self.history_file):
                with open(self.history_file, 'r', encoding='utf-8') as f:
                    self.history = json.load(f)
            else:
                self.history = {}
        except Exception as e:
            logger.error(f"Error loading history: {str(e)}")
            self.history = {}
    
    def save_history(self):
        try:
            with open(self.history_file, 'w', encoding='utf-8') as f:
                json.dump(self.history, f, ensure_ascii=False, indent=2)
        except Exception as e:
            logger.error(f"Error saving history: {str(e)}")
    
    def load_frequent_queries(self):
        try:
            if os.path.exists(self.frequent_queries_file):
                with open(self.frequent_queries_file, 'r', encoding='utf-8') as f:
                    self.frequent_queries = json.load(f)
            else:
                self.frequent_queries = {}
        except Exception as e:
            logger.error(f"Error loading frequent queries: {str(e)}")
            self.frequent_queries = {}
    
    def save_frequent_queries(self):
        try:
            with open(self.frequent_queries_file, 'w', encoding='utf-8') as f:
                json.dump(self.frequent_queries, f, ensure_ascii=False, indent=2)
        except Exception as e:
            logger.error(f"Error saving frequent queries: {str(e)}")
    
    def get_user_history(self, user_id):
        if not user_id:
            return []
        return self.history.get(user_id, [])
    
    def update_user_history(self, user_id, query, response):
        if not user_id:
            return
        if user_id not in self.history:
            self.history[user_id] = []
        history_entry = {
            "query": query,
            "timestamp": time.strftime("%Y-%m-%d %H:%M:%S"),
            "answer": response.get("answer", ""),
            "sources": response.get("sources", [])
        }
        self.history[user_id] = [history_entry] + self.history[user_id][:19]
        
        self.save_history()
    
    def update_frequent_queries(self, query, response):
        normalized_query = " ".join(query.lower().split())
        if normalized_query not in self.frequent_queries:
            self.frequent_queries[normalized_query] = {
                "count": 0,
                "last_response": None,
                "sources": []
            }
        self.frequent_queries[normalized_query]["count"] += 1
        self.frequent_queries[normalized_query]["last_response"] = response.get("answer", "")
        if "sources" in response and response["sources"]:
            self.frequent_queries[normalized_query]["sources"] = response["sources"]
        
        self.save_frequent_queries()
    
    def find_similar_query(self, query):
        normalized_query = " ".join(query.lower().split())
        if normalized_query in self.frequent_queries:
            return self.frequent_queries[normalized_query]
        for q, data in self.frequent_queries.items():
            if normalized_query in q or q in normalized_query:
                return data
        query_words = set(normalized_query.split())
        best_match = None
        best_match_score = 0
        for q, data in self.frequent_queries.items():
            q_words = set(q.split())
            common_words = query_words.intersection(q_words)
            if common_words:
                score = len(common_words) / max(len(query_words), len(q_words))
                if score > 0.6 and score > best_match_score:
                    best_match = data
                    best_match_score = score
        
        return best_match

class MediaWikiClient:
    def __init__(self, api_url):
        self.api_url = api_url
    
    def search_pages(self, query, limit=5):
        """
        Search for pages in MediaWiki
        """
        try:
            params = {
                'action': 'query',
                'list': 'search',
                'srsearch': query,
                'srlimit': limit,
                'format': 'json'
            }
            
            response = requests.get(self.api_url, params=params, timeout=10)
            
            if response.status_code != 200:
                logger.error(f"Error searching MediaWiki: {response.status_code} {response.text}")
                return []
            data = response.json()
            if 'query' not in data or 'search' not in data['query']:
                return []

            results = []
            for page in data['query']['search']:
                page_url = f"{self.api_url.split('/api.php')[0]}/index.php?title={page['title'].replace(' ', '_')}"
                
                results.append({
                    'id': f"mediawiki_{page['pageid']}",
                    'title': page['title'],
                    'content': self.strip_html(page.get('snippet', '')),
                    'url': page_url,
                    'score': 1.0,
                    'source': 'mediawiki'
                })
            
            return results
        
        except Exception as e:
            logger.error(f"Error searching MediaWiki: {str(e)}")
            return []
    
    def get_page_content(self, page_id):
        """
        Get content of a page by ID
        """
        try:
            if not page_id.startswith('mediawiki_'):
                return None
            
            page_id = page_id.replace('mediawiki_', '')
            
            params = {
                'action': 'parse',
                'pageid': page_id,
                'prop': 'text',
                'format': 'json'
            }
            
            response = requests.get(self.api_url, params=params, timeout=10)
            
            if response.status_code != 200:
                logger.error(f"Error getting page content: {response.status_code} {response.text}")
                return None
            
            data = response.json()
            
            if 'parse' not in data or 'text' not in data['parse']:
                return None
            
            return self.strip_html(data['parse']['text']['*'])
        
        except Exception as e:
            logger.error(f"Error getting page content: {str(e)}")
            return None
    
    def strip_html(self, html):
        """
        Remove HTML tags from text
        """
        import re
        return re.sub('<.*?>', ' ', html).strip()

history_manager = QueryHistoryManager()
mediawiki_client = MediaWikiClient(MEDIAWIKI_URL)

@app.post("/api/search_ai", response_model=AISearchResponse, tags=["Search"])
async def search_ai(search_query: SearchQuery):
    """
    AI-powered search endpoint
    
    Analyze query context and find relevant information from MediaWiki
    
    - **query**: Search query text
    - **context**: Optional context from previous interactions
    - **user_id**: Optional user identifier for personalization
    """
    try:
        query = search_query.query
        context = search_query.context
        user_id = search_query.user_id
        
        logger.info(f"AI search query: {query}")
        logger.info(f"Context: {context}")
        logger.info(f"User ID: {user_id}")
        logger.info(f"Response Sources: {sources}")
        logger.info(f"Response Length: {len(answer)}")
        if not query:
            return AISearchResponse(
                answer="Пожалуйста, укажите поисковый запрос.",
                sources=[],
                success=False
            )
        similar_query = None
        if user_id:
            user_history = history_manager.get_user_history(user_id)
            for entry in user_history:
                if entry["query"].lower() == query.lower():
                    similar_query = entry
                    logger.info(f"Found exact match in user history: {entry['query']}")
                    break
        if not similar_query:
            similar_query = history_manager.find_similar_query(query)
            if similar_query:
                logger.info(f"Found similar query in global history, count: {similar_query['count']}")
        if similar_query and (isinstance(similar_query, dict) and similar_query.get("count", 0) > 2 or 
                             similar_query.get("answer")):
            answer = similar_query.get("answer", "")
            if not answer:
                answer = similar_query.get("last_response", "")
            sources_data = similar_query.get("sources", [])
            sources = []
            for source_data in sources_data:
                source = Source(
                    title=source_data.get("title", "Unknown"),
                    id=source_data.get("id", ""),
                    score=source_data.get("score", 0.0)
                )
                if "url" in source_data:
                    source.url = source_data["url"]
                sources.append(source)
            if user_id:
                history_manager.update_user_history(user_id, query, {
                    "answer": answer,
                    "sources": sources_data
                })
            return AISearchResponse(
                answer=answer,
                sources=sources,
                success=True
            )
        mediawiki_results = mediawiki_client.search_pages(query)
        if not mediawiki_results:
            error_response = AISearchResponse(
                answer="К сожалению, не удалось найти подходящие материалы. \
                    Попробуйте переформулировать запрос или создать заявку для получения помощи.",
                sources=[],
                success=False
            )
            if user_id:
                history_manager.update_user_history(user_id, query, {
                    "answer": error_response.answer,
                    "sources": []
                })
            
            return error_response
        top_result = mediawiki_results[0]
        content = top_result.get("content", "")
        if len(content) < 2000:
            full_content = mediawiki_client.get_page_content(top_result["id"])
            if full_content:
                content = full_content
                if len(content) > 1000:
                    content = content[:1000] + "..."
        answer = f"Вот информация по вашему запросу '{query}':\n\n{content}\n\nПодробнее вы можете прочитать на странице \"{top_result['title']}\"."
        sources = []
        for result in mediawiki_results[:3]:
            source = Source(
                title=result["title"],
                id=result["id"],
                score=result["score"],
                url=result.get("url")
            )
            sources.append(source)
        response_data = {
            "answer": answer,
            "sources": [s.dict() for s in sources]
        }
        
        if user_id:
            history_manager.update_user_history(user_id, query, response_data)
        history_manager.update_frequent_queries(query, response_data)
        
        return AISearchResponse(
            answer=answer,
            sources=sources,
            success=True
        )
        
    except Exception as e:
        logger.error(f"Error in search_ai: {str(e)}", exc_info=True)
        return AISearchResponse(
            answer="Произошла ошибка при обработке запроса. Пожалуйста, попробуйте позже.",
            sources=[],
            success=False
        )

if __name__ == "__main__":
    import uvicorn
    uvicorn.run("app:app", host="0.0.0.0", port=5000, reload=True)