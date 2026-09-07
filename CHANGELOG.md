# Changelog

## 1.0.90 - 2026-09-07

**Second release-pipeline pass**

Version: `2026090701`. No functional change. Applies to DocGuard everything Essay Guard's
second pipeline run turned up, so the two plugins are checked to the same standard before
upload. Every code edit was verified token-identical to 1.0.89 with `token_get_all()`, and the
214 language-string values were compared by evaluating `$string` before and after - same hash
both times.

### Fixed - the language file still concatenated

1.0.89 joined each assignment onto one line but left the `.` operators in place, and AMOS does
not accept concatenation in any form. All 47 are now single string literals.

### Fixed - the README stated a version and a Moodle range that were both wrong

It claimed version 1.0.80 (ten releases behind) and Moodle 4.0 - 5.1, quoting
`$plugin->requires = 2022041900` in prose, while `version.php` declares `requires =
2024100700` and `supported = [405, 502]` - Moodle 4.5 LTS to 5.2. The version line is now a
pointer to `version.php` and the changelog rather than another hand-maintained copy of the
release string.

### Changed - the risk labels are no longer stored upper-case

`HIGH RISK`, `LOW RISK`, `MEDIUM RISK`, `HIGH`, `LOW`, `MEDIUM` and `FIRED` are now stored in
sentence case and upper-cased at the point of display with `core_text::strtoupper()`, so every
badge renders exactly as before. `TTR` and `TTR std dev` became `Type-token ratio` and
`Type-token ratio std dev` in 1.0.89.

One label does change on screen: the class report's `HIGH concern` now reads `High concern`.
It is the only place the upper-case form was mixed into a sentence, where shouting reads as a
mistake rather than emphasis.

### Changed - coding style

59 further multi-line calls now put the opening parenthesis last on its line, across the
plugin and its test suite.

## 1.0.89 - 2026-09-07

**Release-pipeline conformance**

Version: `2026090700`. No functional change. Essay Guard 1.2.229 was rejected by the LMS-Labs
release pipeline on a blocker, two errors and four warnings; the same checks were applied to
DocGuard before upload and the items below are what they found. Every code edit was verified
token-identical to 1.0.88 with `token_get_all()`, so the plugin's behaviour is unchanged.

### Fixed - thirdpartylibs.xml was missing

Required even when a plugin bundles nothing. DocGuard bundles no third-party libraries, so the
file now declares that explicitly rather than leaving it to be inferred from absence.

### Fixed - multi-line string concatenation in the language file

47 `$string[...]` assignments were split across continuation lines. Each is now a single-line
assignment. The 214 resulting string values were compared before and after and hash identical.

### Changed - two metric labels no longer lead with an acronym

`TTR` and `TTR std dev` become `Type-token ratio` and `Type-token ratio std dev`. These are
labels a teacher reads on the report, so spelling them out is worth doing for its own sake.

### Changed - coding style

24 multi-line `debugging()` and `mtrace()` calls now put the opening parenthesis last on its
line. No `PARAM_RAW` usage and no lowercase-leading comment blocks were present to fix.

## 1.0.88 - 2026-09-07

**The doors nobody checked**

Version: `2026082906`. No database schema changes; two upgrade steps repair previously-stored
rows that are wrong.

Seven defects, found the way the dead submission pipeline was found in 1.0.87.1: by reading the
real Moodle core source for every contract the plugin relies on, rather than the plugin's own
description of it. Five of the seven are places where a rule the plugin already enforces
somewhere was simply absent somewhere else.

### Fixed - deleting an activity left the student's document text behind, beyond GDPR reach

DocGuard observed no deletion event at all. Deleting an assignment left every
`plagiarism_docguard_sub` row — `normtext` holds the complete extracted text of the student's
document — and every `plagiarism_docguard_sec` row, holding up to 65,000 characters of their
verbatim answer per section.

Those rows were not merely stale, they were unreachable. `course/lib.php::course_delete_module()`
deletes the module context **before** it triggers `course_module_deleted`; both privacy paths key
on `contextid`; and `core_privacy`'s `contextlist_base::get_contexts()` wraps
`context::instance_by_id()` in a try/catch and silently drops any context it cannot instantiate.
So the data never appeared in a subject access export, an erasure request deleted nothing and
reported success, and `delete_data_for_all_users_in_context()` was never called for a context that
had ceased to exist. The only remaining deletion route was an administrator running SQL by hand.

