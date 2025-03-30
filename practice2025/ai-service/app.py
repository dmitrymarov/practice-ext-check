from flask import Flask, jsonify, request
from flask_cors import CORS
import os
import requests
import json
import logging
from opensearchpy import OpenSearch
from sentence_transformers import SentenceTransformer
from sklearn.metrics.pairwise import cosine_similarity
import numpy as np
import nltk
from nltk.tokenize import word_tokenize
from nltk.corpus import stopwords

# Download NLTK resources
nltk.download('punkt')
nltk.download('stopwords')

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

app = Flask(__name__)
CORS(app)

# Environment variables
OPENSEARCH_HOST = os.environ.get('OPENSEARCH_HOST', 'localhost')
OPENSEARCH_PORT = int(os.environ.get('OPENSEARCH_PORT', 9200))
OPENSEARCH_INDEX = os.environ.get('OPENSEARCH_INDEX', 'solutions')
MEDIAWIKI_URL = os.environ.get('MEDIAWIKI_URL', 'http://mediawiki/api.php')
REDMINE_URL = os.environ.get('REDMINE_URL', 'http://redmine:3000')
REDMINE_API_KEY = os.environ.get('REDMINE_API_KEY', 'c177337d75a1da3bb43d67ec9b9bb139b299502f')

# Initialize OpenSearch client
try:
    opensearch = OpenSearch(
        hosts=[{'host': OPENSEARCH_HOST, 'port': OPENSEARCH_PORT}],
        http_auth=None,
        use_ssl=False,
        verify_certs=False,
        ssl_show_warn=False,
        timeout=30,
        retry_on_timeout=True,
        max_retries=3
    )
    logger.info(f"OpenSearch connection established: {OPENSEARCH_HOST}:{OPENSEARCH_PORT}")
except Exception as e:
    logger.error(f"Error connecting to OpenSearch: {str(e)}")
    opensearch = None

# Initialize sentence transformer model
try:
    model = SentenceTransformer('paraphrase-multilingual-MiniLM-L12-v2')
    logger.info("Sentence transformer model loaded")
except Exception as e:
    logger.error(f"Error loading sentence transformer model: {str(e)}")
    model = None

@app.route('/')
def index():
    return jsonify({
        'status': 'ok',
        'message': 'AI service is running'
    })

@app.route('/api/search_ai', methods=['POST'])
def search_ai():
    """
    Endpoint for AI-powered search
    
    Expects JSON with:
    - query: Search query text
    - context: Optional context from previous interactions
    
    Returns:
    - answer: Generated answer based on relevant documents
    - sources: List of sources used for the answer
    - success: Boolean indicating success
    """
    try:
        data = request.json
        query = data.get('query', '')
        context = data.get('context', [])
        
        logger.info(f"AI search query: {query}")
        logger.info(f"Context: {context}")
        
        if not query:
            return jsonify({
                'answer': 'Пожалуйста, укажите поисковый запрос.',
                'sources': [],
                'success': False
            })
        
        # Get relevant documents
        docs = search_relevant_documents(query, context)
        
        if not docs:
            return jsonify({
                'answer': 'К сожалению, не удалось найти подходящие материалы. Попробуйте переформулировать запрос или создать заявку для получения помощи от специалиста.',
                'sources': [],
                'success': False
            })
        
        # Generate answer
        answer = generate_answer(query, docs, context)
        
        # Format sources for response
        sources = []
        for doc in docs[:3]:  # Limit to top 3 sources
            source = {
                'title': doc.get('title', 'Документ без названия'),
                'id': doc.get('id', ''),
                'score': doc.get('score', 0)
            }
            
            if 'url' in doc:
                source['url'] = doc['url']
            
            sources.append(source)
        
        return jsonify({
            'answer': answer,
            'sources': sources,
            'success': True
        })
        
    except Exception as e:
        logger.error(f"Error in search_ai: {str(e)}")
        return jsonify({
            'answer': 'Произошла ошибка при обработке запроса. Пожалуйста, попробуйте позже.',
            'sources': [],
            'success': False
        }), 500

def search_relevant_documents(query, context=None):
    """
    Search for relevant documents using semantic search
    
    Args:
        query: Search query
        context: Optional context from previous interactions
        
    Returns:
        List of relevant documents
    """
    try:
        # If OpenSearch is available, use it for search
        if opensearch:
            # First try exact search
            exact_docs = search_opensearch(query)
            
            # If we have sentence transformer model, try semantic search
            if model and (not exact_docs or len(exact_docs) < 3):
                # Get more documents for semantic reranking
                more_docs = search_opensearch(query, size=10, exact_match=False)
                semantic_docs = semantic_search(query, more_docs)
                
                # Combine and deduplicate results
                all_docs = exact_docs + semantic_docs
                unique_docs = []
                doc_ids = set()
                
                for doc in all_docs:
                    if doc['id'] not in doc_ids:
                        doc_ids.add(doc['id'])
                        unique_docs.append(doc)
                
                return unique_docs[:5]  # Return top 5
            
            return exact_docs
        
        # Fallback to mock data if OpenSearch is not available
        return search_mock(query)
        
    except Exception as e:
        logger.error(f"Error in search_relevant_documents: {str(e)}")
        return []

