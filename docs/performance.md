Performance and query budgets
=============================

Measured on 2026-10-08 with version 1.2 (2026100800) on Moodle 4.5.15, PHP 8.3, PostgreSQL 16
(development container, one LTI tool mapped to two groups). Reads and writes are Moodle's
`perf_get_reads()` / `perf_get_writes()` counters.

| Operation | 300 participants | 1000 participants |
|---|---|---|
| Preview (backfill page) | 6 reads, 0.007 s | 6 reads, 0.018 s |
| Backfill (adhoc task, batches of 500) | 5402 reads, 1812 writes, 4.4 s for 600 memberships | 18004 reads, 6040 writes, 17.7 s for 2000 memberships |
| Event path per new enrolment | 21 reads, 6 writes, 16 ms | 21 reads, 6 writes, 16 ms |

Budgets enforced by PHPUnit (tests/local/assignment_service_test.php):

* Preview: independent of the number of participants, at most 12 reads for 2 tools / 3 groups.
* Event path: at most 15 reads and 3 writes for one group.
* Backfill: at most 12 reads per participant and group; 300 participants within 60 s.

Behaviour under load:

* The backfill runs only as an adhoc task, never in the browser request, with a per-course lock,
  keyset batches and an idempotent retry.
* Parallel events for the same membership are safe: groups_add_member() checks the membership and
  core's unique key on groups_members (userid, groupid) prevents duplicates; a lost race counts as
  error and is reported, the membership exists anyway.

Scaling of the pending check and the preview (#17, #18)
--------------------------------------------------------

Measured on 2026-10-26 with tests/load/benchmark.php (version 1.2, 2026102502 code; development
container, PHP 8.3; median of 5 runs, group data cache purged before each run; memory is the growth
of PHP memory during one run).

`backfill_task::is_pending()` with N queued backfill tasks of other courses:

| N | Queries | 4.5 / PostgreSQL 16 | 4.5 / MariaDB 10.11 | 5.3 / PostgreSQL 17 | 5.3 / MariaDB 11.4 |
|---|---|---|---|---|---|
| 1 | 1 | 0.42 ms | 0.38 ms | 0.23 ms | 0.41 ms |
| 100 | 1 | 0.39 ms | 0.29 ms | 0.25 ms | 0.50 ms |
| 1000 | 1 | 0.47 ms | 0.63 ms | 0.39 ms | 0.59 ms |

Memory growth: 0 KB from N = 100 on (no task objects are loaded any more). Plan: one sequential scan
of task_adhoc with the filter `classname = ... AND customdata LIKE '{"courseid":<id>,%'`
(PostgreSQL: Seq Scan with filter; MariaDB: type ALL, "Using where"). The cost grows with the size of
the whole adhoc task table, not with the number of courses; task_adhoc only holds queued, running and
retried tasks of the site and is small in normal operation. The filter relies on the custom data
format written by `backfill_task::create()` (course id first), which a test checks
(tests/task/backfill_task_test.php: course-specific matching, queued, running, retried and finished
tasks).

Backfill preview (`assignment_service::preview()` / `count_missing()`) with N groups mapped to one tool
and 300 participants (worst case: nobody is a member yet, N x 300 missing memberships):

| N | Queries | 4.5 / PostgreSQL 16 | 4.5 / MariaDB 10.11 | 5.3 / PostgreSQL 17 | 5.3 / MariaDB 11.4 | Memory |
|---|---|---|---|---|---|---|
| 1 | 3 | 5.3 ms | 2.8 ms | 26.3 ms | 2.6 ms | 0.1 MB |
| 10 | 3 | 5.8 ms | 3.7 ms | 16.8 ms | 3.5 ms | 0.1 MB |
| 100 | 3 | 36.3 ms | 11.1 ms | 58.9 ms | 8.9 ms | 0.5 MB |
| 1000 | 3 | 395.9 ms | 114.9 ms | 23.3 ms | 82.7 ms | 5.5 MB |

The number of queries does not depend on the number of groups (tools of the course, eligible users,
missing memberships per tool). The group ids are passed as one IN list (1000 parameters at most, the
mapping limit `mapping::MAX_GROUPS_PER_TOOL`; far below the parameter limits of PostgreSQL and
MariaDB). Plans: PostgreSQL uses the index on groups.courseid and an anti join on groups_members
(nested loop or merge anti join, depending on the statistics); MariaDB uses `mdl_grou_cou_ix` (ref)
for the groups and the unique index `mdl_groumemb_usegro_uix` for the NOT EXISTS. Memory grows with
the IN list only (about 5 MB for 1000 groups). The PostgreSQL 17 timings of 1 and 10 groups include
the planning of a fresh plan; the 1000-group case uses a merge anti join there.

Enforced by PHPUnit (tests/local/scaling_test.php): `is_pending()` needs exactly 1 query with 1, 100
and 1000 queued tasks and less than 1 s; the preview needs the same number of queries for 1, 10, 100
and 1000 groups, less than 5 s and less than 16 MB of memory.
