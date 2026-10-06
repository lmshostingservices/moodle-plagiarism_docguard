# Changelog

## 1.1.3 - 2026-10-06

**From the first full run of the test suite on a real Moodle.** Version `2026101600`. No
schema change.

**192 passed, 1 failed, 1 skipped, 631 assertions.** Both the failure and the skip are
addressed here. Sixty of those tests had never been executed before — they need Moodle's
data generator and file API, which no offline harness can honestly fake.

### A deleted activity was reported as disabled

`scan_activity::execute()` checked `plagiarism_docguard_is_cm_active()` **before** checking
whether the course module still existed. `is_cm_active()` returns false for a deleted
module, so a queued scan whose activity was removed before cron reached it reported:

> DocGuard is not enabled for this activity, abandoning scan

…and the `no longer exists` branch below it was unreachable.

Nothing was analysed wrongly — the scan stopped either way. The damage was to the
diagnostic: an administrator reading cron output was sent looking for a setting on an
activity that no longer exists.

Existence is now checked first, which is also the cheaper question: a deleted activity
short-circuits before the per-activity config lookup and before `check_unlock()`, which
performs a licence verification.

Caught by `test_execute_when_activity_has_gone`, which calls `course_delete_module()` and
asserts the message.

### The PDF extraction tier is now visible

The digit-heavy extraction test skipped on that server because **`pdftotext` is not
installed**. PDF text is extracted by a three-tier cascade — `pdftotext`, then Ghostscript,
then a pure-PHP fallback that the code itself describes as *lowest* quality — and nothing
anywhere told an administrator which tier their server was on. That site was silently
running the fallback.

This is not cosmetic. **The comparison runs on extracted text**, so a poor extraction
produces a similarity figure about nothing.

`extractor::extraction_tools()` now reports the tier. The settings page shows it in colour
with the remedy, and `docguard-verify-install.php` reports it too:

```
pdftotext (poppler-utils) : NOT INSTALLED
ghostscript               : not installed
tier in use               : pure-php (lowest quality)
```

The remedy on a Debian/Ubuntu server is `apt install poppler-utils`.

## 1.1.2 - 2026-10-06

**A measurement helper that measured the wrong thing.** Version `2026101500`. No schema
change.

Found while writing an install-verification script to run on a real Moodle — the script
produced numbers that did not match the audit, and the script was right to be suspicious.

### `question_parser::similarity()` removed

It wrapped PHP's `similar_text`, a character-level longest-common-substring measure. Copy
detection has **never** used it — that is `jaccard_sets()` over word bigrams — and the two
do not agree even approximately:

| Pair | Old helper | Production measure |
|---|---|---|
| Independent answers, same question | **54.4%** | **9.8%** |
| Unrelated topics (control) | **23.0%** | **1.1%** |
| Identical text | 100% | 100% |

Any two pieces of English prose share most of their characters, so it reported 23%
similarity between a hand-washing procedure and a paragraph on mitochondria.

**It was never called by production code.** It was called by two tests, and that is the
damage: `test_a_shared_quotation_is_not_reported_as_copying` — the guard for one of this
release series' real fixes — was asserting against the wrong measure and passing anyway.
The test for the fix was not measuring the fix.

Removed rather than renamed, and the test now measures exactly what
`cross_student_similarity()` measures, with a mutation guard that fails if it drifts back.

### The mutation runner was blind to its own failure

When the checker crashed on the removed function it produced no output — which is
indistinguishable from "no guard fired". **16 of 17 mutations reported NOT CAUGHT**, and
the suite reported a catastrophe that was really one stale call.

It now refuses to interpret a crashed checker as a result, and refuses to run at all if the
unmutated tree already fails a guard.

That second check immediately earned itself: it caught that the explanatory comment left
behind in `question_parser.php` *quoted* the removed code, so a guard matching on the
string `similar_text(` matched the comment. Third time in this release series that a guard
has matched a comment rather than code.

### Verification

17 known-answer checks now run against deployed code on a real Moodle via
`docguard-verify-install.php`, and reproduce the audit figures: 9.8% independent, 87.1%
genuine copy, 1.1% control, 57.9% for two answers sharing one long quotation before
stripping.

## 1.1.1 - 2026-10-06

**Findings from a live site.** Version `2026101400`. No schema change.

A live installation was queried — the first time anything in this plugin has been checked
against a real site rather than a corpus written for the purpose. Two things came out of it,
and one of them is a defect no amount of reading the code would have found.

### "Minimum section words" was a dead setting

It was saved, read back, rendered in the settings form, and described to the administrator
as *"Minimum word count per section to include it in analysis. Sections shorter than this
are skipped."*

**Nothing in the plugin ever read it.**

It was found because the site had it set to **200** — not the default of 30. Somebody
configured it deliberately, expecting an effect, and got none.

Removed rather than implemented. Sections are no longer analysed at all — the comparison
runs on the whole document — so the setting cannot do what it claims, and hiding short
sections from a marker would be a loss rather than a control. The real behaviour, which the
setting never governed, is a 40-character minimum per section with a fallback to treating
the whole document as one section. That is now documented instead of being misdescribed by
a control that did nothing.

The orphaned config row is left in place rather than deleted, so an administrator
downgrading does not silently lose a value they set.

### Text retention and copy detection are in direct conflict

Copy detection needs `normtext`. The cleanup task deletes `normtext` after
`retentiondays` (default 90). **Once it is gone, that submission can never be compared
again.**

