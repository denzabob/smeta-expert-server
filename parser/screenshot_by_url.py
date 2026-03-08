#!/usr/bin/env python3
"""
Screenshot capture entrypoint called by ScreenshotCaptureService.php.

Strategy:
  1. If the URL domain has a known supplier adapter — use that adapter directly.
     The adapter already has resource-blocking, stealth headers and Cloudflare bypass
     that proved to work in production bulk parsing.
  2. For unknown domains — fall back to a generic capture with the same
     resource-blocking setup used by the adapter (avoids bare headless detection).
"""
import argparse
import hashlib
import json
import random
import re
import sys
import time
from datetime import datetime
from pathlib import Path
from urllib.parse import parse_qsl, urlencode, urlparse, urlunparse

from playwright.sync_api import sync_playwright, TimeoutError as PlaywrightTimeoutError

# ── Supplier adapter registry (domain → config_file, adapter_class) ─────────
_SUPPLIER_REGISTRY = {
    "skm-mebel.ru": ("skm_mebel", "skm_mebel", "SkmMebelAdapter"),
}

TRACKING_PARAMS = {
    "gclid", "yclid", "fbclid", "etext", "ybaip",
    "pm_source", "callibri", "_openstat",
}

USER_AGENTS = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36",
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 14_7) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
]

# Third-party tracker/analytics patterns to always block
_TRACKER_PATTERNS = [
    ".woff", ".woff2", ".ttf", ".eot",
    ".mp4", ".webm", ".ogg", ".mp3",
    "google-analytics", "googletagmanager", "yandex", "metrika",
    "facebook", "vk.com/rtrg", "mc.yandex", "top-fwz1",
    "/tracker", "/analytics", "/pixel", "jivosite", "carrotquest",
    "counters", "beacon", "collect",
]

# Stealth headers to avoid bot detection (used by both generic and adapter fallback)
_STEALTH_HEADERS = {
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
    "Accept-Language": "ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7",
    "Accept-Encoding": "gzip, deflate, br",
    "Sec-Fetch-Dest": "document",
    "Sec-Fetch-Mode": "navigate",
    "Sec-Fetch-Site": "none",
    "Sec-Fetch-User": "?1",
    "Upgrade-Insecure-Requests": "1",
}


# ── Helpers ──────────────────────────────────────────────────────────────────

def normalize_url(url: str) -> str:
    p = urlparse(url)
    if not p.netloc:
        return url[:2048]
    query = []
    for k, v in parse_qsl(p.query, keep_blank_values=True):
        lk = k.lower()
        if lk.startswith("utm_") or lk in TRACKING_PARAMS:
            continue
        query.append((k, v))
    query.sort(key=lambda x: x[0].lower())
    normalized = urlunparse((
        p.scheme or "https", p.netloc.lower(), p.path or "/",
        "", urlencode(query, doseq=True), "",
    ))
    return normalized[:2048]


def _detect_supplier(url: str):
    """Return (config_file, module_name, class_name) or None."""
    host = (urlparse(url).hostname or "").replace("www.", "")
    return _SUPPLIER_REGISTRY.get(host)


def _load_adapter(config_name: str, module_name: str, class_name: str):
    """Dynamically load a supplier adapter and its config."""
    import importlib
    project_root = Path(__file__).resolve().parent

    config_path = project_root / "configs" / f"{config_name}.json"
    if not config_path.exists():
        raise FileNotFoundError(f"Supplier config not found: {config_path}")

    import json as _json
    with open(config_path) as f:
        config = _json.load(f)

    # Support running both as module (parser.suppliers.X) and as script
    try:
        mod = importlib.import_module(f".suppliers.{module_name}", package="parser")
    except ImportError:
        sys.path.insert(0, str(project_root.parent))
        mod = importlib.import_module(f"parser.suppliers.{module_name}")

    cls = getattr(mod, class_name)
    return cls(config), config


