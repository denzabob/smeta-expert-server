#!/usr/bin/env python3
"""
collect_urls.py - DETERMINISTIC URL COLLECTION WITH CHUNKED SEND

REQUIREMENTS:
1. HARD LIMITS on pages, URLs, and time
2. Runs EXACTLY ONCE per session (checked via session state API)
3. Chunked URL sending (every N URLs or M seconds)
4. Cursor saving for resume capability
5. Proper stats: found_total, unique_total, sent_total, duplicates_dropped

LIMITS (configurable via session or config):
- max_pages_per_category: Default 100
- max_total_urls: Default 10000  
- max_collect_time_seconds: Default 600 (10 min)

CHUNKED SEND:
- Chunk size: 300 URLs (configurable)
- Or every 60 seconds
- Cursor saved to API after each chunk

Usage:
    python3 collect_urls.py --supplier skm_mebel --hmac-secret your-secret [--session 123]
"""

import argparse
import sys
import os
import importlib
import time
import requests
import json
import base64
import json
import base64
from pathlib import Path
from typing import List, Dict, Any, Optional, Set
from datetime import datetime
from urllib.parse import urljoin, urlparse, parse_qs, urlencode

# Добавляем путь к парсеру
parser_path = Path(__file__).parent
sys.path.insert(0, str(parser_path))

from config import config_manager


# ==================== HARD LIMITS (ANTI-LOOP) ====================
DEFAULT_MAX_PAGES_PER_CATEGORY = 100
DEFAULT_MAX_TOTAL_URLS = 10000
DEFAULT_MAX_COLLECT_TIME_SECONDS = 600  # 10 minutes

# ==================== CHUNKED SEND SETTINGS ====================
DEFAULT_CHUNK_SIZE = 300  # URLs per chunk
DEFAULT_CHUNK_INTERVAL_SECONDS = 60  # Send chunk every N seconds