On the site queried: of three analysed submissions, **two had already been pruned**, so one
was comparable and no pair could be formed at all.

Within a single activity this rarely bites — classmates submit within days of each other. It
makes **cross-cohort detection impossible by construction**, which is the thing an RTO most
needs to catch: next year's intake copying this year's work.

Now documented in the README, with the resolution for whenever cross-cohort comparison is
built: store an irreversible hashed-bigram fingerprint that survives pruning. A fingerprint
cannot be read back as the student's writing, so it is *better* for privacy than retaining
the text, and it is all the comparison needs. Nothing in this release implements it.

### Also confirmed on that site

| | |
|---|---|
| Installed version | `2026091600` — release **1.0.92**. Nothing from this release series is deployed. |
| `plagiarism/docguard:viewreport` | Granted to teacher, editingteacher and manager, with no context override blocking it — the 1.0.77 fault is fixed there |
| Stuck or errored records | None |
| Orphaned rows | None, in either table |
| Old score bands | All 3 submissions LOW (max 17) — too few to mean anything |

The upgrade path from `2026091600` was simulated end to end: **nine savepoints,
monotonically increasing, no duplicates, none re-running a completed step, final savepoint
equal to `version.php`.**

### What that site could not tell us

Three submissions, one activity, one comparable submission, **zero pairs**. The question
that decides whether this plugin is usable — how often two real submissions reach 35% — is
completely untouched by it. That needs a site with real submission volume.

## 1.1.0 - 2026-10-06

**Finishing what 1.0.99 started.** Version `2026101300`. No schema change; nothing in the
database is altered by the upgrade.

1.0.99 changed what the score means and removed the style signals, but left the old model's
debris behind — including the text a teacher actually reads.

### The reports were still describing an AI detector

| Where | Said | Now says |
|---|---|---|
| Interpretation guide | "Multiple strong AI-writing indicators present" | "Most of this submission's wording also appears in another submission" |
| Badge tooltip | "HIGH risk (78/100). Significant signals detected" | "78% similar to another submission in this activity. Open both and read them." |
| Band labels | "High risk" | "High similarity" |
| Student disclosure | "analysed for indicators of plagiarism and AI-generated writing" | describes the comparison that is actually performed |
| Privacy metadata | "per-signal findings" | describes what is actually stored |
| Pair concern labels | "High concern" | "Most wording shared — read both" |

A trainer reading a similarity percentage under a heading about AI-writing indicators was
being actively misled, and that is the text people act on.

### The same threshold defect, a third time

`report.php` coloured and labelled pairs at **0.70 and 0.50** while the bands are **65 and
35**. A pair at 60% was labelled the middle tier in the table while its submission was
banded MEDIUM elsewhere; a pair at 67% was labelled middle while its submission was HIGH.

This is the third appearance of one defect:

1. 1.0.93 — the S12 reporting threshold had drifted from the scoring threshold
2. 1.0.94 — band literals duplicated out of `band()`
3. 1.1.0 — these

Every threshold a reader can see now comes from the constants, with a mutation guard that
reintroduces the literals to prove the test catches them.

### The copy decision is testable now

Which submissions to rescore, and to what, was a dozen `$DB->set_field()` calls inside the
observer, so no test could execute it. **That is the exact condition that hid both defects
shipped in 1.0.94.**

It is now `analyser::plan_copy_evidence()` — a pure function over plain arrays, six tests,
five mutation guards — and the observer holds nothing but the reads and the writes.

The rule most worth a test: a matched submission is **raised only, never lowered**.
Otherwise a student could clear an existing 90% match by submitting again and matching
someone else at 40%.

Mutation testing also caught a bad test of my own here: my "takes the highest match" test
put the highest match **last** in the list, so it passed even if the code took the last one.
Fixed the fixture, not the mutation.

### 43 tests had never been executed; 57 now run

There is no Moodle in the build environment, so `tests/` had only ever been lint-checked.
The standalone runner now stubs `set_config`, `get_config`, `make_request_directory`,
`mtrace`, `DAYSECS` and the task base classes.

**57 pass, 0 failures, 34 honestly skipped** as needing a real Moodle database — reported as
skipped rather than passed, because a harness must never claim coverage it does not have.

Two failures it surfaced were the harness's own fault, and both were worth fixing: the
config store persisted between test methods where Moodle's `resetAfterTest()` rolls it back,
inventing a failure in a test asserting the 90-day retention default; and a silent `mtrace`
stub swallowed output that a task test captures.

### Copying leads the class report

It used to sit below the submissions table under a column of style scores. It is the only
check this plugin performs that compares a submission against anything.

Moving it surfaced a regression in the move itself: the user lookup stayed below the block
that names students, so every name in the copying table would have rendered as `#42`.
Caught by checking definition order before packaging.

### Legacy rows

The class report counts submissions still carrying a score from the previous model and
explains them in place. **No bulk rescore button yet** — it needs new task and database
code, which is the one area of this plugin that has repeatedly shipped defects, so it waits
for a release that can be verified against a real Moodle. Per-submission **Re-analyse**
works now, and the copying table is computed fresh from stored text regardless of which
model scored a submission.

31 orphaned language strings from the deleted signal table were removed. 204 remain, 0
orphaned, 0 referenced-but-undefined.

### Still not known

No false-positive rate has been measured on real student submissions.

## 1.0.99 - 2026-10-06

**The writing-style signals are removed. Copy evidence becomes the score.**

Version: `2026101200`. No schema change — the existing columns are reused with a new
meaning, and every analysed row now records which meaning applies to it. No stored data is
altered by the upgrade.

