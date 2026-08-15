# DocGuard 1.0.78 — teachers unable to see the plagiarism breakdown

Built from 1.0.77 (`2026072300`, build `a7731b19c5260a41`). No database schema changes.

**Version: `2026081500` — this MUST be higher than the installed version or Moodle will not run the upgrade.** See fix 2.

---

## The reported fault

Lecturers could not open the DocGuard breakdown — the per-signal report and the class report. The badge appeared on the submissions page; the report behind it did not open.

Two independent defects produce that symptom, and both are fixed here. A third produces a variant of it (report opens, breakdown empty) and is also fixed.

---

## Fixes

### 1. Report link shown to people who were then denied *(primary cause)*

`lib.php` rendered the badge and the "View DocGuard Report" link to anyone holding `mod/assign:grade` — which Moodle grants to **Non-editing teachers** — while `report.php` and `student_report.php` required `plagiarism/docguard:viewreport`, which `db/access.php` granted only to `editingteacher` and `manager`. Markers, tutors and lecturers on non-editing roles saw a link that always answered *"Sorry, but you do not currently have permissions to do that."*

- `lib.php` — new `plagiarism_docguard_can_view_reports()`. One test now decides both whether the link renders and whether the page opens, so the two cannot diverge again. The test is: an explicit `CAP_PROHIBIT` denies; otherwise `plagiarism/docguard:viewreport`; otherwise `mod/assign:grade`. Each page keeps its own explicit `require_capability()` on the deny path, so the check stays visible to reviewers and to static analysis.
- `report.php`, `student_report.php`, `reanalyse.php` — all three now use it. `reanalyse.php` previously had a third, different rule.
- `db/access.php` — added the `teacher` archetype.
- Result cached per request; `get_links()` is called once per student row.

Allowing `mod/assign:grade` is not a widening of access: anyone who can grade the assignment can already download every file the report describes, and `reanalyse.php` already accepted exactly that capability for a heavier, write-side operation.

### 2. Frozen version number — capability changes could never install

`version.php` was pinned at `2026072300` while `db/upgrade.php` carried **three** separate `if ($oldversion < 2026072300)` blocks: releases 1.0.74–1.0.77 all shipped the same version integer. Moodle runs a plugin's upgrade — and re-syncs `db/access.php` — only when that integer increases, so replacing the plugin files changed nothing. Upgrades appeared to succeed and did nothing.

- `version.php` — bumped to `2026081500`, release `1.0.78`.
- `db/upgrade.php` — a matching upgrade block that **explicitly grants** `plagiarism/docguard:viewreport` to existing non-editing teacher roles. This is necessary because archetypes are applied only when a capability row is first created; adding an archetype does nothing on a site where the capability already exists. Uses `$overwrite = false`, so any explicit permission an administrator has already set is left alone.
- `clonepermissionsfrom` was considered and rejected — it silently overrides the archetypes block and copies every context-specific grant of the source capability.

### 3. Per-section breakdown silently lost

`db/install.xml` has a UNIQUE index on `(subid, section_num)`, but the parser assigns the same number to `Question N`, `Answer N`, `Task N` and so on. A template laid out as `Question 1 / Answer 1 / Question 2 / Answer 2` produced `1,1,2,2`; the second insert threw and aborted the loop. Worse, the parent record was marked `analysed` *before* the sections were written, and `analyse_and_store()` returns early on `analysed` — so the record was stranded permanently with a score and no breakdown.

In `classes/observer.php`:

- `section_num` is now a sequential counter. The parsed number is still shown — it is part of `section_label`.
- Sections are written **before** the parent status is set, so a storage failure no longer strands the record.
- Each insert is individually guarded; one bad section no longer costs the rest of the breakdown.
- `section_label` / `section_text` truncate with `core_text` — a byte-wise cut could sever a multi-byte character, which MySQL/utf8mb4 rejects outright.
- `section_count` is re-read from the database, and a concurrent re-analysis can no longer overwrite a good result with an error.

### 4. Stuck "Pending", and the backfill that never ran