def _make_generic_path(normalized_url: str) -> tuple:
    """Build output path for generic capture: screenshots/<domain>/<date>/<hash12>_<time>.jpg"""
    now = time.localtime()
    domain = (urlparse(normalized_url).hostname or "unknown").replace("www.", "").replace(".", "_")
    url_hash = hashlib.md5(normalized_url.encode()).hexdigest()[:12]
    timestamp = time.strftime("%H%M%S", now)
    date_str = time.strftime("%Y-%m-%d", now)
    relative = f"screenshots/{domain}/{date_str}/{domain}_{url_hash}_{timestamp}.jpg"
    project_root = Path(__file__).resolve().parent.parent
    full = project_root / "server" / "storage" / "app" / "public" / relative
    full.parent.mkdir(parents=True, exist_ok=True)
    return full, relative


def _make_route_handler(allow_host: str):
    """Returns a route handler that allows images from allow_host, blocks trackers."""
    def handler(route):
        url = route.request.url.lower()
        resource_type = route.request.resource_type
        # Always allow everything from the target host (product images etc.)
        if allow_host and allow_host in url:
            route.continue_()
            return
        # Block trackers and third-party heavy resources
        for pat in _TRACKER_PATTERNS:
            if pat in url:
                route.abort()
                return
        # Block third-party fonts and media
        if resource_type in ("font", "media"):
            route.abort()
            return
        route.continue_()
    return handler


def _classify_blocked(status, html: str) -> bool:
    if status in (403, 429):
        return True
    lower = html.lower()
    # Strong indicators — these definitively mean blocked
    strong = [
        r"verify you are human",
        r"ddos.{0,20}protection",
        r"enable javascript and cookies",
        r"checking your browser",
        r"just a moment\.\.\.",
        r"please wait while we check",
    ]
    if any(re.search(p, lower) for p in strong):
        return True
    # Weak indicators — only count if page has NO actual content
    # (avoids false positives from analytics scripts containing these words)
    has_content = bool(re.search(r'<(h1|h2|article|main|product|price)[^>]*>', lower))
    if has_content:
        return False
    weak = [r"cloudflare", r"access denied"]
    return any(re.search(p, lower) for p in weak)


# ── Capture strategies ───────────────────────────────────────────────────────

def capture_via_adapter(url: str, entry) -> dict:
    """
    Use the supplier-specific adapter (e.g. SkmMebelAdapter).
    The adapter has proven Cloudflare bypass and resource blocking.
    """
    config_name, module_name, class_name = entry
    adapter, _ = _load_adapter(config_name, module_name, class_name)
    adapter.setup()
    try:
        result = adapter.parse_product_page(url, take_screenshot=True)
        if result.screenshot_path:
            return {
                "status": "ok",
                "screenshot_path": result.screenshot_path,
                "meta": {
                    "strategy": "adapter",
                    "adapter": class_name,
                    "normalized_url": normalize_url(url),
                    "extracted_price": result.price_per_unit,
                },
            }
        return {
            "status": "error",
            "screenshot_path": None,
            "meta": {"strategy": "adapter", "error": "screenshot_path empty"},
        }
    except RuntimeError as e:
        err = str(e)
        # Map adapter errors to standard status codes
        if "not a product page" in err.lower():
            return {"status": "blocked", "screenshot_path": None,
                    "meta": {"strategy": "adapter", "error": err}}
        return {"status": "error", "screenshot_path": None,
                "meta": {"strategy": "adapter", "error": err}}
    finally:
        adapter.teardown()