### Why the signals went

They were tested against **48 answers to real VET assessment prompts generated by three
different current language models** — Claude, ChatGPT and Gemini, each answering naturally
and again rewritten to sound like a student who struggles with written English, at
129–196 words.

| Document class | n | Range | Flagged |
|---|---|---|---|
| Genuine machine-written | 48 | **0–14** | **0 of 48** |
| Human | 11 | 0–27 | 0 of 11 |

**S1, the largest signal at 22 of 84 points, found zero markers in 48 of 48.** The
highest-scoring document in the whole exercise was a human second-language student essay,
at nearly twice the highest machine-written document.

They were removed rather than kept on display as unscored observations. Anything shown
beside a copying verdict is read as corroborating it, which is how institutions came to
over-rely on detector output in the appeals the OIA upheld in 2025. For a tool that feeds
misconduct decisions that is a safety problem, not an untidiness.

**1,323 lines came out of `analyser.php`** — 2,014 down to 691.

### Why copy evidence becomes the score

It was already the only measurement with evidence behind it — 92% on a genuine copy, 13% on
two students answering the same closed question independently, 0.7% on unrelated work — and
it contributed **nothing**.

- `compute_s12_score()` was dead code. Never called anywhere in the plugin.
- `cross_student_similarity()` ran only when a teacher happened to open one student's
  report page, recomputing every pair on each page load and storing nothing.
- The badge on the submission list came from a weighted average of style-signal points.

So **a student who copied another student verbatim carried a LOW badge**, because verbatim
copying says nothing about writing style, while a second-language student who wrote their
own answer carried 27 of 100.

That is now the right way round. The score is the similarity percentage, computed once at
analysis time and applied to **both sides** of a flagged pair — copying is symmetric, and
whoever submitted first would otherwise keep a clean badge however much of their work
appears in someone else's.

The band boundaries need no rescaling:

| Band | Similarity | Measured example |
|---|---|---|
| LOW | 0–34% | Independent answers to the same closed question: **13%** |
| MEDIUM | 35–64% | Read both submissions |
| HIGH | 65–100% | Genuine copy, light paraphrase: **92%** |

35% is also the threshold that governs whether a pair is listed at all, so the score and the
pair list cannot disagree.

### Quotation stripping now serves the comparison

It was built to stop the style signals charging a student for an author they quoted. It
earns its place for a better reason: two students quoting the same legislation share wording
neither of them wrote.

Measured on two unrelated answers carrying one shared quotation: **47.5% similarity with
the quotation left in — above the reporting threshold, a false copy match put in front of a
teacher — and 0.0% with it removed.**

### Legacy rows

Rows written before this release carry no `score_model` stamp. Their score means a
style-signal sum, which is a different statement from a similarity percentage, so the
reports **label them** as scored under the previous model rather than silently
reinterpreting them. Re-analyse rescores a submission under the current model.

### Testing

42 test methods pass with 0 assertion failures. The mutation suite was rewritten for the new
architecture: 10 reintroduced defects, 10 caught — including one guard that initially missed
a disabled call because it tested for the method *name*, which the method's own definition
satisfies.

### Still not known

No false-positive rate has been measured on real student submissions. That is unchanged, and
it is still what any accuracy claim would require.

## 1.0.98 - 2026-10-06

**Third vendor. Documentation only — no code changes.**

Version: `2026101100`.

Gemini was given the same sixteen VET prompts as Claude and ChatGPT, fresh chat, no mention
that the output would be tested.

| Document class | n | Range | Flagged |
|---|---|---|---|
| Human documents | 11 | 0–27 | 0 of 11 |
| My hand-written imitations of AI (discredited) | 3 | 37–38 | 3 of 3 |
| Genuine Claude | 16 | 0–9 | 0 of 16 |
| Genuine ChatGPT | 16 | 0–14 | 0 of 16 |
| Genuine Gemini | 16 | 0–14 | 0 of 16 |
| **All genuine AI, three vendors** | **48** | **0–14** | **0 of 48** |

**S1 found zero markers in 48 of 48.** The highest-scoring document in the whole exercise
remains a human second-language student essay, at nearly twice the highest machine document.

Three vendors, three different house styles, same result. The style signals do not detect
current model output.

### S3 is the one exception, and it still has no human baseline

S3 (type-token ratio uniformity across paragraphs) fired on **6 of 16 ChatGPT** documents
and **11 of 16 Gemini** documents. It is the only style signal responding to genuine model
output.

No conclusion is drawn from it. Its human baseline is still entirely unmeasured, because
every document in the human corpus was written as a single unbroken block and S3 splits
paragraphs on blank lines. Establishing that baseline needs real student submissions with
paragraph structure, and it is the next measurement worth making.

## 1.0.97 - 2026-10-06

**A second vendor confirms it. Plus another contraction fault.**

Version: `2026101000`. One engine fix (S5).

### ChatGPT, same sixteen prompts

| Document class | n | Range | Flagged |
|---|---|---|---|
| Human documents | 11 | 0–27 | 0 of 11 |
| Genuine Claude output | 16 | 0–9 | 0 of 16 |
| **Genuine ChatGPT output** | 16 | **3–14** | **0 of 16** |
| **All genuine AI, both vendors** | **32** | **0–14** | **0 of 32** |

**S1 found zero markers in all 32.** The highest-scoring document in the whole exercise is
still a human second-language student essay, at 27 — roughly twice the highest machine
document. This is no longer a one-vendor result.

