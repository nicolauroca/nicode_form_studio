# Local SQL scale benchmark

Recorded: 2026-09-27T15:51:00+00:00. Database: MariaDB 11.4.5; PHP 8.5.8.

This is a synthetic repository-level SQL benchmark on the isolated local MariaDB instance. It is not an installed-extension acceptance test, a throughput guarantee, or a result for PostgreSQL/MySQL. Fixtures are seeded directly with SQL; they do not measure submission processing or action delivery.

## Dataset

- forms: 100.
- submissions: 1,000,000.
- submission_index: 3,000,000.
- action_runs: 1,000,000.
- Largest form: 500,000 responses.

## Query timings

20 sequential samples per query, 50-row page limit. Warm/cold effects are not isolated.

| Query | p50 ms | p95 ms | Maximum ms |
|---|---:|---:|---:|
| global_latest | 687.2865 | 704.5311 | 718.8176 |
| large_form_latest | 0.3057 | 0.4388 | 3.9047 |
| rare_email | 0.4817 | 0.632 | 12.3321 |
| numeric_and_country | 107.0915 | 118.0255 | 271.5101 |

## Query plans

InnoDB buffer pool: 134,217,728 bytes. Execution analysis below is one additional sample per query, outside the 20 timing samples.

### global_latest

```json
[
    {
        "id": 1,
        "select_type": "SIMPLE",
        "table": "s",
        "type": "index",
        "possible_keys": "nfs_submissions_u1,nfs_submissions_i1,nfs_submissions_i2,nfs_submissions_i8",
        "key": "nfs_submissions_i0",
        "key_len": "16",
        "ref": null,
        "rows": "51",
        "Extra": "Using where"
    }
]
```

Optimizer: 675.3152 ms; execution: 0.381 ms. The full executed plan is retained in `build/scale-results.json`.

### large_form_latest

```json
[
    {
        "id": 1,
        "select_type": "SIMPLE",
        "table": "s",
        "type": "range",
        "possible_keys": "nfs_submissions_u1,nfs_submissions_i1,nfs_submissions_i2,nfs_submissions_i8",
        "key": "nfs_submissions_i1",
        "key_len": "8",
        "ref": null,
        "rows": "945733",
        "Extra": "Using where"
    }
]
```

Optimizer: 0.0464 ms; execution: 0.0631 ms. The full executed plan is retained in `build/scale-results.json`.

### rare_email

```json
[
    {
        "id": 1,
        "select_type": "PRIMARY",
        "table": "x",
        "type": "ref",
        "possible_keys": "nfs_submission_index_u0,nfs_submission_index_i0,nfs_submission_index_i1,nfs_submission_index_i2,nfs_submission_index_i3,nfs_submission_index_i4,nfs_submission_index_i5",
        "key": "nfs_submission_index_i0",
        "key_len": "1175",
        "ref": "const,const,const",
        "rows": "1",
        "Extra": "Using index condition; Using where; LooseScan; Using temporary; Using filesort"
    },
    {
        "id": 1,
        "select_type": "PRIMARY",
        "table": "s",
        "type": "eq_ref",
        "possible_keys": "PRIMARY,nfs_submissions_u1,nfs_submissions_i1,nfs_submissions_i2,nfs_submissions_i8",
        "key": "PRIMARY",
        "key_len": "8",
        "ref": "formstudio_scale.x.submission_id",
        "rows": "1",
        "Extra": "Using where"
    },
    {
        "id": 2,
        "select_type": "DEPENDENT SUBQUERY",
        "table": "p",
        "type": "eq_ref",
        "possible_keys": "nfs_version_field_policy_u0,nfs_version_field_policy_i0",
        "key": "nfs_version_field_policy_u0",
        "key_len": "152",
        "ref": "formstudio_scale.s.form_version_id,const",
        "rows": "1",
        "Extra": "Using index condition; Using where"
    }
]
```

Optimizer: 0.1362 ms; execution: 0.0517 ms. The full executed plan is retained in `build/scale-results.json`.

### numeric_and_country

