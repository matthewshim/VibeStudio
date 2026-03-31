#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
collect.py — Signal AI 뉴스 수집기
- pip 없이 Python 표준 라이브러리만 사용
- 수집 결과를 /tmp/collected_news.json 에 저장
- 이후 collect_save.php 가 DB에 INSERT

실행: python3 /opt/bitnami/apache2/htdocs/digest/collect.py
Cron: 50 22 * * * python3 /opt/bitnami/apache2/htdocs/digest/collect.py
"""

import urllib.request
import urllib.error
import xml.etree.ElementTree as ET
import json
import re
import os
import sys
from datetime import datetime, timezone, timedelta

# ── 설정 ───────────────────────────────────────────────────────────────
OUTPUT_FILE   = '/tmp/collected_news.json'
LOG_FILE      = '/tmp/signal_collect.log'
MAX_PER_SRC   = 10   # 소스당 최대 수집 건수
MAX_AGE_HOURS = 36   # 이 시간 이전 기사는 스킵

SOURCES = [
    # ── 기존 소스 (6개) ─────────────────────────────────────────────
    {
        "id": 1,
        "name": "arXiv cs.AI",
        "url": "https://export.arxiv.org/rss/cs.AI",
        "type": "rss",
        "category": "research",
        "weight": 1.5
    },
    {
        "id": 2,
        "name": "Hacker News AI",
        "url": "https://hn.algolia.com/api/v1/search?tags=story&query=AI&numericFilters=points%3E10&hitsPerPage=20",
        "type": "json_api",
        "category": "tech",
        "weight": 1.2
    },
    {
        "id": 4,
        "name": "Google AI Blog",
        "url": "https://blog.google/technology/ai/rss",
        "type": "rss",
        "category": "bigtech",
        "weight": 1.4
    },
    {
        "id": 5,
        "name": "VentureBeat AI",
        "url": "https://venturebeat.com/category/ai/feed",
        "type": "rss",
        "category": "industry",
        "weight": 1.1
    },
    {
        "id": 6,
        "name": "MIT Tech Review",
        "url": "https://www.technologyreview.com/feed",
        "type": "rss",
        "category": "research",
        "weight": 1.3
    },
    {
        "id": 7,
        "name": "The Verge AI",
        "url": "https://www.theverge.com/rss/ai-artificial-intelligence/index.xml",
        "type": "rss",
        "category": "industry",
        "weight": 1.0
    },
    # ── 신규 소스 P0 — 빅테크 공식 채널 (2026-03-30 추가) ───────────
    {
        "id": 8,
        "name": "OpenAI News",
        "url": "https://openai.com/news/rss.xml",
        "type": "rss",
        "category": "bigtech",
        "weight": 1.5
    },
    {
        "id": 9,
        "name": "Google Research Blog",
        "url": "https://research.google/blog/rss/",
        "type": "rss",
        "category": "research",
        "weight": 1.4
    },
    {
        "id": 10,
        "name": "Google Cloud AI Blog",
        "url": "https://blog.google/products/google-cloud/rss/",
        "type": "rss",
        "category": "bigtech",
        "weight": 1.3
    },
    # ── 신규 소스 P1 — 미디어·커뮤니티 (주말 활성 높음) ────────────
    {
        "id": 11,
        "name": "TechCrunch AI",
        "url": "https://techcrunch.com/category/artificial-intelligence/feed/",
        "type": "rss",
        "category": "bigmedia",
        "weight": 1.2
    },
    {
        "id": 12,
        "name": "Reddit MachineLearning",
        "url": "https://www.reddit.com/r/MachineLearning/.rss?limit=20",
        "type": "rss",
        "category": "community",
        "weight": 1.1
    },
    {
        "id": 13,
        "name": "Wired AI",
        "url": "https://www.wired.com/feed/tag/ai/latest/rss",
        "type": "rss",
        "category": "bigmedia",
        "weight": 1.2
    },
]

# ── 로깅 ───────────────────────────────────────────────────────────────
def log(msg):
    ts  = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    line = f"[{ts}] {msg}"
    print(line)
    try:
        with open(LOG_FILE, 'a', encoding='utf-8') as f:
            f.write(line + '\n')
    except Exception:
        pass

# ── HTTP 요청 ──────────────────────────────────────────────────────────
def fetch_url(url, timeout=15):
    req = urllib.request.Request(
        url,
        headers={
            'User-Agent': 'Signal-Bot/1.0 (vibestudio.prisincera.com; AI news aggregator)',
            'Accept': 'application/rss+xml, application/xml, text/xml, application/json, */*'
        }
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return resp.read().decode('utf-8', errors='replace')
    except Exception as e:
        log(f"  ✗ fetch error: {e}")
        return None

# ── 날짜 파싱 ──────────────────────────────────────────────────────────
def parse_date(date_str):
    """RSS pubDate 등 다양한 형식 → datetime (UTC)"""
    if not date_str:
        return datetime.now(timezone.utc)
    for fmt in [
        '%a, %d %b %Y %H:%M:%S %z',
        '%a, %d %b %Y %H:%M:%S GMT',
        '%Y-%m-%dT%H:%M:%S.%fZ',
        '%Y-%m-%dT%H:%M:%SZ',
        '%Y-%m-%dT%H:%M:%S%z',
        '%Y-%m-%dT%H:%M:%S.%f%z',
        '%Y-%m-%d',
    ]:
        try:
            dt = datetime.strptime(date_str.strip(), fmt)
            if dt.tzinfo is None:
                dt = dt.replace(tzinfo=timezone.utc)
            return dt
        except ValueError:
            continue
    return datetime.now(timezone.utc)

def is_too_old(dt):
    cutoff = datetime.now(timezone.utc) - timedelta(hours=MAX_AGE_HOURS)
    return dt < cutoff

# ── RSS 파싱 ───────────────────────────────────────────────────────────
def parse_rss(xml_text, source):
    items = []
    try:
        # namespace prefix 전체 제거 (arXiv dc:creator, media:content 등 처리)
        xml_clean = re.sub(r'<(/?)[a-zA-Z][a-zA-Z0-9]*:([a-zA-Z])', r'<\1\2', xml_text)
        xml_clean = re.sub(r' [a-zA-Z][a-zA-Z0-9]*:[a-zA-Z][^=\s>]*\s*=\s*"[^"]*"', '', xml_clean)
        xml_clean = re.sub(r'xmlns(?::[a-zA-Z][a-zA-Z0-9]*)?\s*=\s*"[^"]*"', '', xml_clean)
        root = ET.fromstring(xml_clean)
    except ET.ParseError as e:
        log(f"  ✗ XML parse error: {e}")
        return items

    # <item> 또는 <entry> (Atom)
    entries = root.findall('.//item') or root.findall('.//{http://www.w3.org/2005/Atom}entry')
    for entry in entries[:MAX_PER_SRC]:
        try:
            title = (entry.findtext('title') or
                     entry.findtext('{http://www.w3.org/2005/Atom}title') or '').strip()
            url   = (entry.findtext('link') or
                     entry.findtext('{http://www.w3.org/2005/Atom}link') or '').strip()
            pubdt = (entry.findtext('pubDate') or
                     entry.findtext('published') or
                     entry.findtext('{http://www.w3.org/2005/Atom}published') or '')

            # Atom <link href="...">
            if not url:
                link_el = entry.find('{http://www.w3.org/2005/Atom}link')
                if link_el is not None:
                    url = link_el.get('href', '')

            if not title or not url:
                continue

            pub_dt = parse_date(pubdt)
            if is_too_old(pub_dt):
                continue

            items.append({
                'title':        title[:500],
                'url':          url[:1000],
                'source_id':    source['id'],
                'source_name':  source['name'],
                'category':     source['category'],
                'source_weight':source['weight'],
                'hn_points':    0,
                'published_at': pub_dt.strftime('%Y-%m-%d %H:%M:%S'),
            })
        except Exception as e:
            log(f"  ✗ item parse error: {e}")
            continue
    return items

# ── Hacker News JSON API ───────────────────────────────────────────────
def parse_hn(json_text, source):
    items = []
    try:
        data = json.loads(json_text)
        hits = data.get('hits', [])
    except json.JSONDecodeError as e:
        log(f"  ✗ JSON parse error: {e}")
        return items

    for hit in hits[:MAX_PER_SRC]:
        try:
            title  = hit.get('title', '').strip()
            url    = hit.get('url') or f"https://news.ycombinator.com/item?id={hit.get('objectID','')}"
            points = hit.get('points', 0) or 0
            ts     = hit.get('created_at', '')

            if not title:
                continue

            pub_dt = parse_date(ts)
            if is_too_old(pub_dt):
                continue

            items.append({
                'title':        title[:500],
                'url':          url[:1000],
                'source_id':    source['id'],
                'source_name':  source['name'],
                'category':     source['category'],
                'source_weight':source['weight'],
                'hn_points':    points,
                'published_at': pub_dt.strftime('%Y-%m-%d %H:%M:%S'),
            })
        except Exception as e:
            log(f"  ✗ HN item error: {e}")
            continue
    return items

# ── 점수 산정 ──────────────────────────────────────────────────────────
def calc_score(item):
    from datetime import datetime as dt2
    score = 0.0

    # 소스 신뢰도 (최대 3점)
    score += item['source_weight'] * 2

    # 최신성 (최대 3점) — 24시간 기준
    try:
        pub = dt2.strptime(item['published_at'], '%Y-%m-%d %H:%M:%S').replace(tzinfo=timezone.utc)
        hours_old = (datetime.now(timezone.utc) - pub).total_seconds() / 3600
        score += max(0.0, 3.0 - (hours_old / 8))
    except Exception:
        pass

    # HN 포인트 (최대 2점)
    if item['hn_points'] > 0:
        score += min(2.0, item['hn_points'] / 50.0)

    # 카테고리 보너스 (최대 2점)
    bonus = {
        'research':  2.0,
        'bigtech':   1.8,
        'bigmedia':  1.5,
        'tools':     1.5,
        'korea':     2.0,
        'tech':      1.3,
        'industry':  1.2,
        'community': 1.1,
    }
    score += bonus.get(item['category'], 1.0)

    return round(score, 2)

# ── 메인 ───────────────────────────────────────────────────────────────
def main():
    log("=" * 50)
    log("Signal collect.py 시작")
    log(f"수집 소스: {len(SOURCES)}개")

    all_items = []

    for src in SOURCES:
        log(f"\n[{src['name']}] 수집 중...")
        raw = fetch_url(src['url'])
        if not raw:
            log(f"  ✗ 수집 실패, 스킵")
            continue

        if src['type'] == 'json_api':
            items = parse_hn(raw, src)
        else:
            items = parse_rss(raw, src)

        log(f"  ✓ {len(items)}건 수집")
        all_items.extend(items)

    # 중복 URL 제거 (같은 run 내에서)
    seen_urls = set()
    unique_items = []
    for item in all_items:
        if item['url'] not in seen_urls:
            seen_urls.add(item['url'])
            item['score'] = calc_score(item)
            unique_items.append(item)

    # 점수 내림차순 정렬
    unique_items.sort(key=lambda x: x['score'], reverse=True)

    log(f"\n총 수집: {len(unique_items)}건 (중복 제거 후)")

    # JSON 파일 저장
    output = {
        'collected_at': datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S'),
        'count':        len(unique_items),
        'items':        unique_items
    }
    with open(OUTPUT_FILE, 'w', encoding='utf-8') as f:
        json.dump(output, f, ensure_ascii=False, indent=2)

    log(f"✓ 저장 완료: {OUTPUT_FILE}")
    log("=" * 50)

if __name__ == '__main__':
    main()
