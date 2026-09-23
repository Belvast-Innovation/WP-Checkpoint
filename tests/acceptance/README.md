# Large-site export acceptance

Tooling to back up a large generated site in the wp-env development environment under shared-hosting limits, driven over REST the way the admin page drives a job, and to check the result. The tooling is for development only: the probe it installs logs job positions, and setup lowers the web container's CPU and PHP limits.

## Requirements

- A running `npx wp-env start` environment (Apache with mod_php).
- On the host: PHP 8 CLI with `curl` and `mysqli`, `unzip`, and `docker`.
- Free disk space of about three times the generated size: the source, the backup, and an extracted copy.

## Steps

```bash
# 1. Data: about 4.6 GB of files and 500 MB of table data (sizes in MB).
npx wp-env run cli --env-cwd=wp-content/plugins/wp-checkpoint wp eval-file tests/acceptance/generate.php 4600 500

# 2. Limits and probe: the web container gets 1 CPU; web requests get memory_limit=128M and max_execution_time=30
#    (a block in .htaccess); a mu-plugin records every request; an application password is created for the driver.
php tests/acceptance/acceptance.php setup --dir=$HOME/wpc-acceptance --cpus=1

# 3. Runs: TTFB before, during and after; a REST tick loop; the work directory's size over time.
php tests/acceptance/acceptance.php run --dir=$HOME/wpc-acceptance --name=first
#    The second run kills the tick's web process (SIGKILL) at planned points: mid-table in the database export,
#    between chunks of a large file, right before and right after a volume is sealed, during the manifest's
#    walks over the entries, and between two renames of the store step. After each kill the job is taken over
#    when the lease expires.
php tests/acceptance/acceptance.php run --dir=$HOME/wpc-acceptance --name=second --kills=standard
#    The planned kills follow each other about one lease apart, so a takeover tick may itself be killed at the next
#    point (a kill during recovery). To measure every takeover tick, run each rule on its own as well:
for k in database-mid-table pack-between-chunks seal-before-rename seal-after-rename manifest-audit-walk manifest-verify-walk store-between-renames; do
  php tests/acceptance/acceptance.php run --dir=$HOME/wpc-acceptance --name=kill-$k --kills=$k --no-ttfb
done

# 4. Checks per run, then the two runs against each other.
php tests/acceptance/acceptance.php check --dir=$HOME/wpc-acceptance --name=first
php tests/acceptance/acceptance.php check --dir=$HOME/wpc-acceptance --name=second
php tests/acceptance/acceptance.php compare --dir=$HOME/wpc-acceptance --name=first --with=second

# 5. Remove the probe, the limits and the application passwords.
php tests/acceptance/acceptance.php teardown --dir=$HOME/wpc-acceptance
```

## Planned kills

`ACC_KILLS` in `acceptance.php` lists the kill points. The probe matches each rule against the tick's call stack when the job table is updated:
- a checkpoint (`JobContext::checkpoint`), or the lease confirmation made right before an irreversible step (`JobContext::confirm_lease`);
- optionally narrowed by a function in the stack, text in the cursor being written, or a confirmation earlier in the same request.

On its nth match the rule kills the process before the query runs. `check` then reports for every kill:
- where the kill landed (cursor and stack);
- how long until another tick took the job over, and that tick's duration and peak memory;
- the job's takeover count and mark;
- any concurrent-writer lines in the job log.

## What `check` reports

| | Check |
| --- | --- |
| i | The job completed, and every planned kill fired. Its log has no zero-progress step and no concurrent writer, and exactly as many takeovers as kills. |
| ii | Duration of every web tick from the probe: p50, p99 and maximum. Each tick over 20 s is listed with the step and position before and after it. Passes when p99 ≤ 20 s and the maximum is ≤ 25 s. |
| iii | Peak memory of each tick request. It must stay within 128 MB and within 32 MB (plus 8 MB margin) of an idle tick request. |
| iv | Median time to first byte of the front page before, during and after the export. The export may add less than 50 ms. When before and after differ by more than 20 ms or 25 %, the environment drifted: discard the numbers and repeat the run. |
| v | `wp wpcheckpoint verify --depth=full` passes. |
| vi | `unzip -t` passes on every volume. |
| vii | Every volume is extracted. Every file matches its source by sha256, and nothing extra is present. The database chunks are loaded in index order into a scratch database with the `mariadb` client, and every table matches the source row by row. The options and user meta tables change while the export runs and are only reported. |
| viii | The manifest's file count and bytes match an independent walk of `wp-content` that applies the same exclusions. |
| ix | The work directory's peak size stays within the archive size plus a margin, and the next maintenance pass reclaims it. |

`check` also writes `distributions.json`: every tick's duration, peak memory and steps, the idle baseline, every TTFB sample, and the work directory's size over time. Keep these as the baseline for budget or environment changes.

`compare` compares two runs by what the backups restore, not byte for byte:
- Every extracted file must have the same sha256 in both runs. This uses `unzip` and the host's hashing, not the plugin's reader.
- `files.index.jsonl` must be identical.
- Every table except the volatile ones must restore to the same rows.
- The standalone manifests must be equal except for the fields listed in `ACC_MAY_DIFFER`, each with its reason:
  - the clock (`created_at` and the export times);
  - the database chunk boundaries and what derives from them (table and index hashes, sizes and chunk counts, and the volume list, which also carries the base name);
  - the row counts of the tables written during the export.

Any other field, including one added later, must be equal. Chunk boundaries can differ because a resumed table starts a new batch.
