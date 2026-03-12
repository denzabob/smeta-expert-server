#!/usr/bin/env python3
"""
Screenshot capture entrypoint called by ScreenshotCaptureService.php.
"""
import argparse
import hashlib
import json
import os
import random
import re
import sys
import time
from pathlib import Path
from urllib.parse import parse_qsl, urlencode, urlparse, urlunparse

from playwright.sync_api import sync_playwright, TimeoutError as PlaywrightTimeoutError

TRACKING_PARAMS = {
    "gclid", "yclid", "fbclid", "etext", "ybaip",
    "pm_source", "callibri", "_openstat",
}

VIEWPORT_WIDTH = 1280
VIEWPORT_HEIGHT = 900

REAL_USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/120.0.0.0 Safari/537.36"
)

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
    "Accept": "text/html,application/xhtml+xml",
    "Accept-Language": "ru-RU,ru;q=0.9",
    "Upgrade-Insecure-Requests": "1",
}


# Helpers

def _log_info(event: str, **fields):
    payload = " ".join(f"{k}={v}" for k, v in fields.items() if v is not None)
    if payload:
        print(f"INFO: {event} {payload}", file=sys.stderr, flush=True)
    else:
        print(f"INFO: {event}", file=sys.stderr, flush=True)


def _log_error(event: str, **fields):
    payload = " ".join(f"{k}={v}" for k, v in fields.items() if v is not None)
    if payload:
        print(f"ERROR: {event} {payload}", file=sys.stderr, flush=True)
    else:
        print(f"ERROR: {event}", file=sys.stderr, flush=True)


def _remaining_ms(started_at: float, total_timeout_seconds: int) -> int:
    elapsed = time.monotonic() - started_at
    return int((total_timeout_seconds * 1000) - (elapsed * 1000))


def _step_timeout_ms(desired_timeout_ms: int, started_at: float, total_timeout_seconds: int) -> int:
    remaining = _remaining_ms(started_at, total_timeout_seconds)
    if remaining <= 0:
        raise PlaywrightTimeoutError("overall_timeout")
    return max(1, min(desired_timeout_ms, remaining))


def _detect_cloudflare(page) -> bool:
    try:
        title = (page.title() or "").lower()
    except Exception:
        title = ""

    if "just a moment" in title or "checking your browser" in title:
        return True

    try:
        return page.query_selector("#challenge-running") is not None
    except Exception:
        return False


def _classify_navigation_error(error_text: str) -> str:
    lower = error_text.lower()
    blocked_markers = [
        "cloudflare",
        "just a moment",
        "checking your browser",
        "timeout",
        "navigation",
        "net::",
        "err_",
    ]
    if any(marker in lower for marker in blocked_markers):
        return "blocked"
    return "error"


def _vendor_from_host(host: str) -> str:
    vendor = (host or "unknown").replace("www.", "")
    return re.sub(r"[^a-zA-Z0-9._-]", "_", vendor)[:80]


def _build_screenshot_path(normalized_url: str) -> tuple[Path, str]:
    host = (urlparse(normalized_url).hostname or "unknown")
    vendor = _vendor_from_host(host)
    date_str = time.strftime("%Y-%m-%d", time.localtime())
    url_hash = hashlib.sha1(normalized_url.encode("utf-8")).hexdigest()[:24]

    relative = f"screenshots/{vendor}/{date_str}/{url_hash}.jpg"
    project_root = Path(__file__).resolve().parent.parent
    full = project_root / "server" / "storage" / "app" / "public" / relative
    full.parent.mkdir(parents=True, exist_ok=True)
    return full, relative


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

def _make_route_handler(allow_host: str):
    """Allow first-party requests and block known trackers/heavy resources."""

    def handler(route):
        url = route.request.url.lower()
        resource_type = route.request.resource_type

        # Keep first-party resources to make screenshot realistic.
        if allow_host and allow_host in url:
            route.continue_()
            return

        for pat in _TRACKER_PATTERNS:
            if pat in url:
                route.abort()
                return

        if resource_type in ("font", "media"):
            route.abort()
            return

        route.continue_()

    return handler


def capture_generic(url: str, price: str, currency: str, region_id: int) -> dict:
    del price, currency, region_id

    started_at = time.monotonic()
    navigation_timeout_ms = int(os.getenv("SCREENSHOT_NAVIGATION_TIMEOUT_MS", "20000"))
    total_timeout_seconds = int(os.getenv("SCREENSHOT_TOTAL_TIMEOUT_SECONDS", "45"))

    # Try up to 2 attempts (initial + 1 retry)
    last_result = None
    for attempt in range(1, 3):
        result = _capture_attempt(url, attempt, started_at, navigation_timeout_ms, total_timeout_seconds)
        if result["status"] == "ok":
            return result
        last_result = result
        # Only retry if there's enough time budget left
        elapsed = time.monotonic() - started_at
        if elapsed > total_timeout_seconds * 0.7:
            break
        if attempt < 2:
            _log_info("screenshot.retry", url=url, attempt=str(attempt + 1))
            time.sleep(random.uniform(1.0, 2.0))

    return last_result