### S5 was still wrong, in the direction that hurts most

1.0.93 fixed Word's curly apostrophe. It did not fix the writer who types **no apostrophe
at all**, which is the commoner case in student writing. All six informally-written
submissions scored 3 of 6 for "contraction absence" while containing up to seven
contractions — `dont`, `shouldnt`, `thats`, `doesnt`, `cant`, `wouldnt`. The signal
reported the exact opposite of what was in front of it.

Dropping apostrophes goes with hurried and lower-literacy writing. So the fault made S5
fire hardest on precisely the students least able to answer the accusation it feeds —
stacked on the register bias the signal already carries.

Forms that are also ordinary English words (`its`, `were`, `well`, `ill`, `id`, `hes`,
`shed`, `wed`) are deliberately not matched. Matching them would silence S5 on nearly every
document, which would be a covert withdrawal rather than a fix.

### Correction: S3 does fire, and the earlier claim was my test data's fault

Earlier releases recorded that S3 "never fires on a real document". That was an artefact of
the corpus, not a property of S3 — those documents were written as single unbroken blocks,
and S3 splits paragraphs on blank lines, so it was never measured at all.

Against real ChatGPT output, which arrives in three or four paragraphs, **S3 measured on 16
of 16 documents and fired on 6**. It is the only style signal showing any response to
genuine model output.

No conclusion is drawn from that yet: the human baseline for S3 is still entirely
unmeasured, for exactly the same reason. Establishing it needs human documents with real
paragraph structure.

## 1.0.96 - 2026-10-06

**Correction: the detection claims in previous releases were measured against a strawman**

Version: `2026100900`. No code changes to the scoring engine. This release exists to
withdraw a performance claim, because continuing to ship it would be the most harmful
thing in the package.

### What was wrong

Every release from 1.0.93 onward reported that DocGuard flagged "3 of 3 generated
documents (lowest 37)" against "0 of 11 human documents (highest 27)", a ten-point margin.

Those three "generated" documents were **written by hand in imitation of AI style**. They
were composed from the same folklore about how chatbots write — "delve into the
complexities", "it is important to note", "a multifaceted tapestry" — that the S1 marker
list was itself built from. The test set and the detector were derived from the same
assumption, so the measurement was circular. It demonstrated only that the detector finds
what it was told to look for.

### What genuine model output actually scores

Sixteen answers to real VET assessment prompts were generated by a current language model:
ten written naturally, six written after asking the model to sound like a struggling
student, which is what a cheating submission actually looks like. Lengths 129–196 words,
the realistic range for a VET short answer.

| Document class | n | Range | Flagged MEDIUM+ |
|---|---|---|---|
| Human documents | 11 | 0–27 | **0 of 11** |
| Hand-written imitations of AI (the old test set) | 3 | 37–38 | 3 of 3 |
| **Genuine model output, natural** | 10 | **0–9** | **0 of 10** |
| **Genuine model output, evasion-prompted** | 6 | **0–3** | **0 of 6** |

**DocGuard flagged 0 of 16 genuine machine-written documents.** Its highest-scoring human
document, a second-language student essay, scored 27 — three times the highest genuine
machine document, which scored 9.

S1, the signal carrying effectively all of the apparent discrimination, found **zero
markers in all sixteen** genuine model documents. It found up to three in human documents.

### Why

Published work explains it. Kobak et al. (Science Advances, 2025) established the marker
vocabulary effect at corpus scale and it is real. But Geng & Trotta (arXiv:2502.09606),
over 1.29M arXiv abstracts, show `delve`, `intricate`, `showcasing`, `realm`, `pivotal`
and `meticulous` all began **declining from March–April 2024** — exactly when they became
publicly known as AI tells. Models were tuned away from them and authors stopped using
them. Marker lists also differ by model within one family: `underscore` appears at 18 per
million words in GPT-3.5 and 1,365 per million in GPT-4o-mini.

A marker list has a useful life of roughly 12–18 months. DocGuard is shipping a 2024 list.

### What has not changed

S12, cross-student similarity, is unaffected and remains sound: independent answers to the
same question 13%, a genuine copy 92%, unrelated control 0.7%. It is measured on
submissions rather than inferred from style, and it is the part of this plugin with
evidence behind it.

### What a teacher should take from this

A LOW score from DocGuard does not mean a submission was not AI-written. On this evidence
it is what a genuine AI submission usually produces. The reports already state that the
plugin holds no external corpus; they now also state that the style signals have not been
shown to detect current model output.

## 1.0.95 - 2026-10-06

**Defects found by auditing 1.0.94**

Version: `2026100800`. No database schema changes.

Everything in this release is a fault 1.0.94 introduced, found by reviewing that release
rather than by new measurement. Two of the three had quietly defeated the fix 1.0.94
existed to make. **Do not deploy 1.0.94.**

### S11 was applied to sections that were never scored

The cross-section signal was distributed over every section, including ones
`score_section()` had declined to score. A bibliography section was given 10 points on a
word count of zero.

The worse half: injecting the signal made that section's signal set non-empty, so the
report rendered a one-row signal table instead of the plain-English explanation that there
was too little of the student's own prose to measure. VET submissions are
question-per-section almost by definition, so 1.0.94's headline fix was unreachable on
close to every real document.

That loop was a dozen lines inside `analyse_file()`, which needs a `stored_file` and a
database and was therefore never unit tested. It is now `apply_cross_section_signal()`,
taking and returning plain arrays, so this behaviour is a test rather than a code review.

