<p align="center">
  <a href="https://lmshostingservices.com">
    <img src="https://raw.githubusercontent.com/lmshostingservices/lms-labs/main/attached_assets/lms-hosting-logo.png" alt="LMS Hosting Services" height="60">
  </a>
</p>

> **LMS Labs** is the Moodle plugin division of [LMS Hosting Services](https://lmshostingservices.com) — Australia's Moodle™ Certified Partner.

---

# DocGuard — Document Plagiarism and AI-Use Checker

**Version:** see `version.php` and the top of [CHANGELOG.md](CHANGELOG.md) - deliberately not repeated here, so it cannot go stale
**Moodle Compatibility:** Moodle 4.5 LTS - 5.2 (`$plugin->requires = 2024100700`, `$plugin->supported = [405, 502]`)
**Plugin Type:** Plagiarism plugin (`plagiarism_docguard`)
**Licence:** GNU GPL v3 or later

---

## What It Does

DocGuard analyses **uploaded assignment documents**. When a student submits a PDF or
Word file to an assignment where DocGuard is enabled, the plugin extracts the document's
text, splits it into question/answer sections, and scores each section against twelve
heuristic signals for indicators of plagiarism and AI-generated writing.

**All analysis happens on your own Moodle server.** The submitted document is never sent
anywhere. Text extraction, scoring and the cross-student comparison all run locally
against your own Moodle database.

Teachers see a risk badge next to each submitted file, a class report listing every
submission for the activity, and a per-student report showing the signal-by-signal
breakdown with the evidence behind each score.

DocGuard is a **decision-support tool**. It produces heuristic indicators, not findings
of misconduct, and every report says so.

---

## Requirements

- Moodle 4.5 LTS or later (declared support runs to 5.2).
- Moodle's core plagiarism subsystem switched on:
  *Site administration → Advanced features → Enable plagiarism plugins*.
  DocGuard does **not** change this site-wide setting for you, because it also affects
  any other plagiarism plugin installed on the site.
- A paid DocGuard licence — see **Licensing** below.
- For DOCX extraction: PHP's `ZipArchive` extension.
- For PDF extraction: `pdftotext` (poppler-utils) or Ghostscript is strongly recommended.
  DocGuard falls back to a pure-PHP PDF parser when neither is available, but the
  external tools produce better text on complex documents.

Supported activity types: **assignments** (`mod_assign`) only.
Supported file types: **PDF** (`.pdf`) and **Word** (`.docx`). A genuine legacy binary
`.doc` file is detected by its magic number and reported with a message asking the
student to resubmit as `.docx` or PDF, rather than being analysed as garbage text.

---

## Installation

1. Copy the plugin into `plagiarism/docguard` in your Moodle root, or install the ZIP
   through *Site administration → Plugins → Install plugins*.
2. Visit *Site administration → Notifications* to run the database upgrade.
3. Turn on Moodle's plagiarism subsystem at
   *Site administration → Advanced features → Enable plagiarism plugins*.
4. Enter your credentials and enable DocGuard at
   *Site administration → Plugins → Plagiarism → DocGuard* (see **Configuration**).
5. Tick **Enable DocGuard** in the settings of each assignment you want analysed.
   DocGuard ships switched off, both site-wide and per activity, because it stores
   extracted student document text.

---

## Licensing — this plugin requires a paid licence

**DocGuard will not analyse anything until the site is unlocked against a paid LMS Labs
licence.** This is a commercial plugin: installing the code is not sufficient.

To unlock a site you need an account at [lms-labs.com](https://lms-labs.com) with a
sufficient credit balance, then:

*lms-labs.com → Dashboard → Plugins → DocGuard → Unlock (5,000 credits)*

**The plugin contacts lms-labs.com to verify this.** On installation and periodically
thereafter, DocGuard makes outbound HTTPS calls to `lms-labs.com` to check the site's
licence, to perform the one-time unlock, and to read site-wide plugin enablement flags.

Those calls carry **only this site's own Site ID, API key and the fixed plugin
identifier `docguard`**. They carry no submitted documents, no extracted text, no
scores, and no student identity of any kind. The transmission is declared in the
plugin's Moodle privacy metadata as an external location, so it appears in your site's
own privacy registry.

If your site cannot reach `lms-labs.com`, or the licence check fails, DocGuard does not
analyse submissions. Use **Test connection and unlock status** on the settings page to
check the current state.

---

## Configuration

Settings page: *Site administration → Plugins → Plagiarism → DocGuard*

| Setting | Default | Description |
|---------|---------|-------------|
| Site ID | — | Your LMS Labs site identifier. Read from the `local_aiconfig` plugin when that is installed. |
| API Key | — | Your LMS Labs API key. Also read from `local_aiconfig` when present. |
| Enable DocGuard | Off | Site-wide master switch. Nothing is analysed while this is off. |
| Minimum section words | 30 | Sections shorter than this are skipped. |
| Analyse historical submissions (backfill) | Off | Lets the hourly task also process submissions made before DocGuard was installed, up to 15 files per run. On a large site this works through every past submission and stores extracted text for every student who has ever submitted. |
| Data retention (days) | 90 | Extracted text older than this is deleted by the nightly cleanup task. `0` keeps it indefinitely. |

There is also a per-activity **Enable DocGuard** checkbox in each assignment's settings.
Both switches must be on for a submission to be analysed. New activities start unticked.

**Capability:** `plagiarism/docguard:viewreport` controls access to the reports. A user
holding `mod/assign:grade` in the activity can also open them, which is what makes
non-editing teachers able to see results for the classes they mark.

---

## Detection Signals

Each section is scored 0–100 from eleven per-section signals, plus a twelfth
cross-student signal applied to the submission as a whole.

| # | Signal | Max | What it looks for |
|---|--------|-----|-------------------|
| S1 | AI / LLM marker vocabulary | 22 | Phrases over-represented in LLM output |
| S2 | Sentence length uniformity | 10 | Unusually low variation in sentence length |
| S3 | Type-Token Ratio uniformity across paragraphs | 8 | Vocabulary richness too consistent between paragraphs |
| S4 | Formal transition word overuse | 8 | Excessive academic connectors per 100 words |
| S5 | Absence of contractions | 6 | No contractions at all in 100+ words |
| S6 | Passive voice overuse | 6 | High proportion of passive constructions |
| S7 | Intro/conclusion template pattern | 10 | Stock opening and closing phrases |
| S8 | Trigram repetition | 8 | Low word-trigram uniqueness |
| S9 | Sentence-start uniformity | 6 | Many sentences beginning the same way |
| S10 | Vocabulary richness extremity | 6 | Type-Token Ratio at either extreme |
| S11 | Cross-section style inconsistency | 10 | Sections of one document written differently |
| S12 | Cross-student submission similarity | 15 | Jaccard bigram similarity against other submissions to the same activity |

**Risk bands:** 0–34 low · 35–64 medium · 65–100 high.

Signals S1–S10 are English-language heuristics. DocGuard measures how much of a
document is Latin-script and will not report a confident low score for a document it
cannot meaningfully analyse.

---

## What Data Is Processed, and Where It Goes

**Stored in your Moodle database, and nowhere else:**

`plagiarism_docguard_sub` — one record per analysed file: the student's user ID, the
course module, the file name and type, the content hash, the analysis status, the
overall risk score and level, and the **normalised extracted text of the document**.

`plagiarism_docguard_sec` — one record per detected section: the section number and
label, the section risk score and level, the per-signal JSON breakdown, and the
**extracted text of that section**.

**Sent outside your server:** only the Site ID, API key and the plugin identifier
`docguard`, to `lms-labs.com`, for licence verification and site-wide settings. Nothing
else — no documents, no extracted text, no scores, no names. No external AI service is
involved in scoring at any point.

**Who can see it:** teachers holding `plagiarism/docguard:viewreport` or
`mod/assign:grade` in the activity, and site administrators. In a separate-groups
activity the reports and the cross-student similarity tables are filtered to the groups
the viewer is permitted to see.

---

## Privacy and GDPR

- Students are shown a disclosure above the submission form stating that their document
  text is extracted and stored, that it is compared with other submissions to the same
  activity, who can see the results, that scores are heuristic rather than proof, and
  how long the extracted text is kept.
- Full Moodle Privacy API implementation in `classes/privacy/provider.php`, covering the
  metadata declaration, per-user export, per-user erasure and the user-list provider
  across both tables.
- The external transmission to `lms-labs.com` is declared as an external location link
  in the privacy metadata, so it appears in your site's own privacy registry.
- The **DocGuard — clean up old submission text** scheduled task deletes extracted text
  past the retention window. Risk scores and section breakdowns are kept so historical
  reports stay viewable; only the stored text is pruned.
- Extracted text is stored for **every** analysed submission. If you enable the backfill
  setting, that includes every historical submission the task reaches. Take that into
  account in your site's data-protection assessment.

---

## Scheduled Tasks

| Task | Purpose |
|------|---------|
| DocGuard — process pending and untracked submissions | Retries submissions still marked pending, and optionally backfills older ones when the backfill setting is on. |
| DocGuard — clean up old submission text | Deletes extracted text past the retention window. |
| DocGuard — scan and analyse an activity's unprocessed submissions | Ad-hoc task queued by the **Scan & Analyse Unprocessed Submissions** button on the class report. Runs in bounded batches and re-queues itself, so a large class cannot exhaust the cron worker's execution time. |

DocGuard depends on Moodle cron running regularly. If submissions stay on "Pending",
check the cron schedule first.

---

## Support

- **Portal:** [lms-labs.com](https://lms-labs.com)
- **Email:** support@lmshostingservices.com
- **Website:** [lmshostingservices.com](https://lmshostingservices.com)

LMS Labs is the plugin division of LMS Hosting Services, Australia's Moodle™ Certified Partner.

---

## Licence

Copyright 2026 LMS-Labs.

This program is free software: you can redistribute it and/or modify it under the terms
of the GNU General Public License as published by the Free Software Foundation, either
version 3 of the License, or (at your option) any later version. See
[LICENSE](LICENSE), or <http://www.gnu.org/licenses/>.