- `classes/task/process_pending.php` Phase 2 looked for enabled activities in `{plagiarism_config}`, a table this plugin never writes to, and a config key nothing ever sets. It logged "no DocGuard-enabled assign CMs found" and returned on every run since it was written. Repaired: candidates are now found with `NOT EXISTS`, oldest-first, filtered through the same `is_cm_active()` the badge uses.
- Its SQL aliased `{modules}` as `mod`, a **reserved word on MySQL/MariaDB**. This had never surfaced because the query was unreachable; it would have thrown on most installs the moment the phase went live. Renamed.
- Phase 1 had a poison pill: an unretrievable file was skipped without changing status, so it was re-selected first on every run forever, permanently consuming one of 15 slots per run. Now marked as an error after a one-hour grace window (short enough to drain the queue, long enough to survive a transient object-store blip).
- Phase 2 is wrapped so it can never fail the whole task — a failed scheduled task gets exponential backoff, which would have throttled Phase 1 too.

**Phase 2 is off by default.** New setting *"Analyse historical submissions (backfill)"*. This phase has never run on any site, and switching it on silently would turn an inert task into a site-wide sweep of every historical assignment submission on the install. The reported fault does not depend on it: new submissions go through the observer, stuck records through Phase 1, and a single activity's backlog through the *Scan & Analyse Unprocessed Submissions* button. Turn it on deliberately, ideally out of hours.

### 5. Arbitrary file disclosure in `reanalyse.php`

`reanalyse.php` carried the comment *"Verify the file belongs to this CM's context (security check)"* above a line that assigned a variable and checked nothing. `fileid` and `userid` came straight from the query string. Any user who could reach the page could pass any file id in the Moodle filestore, have DocGuard extract it and store its full text against their own activity, then read the whole document back through the report — including other courses' submissions. The user id was equally unchecked, so a foreign document could be filed against any account and would surface in that person's GDPR export.

This is **pre-existing in 1.0.77**, not introduced here, but this release grants the report capability to more roles, so it is fixed now: the file must belong to this activity's context and be a student submission file, and the named user must actually have a submission in this activity.

### 6. Supporting fixes

- `classes/privacy/provider.php` — GDPR export fatalled on `transform::datetime()` (class never imported), and the metadata map named columns `sectionnum`/`sectiontext` that do not exist. Both fixed; no language pack change needed.
- `classes/extractor.php` — legacy `.doc` files are accepted then handed to `ZipArchive`, which always fails; teachers got *"Cannot open DOCX file"*. Now detects the OLE2 signature and says to resubmit as `.docx` or PDF. Temp files also get a unique name — two students submitting `assignment.pdf` at once could delete each other's working file mid-read.
- `student_report.php` — "No section data available." and "No signal data available." now say what happened and what to do. The second reflects a real rule: fewer than 8 recognised words in a section means no signals are evaluated.
- `lib.php` — pending and errored badges now link to the class report, which is the only route to the recovery tools; previously only analysed submissions linked anywhere, so a class stuck on Pending looked exactly like a permissions fault.
- `report.php` — backfill bookkeeping rows are hidden from the class list.
- `db/tasks.php` — the comment claimed the task runs every 15 minutes; the schedule is hourly. Comment corrected, schedule unchanged.

---

## Installing

1. Confirm the current version at *Site administration → Plugins → Plugins overview*. Note it.
2. Back up, or install on staging first.
3. Replace `/plagiarism/docguard/` with the contents of this zip.
4. Visit *Site administration → Notifications* and complete the upgrade. **The version must move to `2026081500`.** If it does not, the upgrade did not run and nothing has changed.
5. Purge all caches.
6. Check *Define roles → Non-editing teacher* — `plagiarism/docguard:viewreport` should now be **Allow**.
7. Ask the lecturer to re-open a report.

## After upgrading

- Submissions whose breakdown was lost to fix 3 need a **Re-analyse** to rebuild it — the fix prevents recurrence, it cannot recover data that was never written.
- Leave the historical backfill setting off unless you want past submissions processed.

## Deliberately not changed

- **The word tokeniser is still ASCII-only** (`classes/analyser.php`). Sections in accented or non-Latin scripts can fall under the 8-word threshold and show no signals. Making it Unicode-aware would change risk scores on existing submissions, which does not belong in a fix release — but it is a real defect and worth raising with the vendor.
- **No groups filtering on either report.** A teacher restricted to Separate Groups can see every student in the activity, and the cross-student similarity table names them. This is over-exposure, but it is the opposite direction from the reported fault, and tightening it in the same release that restores access would be confusing. Worth a decision of its own.
- **Group (team) assignments are excluded from the backfill.** Their submission rows carry `userid = 0`, which the record-keying cannot represent; the observer handles them as they are submitted.