class UrlCollector:
    """Класс для сбора и валидации URL товаров с жёсткими лимитами и chunked sending."""
    
    def __init__(
        self, 
        supplier_name: str, 
        hmac_secret: str, 
        session_id: int = None,
        max_pages: int = None,
        max_urls: int = None,
        max_time_seconds: int = None,
        chunk_size: int = None,
        api_url_base: str = None,
    ):
        """
        Инициализация сборщика URL.
        
        Args:
            supplier_name: Имя поставщика
            hmac_secret: HMAC секрет для API
            session_id: Опциональный ID сессии для логирования
            max_pages: Макс. страниц на категорию (override)
            max_urls: Макс. URL всего (override) 
            max_time_seconds: Макс. время сбора в секундах (override)
            chunk_size: Размер chunk для отправки (override)
        """
        self.supplier_name = supplier_name
        self.hmac_secret = hmac_secret
        self.session_id = session_id
        self.config = None
        self.adapter = None
        self.config_override: Optional[Dict[str, Any]] = None
        self.api_url_base = api_url_base
        
        # HARD LIMITS
        self.max_pages = max_pages or DEFAULT_MAX_PAGES_PER_CATEGORY
        self.max_urls = max_urls or DEFAULT_MAX_TOTAL_URLS
        self.max_time_seconds = max_time_seconds or DEFAULT_MAX_COLLECT_TIME_SECONDS
        self.chunk_size = chunk_size or DEFAULT_CHUNK_SIZE
        self.min_chunk_size = 50
        
        # Stats tracking (IMPORTANT for logging)
        self.stats = {
            'urls_found_total': 0,       # Before any filtering
            'urls_unique_total': 0,      # After dedup
            'urls_sent_total': 0,        # Actually sent to API
            'duplicates_dropped': 0,     # Sum of page + global
            'page_duplicates_dropped': 0,
            'global_duplicates_dropped': 0,
            'chunks_sent': 0,            # Number of API calls
            'chunk_send_attempted': 0,   # How many send attempts were made
            'chunk_send_success': 0,     # Successful sends
            'chunk_send_failed': 0,      # Failed sends
            'chunk_send_last_error': None,
            'chunk_send_last_status_code': None,
        }
        
        # URL deduplication
        self.seen_urls: Set[str] = set()
        self.pending_chunk: List[Dict] = []
        
        # Tracking
        self.start_time = None
        self.last_chunk_time = None
        self.pages_collected = 0
        self.stop_reason = None
        self.last_skip_log_at = 0.0
        self._log_seq = 0
    
    def log(self, message: str, level: str = 'info') -> None:
        """
        Helper to log both to stderr and send via API.
        Use this instead of print() for logs that should appear in UI.
        """
        print(f"[COLLECT] {message}", file=sys.stderr, flush=True)
        self.send_log(level, message)
        
    def load_config(self):
        """Загружает конфигурацию поставщика."""
        self.log(f"Loading config for {self.supplier_name}...")
        
        try:
            self.config = config_manager.load_supplier_config(self.supplier_name)

            if self.config_override and isinstance(self.config_override, dict):
                self.config = self._merge_dicts(self.config, self.config_override)
                override_keys = list(self.config_override.keys())
                self.log(f"Config override applied. Keys={override_keys}")
                
                override_url_collection = self.config_override.get('url_collection') or {}
                if override_url_collection:
                    self.log(
                        f"Override url_collection: "
                        f"filter_keywords={override_url_collection.get('filter_keywords', [])} "
                        f"exclude_keywords={override_url_collection.get('exclude_keywords', [])} "
                        f"max_pages={override_url_collection.get('max_pages_per_category')} "
                        f"max_urls={override_url_collection.get('max_total_urls')} "
                        f"max_time={override_url_collection.get('max_collect_time_seconds')}"
                    )
            
            # Override limits from config if not set explicitly
            url_collection = self.config.get('url_collection', {})
            if self.max_pages == DEFAULT_MAX_PAGES_PER_CATEGORY:
                self.max_pages = url_collection.get('max_pages_per_category', DEFAULT_MAX_PAGES_PER_CATEGORY)
            if self.max_urls == DEFAULT_MAX_TOTAL_URLS:
                self.max_urls = url_collection.get('max_total_urls', DEFAULT_MAX_TOTAL_URLS)
            if self.max_time_seconds == DEFAULT_MAX_COLLECT_TIME_SECONDS:
                self.max_time_seconds = url_collection.get('max_collect_time_seconds', DEFAULT_MAX_COLLECT_TIME_SECONDS)
            
            # Propagate overrides back into config for adapter use
            url_collection['max_pages_per_category'] = self.max_pages
            url_collection['max_total_urls'] = self.max_urls
            url_collection['max_collect_time_seconds'] = self.max_time_seconds
            self.config['url_collection'] = url_collection

            self.log(
                f"url_collection settings: "
                f"filter_keywords={url_collection.get('filter_keywords', [])} "
                f"exclude_keywords={url_collection.get('exclude_keywords', [])} "
                f"allowed_categories={self.config.get('allowed_categories', [])}"
            )

            self.log(f"Config loaded. Limits: max_pages={self.max_pages}, max_urls={self.max_urls}, max_time={self.max_time_seconds}s")
        except Exception as e:
            self.log(f"ERROR loading config: {e}", level='error')
            raise

    def _merge_dicts(self, base: Dict[str, Any], override: Dict[str, Any]) -> Dict[str, Any]:
        result = dict(base)
        for key, value in override.items():
            if isinstance(value, dict) and isinstance(result.get(key), dict):
                result[key] = self._merge_dicts(result[key], value)
            else:
                result[key] = value
        return result
    
    def check_limits(self, current_urls_count: int) -> Optional[str]:
        """
        Check if any limit has been reached.
        Returns stop reason or None if can continue.
        """
        # Time limit
        if self.start_time:
            elapsed = time.time() - self.start_time
            if elapsed >= self.max_time_seconds:
                return f"TIME_LIMIT_REACHED ({int(elapsed)}s >= {self.max_time_seconds}s)"
        
        # URL count limit
        if current_urls_count >= self.max_urls:
            return f"URL_LIMIT_REACHED ({current_urls_count} >= {self.max_urls})"
        
        # Page limit is checked in collect_urls loop
        return None

    def check_limits_and_handle(self, current_urls_count: int) -> Optional[str]:
        """
        Check limits and set stop_reason + flush if needed.
        """
        stop_reason = self.check_limits(current_urls_count)
        if stop_reason:
            self.stop_reason = stop_reason
            print(f"[COLLECT] LIMIT REACHED: {stop_reason}", file=sys.stderr, flush=True)
            self.flush_pending_chunk(reason="stop_reason", final=True)
        return stop_reason

    def normalize_url(self, url: str) -> str:
        """
        Normalize URL by removing tracking params, anchors, trailing slashes.
        """
        try:
            parsed = urlparse(url)
            
            # Remove tracking parameters (utm_*, fbclid, etc.)
            params_to_remove = {'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 
                               'utm_content', 'fbclid', 'gclid', 'yclid', '_ga'}
            
            if parsed.query:
                params = parse_qs(parsed.query, keep_blank_values=True)
                filtered_params = {k: v for k, v in params.items() 
                                 if k.lower() not in params_to_remove}
                new_query = urlencode(filtered_params, doseq=True) if filtered_params else ''
            else:
                new_query = ''
            
            # Remove anchor
            # Normalize path (remove trailing slash unless it's root)
            path = parsed.path.rstrip('/') if parsed.path != '/' else '/'
            
            # Rebuild URL
            normalized = f"{parsed.scheme}://{parsed.netloc}{path}"
            if new_query:
                normalized += f"?{new_query}"
            
            return normalized
        except Exception:
            return url

    def add_url(self, url: str) -> bool:
        """
        Add URL to collection with deduplication.
        Returns True if URL was added (new), False if duplicate.
        """
        self.stats['urls_found_total'] += 1
        
        # Normalize URL
        normalized = self.normalize_url(url)
        
        # Check for duplicate
        if normalized in self.seen_urls:
            self.stats['global_duplicates_dropped'] += 1
            self.stats['duplicates_dropped'] = (
                self.stats['page_duplicates_dropped'] + self.stats['global_duplicates_dropped']
            )
            return False
        
        # Add to seen set
        self.seen_urls.add(normalized)
        self.stats['urls_unique_total'] += 1
        
        # Determine material type
        material_type = self.determine_material_type(normalized)
        
        # Add to pending chunk
        self.pending_chunk.append({
            'url': normalized,
            'is_valid': True,
            'material_type': material_type,
            'validation_error': None,
        })
        
        # Check if should send chunk
        should_send = (
            len(self.pending_chunk) >= self.chunk_size or
            (self.last_chunk_time and time.time() - self.last_chunk_time >= DEFAULT_CHUNK_INTERVAL_SECONDS)
        )
        
        if should_send:
            # Timer-based or size-based flush (non-final)
            self.flush_pending_chunk(reason="timer_or_size", final=False)
        
        return True

    def send_chunk(self) -> bool:
        """
        Send current pending chunk to API.
        """
        if not self.pending_chunk:
            return True
        
        chunk_to_send = self.pending_chunk[:]
        self.pending_chunk = []

        self.stats['chunk_send_attempted'] += 1
        print(
            f"[COLLECT] chunk_send_attempted={self.stats['chunk_send_attempted']} count={len(chunk_to_send)}",
            file=sys.stderr,
            flush=True,
        )

        success, status_code, error_text = self.send_to_api(chunk_to_send)

        if success:
            self.stats['urls_sent_total'] += len(chunk_to_send)
            self.stats['chunks_sent'] += 1
            self.stats['chunk_send_success'] += 1
            self.last_chunk_time = time.time()
            self.stats['chunk_send_last_status_code'] = status_code
            self.stats['chunk_send_last_error'] = None
            
            # Log progress
            elapsed = time.time() - self.start_time if self.start_time else 0
            print(f"[COLLECT] Chunk #{self.stats['chunks_sent']} sent: {len(chunk_to_send)} URLs "
                  f"(total_sent={self.stats['urls_sent_total']}, elapsed={elapsed:.1f}s)", 
                  file=sys.stderr, flush=True)
            
            # Send phase progress callback
            self.send_phase_progress()
        else:
            # On failure, restore chunk for retry
            self.pending_chunk = chunk_to_send + self.pending_chunk
            self.stats['chunk_send_failed'] += 1
            self.stats['chunk_send_last_status_code'] = status_code
            self.stats['chunk_send_last_error'] = error_text
            print(
                f"[COLLECT] chunk_send_failed status={status_code} error={error_text}",
                file=sys.stderr,
                flush=True,
            )
        
        return success

    def flush_pending_chunk(self, reason: str = "flush", final: bool = False): 
        """Flush pending chunk on stop/error/finish."""
        if not self.pending_chunk:
            self.log(f"{reason}: no pending chunk to flush")
            return True

        if not final and len(self.pending_chunk) < self.min_chunk_size:
            now_ts = time.time()
            should_log = (
                now_ts - self.last_skip_log_at >= 10
                or len(self.pending_chunk) in (1, self.min_chunk_size - 1)
            )
            if should_log:
                self.log(f"{reason}: skipping send because pending<{self.min_chunk_size} (size={len(self.pending_chunk)})")
                self.last_skip_log_at = now_ts
            return True

        self.log(f"{reason}: flushing pending chunk size={len(self.pending_chunk)}")
        return self.send_chunk()

    def send_phase_progress(self):
        """Send phase_progress callback to Laravel."""
        if not self.session_id:
            return
        
        try:
            api_base = self._get_api_base()
            callback_url = f"{api_base}/internal/parser/callback"
            token = os.environ.get('PARSER_CALLBACK_TOKEN', 'test-secret-parser-token')
            
            payload = {
                'session_id': self.session_id,
                'token': token,
                'type': 'phase_progress',
                'timestamp': int(time.time()),
                'event_id': f"collect_progress_{self.stats['chunks_sent']}_{int(time.time())}",
                'payload': {
                    'phase': 'collect',
                    'processed': self.stats['urls_sent_total'],
                    'total': self.max_urls,
                    'extra': {
                        'urls_found_total': self.stats['urls_found_total'],
                        'urls_unique_total': self.stats['urls_unique_total'],
                        'urls_sent_total': self.stats['urls_sent_total'],
                        'duplicates_dropped': self.stats['duplicates_dropped'],
                        'page_duplicates_dropped': self.stats['page_duplicates_dropped'],
                        'global_duplicates_dropped': self.stats['global_duplicates_dropped'],
                        'chunks_sent': self.stats['chunks_sent'],
                        'elapsed_seconds': time.time() - self.start_time if self.start_time else 0,
                    }
                }
            }
            
            headers = {
                'Content-Type': 'application/json',
                'Authorization': f'Bearer {token}',
            }
            
            requests.post(callback_url, json=payload, headers=headers, timeout=5)
        except Exception as e:
            print(f"[COLLECT] Warning: phase_progress callback failed: {e}", file=sys.stderr, flush=True)
    
    def create_adapter(self):
        """Создаёт адаптер поставщика."""
        self.log("Creating adapter...")
        
        try:
            adapter_class_path = self.config['adapter_class']
            module_path, class_name = adapter_class_path.rsplit('.', 1)
            module = importlib.import_module(module_path)
            adapter_class = getattr(module, class_name)
            self.adapter = adapter_class(self.config)

            # Attach log callback for adapter-level debug
            try:
                setattr(self.adapter, 'log_callback', self.send_log)
            except Exception:
                pass
            
            self.log(f"Adapter created: {adapter_class.__name__}")
        except Exception as e:
            self.log(f"ERROR creating adapter: {e}", level='error')
            raise
    
    def collect_urls(self) -> int:
        """
        Собирает URL из каталога поставщика с жёсткими лимитами.
        Uses chunked sending to avoid losing progress.
        
        ANTI-LOOP: Stops immediately when limit is reached.
        
        Returns:
            int: Number of unique URLs collected
        """
        if not self.config.get('collect_urls', False):
            self.log("URL collection disabled in config")
            return 0
        
        self.log("Starting URL collection with HARD LIMITS...")
        self.log(f"Limits: max_pages={self.max_pages}, max_urls={self.max_urls}, max_time={self.max_time_seconds}s")
        self.log(f"Chunk size: {self.chunk_size} URLs")
        
        self.start_time = time.time()
        self.last_chunk_time = time.time()
        
        try:
            # Инициализация адаптера (запуск браузера)
            self.adapter.setup()
            
            # Check if adapter has collect_urls_with_callback method
            if hasattr(self.adapter, 'collect_urls_with_callback'):
                # New style: adapter calls our callback for each URL
                self.adapter.collect_urls_with_callback(
                    callback=self.add_url,
                    max_pages=self.max_pages,
                    max_urls=self.max_urls,
                    max_time=self.max_time_seconds,
                    start_time=self.start_time,
                    check_limits=lambda: self.check_limits_and_handle(self.stats['urls_unique_total']),
                )
            else:
                # Fallback: get all URLs and add them one by one
                all_urls = self.adapter.collect_urls()
                
                for url in all_urls:
                    # Hard time limit check BEFORE processing
                    # Check limits before adding each URL
                    stop_reason = self.check_limits_and_handle(self.stats['urls_unique_total'])
                    if stop_reason:
                        break
                    
                    self.add_url(url)
            
            # Adopt adapter stop reason if present
            adapter_stop_reason = getattr(self.adapter, 'collect_stop_reason', None)
            if adapter_stop_reason and not self.stop_reason:
                self.stop_reason = adapter_stop_reason

            # Adopt adapter duplicate stats if present
            self.stats['page_duplicates_dropped'] += getattr(self.adapter, 'page_duplicates_dropped', 0)
            self.stats['global_duplicates_dropped'] += getattr(self.adapter, 'global_duplicates_dropped', 0)
            self.stats['duplicates_dropped'] = (
                self.stats['page_duplicates_dropped'] + self.stats['global_duplicates_dropped']
            )

            # Send any remaining URLs in pending chunk (always flush)
            self.flush_pending_chunk(reason="normal_finish", final=True)
            
            # Final limit check
            if not self.stop_reason:
                stop_reason = self.check_limits(self.stats['urls_unique_total'])
                if stop_reason:
                    self.stop_reason = stop_reason
            
            elapsed = time.time() - self.start_time
            
            return self.stats['urls_unique_total']
            
        except Exception as e:
            self.log(f"ERROR during collection: {e}", level='error')
            import traceback
            traceback.print_exc(file=sys.stderr)

            if not self.stop_reason:
                self.stop_reason = "COLLECT_EXCEPTION"
            
            # Try to send any pending URLs before failing
            self.flush_pending_chunk(reason="exception", final=True)
            
            return self.stats['urls_unique_total']
        finally:
            # Всегда закрываем браузер
            try:
                self.adapter.teardown()
            except:
                pass
    
    def determine_material_type(self, url: str) -> str:
        """
        Определяет тип материала по URL.
        
        Args:
            url: URL товара
            
        Returns:
            str: Тип материала (e.g., 'лдсп', 'мдф') или None
        """
        url_lower = url.lower()
        material_types = self.config.get('material_types', [])
        
        for material in material_types:
            if material.lower() in url_lower:
                return material
        
        # Fallback на filter_keywords
        filter_keywords = self.config.get('url_collection', {}).get('filter_keywords', [])
        for keyword in filter_keywords:
            if keyword.lower() in url_lower:
                return keyword
        
        return None
    
    def send_to_api(self, validated_urls: List[Dict[str, Any]]):
        """
        Отправляет собранные URL в Laravel API.
        
        Args:
            validated_urls: Список валидированных URL
        """
        import requests
        import hmac
        import hashlib
        import json
        
        api_base = self._get_api_base()
        api_url = f"{api_base}/parsing/save-urls"
        
        # Формируем payload
        payload = {
            'supplier_name': self.supplier_name,
            'urls': validated_urls,
            'collected_at': datetime.utcnow().isoformat() + 'Z'
        }
        
        # Сначала преобразуем в JSON - это будет отправлено запросом
        body_str = json.dumps(payload, separators=(',', ':'), sort_keys=True)
        
        # Генерируем HMAC на основе точного JSON который отправляем
        token = hmac.new(
            self.hmac_secret.encode(),
            body_str.encode(),
            hashlib.sha256
        ).hexdigest()
        
        headers = {
            'Content-Type': 'application/json',
            'X-HMAC-Signature': token,
            'X-Parser-Token': os.environ.get('PARSER_CALLBACK_TOKEN', 'test-secret-parser-token'),
        }
        
        try:
            print(f"[COLLECT] chunk_send_attempted: sending {len(validated_urls)} URLs to API...", file=sys.stderr, flush=True)
            
            # Отправляем точно строку которую использовали для подписи
            response = requests.post(
                api_url,
                data=body_str,  # Используем data с точным JSON, не json параметр
                headers=headers,
                timeout=30
            )
            
            if response.status_code == 200:
                print(f"[COLLECT] chunk_send_success status={response.status_code}", file=sys.stderr, flush=True)
                return True, response.status_code, None
            else:
                error_text = response.text[:200]
                print(f"[COLLECT] chunk_send_failed status={response.status_code} error={error_text}", file=sys.stderr, flush=True)
                return False, response.status_code, error_text
                
        except Exception as e:
            print(f"[COLLECT] chunk_send_failed exception={e}", file=sys.stderr, flush=True)
            import traceback
            traceback.print_exc(file=sys.stderr)
            return False, None, str(e)
    
    def run(self):
        """Основной метод выполнения сбора (DETERMINISTIC с chunked sending)."""
        self.log("========== STARTING URL COLLECTION ==========")
        self.log(f"Supplier: {self.supplier_name}")
        self.log(f"Session: {self.session_id}")
        self.log(f"Time: {datetime.now().isoformat()}")
        self.log(f"HARD LIMITS: max_pages={self.max_pages}, max_urls={self.max_urls}, max_time={self.max_time_seconds}s")
        
        # Start timer early to avoid zero stats on early exit
        self.start_time = time.time()

        try:
            # Send phase_started callback
            self.send_phase_callback('phase_started', {'phase': 'collect'})

            # 1. Загрузка конфига
            self.load_config()

            # 2. Создание адаптера
            self.create_adapter()

            # 3. Сбор URL с chunked sending
            self.collect_urls()
        finally:
            # Always flush pending chunk and send final stats
            self.flush_pending_chunk(reason="finally", final=True)

            final_stats = {
                'urls_found_total': self.stats['urls_found_total'],
                'urls_unique_total': self.stats['urls_unique_total'],
                'urls_sent_total': self.stats['urls_sent_total'],
                'duplicates_dropped': self.stats['duplicates_dropped'],
                'page_duplicates_dropped': self.stats['page_duplicates_dropped'],
                'global_duplicates_dropped': self.stats['global_duplicates_dropped'],
                'chunks_sent': self.stats['chunks_sent'],
                'chunk_send_attempted': self.stats['chunk_send_attempted'],
                'chunk_send_success': self.stats['chunk_send_success'],
                'chunk_send_failed': self.stats['chunk_send_failed'],
                'chunk_send_last_status_code': self.stats['chunk_send_last_status_code'],
                'chunk_send_last_error': self.stats['chunk_send_last_error'],
                'stop_reason': self.stop_reason or 'completed',
                'elapsed_seconds': time.time() - self.start_time if self.start_time else 0,
            }

            result = 'success' if self.stats['urls_sent_total'] > 0 else 'failed'
            self.send_phase_callback('phase_finished', {
                'phase': 'collect',
                'result': result,
                'stats': final_stats,
            })

            # Final stats print (single source of truth)
            self.log("========== COLLECTION COMPLETE ==========")
            self.log(f"urls_found_total: {self.stats['urls_found_total']}")
            self.log(f"urls_unique_total: {self.stats['urls_unique_total']}")
            self.log(f"urls_sent_total: {self.stats['urls_sent_total']}")
            self.log(f"duplicates_dropped: {self.stats['duplicates_dropped']}")
            self.log(f"page_duplicates_dropped: {self.stats['page_duplicates_dropped']}")
            self.log(f"global_duplicates_dropped: {self.stats['global_duplicates_dropped']}")
            self.log(f"chunk_send_attempted: {self.stats['chunk_send_attempted']}")
            self.log(f"chunk_send_success: {self.stats['chunk_send_success']}")
            self.log(f"chunk_send_failed: {self.stats['chunk_send_failed']}")
            self.log(f"stop_reason: {self.stop_reason or 'completed'}")

        # Return 0 if any URLs were sent (even partial success)
        return 0 if self.stats['urls_sent_total'] > 0 else 1

    def send_phase_callback(self, callback_type: str, payload: dict):
        """Send phase callback to Laravel."""
        if not self.session_id:
            return
        
        try:
            api_base = self._get_api_base()
            callback_url = f"{api_base}/internal/parser/callback"
            token = os.environ.get('PARSER_CALLBACK_TOKEN', 'test-secret-parser-token')
            
            data = {
                'session_id': self.session_id,
                'token': token,
                'type': callback_type,
                'timestamp': int(time.time()),
                'event_id': f"{callback_type}_{int(time.time())}",
                'payload': payload,
            }
            
            headers = {
                'Content-Type': 'application/json',
                'Authorization': f'Bearer {token}',
            }
            
            response = requests.post(callback_url, json=data, headers=headers, timeout=10)
            print(f"[COLLECT] {callback_type} callback: HTTP {response.status_code}", file=sys.stderr, flush=True)
        except Exception as e:
            print(f"[COLLECT] Warning: {callback_type} callback failed: {e}", file=sys.stderr, flush=True)

    def send_log(self, level: str, message: str, details: Optional[dict] = None) -> None:
        """Send log callback to Laravel."""
        if not self.session_id:
            return

        try:
            api_base = self._get_api_base()
            callback_url = f"{api_base}/internal/parser/callback"
            token = os.environ.get('PARSER_CALLBACK_TOKEN', 'test-secret-parser-token')

            self._log_seq += 1
            payload = {
                'level': level,
                'message': message,
            }
            if details is not None:
                payload['details'] = details

            data = {
                'session_id': self.session_id,
                'token': token,
                'type': 'log',
                'timestamp': int(time.time()),
                'event_id': f"log_{self._log_seq}_{int(time.time() * 1000)}",
                'payload': payload,
            }

            headers = {
                'Content-Type': 'application/json',
                'Authorization': f'Bearer {token}',
            }

            response = requests.post(callback_url, json=data, headers=headers, timeout=5)
            if response.status_code != 200:
                print(f"[COLLECT] Warning: log callback HTTP {response.status_code}: {response.text[:200]}", file=sys.stderr, flush=True)
        except Exception as e:
            print(f"[COLLECT] Warning: log callback failed: {e}", file=sys.stderr, flush=True)

    def _get_api_base(self) -> str:
        if self.api_url_base:
            return self.api_url_base.rstrip('/')

        raw = os.environ.get('PARSER_API_URL')
        if raw:
            raw = raw.rstrip('/')
            if raw.endswith('/parsing/save-urls'):
                return raw[: -len('/parsing/save-urls')]
            return raw

        return 'http://smeta_web:80/api'


