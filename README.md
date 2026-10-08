<p align="center">
  <a href="https://lmshostingservices.com">
    <img src="https://raw.githubusercontent.com/lmshostingservices/lms-labs/main/attached_assets/lms-hosting-logo.png" alt="LMS Hosting Services" height="60">
  </a>
</p>

> **LMS Labs** is the Moodle plugin division of [LMS Hosting Services](https://lmshostingservices.com) — Australia's Moodle™ Certified Partner.

---

# DocGuard — Assessment Authenticity Review for Moodle

**Version:** see `version.php` and the top of [CHANGELOG.md](CHANGELOG.md) - deliberately not repeated here, so it cannot go stale
**Moodle Compatibility:** Moodle 4.4 - 5.2 (`$plugin->requires = 2024042200`, `$plugin->supported = [404, 502]`)
**Plugin Type:** Plagiarism plugin (`plagiarism_docguard`)
**Licence:** GNU GPL v3 or later

---

## What It Does

DocGuard compares **every submission in an activity against every other submission** and
reports the overlap. When a student submits a PDF or Word file to an assignment where
DocGuard is enabled, the plugin extracts the text, splits it into question/answer sections,
removes quotations and the reference list, and measures how much of the remaining prose
appears in other students' submissions to the same activity.

**All analysis happens on your own Moodle server.** The submitted document is never sent
anywhere. Extraction and comparison run locally against your own Moodle database.

Teachers see a similarity badge next to each submitted file, a class report listing every
flagged pair, and a per-student report showing the extracted text and which other
submissions it overlaps with.

Since 1.2.0 it also runs **authenticity checks** on the submitted file: things that are true
or false about the document, such as a chat assistant's own words left in the text, markdown
pasted from somewhere else, a citation to an Act that does not check out, and what the file
records about how it was made. These are reported individually with the evidence quoted, and
they carry **no score**.

DocGuard is a **decision-support tool**. A similarity figure is a reason to open both
submissions and read them. It is not a finding of misconduct, and every report says so.

**DocGuard does not detect AI writing, and does not claim to.** Releases up to 1.0.98 scored
each submission across eleven writing-style signals as an AI-writing indicator. Those signals
were tested against 48 answers written by three current language models and flagged **none** of
them, while the highest-scoring document in the test set was a **human second-language
student**. They were removed in 1.0.99 and nothing has replaced them. See
**What DocGuard Checks** below.
---

## Requirements

- Moodle 4.4 or later (declared support runs to 5.2).
- Moodle's core plagiarism subsystem switched on:
  *Site administration → Advanced features → Enable plagiarism plugins*.
  DocGuard does **not** change this site-wide setting for you, because it also affects
  any other plagiarism plugin installed on the site.
- A paid DocGuard licence — see **Licensing** below.
- For DOCX extraction: PHP's `ZipArchive` extension.
- For PDF extraction: `pdftotext` (poppler-utils) or Ghostscript is strongly recommended.
  DocGuard falls back to a pure-PHP PDF parser when neither is available, but the
  external tools produce better text on complex documents.

### External binaries DocGuard shells out to

DocGuard invokes two command-line tools when they are present on the server's `PATH`.
Administrators on locked-down or shared hosting should know this before installing.

| Binary | Package | Used for | How it is invoked |
|--------|---------|----------|-------------------|
| `pdftotext` | poppler-utils | First-choice PDF text extraction | `timeout 60 pdftotext -layout -enc UTF-8 <file> -` |
| `gs` | Ghostscript | Fallback PDF extraction when `pdftotext` is absent or returns unreadable text | `timeout 60 gs -dSAFER -dNOPAUSE -dBATCH -dQUIET -sDEVICE=txtwrite -dNoOutputFonts …` |

Both are optional — DocGuard degrades to its pure-PHP PDF parser if neither exists — and
neither is ever passed anything but a path to the submitted file. Every variable part of
both command lines goes through `escapeshellarg()`, and the binary names are literals.

`-dSAFER` is passed to Ghostscript explicitly and must not be removed. Every PDF reaching
the extractor is a file a student uploaded, and without `-dSAFER` Ghostscript honours
PostScript operators that read and write arbitrary paths reachable by the web server user —
and, on affected versions, execute commands via `%pipe%` device names. Ghostscript has
enabled SAFER by default since 9.50, but DocGuard declares no minimum Ghostscript version
and runs whatever binary the host provides, so the flag is set rather than assumed.

The 60-second `timeout` on each call is also deliberate: a malformed PDF can hang
Ghostscript indefinitely, and without it every cron run left another stuck process behind.

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

*lms-labs.com → Dashboard → Plugins → DocGuard → Unlock (50 credits, US$5)*

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

## Scope — read this first

DocGuard holds **no external corpus**. It does not search the web, published work, essay
banks, other courses on your site, previous cohorts, or a student's own earlier
submissions. The only comparison it makes is with **other submissions to the same
activity**.

| Copying from… | Detected |
|---|---|
| Another student in the same activity | **Yes** |
| A student in another activity, course or cohort | No |
| The web, a published source, a textbook | No |
| An essay bank or paper mill | No |
| The student's own earlier submission | No |

**A low score means no other submission to this activity shares significant wording with
this one. It is not evidence that the work is original**, and it is not evidence that the
work was written by a person. The reports state this; so should anyone quoting a DocGuard
score in an academic integrity process.

---

## What DocGuard Checks

Two separate things, reported separately and never combined into one number.

| | What it is | Status |
|---|---|---|
| Cross-submission similarity | Jaccard similarity over word bigrams, after quotations, the reference list and the activity's shared assessment template are removed | **This is the product** |
| Authenticity findings | Checks on the submitted file that are true or false: chat-assistant artefacts, markdown in a word-processed document, unverifiable legislation citations, and what the file records about how it was made | Added in 1.2.0 |
| Text extraction | PDF (pdftotext / Ghostscript) and DOCX, with a readability check and a non-Latin-script guard | Supporting |

**Score = similarity percentage.** A submission scored 78 shares 78% of its word pairs with
another submission to the same activity. The bands sit where the measurements put them:

| Band | Similarity | Measured example |
|---|---|---|
| LOW | 0–34% | Students answering the same question independently, template excluded: **0–1%** |
| MEDIUM | 35–64% | A copy with about ten words changed: **48%** |
| HIGH | 65–100% | A near-verbatim copy: **93%** |

Every figure here comes from the sample pack shipped with the release, so you can reproduce
them: `php docguard-measure-sample-pack.php /path/to/plagiarism/docguard .`

Earlier releases of this README quoted 13%, 92% and 47.5%. Those were measured before 1.1.5
excluded the shared assessment template and no longer reproduce.

Quotations of 20 words or more and the reference list are removed before comparison. Two
students who quote the same legislation or textbook passage share wording that neither of
them wrote: measured on two unrelated answers carrying one shared quotation, similarity was
**44.8% with the quotation left in — above the reporting threshold, a false copy match —
and 0.0% with it removed.**

### The assessment template is excluded (1.1.5)

A real RTO submission carries the assessment tool's own text — the cover sheet, the RTO code,
the instructions, the misconduct declaration, the question. Every student submits it because the
template told them to, so it is not evidence about anybody. Measured on a ten-student cohort
with one ordinary 185-word cover sheet, counting it made **44 of 44 innocent pairs** read as
matches, and two students answering **different units** reached 48% on the cover sheet alone.

Wording carried by at least a third of an activity's submissions is treated as template and
removed before comparison. On that cohort the real copy pair holds at 90.9% and the worst
innocent pair falls to 9.2%.

**No pair is reported until an activity has three submissions.** With two documents, shared
wording is either the template or a copy and nothing in the data separates them — two
independent answers on a cover sheet measure 50.5%. The report says so, and fills in when the
third student submits.

### Authenticity findings (1.2.0)

Reported individually, each with its severity and the evidence quoted. **There is no composite
score**, because weighting these against one another needs calibration against real student
submissions and inventing weights is how the pre-1.0.99 badge became a confident number built
from signals that discriminated nothing.

| Check | Severity | Notes |
|---|---|---|
| Chat-assistant artefacts, prompt remnants | **strong** | A student would have to type the assistant's own words |
| Markdown in a word-processed document | notable | Never strong: honest drafting in a notes app produces it |
| Legislation not in the recognised list | notable | **Unrecognised, not fabricated** — the list is curated, not the statute book |
| A real Act cited with a year it never had | **strong** | A common signature of a reference that was not looked up |
| What the file records about itself | context only | Editing minutes, saves, created-to-modified span, PDF producer |

**These checks find evidence of pasting, not evidence of AI authorship.** Measured: 0 findings
across all 73 corpus documents, including 48 generated by ChatGPT, Gemini and Claude, because
those were stored as clean answer text. Run against the **raw** chat output as it leaves the
interface, the same checks produced 10 strong findings for ChatGPT and 10 for Gemini. A learner
who pastes only the answer body leaves nothing behind. The report panel says this itself.

Each submission also gets **verification questions** drawn from the learner's own wording. If a
learner can explain what they submitted, that settles the question in their favour better than
any check above; if they cannot, that is a conversation about competence, which is the
assessor's to have and not the software's.

### Scope, stated plainly

The comparison covers **submissions to the same activity only**. DocGuard does not check
the web, published sources, essay banks, other courses, or previous cohorts. A LOW score
means no other submission to this activity shares significant wording with this one. It
means nothing else.

### The writing-style signals were removed in 1.0.99

Releases up to 1.0.98 scored each submission 0–100 across eleven writing-style signals —
marker vocabulary, sentence-length uniformity, transition density, contraction absence,
passive voice, type-token ratio, trigram repetition, sentence-opener uniformity and others.

They were tested against **48 answers to real VET assessment prompts generated by three
different current language models** (Claude, ChatGPT and Gemini), each answering naturally
and again rewritten to sound like a student who struggles with written English, at
129–196 words — the realistic length for a VET short answer.

| Document class | n | Range | Flagged |
|---|---|---|---|
| Genuine machine-written | 48 | **0–14** | **0 of 48** |
| Human | 11 | 0–27 | 0 of 11 |

**S1, the largest signal at 22 of 84 points, found zero markers in 48 of 48.** The
highest-scoring document in the whole exercise was a human second-language student essay,
at nearly twice the highest machine-written document.

The published evidence for the individual signals is weak or contradictory. Marker
vocabulary is real at corpus scale (Kobak et al., *Science Advances* 2025) but the words
decay once known — `delve`, `intricate`, `showcasing`, `realm` and `pivotal` have been
declining in published writing since March 2024 (Geng & Trotta, arXiv:2502.09606).
Sentence-length variability measures *r* = −0.13 on real student writing. GPT-4 uses
*fewer* discourse markers than students (Herbold et al., *Scientific Reports* 2023). Claude
emits contractions at 30,611 per million words against GPT-3.5's 120.

And the signals share a mechanism with a known harm: they score low lexical and syntactic
complexity as machine-like, which is what Liang et al. (*Patterns*, 2023) measured
producing a **61% false-positive rate against non-native English writers**.

They were removed rather than left on display as observations. Anything shown beside a
copying verdict is read as corroborating it, which is how institutions came to over-rely on
detector output in the appeals the OIA upheld in 2025.

### What this plugin is not

An AI-writing detector. It does not estimate whether a submission was written by a language
model, and no score it produces is evidence of that. TEQSA's position (2025) is that
"detecting gen AI use with certainty in assessments is, at this point, all but impossible".

A similarity score is a reason to read both submissions. It is not a finding.

## Text retention and copy detection are in direct conflict

This is worth understanding before setting a retention period.

Copy detection needs `normtext` — the stored, normalised text of a submission. The cleanup
task deletes that text after `retentiondays` (default 90). **Once it is gone, that
submission can never be compared against anything again.** Observed on a live site: of
three analysed submissions, two already had their text pruned, so only one was comparable
and no pair could be formed at all.

Within a single activity this rarely matters — classmates submit within days of each other.
It matters completely for the thing RTOs most need to catch: **next year's intake copying
this year's work.** A 90-day retention period makes cross-cohort detection impossible by
construction, because last year's text is already deleted.

The resolution, if cross-cohort comparison is ever built, is **not** to retain the text
longer. It is to store an irreversible similarity fingerprint — a set of hashed word
bigrams — alongside the text, and let the cleanup task delete the readable text on schedule
while keeping the fingerprint. A fingerprint cannot be read back as the student's writing,
so it is *better* for privacy than what is stored today, and it is all the comparison needs.
That is a design note, not a feature: nothing in this release does it.

### What governs sections

Not a setting. `question_parser` drops any section shorter than 40 characters, and falls
back to treating the whole document as one section if that leaves nothing.

Releases up to 1.0.99 offered a **"Minimum section words"** setting, described as "Sections
shorter than this are skipped". It was never read by any code — it was saved, displayed and
ignored. It was found because a live site had it deliberately set to 200, which is not the
default, so somebody had configured it expecting an effect. It was removed in 1.1.1 rather
than implemented, because sections are no longer analysed at all: they exist to show a
marker the text that was extracted, and hiding short ones from them would be a loss rather
than a control.

---

## Upgrading from 1.0.98 or earlier

**The score changed meaning in 1.0.99.** It used to be a sum of writing-style signals; it
is now the percentage of a submission's wording that appears in another submission to the
same activity.

Submissions analysed before the upgrade keep their old number, and DocGuard labels them
rather than reinterpreting them — both the class report and the per-student report say
plainly that they were scored under the previous model. Nothing in the database is altered
by the upgrade.

To rescore a submission against the current model, use the **Re-analyse** link beside it.
The copying table is unaffected either way: it is computed fresh from the stored text on
every page load, for every analysed submission, whichever model scored it.

There is deliberately no bulk-rescore button yet. It needs new task and database code, and
that is the one area of this plugin that has repeatedly shipped defects, so it is being held
for a release that can be verified against a real Moodle first.

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
| DocGuard — analyse a submitted assignment | Ad-hoc task queued by the submission event observer. Holds the extraction and scoring for one submission, so that work runs under cron instead of inline in the student's submit request. |

DocGuard depends on Moodle cron running regularly. Since v1.0.92 that includes analysis of
new submissions: the event observer queues an ad-hoc task rather than analysing inline, so
a badge reads "Pending" until the next cron run picks it up. If submissions stay on
"Pending" for longer than your cron interval, check the cron schedule first.

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
