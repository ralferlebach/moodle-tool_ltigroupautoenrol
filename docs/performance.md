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