def search_opensearch(query, size=5, exact_match=True):
    """
    Search in OpenSearch
    
    Args:
        query: Search query
        size: Number of results to return
        exact_match: Whether to use exact matching
        
    Returns:
        List of documents
    """
    try:
        if exact_match:
            query_body = {
                'query': {
                    'multi_match': {
                        'query': query,
                        'fields': ['title^2', 'content', 'tags^1.5'],
                        'type': 'best_fields'
                    }
                },
                'size': size
            }
        else:
            # More relaxed query for semantic reranking
            query_body = {
                'query': {
                    'bool': {
                        'should': [
                            {'match': {'title': {'query': query, 'boost': 2.0}}},
                            {'match': {'content': {'query': query}}},
                            {'match': {'tags': {'query': query, 'boost': 1.5}}}
                        ]
                    }
                },
                'size': size
            }
        
        response = opensearch.search(
            body=query_body,
            index=OPENSEARCH_INDEX
        )
        
        results = []
        for hit in response['hits']['hits']:
            source = hit['_source']
            result = {
                'id': source.get('id', hit['_id']),
                'title': source.get('title', 'Untitled'),
                'content': source.get('content', ''),
                'score': hit['_score'],
                'source': source.get('source', 'opensearch')
            }
            
            if 'url' in source:
                result['url'] = source['url']
                
            if 'tags' in source:
                result['tags'] = source['tags']
                
            results.append(result)
            
        return results
        
    except Exception as e:
        logger.error(f"Error in search_opensearch: {str(e)}")
        return []

def semantic_search(query, documents):
    """
    Semantic search using sentence embeddings
    
    Args:
        query: Search query
        documents: List of documents to search in
        
    Returns:
        List of documents sorted by semantic similarity
    """
    try:
        if not model or not documents:
            return []
        
        # Create query embedding
        query_embedding = model.encode([query])[0]
        
        # Create document embeddings
        doc_texts = []
        for doc in documents:
            # Combine title and content for better matching
            doc_text = f"{doc.get('title', '')} {doc.get('content', '')}"
            doc_texts.append(doc_text)
        
        doc_embeddings = model.encode(doc_texts)
        
        # Calculate similarities
        similarities = cosine_similarity([query_embedding], doc_embeddings)[0]
        
        # Sort documents by similarity
        doc_sims = list(zip(documents, similarities))
        doc_sims.sort(key=lambda x: x[1], reverse=True)
        
        # Return documents with updated scores
        results = []
        for doc, sim in doc_sims:
            doc_copy = doc.copy()
            doc_copy['score'] = float(sim)
            results.append(doc_copy)
            
        return results
        
    except Exception as e:
        logger.error(f"Error in semantic_search: {str(e)}")
        return []

def search_mock(query):
    """
    Search in mock data (fallback)
    
    Args:
        query: Search query
        
    Returns:
        List of documents
    """
    mock_data = [
        {
            'id': 'mock1',
            'title': 'Решение проблем с Wi-Fi подключением',
            'content': '1. Перезагрузите роутер. 2. Проверьте настройки Wi-Fi на устройстве. 3. Убедитесь, что пароль вводится правильно.',
            'tags': ['wi-fi', 'интернет', 'сеть', 'подключение'],
            'source': 'mock',
            'score': 1.0
        },
        {
            'id': 'mock2',
            'title': 'Исправление проблем с электронной почтой',
            'content': '1. Проверьте подключение к интернету. 2. Убедитесь, что логин и пароль верны. 3. Очистите кэш приложения.',
            'tags': ['email', 'почта', 'авторизация'],
            'source': 'mock',
            'score': 1.0
        },
        {
            'id': 'mock3',
            'title': 'Устранение неполадок с браузером',
            'content': '1. Очистите историю и кэш браузера. 2. Обновите браузер до последней версии. 3. Проверьте настройки интернет-соединения.',
            'tags': ['браузер', 'интернет', 'зависание'],
            'source': 'mock',
            'score': 1.0
        }
    ]
    
    query_lower = query.lower()
    results = []
    
    for doc in mock_data:
        score = 0
        
        # Check for keywords in title
        if query_lower in doc['title'].lower():
            score += 2
        
        # Check for keywords in content
        if query_lower in doc['content'].lower():
            score += 1
        
        # Check for keywords in tags
        for tag in doc['tags']:
            if query_lower in tag.lower():
                score += 1
                break
        
        if score > 0:
            doc_copy = doc.copy()
            doc_copy['score'] = score
            results.append(doc_copy)
    
    # Sort by score
    results.sort(key=lambda x: x['score'], reverse=True)
    return results

def generate_answer(query, documents, context=None):
    """
    Generate an answer based on relevant documents
    
    Args:
        query: Search query
        documents: Relevant documents
        context: Optional context from previous interactions
        
    Returns:
        Generated answer
    """
    if not documents:
        return "К сожалению, не удалось найти информацию по вашему запросу. Пожалуйста, попробуйте переформулировать вопрос или создайте заявку для получения помощи."
    
    # Extract most relevant content
    relevant_content = ""
    for doc in documents[:3]:  # Use top 3 documents
        relevant_content += f"{doc.get('title', '')}: {doc.get('content', '')}\n\n"
    
    # Simple answer generation based on most relevant document
    top_doc = documents[0]
    answer = f"{top_doc.get('content', '')}"
    
    # Clean up the answer
    answer = answer.replace('1. ', '\n1. ')
    answer = answer.replace('2. ', '\n2. ')
    answer = answer.replace('3. ', '\n3. ')
    
    # Add introduction
    intro = f"Вот решение для проблемы '{query}':\n"
    
    return intro + answer

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000, debug=True)