Fixed with a `\core\event\course_module_deleted` observer, plus a nightly orphan sweep in the
cleanup task — because deleting a *course* fires no per-module event at all
(`lib/moodlelib.php::remove_course_contents()` deletes contexts and `course_modules` rows
directly), and because no event can reach rows already stranded by earlier releases. The upgrade
step purges those.

### Fixed - the cron task that recovers stuck submissions had no licence gate

The event observer, the historical backfill and the `scan_activity` task all called
`plagiarism_docguard_check_unlock()`. Phase 1 of `process_pending` never did, so it was the one
processing path on the site with no licence gate — analysing every stuck record, every hour, on a
site where `print_disclosure()` had stopped telling students their work was being checked and the
settings page reported "Credentials not configured".

### Fixed - the historical backfill could never pick up a student's second document

Its candidate query selected `{assignsubmission_file}` rows — one row per *submission*, not per
file — and excluded a row as soon as the student had **any** DocGuard record in that activity.
A student who attached two documents of which one was already analysed was dropped from the
window entirely; the finer-grained content-hash check inside the loop could never run for them.
The second document's badge said "Plagiarism Check Pending" indefinitely, with nothing anywhere
saying why.

The window is now built from `{files}` and excluded on `(cmid, userid, contenthash)` — the same
key `analyse_and_store()` uses — and the terminal "unsupported" marker is written per file with
the file's real name and hash instead of once per submission with both fields empty. The upgrade
step clears the old empty-hash markers so they are re-derived.

### Fixed - the privacy registry under-declared what the plugin stores

`get_metadata()` declared eight of the sixteen columns of `plagiarism_docguard_sub` and five of
the twelve of `plagiarism_docguard_sec`, while every one of them is written for every analysed
submission. The omissions included `plagiarism_docguard_sec.userid`, a direct user identifier in a
table the registry described only as "per-section analysis scores"; `analysisjson` and
`signalsjson`, which quote phrases from the student's own writing; and `section_label`, text taken
verbatim from their document. That is the content of the site's record of processing, and it was
already inconsistent with the plugin: the export has returned four of those fields since 1.0.82.

All columns are now declared, and a test derives the expectation from the live schema so the two
cannot drift apart again. Section rows are also now discoverable and erasable by their own
`userid` rather than only through their parent record.

### Fixed - three re-analyse endpoints ignored the licence gate

`reanalyse.php`, `report.php?dg_action=reanalyse_pending` and `student_report.php?reanalyse=1` all
extract and store student document text and none of them asked whether the site was licensed. They
now share one credentials test with `print_disclosure()` — credentials rather than a full
`check_unlock()`, because these are web requests and a cache miss there costs up to 15 seconds
holding the session write lock.

### Fixed - report.php's Analyse action bypassed separate groups

`report.php`'s page body was hardened for `SEPARATEGROUPS` in 1.0.80, `student_report.php` in
1.0.80/1.0.81 and `reanalyse.php` in 1.0.84 — and the "Analyse" action living inside the first of
those files was missed every time. It checked only report visibility and a matching `cmid`, so a
teacher restricted to one group could put any `subid` from the activity in the query string and
trigger re-extraction and re-storage of another group's student's document text. All four doors now
call one shared `plagiarism_docguard_user_visible()`, which is what stops this recurring.

### Fixed - "Test connection" always ended on Moodle's error page

`testconnection.php` redirected to `/admin/settings.php?section=plagiarismdocguard`.
`core\plugininfo\plagiarism::load_settings()` registers each plagiarism plugin as an
`admin_externalpage` pointing straight at the plugin's own `settings.php`, and `admin/settings.php`
throws `sectionerror` for anything that is not an `admin_settingpage`. So the administrator never
saw the licence verdict, which is the entire output of that script. The plugin already knew: the
1.0.83 note in `settings.php` records this exact fact.

### Also

`plagiarism_docguard_find_submissionid()` asked `get_record()` for `(assignment, userid)` alone,
which throws `dml_multiple_records_exception` on any activity configured for multiple attempts and
matched group submissions too; it now asks for the row mod_assign flags as `latest`. And
`check_unlock()`'s request-level memo is keyed on the credentials it was computed for, so
`testconnection.php` clearing the config cache actually produces a fresh answer.

Suite: 208 tests, 684 assertions, green on Moodle 4.5.13 (PHPUnit 9) and 5.2.2 (PHPUnit 11).
`phpcs --standard=moodle`: zero violations.

## 1.0.87 - 2026-08-29

**Real toolchain, real standard**

Version: `2026082905`. No database schema changes.

Everything previously reported as "verified" was measured with tools built in-house because
the real ones could not be installed. That turned out to be wrong: Moodle, PHPUnit and the
Moodle coding standard can all be built from git source. Redone properly, with the real
tools, this release is what they found.