def capture_generic(url: str, price: str, currency: str, region_id: int) -> dict:
    """
    Universal screenshot capture for any domain.
    - Allows product images from the target host (so photos appear in screenshots)
    - Blocks only trackers and third-party heavy resources
    - Retries once on soft Cloudflare challenges
    - Saves as JPEG (compatible with PHP GD without WebP support)
    """
    normalized = normalize_url(url)
    host = (urlparse(normalized).hostname or "").replace("www.", "")
    file_path, relative = _make_generic_path(normalized)

    ua = random.choice(USER_AGENTS)
    route_handler = _make_route_handler(host)

    with sync_playwright() as p:
        browser = p.chromium.launch(
            headless=True,
            args=["--disable-dev-shm-usage", "--no-sandbox", "--disable-gpu"],
        )
        context = browser.new_context(
            viewport={"width": 1280, "height": 720},
            user_agent=ua,
            java_script_enabled=True,
            ignore_https_errors=True,
            extra_http_headers=_STEALTH_HEADERS,
        )
        context.route("**/*", route_handler)
        page = context.new_page()
        try:
            resp = page.goto(url, wait_until="domcontentloaded", timeout=25000)
            status = resp.status if resp else None

            try:
                page.wait_for_load_state("networkidle", timeout=8000)
            except PlaywrightTimeoutError:
                pass

            time.sleep(random.uniform(0.5, 1.0))
            html = page.content()

            # Soft Cloudflare challenge: retry once after short wait
            if _classify_blocked(status, html):
                time.sleep(3)
                try:
                    page.wait_for_load_state("networkidle", timeout=8000)
                except PlaywrightTimeoutError:
                    pass
                html = page.content()
                if _classify_blocked(status, html):
                    return {"status": "blocked", "screenshot_path": None,
                            "meta": {"http_status": status, "normalized_url": normalized,
                                     "strategy": "generic"}}

            # Save as JPEG (PHP GD compatible, no WebP needed)
            temp_png = file_path.with_suffix(".png")
            page.screenshot(path=str(temp_png), full_page=False, timeout=10000)
            try:
                from PIL import Image as PilImage
                with PilImage.open(temp_png) as img:
                    img.convert("RGB").save(str(file_path), "JPEG", quality=85, optimize=True)
                temp_png.unlink(missing_ok=True)
            except Exception:
                # PIL not available — keep PNG and update path
                png_relative = relative.replace(".jpg", ".png")
                temp_png.rename(file_path.with_suffix(".png"))
                return {"status": "ok", "screenshot_path": png_relative,
                        "meta": {"http_status": status, "normalized_url": normalized,
                                 "strategy": "generic"}}

            return {"status": "ok", "screenshot_path": relative,
                    "meta": {"http_status": status, "normalized_url": normalized,
                             "strategy": "generic"}}
        except PlaywrightTimeoutError:
            return {"status": "timeout", "screenshot_path": None,
                    "meta": {"normalized_url": normalized, "strategy": "generic"}}
        except Exception as e:
            return {"status": "error", "screenshot_path": None,
                    "meta": {"error": str(e), "normalized_url": normalized,
                             "strategy": "generic"}}
        finally:
            context.close()
            browser.close()


def capture(url: str, price: str, currency: str, region_id: int) -> dict:
    """
    Main capture dispatcher.
    Try adapter first (if domain is known), fall back to generic.
    """
    entry = _detect_supplier(url)
    if entry:
        try:
            return capture_via_adapter(url, entry)
        except Exception as e:
            # Adapter loading or runtime failure — fall back to generic
            print(f"[screenshot_by_url] adapter failed ({e}), falling back to generic",
                  file=sys.stderr, flush=True)

    return capture_generic(url, price, currency, region_id)


def main():
    parser = argparse.ArgumentParser(description="Capture first-screen screenshot by URL")
    parser.add_argument("--url", required=True)
    parser.add_argument("--price", required=True)
    parser.add_argument("--currency", default="RUB")
    parser.add_argument("--region-id", type=int, default=0)
    parser.add_argument("--material-id", type=int, default=0)
    parser.add_argument("--revision-run-item-id", type=int, default=0)
    args = parser.parse_args()

    result = capture(args.url, args.price, args.currency, args.region_id)
    print(json.dumps(result, ensure_ascii=False))
    sys.exit(0)


if __name__ == "__main__":
    main()
