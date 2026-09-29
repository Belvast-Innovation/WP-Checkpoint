#!/usr/bin/env bash
# Manual restore acceptance, part 2: restore the archive from build.php by hand, without the plugin, following the
# documented manual restore steps command for command, then compare the result with the original site.
#
#   bash tests/acceptance/manual-restore/restore.sh <build dir> <mysql host> <port> <user> <password>
#
# <build dir> is build/manual-restore from build.php (archive/, source.json, tables.json). A new database is created
# on the given server (it must be empty of it: the name is random). Tools: bash, python3, sha256sum, split, unzip,
# mysql (client), php with mysqli (only for the comparison at the end).
#
# Steps 1-5 below are the documented steps as a user runs them; 6 (another table prefix) and 7 (another address) do
# not apply to a restore into the same prefix and address and are not run. Anything the steps print as "DIFFERS" or
# "failed" fails this script.
set -euo pipefail

BUILD=$(cd "$1" && pwd)
HOST=$2
PORT=$3
USER=$4
PASSWORD=$5
HERE=$(cd "$(dirname "$0")" && pwd)
WORK=$(mktemp -d)
TARGET="$WORK/wordpress"
DBNAME="wpc_manual_$(date +%s)_$$"
LOG="$WORK/steps.log"
export MYSQL_PWD="$PASSWORD"

echo "manual-restore: working in $WORK"
cp -a "$BUILD/archive/." "$WORK/"
mkdir -p "$TARGET/wp-content"
mysql -h "$HOST" -P "$PORT" -u "$USER" -e "CREATE DATABASE \`$DBNAME\` CHARACTER SET utf8mb4"
cd "$WORK"

# The documented steps. Their output goes to the log as well, to be searched for failures afterwards.
{
# --- 1. Check the volumes ---------------------------------------------------------------------------------------------
M=$(ls *.manifest.json)
B=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["hashing"]["volume_chunk_bytes"])' "$M")
python3 -c 'import json,sys
for v in json.load(open(sys.argv[1]))["volumes"]: print(v["path"], v["sha256"], " ".join(v.get("chunks", [])))' "$M" > volumes.txt
while read -r path sha chunks; do
  if [ -z "$chunks" ]; then
    echo "$sha  $path" | sha256sum -c -
  else
    rm -rf blocks && mkdir blocks && split -b "$B" -a 6 "$path" blocks/b.
    got=$(for f in blocks/b.*; do sha256sum "$f" | cut -d' ' -f1; done | tr -d '\n')
    [ "$got" = "$(printf %s $chunks)" ] && echo "blocks OK: $path" || echo "BLOCKS DIFFER: $path"
    [ "$(printf %s "$got" | sha256sum | cut -d' ' -f1)" = "$sha" ] && echo "OK: $path" || echo "DIFFERS: $path"
  fi
done < volumes.txt
rm -rf blocks volumes.txt

# --- 2. Extract every volume into one directory -----------------------------------------------------------------------
mkdir extracted
for v in *.wpcheckpoint.zip; do unzip -q -o "$v" -d extracted; done

# --- 3. Check the extracted files ---------------------------------------------------------------------------------------
python3 - extracted "$M" <<'PY'
import hashlib, json, os, sys
root, chunk = sys.argv[1], json.load(open(sys.argv[2]))["hashing"]["chunk_bytes"]
def blocks(path):
    out = []
    with open(path, "rb") as f:
        while True:
            data = f.read(chunk)
            if not data: break
            out.append(hashlib.sha256(data).hexdigest())
    return out
bad = 0
for index, prefix in (("files.index.jsonl", "files/"), ("database.index.jsonl", "")):
    for line in open(os.path.join(root, index), encoding="utf-8"):
        e = json.loads(line)
        hc = blocks(os.path.join(root, prefix + e["p"]))
        h = hashlib.sha256("".join(hc).encode()).hexdigest() if "hc" in e else (hc[0] if hc else hashlib.sha256(b"").hexdigest())
        if ("hc" in e and hc != e["hc"]) or h != e["h"]:
            bad += 1; print("DIFFERS:", e["p"])
print("files checked," , bad, "differ")
PY

# --- 4. Copy the files ------------------------------------------------------------------------------------------------
cp -a extracted/files/. "$TARGET/"

# --- 5. Import the database -------------------------------------------------------------------------------------------
export LC_ALL=C   # file names in byte order
for f in extracted/database/*.sql; do
  mysql --max-allowed-packet=64M -h "$HOST" -P "$PORT" -u "$USER" "$DBNAME" < "$f" || { echo "failed: $f"; break; }
done
} 2>&1 | tee "$LOG"

if grep -E -q 'DIFFER|failed|FAILED' "$LOG"; then
  echo "manual-restore: the documented steps reported a problem (see above)"
  exit 1
fi
grep -q 'files checked, 0 differ' "$LOG" || { echo "manual-restore: step 3 did not check the files"; exit 1; }
[ "$(grep -c '^OK: ' "$LOG" || true)" -ge 1 ] || { echo "manual-restore: no volume was checked in blocks"; exit 1; }
[ "$(grep -c ': OK$' "$LOG" || true)" -ge 1 ] || { echo "manual-restore: no volume was checked whole"; exit 1; }

# --- Compare with the original ---------------------------------------------------------------------------------------
PREFIX=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["table_prefix"])' "$BUILD/source.json")
php "$HERE/fingerprint.php" db "$HOST" "$PORT" "$USER" "$PASSWORD" "$DBNAME" "$PREFIX" "$BUILD/tables.json" > "$WORK/restored-tables.json"
UPLOADS=$(python3 -c 'import sys; print(sys.argv[1])' "$TARGET/wp-content/uploads")
php "$HERE/fingerprint.php" files "$UPLOADS" > "$WORK/restored-files.json"
python3 - "$BUILD/source.json" "$WORK/restored-tables.json" "$WORK/restored-files.json" <<'PY'
import json, sys
source = json.load(open(sys.argv[1]))
tables = json.load(open(sys.argv[2]))
files = json.load(open(sys.argv[3]))
problems = []
for name, want in sorted(source["tables"].items()):
    got = tables.get(name)
    if got != want:
        problems.append("table %s: original %s, restored %s" % (name, want, got))
if files != source["files"]:
    for path in sorted(set(files) | set(source["files"])):
        if files.get(path) != source["files"].get(path):
            problems.append("file %s: original %s, restored %s" % (path, source["files"].get(path), files.get(path)))
if not source["files"] or not source["tables"]:
    problems.append("the original's fingerprint is empty")
for p in problems:
    print("MISMATCH:", p)
print("manual-restore: %d tables and %d files compared, %d mismatches" % (len(source["tables"]), len(source["files"]), len(problems)))
sys.exit(1 if problems else 0)
PY
mysql -h "$HOST" -P "$PORT" -u "$USER" -e "DROP DATABASE \`$DBNAME\`"
cd /
# Only the directory mktemp made for this run, and only after a run that passed (a failed one is kept to be looked at).
case "$WORK" in
  "${TMPDIR:-/tmp}"/tmp.*) rm -rf -- "$WORK" ;;
esac
echo "manual-restore: the restored copy matches the original"