def main():
    """Entry point."""
    parser = argparse.ArgumentParser(
        description='Collect and validate URLs from supplier catalog'
    )
    parser.add_argument(
        '--supplier',
        required=True,
        help='Supplier name (e.g., skm_mebel)'
    )
    parser.add_argument(
        '--hmac-secret',
        required=True,
        help='HMAC secret for API authentication'
    )
    parser.add_argument(
        '--session',
        type=int,
        default=None,
        help='Session ID for logging (optional)'
    )
    parser.add_argument(
        '--api-url',
        default=None,
        help='Base API URL (e.g., http://localhost/api)'
    )
    parser.add_argument(
        '--config-override-base64',
        default=None,
        help='Base64-encoded JSON config override for URL collection'
    )
    
    args = parser.parse_args()
    
    # Создаём и запускаем сборщик
    collector = UrlCollector(
        supplier_name=args.supplier,
        hmac_secret=args.hmac_secret,
        session_id=args.session,
        api_url_base=args.api_url
    )

    if args.config_override_base64:
        try:
            decoded = base64.b64decode(args.config_override_base64).decode('utf-8')
            override = json.loads(decoded)
            if isinstance(override, dict):
                collector.config_override = override
        except Exception as e:
            print(f"[COLLECT] Invalid config override: {e}", file=sys.stderr, flush=True)
    
    exit_code = collector.run()
    sys.exit(exit_code)


if __name__ == '__main__':
    main()
