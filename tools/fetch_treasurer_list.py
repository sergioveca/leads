#!/usr/bin/env python3
"""Find, download, parse and validate the newest Wayne County Treasurer forfeiture list.

Run by .github/workflows/treasurer-list.yml (daily) and usable by hand (needs poppler's pdftotext):

    python3 tools/fetch_treasurer_list.py                 # newest list on the Treasurer page
    python3 tools/fetch_treasurer_list.py --year 2027     # a specific year from the page
    python3 tools/fetch_treasurer_list.py --pdf-url URL --year 2027
    python3 tools/fetch_treasurer_list.py --dry-run       # parse + validate, write nothing to data/

Writes data/treasurer/wayne_forfeited_<year>.csv plus a .source.json sidecar (PDF URL + sha256).
A year is re-parsed only when its PDF changes (different sha256). Exit 0 = up to date or written,
exit 1 = page/PDF/validation problem (the CSV is not written, so nothing bad is deployed).
"""
import argparse
import hashlib
import json
import os
import re
import shutil
import sys
import tempfile
import urllib.request
from datetime import datetime, timezone

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from parse_treasurer_pdf import parse  # noqa: E402

SITE = "https://www.waynecountymi.gov"
PAGE = SITE + "/Government/Elected-Officials/Treasurer/Property-Tax-Information/Forfeited-Property-List-with-Interested-Parties"
# The county's Akamai front end returns 403 to browser-like and unknown User-Agents but allows tool ones.
UA = "curl/8.5.0 Leads/1.0 (+https://leads.4-return.com)"
LINK = re.compile(r'href="([^"]*?((?:19|20)\d{2})_wayne_county_delinquent_tax_liens[^"]*?\.pdf)[^"]*"', re.I)
DATA = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "data", "treasurer")

# Sanity limits (2026 list: 37,001 parcels, 85% Detroit, 0.2% without address).
MIN_ROWS = 10000
MIN_DETROIT_SHARE = 0.40
MAX_NO_ADDRESS_SHARE = 0.05


def get(url):
    req = urllib.request.Request(url, headers={"User-Agent": UA, "Accept": "*/*"})
    with urllib.request.urlopen(req, timeout=120) as r:
        return r.read()


def find_lists():
    html = get(PAGE).decode("utf-8", "replace")
    years = {}
    for href, year in LINK.findall(html):
        if "archive" in href.lower():
            continue
        years.setdefault(int(year), href if href.startswith("http") else SITE + href)
    return years


def output(**kv):
    """Expose results to GitHub Actions (no-op elsewhere)."""
    path = os.environ.get("GITHUB_OUTPUT")
    if path:
        with open(path, "a", encoding="utf-8") as f:
            for k, v in kv.items():
                f.write(f"{k}={v}\n")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--year", type=int)
    ap.add_argument("--pdf-url")
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--force", action="store_true", help="re-parse even if the PDF is unchanged")
    a = ap.parse_args()

    if a.pdf_url:
        if not a.year:
            sys.exit("--pdf-url needs --year")
        year, url = a.year, a.pdf_url
    else:
        lists = find_lists()
        if not lists:
            print(f"No list links found on {PAGE} (page layout changed?)")
            return 1
        year = a.year or max(lists)
        if year not in lists:
            print(f"No {year} list on the page; found {sorted(lists)}")
            return 1
        url = lists[year]
    print(f"{year} list: {url}")

    csv_path = os.path.join(DATA, f"wayne_forfeited_{year}.csv")
    meta_path = os.path.join(DATA, f"wayne_forfeited_{year}.source.json")
    if os.path.exists(csv_path) and not os.path.exists(meta_path) and not (a.force or a.dry_run):
        print(f"{os.path.basename(csv_path)} already exists (loaded by hand); nothing to do")
        output(changed="false", year=year)
        return 0

    pdf = get(url)
    if not pdf.startswith(b"%PDF"):
        print(f"Download is not a PDF ({len(pdf)} bytes, starts {pdf[:40]!r})")
        return 1
    sha = hashlib.sha256(pdf).hexdigest()
    if os.path.exists(meta_path) and not (a.force or a.dry_run):
        with open(meta_path, encoding="utf-8") as f:
            if json.load(f).get("sha256") == sha:
                print("PDF unchanged since last import; nothing to do")
                output(changed="false", year=year)
                return 0

    with tempfile.TemporaryDirectory() as tmp:
        pdf_path = os.path.join(tmp, "list.pdf")
        tmp_csv = os.path.join(tmp, "list.csv")
        with open(pdf_path, "wb") as f:
            f.write(pdf)
        s = parse(pdf_path, year, tmp_csv)
        rows = s["rows"]
        print(f"Parsed {rows} parcels ({s['detroit']} Detroit, {s['no_address']} without address), "
              f"PDF {len(pdf) // 1024} KB sha256 {sha[:12]}")
        problems = []
        if rows < MIN_ROWS:
            problems.append(f"only {rows} parcels (expected {MIN_ROWS}+)")
        if rows and s["detroit"] / rows < MIN_DETROIT_SHARE:
            problems.append(f"only {s['detroit'] / rows:.0%} Detroit (expected {MIN_DETROIT_SHARE:.0%}+)")
        if rows and s["no_address"] / rows > MAX_NO_ADDRESS_SHARE:
            problems.append(f"{s['no_address'] / rows:.0%} without address (max {MAX_NO_ADDRESS_SHARE:.0%})")
        if problems:
            print("VALIDATION FAILED: " + "; ".join(problems) + ". PDF layout may have changed; check tools/parse_treasurer_pdf.py.")
            return 1
        output(changed="false" if a.dry_run else "true", year=year, rows=rows, detroit=s["detroit"])
        if a.dry_run:
            print("Dry run: nothing written")
            return 0
        os.makedirs(DATA, exist_ok=True)
        shutil.copyfile(tmp_csv, csv_path)
    with open(meta_path, "w", encoding="utf-8") as f:
        json.dump({"year": year, "url": url, "sha256": sha, "bytes": len(pdf), "parcels": rows, "detroit": s["detroit"],
                   "fetched_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")}, f, indent=2)
        f.write("\n")
    print(f"Wrote {csv_path}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