### Quotation marks were paired by proximity, not position

```
/"(?:[^"]{15,})"/u
```

A regex engine scanning left to right will pair a *closing* mark with the next *opening*
one. On an answer using three short scare-quotes:

```
kept       "restructure"   "right-sized"   "opportunity"
stripped   " but everyone knew what that meant. She said the team was being "
           " and that we should see it as an "
```

It removed the student's own narration and retained the quoted words — the exact reverse
of the intent — and silently discarded 20 of 74 words of a realistic answer.

Marks are now paired by position. The threshold is 20 words rather than 15 characters, so
dialogue, scare-quotes and short lifted phrases all survive while a block quotation long
enough to carry its author's style is still removed. An unbalanced mark from OCR or a typo
no longer swallows the rest of the answer.

### Three tests had been failing since 1.0.93, and nothing had run them

There is no Moodle in the build environment, so `tests/` had been lint-checked and never
executed. A standalone runner now executes every test method that does not need the Moodle
database, which is how these surfaced:

- `test_short_sections_are_not_scored_on_density` asserted a flat zero below a hard word
  floor that 1.0.93 had already replaced, in the same release, with a confidence ramp.
- Two of the five S1 density provider cases were transcribed from the band table instead
  of measured, and were wrong about which band a density falls in: three phrases in 200
  words is 18.9 per 1000, below the lowest band, and scores 0 rather than the 5 claimed;
  ten phrases in 150 words is 89.4 per 1000, a fraction under the 90 the top band needs,
  and scores 16 rather than 22.

All three now assert measured behaviour against the named constants, and the provider
records each case's measured density beside its expected points.

76 test methods run and pass. The remaining 43 need Moodle's database, `set_config()` or
`make_request_directory()` and are still unverified outside CI.

### Consistency

`score_section()` returns the same keys on every path; `lowconfidence` was missing from the
under-eight-words branch.

### Unchanged

Known-origin figures are the same as 1.0.94: human documents 0 of 11 flagged MEDIUM or
above (highest 27), generated 3 of 3 flagged (lowest 37). **Superseded — see 1.0.96: those
three "generated" documents were hand-written imitations and the measurement was circular.
Genuine model output scores 0–9 and is not flagged.** No document in the known-origin
set loses a single word to the exclusions. The mutation suite now reintroduces sixteen
fixed defects and a named test catches each one.

The validation gap is also unchanged, and remains the only thing that matters for an
accuracy claim: fourteen hand-written documents demonstrate defects, they do not measure a
false-positive rate.

## 1.0.94 - 2026-10-06

**Signal validity, second round**

Version: `2026100700`. No database schema changes.

1.0.93 fixed the signals that were measuring the wrong thing. This release kept measuring,
and withdrew or corrected what did not survive it.

### A student is scored on what they wrote

Quotations and the reference list are now removed before the style signals run.

Both directions were wrong. A measured bibliography of eight ordinary VET titles scored 11
of 22 on S1 — academic titles are written in exactly the register the marker list
describes, and the student wrote none of those words. A student quoting Boud accurately was
charged for Boud's "it is important to note". In the other direction, the same reference
list enlarged the denominator: a generated passage measuring 382 marker hits per 1000 words
fell to 166 once eight citations were appended. Padding a submission with references was a
working evasion.

The report states how many words were set aside and why. Excluding part of a submission
from scoring without telling the teacher would be worse than not excluding it — they would
be reading a number about a different document from the one in front of them.

A section with almost nothing left after the exclusions is now reported as having too
little of the student's own prose to measure. It is neither scored as submitted nor filed
as an empty submission, because a submission that is 95% quotation is a marking question
and "LOW risk" is the most misleading thing this report could say about it.

Three signals — sentence uniformity, TTR uniformity and sentence-start uniformity — read
the text with its line breaks intact, and were still being handed the raw submission after
the stripping was added. They now read the same stripped text as everything else.

### S6 (passive voice) no longer contributes to the score

Once the counting was corrected in 1.0.93, the signal was measurable, and it ran backwards.

```
Passive ratio, 14 documents of known origin
generated   0.000  0.006  0.007
human       0.000  0.000  0.006  0.009  0.015  0.017  0.018  0.019  0.042  0.118  0.135
```

The three generated essays are the least passive documents in the set. Of the eleven human
documents, the three that cleared the old threshold were a policy document, a lab report
and a nursing clinical answer — each a register in which the passive is the required house
style. The signal awarded points to 3 of 11 human documents and 0 of 3 generated ones.
Every point it ever contributed went to a person writing correctly for their profession.

The premise came from pre-LLM readability checkers, where passive voice marks heavy prose.
It was never evidence about chatbot output, and current chatbots write in a conspicuously
active voice. No choice of threshold fixes a signal pointing the wrong way, so it was
withdrawn rather than rebanded. The measurement is still shown, as an observation worth
nothing, because passive density describes a piece of writing even though it says nothing
about who wrote it.

### A short section is not a clean result

A section under 150 words is now marked as not measurable rather than reported as low risk.
Every signal here is a rate or a distribution, so a short section does not produce a little
evidence — it produces none.

```
Score of the same text, truncated
                   25w   50w   80w  150w  full
generated essay 1    8     7    10    33    37
generated essay 2    5     5     8    34    38
generated essay 3    5     8    14    38    38
```

No document in the set scored materially higher truncated than whole, so a short section
can fail to flag but cannot falsely flag. That makes the reassuring reading the dangerous
one, and a teacher who reads "LOW" on a sixty-word answer as a clear result has been misled
by this plugin.

