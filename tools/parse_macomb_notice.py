#!/usr/bin/env python3
"""Parse Macomb County's yearly "Notice of Properties Subject to Foreclosure" (Macomb Daily supplement PDF, posted on
macombgov.org each December) into the same CSV layout as the Wayne lists, plus the amount owed:

    parcel_id,address,municipality,interested_parties,list_year,amount_owed

Usage (needs poppler's pdftotext):
    python3 tools/parse_macomb_notice.py notice.pdf data/treasurer/macomb_forfeited_2026.csv

In `pdftotext -raw` order each record is:
    Parcel ID#: 01-13-21-202-024           (BSA id: 2-digit community code + local tax id)
    Amount to Redeem as of
    12/01/2025: 22,832.28
    Property Address: 26673                 (address wraps; ends with "<CITY> MI")
    LAWRENCE CENTER LINE MI
    The following parties may have an
    interest in this property:
    <party lines>                           (names wrap over two lines; duplicates are common)
    OCCUPANT:
    DESCRIPTION OF PROPERTY: …              (legal description, then a ____ separator)
The list year (foreclosure year) comes from the heading "NOTICE OF PROPERTIES SUBJECT TO FORECLOSURE IN <year>".
"""
import csv
import re
import subprocess
import sys

PARCEL = re.compile(r"^Parcel ID#:\s*(\d{2}-\d{2}-\d{2}-\d{3}-\d{3})")
AMOUNT = re.compile(r"(\d{1,2}/\d{1,2}/\d{4}):\s*\$?([\d,]+\.\d{2})")
HEADING_YEAR = re.compile(r"SUBJECT\s+TO\s+FORECLOSURE\s+IN\s+(\d{4})", re.I)
# Macomb communities, longest first so "CLINTON TOWNSHIP" wins over "CLINTON"
CITIES = sorted([
    "ARMADA", "ARMADA TOWNSHIP", "BRUCE TOWNSHIP", "CENTER LINE", "CHESTERFIELD TOWNSHIP", "CHESTERFIELD",
    "CLINTON TOWNSHIP", "EASTPOINTE", "FRASER", "HARRISON TOWNSHIP", "LAKE TOWNSHIP", "LENOX TOWNSHIP", "LENOX",
    "MACOMB TOWNSHIP", "MACOMB", "MEMPHIS", "MOUNT CLEMENS", "MT CLEMENS", "NEW BALTIMORE", "NEW HAVEN",
    "RAY TOWNSHIP", "RICHMOND", "RICHMOND TOWNSHIP", "ROMEO", "ROSEVILLE", "SAINT CLAIR SHORES", "ST CLAIR SHORES",
    "SHELBY TOWNSHIP", "SHELBY TWP", "STERLING HEIGHTS", "UTICA", "WARREN", "WASHINGTON TOWNSHIP", "WASHINGTON",
    "HARRISON TWP", "CLINTON TWP", "MACOMB TWP", "CHESTERFIELD TWP", "WASHINGTON TWP", "RAY TWP", "LENOX TWP",
    "BRUCE TWP", "ARMADA TWP", "RICHMOND TWP",
], key=len, reverse=True)


def split_address(text):
    """'26673 LAWRENCE CENTER LINE MI' -> ('26673 LAWRENCE', 'CENTER LINE')."""
    t = re.sub(r"\s+", " ", text).strip()
    t = re.sub(r"\s+MI(\s+\d{5})?$", "", t)
    for city in CITIES:
        if t == city:
            return "", city
        if t.endswith(" " + city):
            return t[: -len(city) - 1].strip(), city
    return t, ""


def parse(pdf, out):
    raw = subprocess.run(["pdftotext", "-raw", pdf, "-"], capture_output=True, text=True, check=True).stdout
    lines = [l.strip() for l in raw.splitlines()]
    m = HEADING_YEAR.search(" ".join(lines[:40]))
    if not m:
        raise SystemExit("Heading 'SUBJECT TO FORECLOSURE IN <year>' not found: not a Macomb notice?")
    year = int(m.group(1))

    rows, cur, mode = [], None, None
    for line in lines:
        if not line:
            continue
        pm = PARCEL.match(line)
        if pm:
            if cur:
                rows.append(cur)
            cur = {"parcel_id": pm.group(1), "amount": "", "addr": [], "parties": [], "party_buf": ""}
            mode = "amount"
            continue
        if cur is None:
            continue
        if mode == "amount":
            am = AMOUNT.search(line)
            if am:
                cur["amount"] = am.group(2).replace(",", "")
            if line.startswith("Property Address:"):
                rest = line[len("Property Address:"):].strip()
                cur["addr"] = [rest] if rest else []
                mode = "addr"
            continue
        if mode == "addr":
            if line.startswith("The following parties"):
                mode = "parties_intro"
            else:
                cur["addr"].append(line)
            continue
        if mode == "parties_intro":
            if line.startswith("interest in this property"):
                mode = "parties"
            continue
        if mode == "parties":
            if line.startswith("OCCUPANT") or line.startswith("DESCRIPTION OF PROPERTY"):
                mode = "skip"
                continue
            cur["parties"].append(line)
            continue
    if cur:
        rows.append(cur)

    seen, out_rows = set(), []
    for r in rows:
        if r["parcel_id"] in seen:
            continue
        seen.add(r["parcel_id"])
        street, city = split_address(" ".join(r["addr"]))
        # party names wrap onto a second line; rejoin short continuation lines, then de-duplicate
        names = []
        for p in r["parties"]:
            if names and (len(p) <= 6 or p in ("BANK", "INC", "LLC", "CO", "TRUST", "COMPANY", "CORP")) and not names[-1].endswith(p):
                names[-1] = names[-1] + " " + p
            else:
                names.append(p)
        uniq = list(dict.fromkeys(n for n in names if len(n) > 2))[:12]
        out_rows.append([r["parcel_id"], street, city, "; ".join(uniq), year, r["amount"]])

    with open(out, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["parcel_id", "address", "municipality", "interested_parties", "list_year", "amount_owed"])
        w.writerows(out_rows)
    n = len(out_rows)
    return {
        "year": year,
        "rows": n,
        "with_amount": sum(1 for r in out_rows if r[5]),
        "no_address": sum(1 for r in out_rows if not r[1]),
        "no_city": sum(1 for r in out_rows if not r[2]),
    }


if __name__ == "__main__":
    s = parse(sys.argv[1], sys.argv[2])
    print(f"{s['rows']} parcels for foreclosure in {s['year']} ({s['with_amount']} with amount, "
          f"{s['no_address']} without street, {s['no_city']} without city) -> {sys.argv[2]}")
