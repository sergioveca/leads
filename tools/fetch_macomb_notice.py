#!/usr/bin/env python3
"""Find, download, parse and validate Macomb County's newest "Notice of Properties Subject to Foreclosure".

The Treasurer posts the Macomb Daily supplement each December on the tax-foreclosure pleadings page
(e.g. sites/default/files/files/2025-12/Macomb Tax Sale 2025 digital.pdf). File names vary, so candidate PDFs
are downloaded and identified by their heading, which also gives the foreclosure year.

    python3 tools/fetch_macomb_notice.py              # newest notice -> data/treasurer/macomb_forfeited_<year>.csv
    python3 tools/fetch_macomb_notice.py --dry-run    # parse + validate only
    python3 tools/fetch_macomb_notice.py --pdf-url URL

Same contract as fetch_treasurer_list.py: re-parsed only when the PDF changes (sha256 sidecar); exit 1 on any
problem so nothing bad is committed; GITHUB_OUTPUT gets changed/year/rows.
"""
import argparse
import hashlib
import json
import os
import re
import shutil
import sys
import tempfile
import urllib.parse
import urllib.request
from datetime import datetime, timezone

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from parse_macomb_notice import parse  # noqa: E402

SITE = "https://macombgov.org"
PAGES = [
    SITE + "/departments/treasurers-office/tax-foreclosure/tax-foreclosure-pleadings",
    SITE + "/departments/treasurers-office/tax-foreclosure/auction-and-claims",
]
UA = "curl/8.5.0 Leads/1.0 (+https://leads.4-return.com)"
CANDIDATE = re.compile(r"tax[\s_%20-]*sale|supplement|subject[\s_%20-]*to[\s_%20-]*foreclos|notice[\s_%20-]*of[\s_%20-]*propert", re.I)
SKIP = re.compile(r"show[\s_%20-]*cause|rules|claim|petition|order|hearing|efile|frc_list", re.I)
DATA = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "data", "treasurer")

MIN_ROWS = 1000           # 2024 notice: 2,537; 2025: 2,700
MIN_WITH_AMOUNT = 0.90
MAX_NO_CITY = 0.10


def get(url):
    req = urllib.request.Request(url, headers={"User-Agent": UA, "Accept": "*/*"})
    with urllib.request.urlopen(req, timeout=180) as r:
        return r.read()


def candidates():
    found = {}
    for page in PAGES:
        try:
            html = get(page).decode("utf-8", "replace")
        except Exception as e:  # one page down is fine
            print(f"warning: {page}: {e}")
            continue
        for href in re.findall(r'href="([^"]+\.pdf)"', html, re.I):
            name = urllib.parse.unquote(href.rsplit("/", 1)[-1])
            if CANDIDATE.search(name) and not SKIP.search(name):
                url = urllib.parse.urljoin(SITE, href)
                folder = re.search(r"/files/(\d{4}-\d{2})/", href)
                found[url] = folder.group(1) if folder else "0000-00"
    # newest upload folders first
    return [u for u, _ in sorted(found.items(), key=lambda kv: kv[1], reverse=True)]


def output(**kv):
    path = os.environ.get("GITHUB_OUTPUT")
    if path:
        with open(path, "a", encoding="utf-8") as f:
            for k, v in kv.items():
                f.write(f"{k}={v}\n")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--pdf-url")
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--force", action="store_true")
    a = ap.parse_args()

    urls = [a.pdf_url] if a.pdf_url else candidates()[:4]
    if not urls:
        print("No notice PDFs found on the Macomb Treasurer pages (page layout changed?)")
        return 1
    best = None
    with tempfile.TemporaryDirectory() as tmp:
        for i, url in enumerate(urls):
            pdf = get(url)
            if not pdf.startswith(b"%PDF"):
                print(f"skip (not a PDF): {url}")
                continue
            path = os.path.join(tmp, f"n{i}.pdf")
            csv_tmp = os.path.join(tmp, f"n{i}.csv")
            with open(path, "wb") as f:
                f.write(pdf)
            try:
                s = parse(path, csv_tmp)
            except SystemExit as e:
                print(f"skip ({e}): {url}")
                continue
            print(f"{url}: foreclosure year {s['year']}, {s['rows']} parcels")
            if best is None or s["year"] > best["year"]:
                best = dict(s, url=url, csv=csv_tmp, sha=hashlib.sha256(pdf).hexdigest(), bytes=len(pdf))
        if best is None:
            print("None of the candidate PDFs is a Macomb foreclosure notice")
            return 1

        year, rows = best["year"], best["rows"]
        csv_path = os.path.join(DATA, f"macomb_forfeited_{year}.csv")
        meta_path = os.path.join(DATA, f"macomb_forfeited_{year}.source.json")
        if os.path.exists(meta_path) and not (a.force or a.dry_run):
            with open(meta_path, encoding="utf-8") as f:
                if json.load(f).get("sha256") == best["sha"]:
                    print(f"Macomb {year} notice unchanged; nothing to do")
                    output(changed="false", year=year)
                    return 0
        problems = []
        if rows < MIN_ROWS:
            problems.append(f"only {rows} parcels (expected {MIN_ROWS}+)")
        if rows and best["with_amount"] / rows < MIN_WITH_AMOUNT:
            problems.append(f"only {best['with_amount'] / rows:.0%} with an amount owed")
        if rows and best["no_city"] / rows > MAX_NO_CITY:
            problems.append(f"{best['no_city'] / rows:.0%} without a city")
        print(f"Macomb {year}: {rows} parcels, {best['with_amount']} with amount, {best['no_address']} without street, "
              f"{best['no_city']} without city")
        if problems:
            print("VALIDATION FAILED: " + "; ".join(problems) + ". Check tools/parse_macomb_notice.py against the PDF.")
            return 1
        output(changed="false" if a.dry_run else "true", year=year, rows=rows)
        if a.dry_run:
            print("Dry run: nothing written")
            return 0
        os.makedirs(DATA, exist_ok=True)
        shutil.copyfile(best["csv"], csv_path)
    with open(meta_path, "w", encoding="utf-8") as f:
        json.dump({"county": "macomb", "year": year, "url": best["url"], "sha256": best["sha"], "bytes": best["bytes"],
                   "parcels": rows, "fetched_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")}, f, indent=2)
        f.write("\n")
    print(f"Wrote {csv_path}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