### The scale is stated honestly

The report displays a section score against 100, but S1–S10 total 84 attainable points
(S11 adds 10 only where a submission has several sections to compare). "26/100" sounds like
a quarter of the available evidence; it is nearer a third. The figure is now a constant with
a test behind it, so a future change to any signal's maximum has to update it and the README
together. The band thresholds followed the S12 threshold into named constants for the same
reason: duplicated numbers drift, and that is how the class report came to name pairs to a
teacher that the engine scored at zero.

### Effect on the known-origin set

| | 1.0.92 | 1.0.93 | 1.0.94 |
|---|---|---|---|
| Human documents flagged MEDIUM+ | 2 of 11 | 0 of 11 | 0 of 11 |
| Highest-scoring human document | 49 | 27 | 27 |
| Generated documents flagged | 3 of 3 | 3 of 3 | 3 of 3 |
| Lowest-scoring generated document | 37 | 37 | 37 |
| Nursing clinical answer | 23 | 15 | 9 |
| Lab report | 12 | 12 | 6 |
| Policy / management prose | 33 | 14 | 8 |
| Reference list scored as prose | 11 | 11 | not scored, and said so |

The professional-register documents fell with no change to any generated document, which is
the shape a register bias leaving the score should have.

### Testing

`tests/signal_validity_test.php` carries eight further requirement tests: reference lists
not scored as prose, quotations excluded, appended references not diluting an unchanged
score, the line-shaped signals reading the stripped text, short sections marked, band
thresholds and the attainable maximum coming from shared constants, and scoring metadata
never rendered as a signal. The mutation suite now reintroduces thirteen fixed defects and
confirms a named test catches each one.

### Still outstanding

Fourteen hand-written documents demonstrate a defect; they do not measure a false-positive
rate, and nothing in this release changes that. A blind validation set of several hundred
real submissions of known provenance, including second-language writing, remains the
prerequisite for any accuracy claim about this plugin. The bands for the remaining signals
are fitted to that same small set.

## 1.0.93 - 2026-10-06

**Signal validity**

Version: `2026100600`. No database schema changes.

This release came out of running the scoring engine against texts whose origin is known —
public-domain prose written a century before language models, the registers our customers
actually submit, and generated passages. The result was that a second-language student
essay scored 49 and a VET policy answer 43, while an actual generated essay scored 37. The
tool was ranking writing register, not authorship, and the signal carrying the most weight
was the main reason.

### What the reports now say

DocGuard holds no external corpus: it does not check the web, published sources, essay
banks, other courses or previous cohorts. The only comparison against other work is with
submissions to the same activity. A teacher reading a LOW score had no way to know that,
and would reasonably conclude the work had been checked against sources and come back
clean.

The student report now carries a "What was checked" panel stating this plainly, the class
report carries a one-line version next to the scores, and the headline band is labelled
**AI-writing indicators** rather than presented as an unqualified risk score. The
interpretation guide no longer describes a LOW score as "consistent with authentic student
writing".

Every style signal now states its own limits in its description — that formal register,
the taught essay template and second-language writing raise these scores for reasons
unrelated to how the work was produced.

### S1 — AI marker vocabulary (22 points, the heaviest signal)

Four separate defects, all inflating the score:

- **23 of the 125 entries were ordinary English**: `in order to`, `in conclusion`,
  `foster`, `robust`, `stakeholder`, `essentially`, `ultimately`, `landscape`, `paradigm`,
  `firstly`..`lastly`. One sentence of unremarkable workplace prose matched ten entries and
  scored the full 22 of 22.
- **19 entries were substrings of other entries**, so one word scored twice — `foster`
  inside `fostering`, `stakeholder` inside `key stakeholders`.
- **Four entries were also scored by S4** as transition words, so the same `furthermore`
  was paid for twice in the total.
- **Ten entries were also scored by S7** as the intro/conclusion template — `this essay
  will`, `in conclusion`, `in summary` and the rest. The same double-counting, with a
  different signal, and it survived the first pass at this fix.

The list is now 51 curated entries, matched on word boundaries rather than by `strpos()`.
Scoring is by **density per 1000 words**, not by how many distinct markers appear
anywhere: the old method meant a 2,000-word assignment scored higher than a 200-word one
for being longer, and it computed a density figure that it then never used.

Bands are fitted to measured densities. After the double-counting was removed, human
writing in the sample ranged from 0 to 30 per 1000 and generated prose from 103 to 179;
the top band sits well above the highest human reading observed. S1 also requires at least three weighted hits, and scales its result by how much text the
density was computed from — nothing below 80 words, full weight from 150 — because a
density on a short answer is unstable and a hard word floor produces a cliff. Two earlier
attempts at this used a floor, and both had one: identical writing scored 0 and 16 either
side of 60 words, then 0 and 22 either side of 100. Scoring a student differently because
their answer ran one word longer is not defensible to that student. The largest single-word
change is now 5 points, which is a band boundary and inherent to banded scoring.

### S5 — absence of contractions

Matched `don't` with an ASCII apostrophe. Word, Google Docs and most PDF producers
substitute U+2019 automatically, so on a real submission no contraction was ever found and
the signal awarded its full six points for their absence to text that was full of them.
Identical text scored 0 of 6 typed one way and 6 of 6 typed the other. Apostrophes are
normalised before matching.

### S6 — passive voice