### Fixed - a malformed PDF could hang or exhaust the cron worker

`extract_pdf_php()`'s CMap parser read a `beginbfrange` triple as
`<from> <to> <start>` and looped from `from` to `to`, allocating an array entry each
iteration, with no bound on the distance between them. A crafted or corrupt CMap containing
`<00000000> <FFFFFFFF> <0041>` asks for **4.29 billion iterations**.

This was the one PDF path with no timeout guard. Both CLI branches are wrapped in
`timeout 60` precisely because "corrupt/malformed PDFs hang the cron worker indefinitely" —
but the pure-PHP fallback that runs when those tools are absent had nothing equivalent, and
since v1.0.86 we know that fallback is the path most managed hosts actually take.

Ranges are now clamped to 65,536 entries — every code point a 2-byte CID font can address —
and a reversed range is skipped. Measured: the hostile input above now completes in 0.01s
using 3 MB, with a developer-level warning, instead of running until the worker dies.

### Changed - the real Moodle coding standard, from 1,333 violations to zero

Previous releases reported "phpcs clean" against a ruleset assembled by hand, because
`moodlehq/moodle-cs` could not be installed from Composer. Built from git source instead,
the real standard reported **1,333 violations**. The proxy had missed, among much else, the
rule that Moodle forbids underscores in variable names — 422 instances here.

Now zero. The work was: 888 auto-fixable formatting fixes, 422 variable renames done through
PHP's tokenizer (never a text regex, so object properties and array keys — several of which
are database column names — could not be touched), and comment capitalisation and
punctuation with the text preserved word for word. Section-banner comments that cannot
satisfy the sniff in any arrangement were converted to block comments with their text
byte-identical rather than reworded.

Correctness was gated on the real test suite after every stage, and a token-level semantic
diff proves the only changes are variable renames, four unnecessary `MOODLE_INTERNAL`
guards removed, and two `require_once` parenthesis pairs.

### Verified - on real Moodle, both branches

| | Moodle 4.5.13 | Moodle 5.2.2 |
|---|---|---|
| PHPUnit | 9.6.36 | 11.5.56 |
| Result | **OK (137 tests, 335 assertions)** | **OK (137 tests, 335 assertions)** |

Real PostgreSQL, real Moodle installs. The suites were also proved non-hollow: reintroducing
the duplicate-AI-marker defect made the suite fail, and restoring it made it pass.

### Noted - the plugin class scheme is vestigial

Runtime probing on real Moodle 4.5 established that `classes/pluginclass_standalone.php` is
the live file and `classes/pluginclass.php` is unreachable: `plagiarism_plugin` is declared
in `plagiarism/lib.php`, which core never loads and which is not autoloadable, while both of
the autoloader's fallbacks target `lib/plagiarismlib.php`, which defines only functions.
An earlier audit had this backwards. On 5.2, `plagiarism_update_status()` — the core function
the whole scheme defends against — has been removed entirely. Both files are retained for now
and the duplicate-class warning is suppressed with an explanation; collapsing them is a design
change, not a style fix.

## 1.0.86 - 2026-08-29

**Per-question analysis, actually working**

Version: `2026082903`. No database schema changes.

Found by testing 1.0.85 on a live site. 1.0.85 fixed one cause of "1 section(s) analysed /
Full Document" and shipped believing it was the cause. It was not the only one, and it was
not the common one.

### Fixed - an indented question heading was never recognised

The section-marker pattern was anchored with a bare `^`, requiring the heading to begin at
column zero of its line. Extracted text is indented far more often than not:

- **Ghostscript's `txtwrite` device prefixes every line with several spaces.** On a host
  with Ghostscript but no poppler — common on managed Moodle hosting — per-question
  analysis had never once worked, on any document.
- **`pdftotext -layout` deliberately preserves the source document's indentation.** So any
  assessment whose questions sit in a table, under a hanging indent, or in a numbered list
  — which is most of them in the VET sector this plugin serves — failed on every host,
  poppler or not.

Both produced the same silent outcome: the whole document scored as one "Full Document"
section, with no error and nothing to suggest anything had been missed.

The pattern now allows leading spaces and tabs. It still requires the marker to start its
own line, so the protection that anchor exists for is intact — a mention of "Question 1"
inside a sentence still cannot open a section, truncate the real one, and attribute a
student's text to the wrong question. Both halves are pinned by tests.

A related fault in the same rules: on an indented line the label-word match also failed, so
every section was labelled "Section N" whatever the document actually said. A teacher
reading "Question 2" in the document saw "Section 2" in the report.