def _capture_attempt(url: str, attempt: int, started_at: float, navigation_timeout_ms: int, total_timeout_seconds: int) -> dict:

    normalized = normalize_url(url)
    host = (urlparse(normalized).hostname or "").replace("www.", "")
    file_path, relative = _build_screenshot_path(normalized)

    _log_info("screenshot.start", url=normalized)

    route_handler = _make_route_handler(host)
    cloudflare_detected = False

    browser = None
    context = None

    with sync_playwright() as p:
        try:
            browser = p.chromium.launch(
                headless=True,
                args=[
                    "--no-sandbox",
                    "--disable-setuid-sandbox",
                    "--disable-blink-features=AutomationControlled",
                    "--disable-dev-shm-usage",
                ],
            )

            context = browser.new_context(
                viewport={"width": VIEWPORT_WIDTH, "height": VIEWPORT_HEIGHT},
                device_scale_factor=1,
                user_agent=REAL_USER_AGENT,
                locale="ru-RU",
                java_script_enabled=True,
                ignore_https_errors=True,
                extra_http_headers=_STEALTH_HEADERS,
            )
            context.add_init_script(
                "Object.defineProperty(navigator, 'webdriver', {get: () => undefined});"
            )
            context.route("**/*", route_handler)

            page = context.new_page()

            # Random delay 1-3s before navigation to mimic human behavior
            time.sleep(random.uniform(1.0, 3.0))

            goto_timeout = _step_timeout_ms(navigation_timeout_ms, started_at, total_timeout_seconds)
            resp = page.goto(url, wait_until="domcontentloaded", timeout=goto_timeout)
            status = resp.status if resp else None

            if status in (403, 429):
                _log_info("screenshot.cloudflare_detected", url=normalized, http_status=status)
                return {
                    "status": "blocked",
                    "screenshot_path": None,
                    "meta": {
                        "http_status": status,
                        "normalized_url": normalized,
                        "cloudflare_detected": True,
                    },
                }

            try:
                idle_timeout = _step_timeout_ms(15000, started_at, total_timeout_seconds)
                page.wait_for_load_state("networkidle", timeout=idle_timeout)
            except PlaywrightTimeoutError:
                pass

            if _detect_cloudflare(page):
                cloudflare_detected = True
                _log_info("screenshot.cloudflare_detected", url=normalized)

                challenge_deadline = time.monotonic() + 20.0
                while time.monotonic() < challenge_deadline:
                    if not _detect_cloudflare(page):
                        break
                    time.sleep(1.0)

                if _detect_cloudflare(page):
                    return {
                        "status": "blocked",
                        "screenshot_path": None,
                        "meta": {
                            "http_status": status,
                            "normalized_url": normalized,
                            "cloudflare_detected": True,
                        },
                    }

            screenshot_timeout = _step_timeout_ms(10000, started_at, total_timeout_seconds)
            page.screenshot(
                path=str(file_path),
                full_page=False,
                type="jpeg",
                quality=80,
                clip={
                    "x": 0,
                    "y": 0,
                    "width": VIEWPORT_WIDTH,
                    "height": VIEWPORT_HEIGHT,
                },
                timeout=screenshot_timeout,
            )

            _log_info("screenshot.saved", path=relative)

            return {
                "status": "ok",
                "screenshot_path": relative,
                "meta": {
                    "http_status": status,
                    "normalized_url": normalized,
                    "cloudflare_detected": cloudflare_detected,
                },
            }
        except PlaywrightTimeoutError:
            _log_error("screenshot.failed", reason="timeout", url=normalized)
            return {
                "status": "blocked",
                "screenshot_path": None,
                "meta": {
                    "error": "timeout",
                    "normalized_url": normalized,
                    "cloudflare_detected": cloudflare_detected,
                },
            }
        except Exception as e:
            error_text = str(e)
            status = _classify_navigation_error(error_text)
            _log_error("screenshot.failed", reason=error_text[:200], url=normalized)
            return {
                "status": status,
                "screenshot_path": None,
                "meta": {
                    "error": error_text,
                    "normalized_url": normalized,
                    "cloudflare_detected": cloudflare_detected,
                },
            }
        finally:
            if context:
                context.close()
            if browser:
                browser.close()


def capture(url: str, price: str, currency: str, region_id: int) -> dict:
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