The pattern was `(was|were|is|are|been|be|being)\s+\w+(ed|en)`, which treats any word
ending in -ed or -en as a past participle. It scored "the door is open", "there are seven
students", "he was keen to help", "this is often the case" and "the women are children of
the village" as passive voice, while missing "mistakes were made", "the city was built"
and "it gets destroyed". It was close to unrelated to the feature it named.

Replaced with a token scan: an auxiliary, then up to two skippable words, then a word that
passes a real participle test — a regular -ed/-en form that is not one of the common
adjectives, numerals and plural nouns sharing those endings, or a known irregular
participle.

The intermediate words matter. A first attempt at this fix used a single regex and lost
**every progressive passive** — "the policy is being reviewed", "the samples were being
analysed" — because the pattern consumed "is being" and "being" is not a participle. Those
are the commonest passives in exactly the clinical and policy registers this signal is read
in, and the same blindness applied to any adverb: "was carefully recorded", "is widely
used". Verified against 26 phrases in both directions (12 that must not count, 14 that must).

### S3 — vocabulary richness across paragraphs

`pdftotext` returns one newline per line of the original rather than a blank line between
paragraphs, so on a PDF this signal saw one paragraph and could not run — and reported that
as 0 of 8, indistinguishable from a document that had been measured and found
unremarkable.

A fallback splitting on single newlines was built and then removed. It did not work:
pdftotext lines are 8 to 18 words, below the 20-word gate, so it never engaged on the
format it targeted. Where lines *were* long enough it did engage, and chopping continuous
prose into equal-length fragments produces type-token ratios that are mechanically
uniform — awarding a full 8 of 8 for "suspicious consistency" to any document it touched,
human or not. Inventing paragraph boundaries invents the measurement.

The signal now reports that it could not measure, and the report says so. An honest "not
measured" is worth more to a teacher than a fabricated zero.

### S12 — cross-student similarity

The class report listed pairs from 0.30 under a heading about academic misconduct while
`compute_s12_score()` awarded nothing below 0.35, so a pair at 0.31 was named to a teacher
as a concern the engine scored at zero. One constant, `S12_REPORT_THRESHOLD`, now governs
collection, scoring and display.

S12 itself was found to be sound and is otherwise unchanged: two independent answers to the
same procedural question measure 13% similarity, a genuine copy with light paraphrase 92%,
unrelated topics 0.7%.

### Measured effect

Same eleven human texts and three generated ones, before and after:

| | Before | After |
|---|---|---|
| Highest-scoring human text | 49 (MEDIUM) | 27 (LOW) |
| Second-language student essay | 49 (MEDIUM) | 27 (LOW) |
| VET policy answer | 43 (MEDIUM) | 26 (LOW) |
| Generated essays | 37–41 (MEDIUM) | 37–41 (MEDIUM) |
| Human texts reaching MEDIUM | 2 of 11 | 0 of 11 |
| Generated texts reaching MEDIUM | 3 of 3 | 3 of 3 |
| Largest single-word change in S1 | 16 points | 5 points |

The margin between the highest human text (27) and the lowest generated one (37) is ten
points. Before, the ordering was inverted: the highest human text scored 49 against a
lowest generated text of 37, so the tool ranked a second-language student as more
suspicious than a chatbot.

### S5 — a second defect the apostrophe fix exposed

Contractions were matched with `strpos()`, so "it's" matched inside "unit's" — and audit's,
credit's, deficit's, permit's, benefit's, all ordinary in VET and policy prose. Before the
apostrophe fix this was unreachable, because Word's U+2019 meant nothing matched at all;
normalising the apostrophes made it live. Now matched on word boundaries, as S1 already
was.

### Tests

`tests/signal_validity_test.php` states each of these as a requirement rather than
recording current output, so none can return unnoticed: no ordinary English in the marker
list, no overlapping entries, no word scored by both S1 and S4 or by both S1 and S7,
markers matched as whole words, contractions found with either apostrophe, possessives not
read as contractions, the passive detector correct in both directions, an unmeasurable
document reported as unmeasured rather than zero, short sections not scored on density,
generated prose scoring above taught formal writing, length not inflating the score, and
one shared threshold for S12 — asserted against report.php's source so the literal cannot
drift back.

Each of these was checked by reintroducing the bug it guards and confirming the suite goes
red. Two of the guards did not discriminate on the first attempt — their fixtures could not
tell the fixed code from the broken code — and were replaced with ones that do. A test that
cannot fail is worse than no test, because it reports a guarantee that is not there.

### Still outstanding

The style signals continue to respond to register. The bands were fitted against fourteen
texts, which is enough to remove the defects above and nowhere near enough to quote an
accuracy figure. Three known weaknesses remain, measured and unfixed: a reference list or
bibliography scores highly because entry titles are short and phrase-dense; quoted material
is attributed to the student, since nothing strips quotations; and S6's own bands were not
re-fitted after its counting method changed in both directions. A validation set of several hundred submissions of
known provenance, including second-language writing, scored blind, is the next piece of
work and should precede any accuracy claim in the listing.

## 1.0.92 - 2026-09-16

**Moodle Marketplace review MMRT-179**

Version: `2026091600`. No database schema changes.

### Security - Ghostscript ran student PDFs without `-dSAFER` (approval blocker)

`classes/extractor.php` shelled out to `gs` with no `-dSAFER`. Every PDF reaching that
method is a file a student uploaded, and without the flag Ghostscript honours PostScript
operators that read and write arbitrary paths reachable by the web server user — and, on
affected versions, execute commands through `%pipe%` device names. Ghostscript has enabled
SAFER by default since 9.50, but the plugin declares no minimum Ghostscript version and
runs whatever binary the host provides, so the flag is now passed explicitly.

