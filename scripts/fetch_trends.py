"""Fetch Google Trends "trending now" searches for several countries and keep a 7-day history.

Usage: python scripts/fetch_trends.py <previous trends.json or ''> <output trends.json>

Runs hourly from .github/workflows/trends.yml. Standard library only.
Each run adds the current top 10 per country, merges it with earlier runs, and
drops anything not seen for 7 days, so the site can show what is rising now
and what was searched most this week.
"""
import json
import re
import sys
import time
import urllib.request
import xml.etree.ElementTree as ET
from datetime import datetime, timezone
from email.utils import parsedate_to_datetime

COUNTRIES = {
    "IN": "India", "US": "United States", "GB": "United Kingdom", "CA": "Canada",
    "AU": "Australia", "AE": "UAE", "PK": "Pakistan", "BD": "Bangladesh",
    "NP": "Nepal", "SG": "Singapore", "NG": "Nigeria", "ZA": "South Africa",
}
NS = {"ht": "https://trends.google.com/trending/rss"}
KEEP_DAYS = 7
UA = {"User-Agent": "Mozilla/5.0 (techmachaw trends bot; +https://techmachaw.com)"}


def traffic_num(s):
    m = re.match(r"([\d,.]+)\s*([KkMm]?)", s or "")
    if not m:
        return 0
    n = float(m.group(1).replace(",", ""))
    return int(n * {"k": 1e3, "m": 1e6}.get(m.group(2).lower(), 1))


def fetch(geo):
    url = f"https://trends.google.com/trending/rss?geo={geo}"
    for attempt in range(3):
        try:
            with urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=30) as r:
                root = ET.fromstring(r.read())
            break
        except Exception as e:  # network hiccup: retry, then give up on this country only
            if attempt == 2:
                print(f"{geo}: failed ({e})", file=sys.stderr)
                return []
            time.sleep(5)
    items = []
    for it in root.iter("item"):
        q = (it.findtext("title") or "").strip()
        if not q:
            continue
        pub = it.findtext("pubDate")
        try:
            started = parsedate_to_datetime(pub).astimezone(timezone.utc).isoformat()
        except Exception:
            started = None
        news = []
        for n in it.findall("ht:news_item", NS)[:3]:
            news.append({
                "title": (n.findtext("ht:news_item_title", default="", namespaces=NS) or "").strip(),
                "url": (n.findtext("ht:news_item_url", default="", namespaces=NS) or "").strip(),
                "source": (n.findtext("ht:news_item_source", default="", namespaces=NS) or "").strip(),
            })
        traffic = it.findtext("ht:approx_traffic", default="", namespaces=NS)
        items.append({
            "q": q,
            "traffic": traffic,
            "n": traffic_num(traffic),
            "started": started,
            "pic": it.findtext("ht:picture", default="", namespaces=NS),
            "news": [x for x in news if x["title"]],
        })
    return items


def main():
    prev_path, out_path = sys.argv[1], sys.argv[2]
    now = datetime.now(timezone.utc)
    now_s = now.replace(microsecond=0).isoformat()
    prev = {}
    if prev_path:
        try:
            prev = json.load(open(prev_path, encoding="utf-8"))
        except (OSError, ValueError):
            prev = {}
    out = {"updated": now_s, "keep_days": KEEP_DAYS, "countries": {}}
    for geo, name in COUNTRIES.items():
        old = {i["q"].lower(): i for i in prev.get("countries", {}).get(geo, {}).get("items", [])}
        current = fetch(geo)
        live = set()
        for rank, it in enumerate(current, 1):
            key = it["q"].lower()
            live.add(key)
            o = old.get(key)
            if o:
                o.update({k: it[k] for k in ("traffic", "pic", "news") if it[k]})
                if it["n"] >= o.get("peak", 0):
                    o["peak"], o["peak_label"] = it["n"], it["traffic"]
                o["last"] = now_s
                o["hours"] = o.get("hours", 1) + 1
                o["best_rank"] = min(o.get("best_rank", rank), rank)
                o["rank"] = rank
            else:
                old[key] = {
                    "q": it["q"], "traffic": it["traffic"], "peak": it["n"], "peak_label": it["traffic"],
                    "first": it["started"] or now_s, "last": now_s, "hours": 1,
                    "rank": rank, "best_rank": rank, "pic": it["pic"], "news": it["news"],
                }
        items = []
        for key, o in old.items():
            o["live"] = key in live
            if not o["live"]:
                o.pop("rank", None)
            last = datetime.fromisoformat(o["last"])
            if (now - last).total_seconds() <= KEEP_DAYS * 86400:
                items.append(o)
        items.sort(key=lambda o: (not o["live"], -o["peak"]))
        out["countries"][geo] = {"name": name, "items": items[:300]}
        print(f"{geo}: {len(current)} live, {len(items)} in history")
        time.sleep(1)
    json.dump(out, open(out_path, "w", encoding="utf-8"), ensure_ascii=False, separators=(",", ":"))


if __name__ == "__main__":
    main()