### Why 1.0.85 did not find this

1.0.85's fix — teaching the pure-PHP PDF fallback to emit line breaks — is real and is
retained. But the test fixture used to verify it was a PDF whose text sits flush against
the left margin, so the indentation case never arose. On the live site the same document,
byte for byte, still reported one section: the server reached it through Ghostscript, whose
output is indented, and the marker pattern rejected every heading.

**Records analysed before this release are wrong and stay wrong until re-analysed.** The
Re-analyse control on the student report and the class report's scan action both re-extract
from the original file.

## 1.0.85 - 2026-08-29

**Correctness pass**

Version: `2026082902`. No database schema changes.

This release finishes the work 1.0.84 started. 1.0.84 fixed what the new tests found;
1.0.85 fixes the items left as judgement calls, on the principle that a plugin must not
claim to do something it does not do.

### Fixed - every PDF submission was reported as one "Full Document" section

On any server with neither `pdftotext` (poppler-utils) nor Ghostscript installed - which
is most shared and managed Moodle hosting, and exactly the case the built-in parser
exists to cover - a PDF containing three clearly marked questions was analysed as a
single section labelled "Full Document". Per-question similarity, the plugin's main
feature, was silently dead on those hosts.

A PDF has no newline character. A line break is a text-POSITIONING operator - `Td`, `TD`,
`T*` or `Tm` - that moves the text matrix down the page. The pure-PHP fallback recognised
only the text-SHOWING operators (`Tj`, `TJ`, `'`, `"`); the positioning operators fell
through to a catch-all that skipped them one byte at a time. The whole page therefore came
back as one space-joined run with not one `\n` in it. `question_parser` splits on
`explode("\n")` and anchors its marker patterns with `^`, so it could not match a single
marker. Nothing in the report gave the problem away, because the display path collapses
whitespace: the rendered text looked identical either way.

The fallback now tracks the origin of the current text line and emits a break whenever
that origin moves vertically, and a space when it moves horizontally far enough to be a
word gap. Because the rule is geometric rather than tied to one operator, it works for
every producer: `Tm` per line (Word, LaTeX, TCPDF, wkhtmltopdf), relative `Td`/`TD`, and
`T*` driven by `TL`. `'` and `"` already emitted a break and now also keep the tracked
position in step, so a following `Tm` compares against the right position.

The `pdftotext` and Ghostscript paths are untouched - byte-identical output verified
against the test submission - and the fix only ever adds whitespace, so stored
`normtext` and every similarity score computed from it stay comparable.

`question_parser` was deliberately NOT relaxed to match markers mid-string as a second
line of defence. "Part 2 of the Act", "Section 5 of the WHS Regulations" and "the hazard
described in Question 1" are ordinary prose inside student answers; an unanchored match
would open a section mid-sentence and attribute text to the wrong question, which reads as
an authoritative score and is wrong. Failing to the whole document analyses everything and
mis-attributes nothing. The reasoning is recorded on `try_labelled()`.

### Fixed - an unlicensed site behaved as though it were licensed

The licence check returned "unlocked" when the site had no Site ID and no API Key at all,
while the settings page reported "Credentials not configured". Two components described
the same site in opposite terms, and nothing in the product could tell an administrator
whether analysis was actually running.

It now fails closed on missing credentials. The distinction from a vendor outage is
deliberate and both halves matter: an outage is transient and outside the administrator's
control, so that path still fails OPEN and analysis continues. Missing credentials are
permanent until someone acts and visible on the settings page, so failing open there just
means behaving as though licensed for as long as nobody notices.

**This changes behaviour for any existing site that never entered credentials: it stops
analysing.** That is the intended outcome - such a site was never licensed - and it is
surfaced rather than silent. The settings page states it plainly, saving credentials
clears the cached verdict so they take effect at once, and the student disclosure now stays
silent rather than telling students their work is being checked when it is not.

### Fixed - quiz support was advertised but has never existed

A `docguardQuizzesEnabled` flag on the vendor platform made the plugin report itself active
on quizzes. DocGuard has never processed a quiz - it supports assignments only, and every
extraction and cron query is joined to the assignment table - so the student saw the
plagiarism disclosure on a quiz, the teacher saw DocGuard reported as active, and nothing
was ever analysed. A site could have believed its quizzes were covered indefinitely.

The activity type is now decided by the code that actually knows what can be processed, and
a platform flag can only enable a type the plugin can genuinely handle. Assignment
behaviour is unchanged.

