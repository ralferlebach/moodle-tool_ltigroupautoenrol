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

Backfill at scale: 10,000 users (GA-02, 1.2.1)
----------------------------------------------

Measured on 2026-10-27 with 1.2.1 (2026102701) by tests/load/run_scale.sh (data created directly in the database,
removed afterwards). Course with two LTI 1.3 tools, 5 groups (tool 1 → groups 0-2, tool 2 → groups 2-4, group 2 shared),
10,000 users: 4,000 active through tool 1, 5,000 through tool 2, 1,000 of the latter also through tool 1 (multiple
enrolment), 500 expired and 500 suspended through tool 1, 1,000 already members of their first group. The real
backfill task runs (course lock, status, final completeness check); 3 s after its start, 200 new LTI enrolments arrive
through the event path in a parallel process, and a third process tries to take the course lock as a second backfill
would. The standard log store is enabled (core's group_member_added and this plugin's events are written).
Database locks are sampled every 0.5 s during the run (PostgreSQL: ungranted lock requests / granted exclusive locks
of the database; MariaDB: InnoDB lock waits / open transactions).

| | 4.5 / PostgreSQL 16 | 4.5 / MariaDB 11.4 | 5.3 / PostgreSQL 17 | 5.3 / MariaDB 11.4 |
|---|---|---|---|---|
| Memberships missing before / added / already member | 29,000\* / 28,000 / 2,000 | 29,000\* / 28,000 / 2,000 | 29,000\* / 28,000 / 2,000 | 29,000\* / 28,000 / 2,000 |
| First run: wall time (200 enrolments in parallel) | 394 s | 350 s | 415 s | 404 s |
| First run: reads / writes | 216,101 / 94,763 | 216,058 / 94,763 | 244,098 / 94,763 | 244,058 / 94,763 |
| Reads / writes per added membership | 7.7 / 3.4 | 7.7 / 3.4 | 8.7 / 3.4 | 8.7 / 3.4 |
| First run: peak PHP memory growth | 8.2 MB | 8.2 MB | 8.4 MB | 7.7 MB |
| Repeated run (nothing missing): time / reads / writes | 0.29 s / 100 / 3 | 0.52 s / 56 / 3 | 0.16 s / 100 / 3 | 0.61 s / 56 / 3 |
| Plugin events logged (first / repeated run) | 10,200 / 0 | 10,200 / 0 | 10,200 / 0 | 10,200 / 0 |
| Missing memberships after the run | 0 | 0 | 0 | 0 |
| 200 parallel new LTI enrolments: assigned / failed | 200 / 0 | 200 / 0 | 200 / 0 | 200 / 0 |
| Their latency p50 / p95 / max | 77 / 136 / 1207 ms | 75 / 155 / 743 ms | 83 / 140 / 424 ms | 74 / 258 / 795 ms |
| Second backfill of the course during the run | lock refused at once (1.2 ms) | refused (4.1 ms) | refused (4.2 ms) | refused (0.4 ms) |
| DB lock samples / max waiting lock requests (PG) or InnoDB lock waits (MariaDB) | 654 / 0 | 570 / 0 | 680 / 0 | 619 / 0 |

\* The preview counts per tool: the 1,000 users enrolled through both tools are counted twice for the shared group, so
28,000 are added and 1,000 of the 2,000 "already member" are those second counts (the other 1,000 existed before).
Writes include the log store rows (core's group_member_added per membership and this plugin's events: 10,000 users of
the backfill whose memberships changed plus the 200 parallel enrolments). Wall times were measured on a shared 2-CPU
development container with other test runs active (a 5.3/MariaDB run without parallel enrolments and less load took
139 s); compare reads, writes and memory, which are deterministic, rather than seconds. The repeated run needs 56
(MariaDB) or 100 (PostgreSQL) reads for 10,200 user-tool pairs in 22 batches: two to four queries per batch,
independent of the number of users per batch.

Changes in 1.2.1 that these numbers reflect:

* **No N+1 on the read path.** Up to 1.2.0 the backfill called `groups_is_member()` once per user and mapped group
  (one query each). Since 1.2.1 one query per batch prefetches the memberships of the batch
  (`assignment_service::get_memberships()`); a repeated run over assigned users therefore needs a constant number of
  queries per batch (PHPUnit: scaling_test::test_repeated_backfill_query_budget). The write path stays one
  `groups_add_member()` call per missing membership: core's API is required for its event, cache invalidation and
  group conversations, so its own queries (user record, enrolment check, membership check, insert, group timestamp,
  event) remain per membership. That cost is linear in the number of memberships actually added, not in the
  course size.
* **Bounded memory.** Keyset batches of 500 users; the prefetch holds at most 50,000 membership rows
  (`MAX_PREFETCH_ROWS`; the batch shrinks for tools mapped to more than 100 groups). Memory does not grow with the
  number of users (peak growth in the table).
* **No long transactions, no lock-intensive bulk write.** The backfill runs without a database transaction: every
  membership is one short autocommit insert, so no row or table lock is held across users, and new enrolments of
  the event path proceed during a run (latency in the table). Only the plugin's own course lock (Moodle lock API,
  no waiting) serialises two backfills of the same course; the second one fails at once and is retried by the
  task API (lock probe in the table; PHPUnit: backfill_task_test::test_locked_course_is_retried).
* **Events.** One `group_assignment_completed` per user with an added membership (10,000-user run: one per changed
  user, none in the repeated run).

Plans at 10,000 users (`backfill_scale.php --explain`): PostgreSQL 17: batch query = Limit → Unique → Merge Join of `mdl_userenro_enruse_uix` (index scan on the enrol
instance and the keyset condition) with `mdl_user_id_pk`, 0.77 ms for 500 rows; membership prefetch = index scan on
`mdl_groumemb_use_ix` (500 users), 1,500 rows in 0.62 ms. MariaDB 11.4: batch query = range on
`mdl_userenro_enruse_uix`, eq_ref on the user; prefetch = range on `mdl_groumemb_use_ix`. No full table scan of
user_enrolments or groups_members on either database.