```json
[
    {
        "id": 1,
        "select_type": "PRIMARY",
        "table": "x",
        "type": "range",
        "possible_keys": "nfs_submission_index_u0,nfs_submission_index_i0,nfs_submission_index_i1,nfs_submission_index_i2,nfs_submission_index_i3,nfs_submission_index_i4,nfs_submission_index_i5",
        "key": "nfs_submission_index_i2",
        "key_len": "171",
        "ref": null,
        "rows": "53214",
        "Extra": "Using index condition; Using where; Start temporary; Using temporary; Using filesort"
    },
    {
        "id": 1,
        "select_type": "PRIMARY",
        "table": "s",
        "type": "eq_ref",
        "possible_keys": "PRIMARY,nfs_submissions_u1,nfs_submissions_i1,nfs_submissions_i2,nfs_submissions_i8",
        "key": "PRIMARY",
        "key_len": "8",
        "ref": "formstudio_scale.x.submission_id",
        "rows": "1",
        "Extra": "Using where; End temporary"
    },
    {
        "id": 1,
        "select_type": "PRIMARY",
        "table": "x",
        "type": "ref",
        "possible_keys": "nfs_submission_index_u0,nfs_submission_index_i0,nfs_submission_index_i1,nfs_submission_index_i2,nfs_submission_index_i3,nfs_submission_index_i4,nfs_submission_index_i5",
        "key": "nfs_submission_index_u0",
        "key_len": "152",
        "ref": "formstudio_scale.x.submission_id,const",
        "rows": "1",
        "Extra": "Using index condition; Using where; FirstMatch(s)"
    },
    {
        "id": 4,
        "select_type": "DEPENDENT SUBQUERY",
        "table": "p",
        "type": "eq_ref",
        "possible_keys": "nfs_version_field_policy_u0,nfs_version_field_policy_i0",
        "key": "nfs_version_field_policy_u0",
        "key_len": "152",
        "ref": "formstudio_scale.s.form_version_id,const",
        "rows": "1",
        "Extra": "Using index condition; Using where"
    },
    {
        "id": 2,
        "select_type": "DEPENDENT SUBQUERY",
        "table": "p",
        "type": "eq_ref",
        "possible_keys": "nfs_version_field_policy_u0,nfs_version_field_policy_i0",
        "key": "nfs_version_field_policy_u0",
        "key_len": "152",
        "ref": "formstudio_scale.s.form_version_id,const",
        "rows": "1",
        "Extra": "Using index condition; Using where"
    }
]
```

Optimizer: 0.2944 ms; execution: 104.4502 ms. The full executed plan is retained in `build/scale-results.json`.

## Cursor and migration checks

100 keyset pages returned 5,000 distinct response IDs in 50.7737 ms. PHP peak allocated memory: 6,291,456 bytes.

ADR 0018 identity schema inspection/upgrade during this run: 19.9194 ms. Already-upgraded fixtures measure the idempotent check, not an ALTER of three million rows.

The first ADR 0018 migration of this existing three-million-index-row fixture completed in 34,576.59 ms (runner output on 2026-09-27). Cardinalities remained unchanged. A later run showed a global-search p95 of 587.3754 ms versus 3.0174 ms on 2026-09-26. Profiling localized the delay to optimizer planning (one sample: 322.6047 ms planning, 0.3293 ms execution); this does not establish migration causality. The small buffer pool, intervening workloads, statistics and additional indexes prevent treating these runs as a controlled before/after comparison. Global planning variability remains a performance risk to measure under deployment-sized resources.

## Export jobs

The independent export runner reads the existing fixture without reseeding or changing responses. Each format exports one million responses across 100 form-scoped jobs; the largest individual artifact contains 500,000 responses. Jobs process at most 500 rows per claim and checkpoint. An independent unbuffered canonical-data traversal computes the expected SHA-256 for every artifact, including record order and selected values. Artifacts are removed and download metadata invalidated after verification.

| Format | Recorded UTC | Rows | Bytes | Export seconds | Total seconds | Peak PHP bytes |
|---|---|---:|---:|---:|---:|---:|
| CSV | 2026-09-28T07:21:26+00:00 | 1,000,000 | 105,755,276 | 41.38 | 47.97 | 8,388,608 |
| JSON | 2026-09-28T07:18:06+00:00 | 1,000,000 | 299,215,076 | 34.4 | 39.05 | 8,388,608 |

Export measurements use MariaDB 11.4.5, a 128 MiB PHP memory limit, and sequential synthetic jobs. Peak PHP allocation excludes database-server memory and operating-system buffers. Export seconds sum handler/claim/checkpoint time; total seconds also include independent verification and cleanup. This is neither a single million-row artifact nor an HTTP/concurrent throughput guarantee. Functional filtering, ACL and lifecycle checks on other engines are recorded separately in the requirement acceptance ledger.

## Reproduce

Start the isolated fixture database using `tools/start-test-database.ps1`, then run `php tests/scale.php --seed`. Subsequent runs can use `php tests/scale.php`. Run `php -d memory_limit=128M tests/scale-export.php csv` and then `php -d memory_limit=128M tests/scale-export.php json` against the existing complete fixture. Regenerate this report with `php tools/benchmark-report.php`. The runners refuse other host/database targets.

Remaining scale acceptance includes concurrent ingestion/workers, the complete administrative UI, operational resource budgets, and throughput on additional database engines.
