#!/usr/bin/env python3
"""
Blog scraper -> Duda-ready RSS feed
Recipe: Meaningful Funerals platform (meaningfulfunerals.net)

Usage:
    pip install requests beautifulsoup4 lxml
    python scrape_blog.py                      # scrape the whole site in SITE below
    python scrape_blog.py --test-file page.html  # parse ONE saved page, no internet needed

Outputs:
    feed.xml    -> the RSS file you host and give to Duda's "Import Posts"
    report.csv  -> one row per post, for checking the scrape before importing
"""
import argparse
import csv
import re
import sys
import time
from datetime import datetime, timezone
from email.utils import format_datetime
from urllib.parse import urljoin

import requests
from bs4 import BeautifulSoup

# ---------------------------------------------------------------------------
# STEP 0: Per-site settings. This is the only part you edit for a new client
# on the same platform.
# ---------------------------------------------------------------------------
SITE = {
    "base_url": "https://www.hathawayfunerals.com/",  # client's own domain, with trailing slash
    "start_page": "blog-home",                         # any page that shows the archive sidebar
    "site_name": "Hathaway Family Funeral Homes",
    "author": "Hathaway Family Funeral Homes",         # no author on posts, so use the business
    "exclude": ["blog-home"],                          # slugs in the sidebar that aren't posts
    "feed_url": "",                                    # optional: where feed.xml will be hosted
}
DELAY_SECONDS = 1.5   # pause between requests so we don't hammer their server
HEADERS = {
    "User-Agent": ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
                   "(KHTML, like Gecko) Chrome/126.0 Safari/537.36")
}
MONTHS = {m: i for i, m in enumerate(
    ["January", "February", "March", "April", "May", "June", "July",
     "August", "September", "October", "November", "December"], start=1)}


# ---------------------------------------------------------------------------
# STEP 1: Download a page and turn it into something searchable.
# ---------------------------------------------------------------------------
def fetch(url):
    resp = requests.get(url, headers=HEADERS, timeout=30)
    resp.raise_for_status()   # stops with an error on 403/404/500 instead of saving junk
    return BeautifulSoup(resp.text, "lxml")


def load_saved_file(path):
    """Open a page saved from the browser. Handles Chrome 'view-source' saves too."""
    html = open(path, encoding="utf-8").read()
    soup = BeautifulSoup(html, "lxml")
    source_lines = soup.select("td.line-content")
    if source_lines:  # it's Chrome's viewer page: rebuild the real HTML from it
        soup = BeautifulSoup("\n".join(td.get_text() for td in source_lines), "lxml")
    return soup


# ---------------------------------------------------------------------------
# STEP 2: Build the list of posts from the archive sidebar.
# The sidebar is: <p class="notoggle"><strong>2026</strong></p>, then one
# <div class="togglenav"> per month holding that month's post links.
# ---------------------------------------------------------------------------
def get_post_list(soup):
    posts, year, seen = [], None, set()
    for el in soup.select("p.notoggle, div.togglenav"):
        if el.name == "p":
            year = int(el.get_text(strip=True))
            continue
        month = MONTHS[el.select_one("p.month_name").get_text(strip=True)]
        for a in el.select("p.tab a"):
            slug = a["href"].strip()
            if slug in SITE["exclude"] or slug in seen:
                continue
            seen.add(slug)
            posts.append({"slug": slug, "title": a.get_text(strip=True),
                          "archive_date": datetime(year, month, 1, 12, tzinfo=timezone.utc)})
    return posts


# ---------------------------------------------------------------------------
# STEP 3: Pull the pieces out of one post page and clean the body.
# ---------------------------------------------------------------------------
def parse_post(soup, url, fallback_date=None):
    # 3a. Find the post body: the text block with the "Published Date:" line.
    date_p = soup.find("p", string=re.compile(r"Published Date:"))
    if date_p:
        body = date_p.parent
    else:  # fallback: the text block in the page body with the most paragraphs
        blocks = soup.select("div.-page-body div.-section_text")
        body = max(blocks, key=lambda d: len(d.find_all("p")))

    # 3b. Date: prefer the exact published date, else the month from the sidebar.
    pub_date = fallback_date
    if date_p:
        text = date_p.get_text(strip=True).replace("Published Date:", "").strip()
        pub_date = datetime.strptime(text, "%B %d, %Y").replace(hour=12, tzinfo=timezone.utc)

    # 3c. Title: the <h2> in the body, else the page's og:title minus the site name.
    h2 = body.find("h2")
    if h2:
        title = h2.get_text(strip=True)
    else:
        title = soup.find("meta", property="og:title")["content"].split(" | ")[0]

    og_desc = soup.find("meta", property="og:description")
    description = og_desc["content"] if og_desc else ""

    # 3d. Clean the body. Remove what Duda shouldn't get twice or at all.
    if date_p:
        date_p.decompose()          # date goes in <pubDate>, not the text
    if h2:
        h2.decompose()              # title goes in <title>, not the text
    for anchor in body.find_all("a", attrs={"name": True}):
        if not anchor.get("href"):
            anchor.decompose()      # empty page-builder markers
    for a in body.find_all("a"):
        if a.find("img"):
            a.unwrap()              # keep the image, drop the click-to-enlarge link

    warnings = []
    for tag in body.find_all(True):
        for attr in ("class", "style", "id", "title"):
            tag.attrs.pop(attr, None)
        for attr in ("src", "href"):
            value = tag.get(attr)
            if not value:
                continue
            if "%20" in value and not value.startswith(("http", "/")):
                warnings.append(f"broken link on original site: {value[:60]}")
            tag[attr] = urljoin(SITE["base_url"], value)  # /fh_live/... -> full URL
    for p in body.find_all("p"):
        if not p.get_text(strip=True) and not p.find(["img", "iframe"]):
            p.decompose()           # empty spacer paragraphs

    images = [img["src"] for img in body.find_all("img") if img.get("src")]
    html = "".join(str(child) for child in body.children).strip()

    return {"url": url, "title": title, "date": pub_date, "description": description,
            "html": html, "images": images, "warnings": warnings,
            "words": len(body.get_text(" ", strip=True).split())}


