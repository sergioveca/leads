#!/usr/bin/env bash
# Signs in to a running dev server (php -S) and checks the main pages render without errors.
# Usage: tests/http_smoke.sh http://127.0.0.1:8080 admin@example.com password parcel_id
set -euo pipefail
BASE=$1 EMAIL=$2 PASS=$3 PID=$4
JAR=$(mktemp) fails=0

csrf() { grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//'; }
page() { # page <path> <must-contain>
  local body code
  body=$(curl -sS -b "$JAR" -c "$JAR" -w '\n%{http_code}' "$BASE$1")
  code=${body##*$'\n'}; body=${body%$'\n'*}
  if [ "$code" = 200 ] && grep -qF -- "$2" <<<"$body" && ! grep -q 'Something went wrong\|Fatal error\|Warning:' <<<"$body"; then
    echo "ok   GET $1"
  else
    echo "FAIL GET $1 (HTTP $code)"; grep -m3 -i 'error\|warning' <<<"$body" || true; fails=$((fails+1))
  fi
}

TOKEN=$(curl -sS -c "$JAR" "$BASE/login" | csrf)
curl -sS -b "$JAR" -c "$JAR" -o /dev/null --data-urlencode "_csrf=$TOKEN" --data-urlencode "email=$EMAIL" --data-urlencode "password=$PASS" "$BASE/login"

page / "Pipeline"
page /leads "Working pool"
page "/leads?view=delivered&status=any&sort=delivered" "Delivered to clients"
page "/leads?view=all&status=dead&min=0" "Dead / not interested"
page "/lead?id=$PID" "Exclusive to"
page /admin "Run all jobs until done"
page /admin " EST"

# one round of "Run all jobs until done" (real jobs, ~50 s) answers with JSON listing what is left
TOKEN=$(curl -sS -b "$JAR" -c "$JAR" "$BASE/admin" | csrf)
RUN=$(curl -sS -b "$JAR" -c "$JAR" --max-time 90 --data-urlencode "_csrf=$TOKEN" "$BASE/admin/run.json")
if grep -q '"pending"' <<<"$RUN" && grep -q '"log"' <<<"$RUN"; then echo "ok   POST /admin/run.json: $(cut -c1-300 <<<"$RUN")"; else echo "FAIL /admin/run.json: $(cut -c1-300 <<<"$RUN")"; fails=$((fails+1)); fi
page /lists "Tired landlords"
page "/leads?m%5Blandlord%5D=all&m%5Bblight%5D=any&m%5Bfire%5D=any&min=0" "Motivators"
page "/leads?stack=2&min=0&ptype=house" "Save list"

# save a list from the Leads page, then it appears on /lists and exports
TOKEN=$(curl -sS -b "$JAR" -c "$JAR" "$BASE/leads" | csrf)
curl -sS -b "$JAR" -c "$JAR" -o /dev/null --data-urlencode "_csrf=$TOKEN" --data-urlencode "name=CI absentee blight" \
  --data-urlencode "all[]=absentee" --data-urlencode "all[]=blight" --data-urlencode "min=0" "$BASE/lists/save"
page /lists "CI absentee blight"
LID=$(curl -sS -b "$JAR" "$BASE/lists" | grep -o 'list=[0-9]*">Open' | tail -1 | grep -o '[0-9]*')
curl -sS -b "$JAR" -o "$JAR.csv" "$BASE/export.csv?all%5B%5D=absentee&min=0&list=$LID"; HDR=$(head -1 "$JAR.csv")
if grep -q 'motivators' <<<"$HDR"; then echo "ok   list export"; else echo "FAIL list export: $HDR"; fails=$((fails+1)); fi

# add a note through the form and see it on the timeline
TOKEN=$(curl -sS -b "$JAR" -c "$JAR" "$BASE/lead?id=$PID" | csrf)
curl -sS -b "$JAR" -c "$JAR" -o /dev/null --data-urlencode "_csrf=$TOKEN" --data-urlencode "id=$PID" \
  --data-urlencode "status=offer" --data-urlencode "note=Offered 35k via HTTP test" "$BASE/lead/update"
page "/lead?id=$PID" "Offered 35k via HTTP test"

# export includes the new columns
curl -sS -b "$JAR" -o "$JAR.csv" "$BASE/export.csv?view=delivered&status=any"; HDR=$(head -1 "$JAR.csv")
if grep -q 'status,exclusive_client,delivered_at,delivery_channel' <<<"$HDR"; then echo "ok   export.csv columns"; else echo "FAIL export.csv header: $HDR"; fails=$((fails+1)); fi

rm -f "$JAR" "$JAR.csv"
[ $fails -eq 0 ] && echo "All HTTP checks passed" || { echo "$fails HTTP check(s) FAILED"; exit 1; }