`-dSAFER` does not restrict reading the input file named on the command line, so
extraction is unaffected.

### Security - the licence API key travelled in the URL

`curl::get($url, $params)` appends parameters to the URL, so the site's API key was written
into the vendor's web-server access logs, every forward and reverse proxy log along the
path, and any error or referrer reporting that records full URLs. The key now travels in an
`X-API-Key` request header on both GET calls. The site ID stays in the query string: it
identifies the site, it does not authenticate it. The third call already posted JSON.

**Deployment note:** the lms-labs.com endpoints must read `X-API-Key`. Until they do,
`/api/plagiarism-settings` answers non-200 and the site-wide platform flags silently stop
applying, and `/api/plugin-unlock/verify` may answer 200 with `unlocked:false`, which sends
`check_unlock()` into `auto_unlock()` — and that spends credits. Confirm server-side support
before rolling this out.

### Changed - submission analysis moved out of the event observer

The `assessable_submitted` observer called `analyse_and_store()` once per submitted file,
inline, in the student's own submit request. Each file costs an external process —
`pdftotext` or Ghostscript, each with a 60-second timeout — plus scoring and several DB
writes. A student submitting two PDFs to a host without poppler could watch a spinning
submit button for two minutes, and a submission that ran past `max_execution_time` died
half-written: record created, analysis not, and a server error shown for work Moodle had in
fact accepted.

The observer now records each supported file as `pending` and queues the new
`analyse_submission` adhoc task. Badges read "Pending" until cron picks the task up.

The licence check has also left the observer. `check_unlock()` is an outbound HTTP call of
up to fifteen seconds whose `write_close()` guard fires only for AJAX and CLI, so on an
ordinary submit it held the Moodle session write lock for that whole window and serialised
every other request from the same browser. The adhoc task re-checks the licence, and every
other gate, before it analyses anything.

**Why the pending row is written in the observer and not left to the task:** that row is the
plugin's only retry mechanism. `process_pending` Phase 1 looks for
`status='pending' AND timecreated < now-300` and retries indefinitely. An adhoc task carries
no such guarantee — core discards it after its attempts are exhausted, an administrator
clearing a stuck adhoc queue deletes it, and `execute()` has several legitimate early
returns. Without the row, any of those would leave a submission unanalysed, unretried and
showing a grey Pending badge for ever.

### Added - backup and restore support

`backup/moodle2/` now carries the per-activity DocGuard setting through course backup,
restore and duplicate. The setting is stored as the config key `enabled_cm_<cmid>`, so the
restore class remaps it onto the new course module id; copying the value verbatim would
write a setting belonging to nothing.

Analysis results are deliberately **not** backed up. They are derived data about specific
student submissions, they are keyed to file content hashes a restore does not reproduce, and
copying extracted student text into a course backup — which administrators routinely
download, email and archive — would turn a backup file into a data-protection problem. A
restored activity re-analyses on submission, or via the class report's scan button.

### Changed - autoloading and stylesheet loading

Both scheduled tasks stopped `require_once`-ing `observer`, `analyser` and `extractor`:
those are classes under `classes/`, which Moodle's autoloader resolves on first use.
`lib.php` is still required explicitly, because it holds global functions and is not
autoloadable.

The two report pages no longer call `$PAGE->requires->css()` for the plugin's own
`styles.css`. Moodle aggregates every plugin's `styles.css` into the theme stylesheet
already, so the manual call loaded it a second time, outside the theme cache.

### Documentation

README now documents the two external binaries DocGuard shells out to (`pdftotext` and
`gs`), exactly how each is invoked, why `-dSAFER` must not be removed, and why each call
carries a 60-second timeout.

## 1.0.91 - 2026-09-07

**Moodle 4.4 restored as the supported floor**

Version: `2026090702`. One-line change to `version.php`, plus the reasoning behind it.

### Fixed - the plugin could not be installed on Moodle 4.4

`$plugin->requires` was `2024100700` (Moodle 4.5 LTS) and `$plugin->supported` was
`[405, 502]`. Moodle's dependency check reports the `supported` range as a hard requirement,
so a 4.4 site refused the upgrade outright - "Moodle 405 - 502 Fails" - and said only that the
requirements must be solved first.

The 4.5 floor arrived on 29 August in 1.0.85 / 1.2.225 and was a **policy** choice, not a
technical one. That release's own comment says "The real floor is Moodle 4.4"; 4.5 was chosen
because it is the lowest branch still receiving security fixes, and because it is where core
deleted `plagiarism_update_status()`, which would have made this plugin's legacy compatibility
scaffolding dead code. Neither is a code requirement, and that scaffolding was never actually
removed - so nothing in the plugin needs 4.5.

The cost of the choice was invisible until an install was attempted: every build since 29 August
has been un-installable on 4.4, and Moodle gives no hint that a declared floor is a preference
rather than a constraint.

Now `requires = 2024042200` (Moodle 4.4) and `supported = [404, 502]`. Verified before
lowering: all three output hooks registered in `db/hooks.php` exist in 4.4; the legacy
`update_status()` scaffolding and the standalone plugin class are both still present; and 4.4's
minimum PHP is 8.1, so the arrow functions that forced the floor up off Moodle 4.0 remain safe.

Moodle 4.4 is nonetheless out of general support. This change unblocks the upgrade; it is not an
endorsement of staying on 4.4.

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