### Fixed - twenty-six upgrade blocks for eleven versions

Five identical blocks at one version, ten at another. All were savepoint-only so no site
was harmed, but repeated savepoints at one version read as a mistake to anyone auditing an
upgrade path, and the next person adding a step had no way to tell which of ten identical
blocks was the live one. Consolidated to one block per version with every explanatory
comment kept verbatim - verified by comparing the complete comment text before and after.

### Fixed - one database query per similarity match

The cross-student comparison fetched each matched student's record inside the loop. On an
activity where a class works from a shared template - exactly when this feature has
anything to report - most comparisons match, so the query count grew with the cohort, once
per submission. Now one query for all of them.

### Fixed - the settings page did not say where the credentials came from

When the AI Config plugin is installed, DocGuard silently prefers its values. An
administrator could edit the Site ID field, save, and watch the old value return with
nothing explaining why - and had no way to tell which credentials were being sent when
debugging a licence failure. The page now says which source is in force.

## 1.0.84 - 2026-08-28

**Marketplace-readiness pass**

Version: `2026082901`. No database schema changes.

This release is the outcome of a full pre-submission review: the Moodle coding standard,
the first unit-test suite this plugin has had, and the defects both of those uncovered.

### Fixed - identical Chinese, Japanese and Thai submissions scored 0% similarity

v1.0.80 fixed `normalise_for_similarity()` so non-Latin text survives normalisation
instead of becoming an empty string. That was only half the defect. `bigrams()` and
`bigram_set()` both tokenised on the space character alone, so a normalised Chinese
document was one token, which yields zero bigrams, which makes the Jaccard comparison
return 0.0 - even comparing a document with itself. Cross-student similarity has been
silently reporting 0% for every CJK and Thai cohort since the feature shipped.

A run of CJK or Thai characters is now split per character, so a bigram is a character
pair. Text in a spaced script contains none of those characters, takes exactly the
whitespace split it always did, and produces byte-identical bigram sets - verified
against the previous implementation - so every stored comparison stays valid.

### Fixed - two duplicated words in the signal lists inflated scores

`landscape` appeared twice in the AI-marker list and `meanwhile` twice in the transition
list. Both signals walk their list and count matches, so one occurrence of either word in
a student's text counted as two and was shown to the teacher twice. For the AI marker that
was enough on its own to move the signal from the one-marker band (3 points) to the
two-marker band (8); for the transition word it crossed the rate threshold and awarded 5
points a single connector should not earn.

### Fixed - the student disclosure stated the opposite of the site's actual policy

`get_config()` returns `false`, not `null`, for a setting an administrator has never
saved. Three components resolved that unset retention period differently: the disclosure
read it as 0 and told the student "extracted text is retained indefinitely", while the
cleanup task defaulted to 90 days and pruned, and the settings page displayed 90. The
component facing the student - the disclosure that exists to state the data policy
accurately - was the one that had it wrong. All three now resolve it in one place.

### Fixed - an offline site paid a 15-second timeout on every request

The licence check wrote its cache only on the path where the vendor answered. On a site
whose outbound network is blocked, the two fail-open paths therefore re-ran the whole
check on every request: a 5-second connect plus 10-second total timeout, every page load,
permanently. Because the session-lock release is guarded to AJAX and CLI, an ordinary web
request also held the Moodle session write lock for that window and serialised every other
request from the same browser. All four exit paths now write the cache; a failure is
cached for five minutes rather than thirty so a transient outage still recovers quickly.

### Fixed - re-analyse ignored separate-groups restrictions

`report.php` and `student_report.php` were hardened for `SEPARATEGROUPS` in v1.0.80 and
v1.0.81; `reanalyse.php` was missed. It checked only the report capability and that the
record belonged to the activity, so a teacher restricted to one group could trigger
re-extraction and re-storage of a submission from another group. It now applies the same
restriction as the two report pages.

### Fixed - a deliberate zero in "minimum section words" was silently replaced

`?: 30` fires on a legitimate `0` as well as on an unset key, so an administrator who
entered 0 to analyse every section saw 30 come back.

### Added - the first test suite

122 cases and 295 assertions across `question_parser`, `analyser` and `extractor`,
including regression tests for each defect above and for the v1.0.81 `\p{N}` regression
that broke maths worksheets. Every expected value was observed from a run of the real code
rather than assumed.

### Changed - coding standard

125 style violations cleared: line length, PHPDoc parameter and return tags, multiple
statements per line, commented-out code and one function nested eight levels deep. No
behaviour changed; the language file was rewrapped and proven to produce a byte-identical
string array.