# ---------------------------------------------------------------------------
# STEP 4: Write the RSS file Duda imports from.
# ---------------------------------------------------------------------------
def xml_escape(text):
    return (text.replace("&", "&amp;").replace("<", "&lt;")
                .replace(">", "&gt;").replace('"', "&quot;"))


def cdata(html):
    return "<![CDATA[" + html.replace("]]>", "]]]]><![CDATA[>") + "]]>"


def build_rss(posts):
    posts = sorted(posts, key=lambda p: p["date"], reverse=True)  # newest first
    items = []
    for p in posts:
        image = (f'\n      <media:content url="{xml_escape(p["images"][0])}" medium="image" />'
                 if p["images"] else "")
        items.append(f"""    <item>
      <title>{xml_escape(p["title"])}</title>
      <link>{xml_escape(p["url"])}</link>
      <guid isPermaLink="true">{xml_escape(p["url"])}</guid>
      <pubDate>{format_datetime(p["date"])}</pubDate>
      <dc:creator>{xml_escape(SITE["author"])}</dc:creator>
      <description>{xml_escape(p["description"])}</description>
      <content:encoded>{cdata(p["html"])}</content:encoded>{image}
    </item>""")

    self_link = (f'\n    <atom:link href="{xml_escape(SITE["feed_url"])}" rel="self" '
                 f'type="application/rss+xml" />' if SITE["feed_url"] else "")
    return f"""<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
     xmlns:content="http://purl.org/rss/1.0/modules/content/"
     xmlns:dc="http://purl.org/dc/elements/1.1/"
     xmlns:media="http://search.yahoo.com/mrss/"
     xmlns:atom="http://www.w3.org/2005/Atom">
  <channel>
    <title>{xml_escape(SITE["site_name"])} Blog</title>
    <link>{xml_escape(SITE["base_url"])}</link>
    <description>Blog posts from {xml_escape(SITE["site_name"])}</description>{self_link}
    <language>en-us</language>
{chr(10).join(items)}
  </channel>
</rss>
"""


# ---------------------------------------------------------------------------
# STEP 5: A report to check before importing.
# ---------------------------------------------------------------------------
def write_report(posts, failures, path="report.csv"):
    title_counts = {}
    for p in posts:
        title_counts[p["title"]] = title_counts.get(p["title"], 0) + 1
    with open(path, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["status", "date", "title", "words", "images", "notes", "url"])
        for p in sorted(posts, key=lambda p: p["date"], reverse=True):
            notes = list(p["warnings"])
            if title_counts[p["title"]] > 1:
                notes.append("DUPLICATE TITLE - check which version to keep")
            if p["words"] < 100:
                notes.append("very short - check body was captured")
            w.writerow(["ok", p["date"].date(), p["title"], p["words"],
                        len(p["images"]), "; ".join(notes), p["url"]])
        for url, err in failures:
            w.writerow(["FAILED", "", "", "", "", err, url])


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--test-file", help="parse one saved page instead of scraping")
    args = parser.parse_args()

    if args.test_file:
        soup = load_saved_file(args.test_file)
        post = parse_post(soup, SITE["base_url"] + "test-post")
        print(f"Found {len(get_post_list(soup))} posts in the sidebar")
        print(f"Title: {post['title']}\nDate: {post['date']}\nWords: {post['words']}")
        print(f"Images: {post['images']}\nWarnings: {post['warnings']}")
        posts, failures = [post], []
    else:
        start = fetch(urljoin(SITE["base_url"], SITE["start_page"]))
        post_list = get_post_list(start)
        if not post_list:
            sys.exit("No archive sidebar found. Try a post URL as start_page.")
        print(f"Found {len(post_list)} posts. Scraping...")
        posts, failures = [], []
        for i, item in enumerate(post_list, 1):
            url = urljoin(SITE["base_url"], item["slug"])
            try:
                posts.append(parse_post(fetch(url), url, item["archive_date"]))
                print(f"  [{i}/{len(post_list)}] ok    {item['title']}")
            except Exception as e:  # keep going; failures land in the report
                failures.append((url, str(e)))
                print(f"  [{i}/{len(post_list)}] FAIL  {item['title']}: {e}")
            time.sleep(DELAY_SECONDS)

    with open("feed.xml", "w", encoding="utf-8") as f:
        f.write(build_rss(posts))
    write_report(posts, failures)
    print(f"\nWrote feed.xml ({len(posts)} posts) and report.csv ({len(failures)} failures)")


if __name__ == "__main__":
    main()
