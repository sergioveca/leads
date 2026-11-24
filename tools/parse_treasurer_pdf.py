#!/usr/bin/env python3
"""Parse the Wayne County Treasurer "Notice of Forfeited Property Subject to Foreclosure"
PDF (published yearly in the Detroit Legal News) into a CSV the app imports.

Usage (needs poppler's pdftotext):
    python3 tools/parse_treasurer_pdf.py liens.pdf 2026 data/treasurer/wayne_forfeited_2026.csv

In `pdftotext -raw` order each record is:
    <parcel id line>          e.g. 05004437.  | 06004356-7 | 33020030226002
    <street address line>     e.g. 10309 CAMERON
    <interested party lines>  until the next parcel id
Municipality headers ("City of DETROIT Ward 06", "City of INKSTER") appear in the flow.
"""
import csv
import re
import subprocess
import sys

DETROIT_ID = re.compile(r"^\d{8}(?:\.[0-9A-Z]*|-[0-9A-Z]+)*$")
SUBURB_ID = re.compile(r"^\d{14}$")
MUNI = re.compile(r"^(City|Township|Charter Township|Village) of (.+)$", re.I)
NOISE = re.compile(
    r"^(Prop No\.|Address|Interested Parties|Paid Supplement|NOTICE OF|SUBJECT TO|Eric R\. Sabree|"
    r"waynecountymi\.gov|Page \d+|\d{1,3}$)",
    re.I,
)
ADDRESS = re.compile(r"^\d+[A-Z]?(?:-\d+)?\s+\S")


def parse(pdf, year, out):
    """Parses `pdf` into the CSV `out`. Returns {"rows", "detroit", "no_address"}."""
    raw =subprocess.run(["pdftotext", "-raw", pdf, "-"], capture_output=True, text=True, check=True).stdout
    lines = [l.strip() for l in raw.splitlines()]
    rows, cur, muni = [], None, ""
    for line in lines:
        if not line:
            continue
        m = MUNI.match(line)
        if m:
            muni = m.group(2).strip().upper()
            continue
        if DETROIT_ID.match(line) or SUBURB_ID.match(line):
            if cur:
                rows.append(cur)
            is_detroit = bool(DETROIT_ID.match(line))
            cur = {
                "parcel_id": line,
                "address": "",
                "municipality": "DETROIT" if is_detroit else (muni if muni and not muni.startswith("DETROIT") else ""),
                "parties": [],
            }
            continue
        if cur is None or NOISE.match(line):
            continue
        if not cur["address"] and (ADDRESS.match(line) or line.startswith("0 ")):
            cur["address"] = line
            continue
        if cur["address"] and len(cur["parties"]) < 12 and line.upper() == line and len(line) > 2:
            cur["parties"].append(line)
    if cur:
        rows.append(cur)

    # Header lines sometimes run together ("SOUTHGATE CITY OF TAYLOR"): keep the first name only,
    # then use the 2-digit municipality code that starts every suburban parcel number.
    split = re.compile(r"\s+(?:CITY|TOWNSHIP|CHARTER TOWNSHIP|VILLAGE) OF\s+", re.I)
    for r in rows:
        m = split.split(r["municipality"])[0]
        m = re.split(r"\s+C/O\s+|,|\s+A MICHIGAN", m, flags=re.I)[0]
        r["municipality"] = m.strip()[:40]
    from collections import Counter, defaultdict
    by_code = defaultdict(Counter)
    for r in rows:
        if SUBURB_ID.match(r["parcel_id"]) and r["municipality"]:
            by_code[r["parcel_id"][:2]][r["municipality"]] += 1
    code_name = {code: c.most_common(1)[0][0] for code, c in by_code.items()}
    for r in rows:
        if SUBURB_ID.match(r["parcel_id"]):
            r["municipality"] = code_name.get(r["parcel_id"][:2], r["municipality"])

    seen, out_rows = set(), []
    for r in rows:
        if r["parcel_id"] in seen:
            continue
        seen.add(r["parcel_id"])
        out_rows.append(r)

    with open(out, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["parcel_id", "address", "municipality", "interested_parties", "list_year"])
        for r in out_rows:
            w.writerow([r["parcel_id"], r["address"], r["municipality"], "; ".join(r["parties"]), year])
    det = sum(1 for r in out_rows if r["municipality"] == "DETROIT")
    noaddr = sum(1 for r in out_rows if not r["address"])
    return {"rows": len(out_rows), "detroit": det, "no_address": noaddr}


def main(pdf, year, out):
    s = parse(pdf, year, out)
    print(f"{s['rows']} parcels ({s['detroit']} Detroit, {s['rows'] - s['detroit']} other), "
          f"{s['no_address']} without address -> {out}")


if __name__ == "__main__":
    main(*sys.argv[1:4])
