<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace plagiarism_docguard;

/**
 * DocGuard analysis engine.
 *
 * Scores a document (or individual section) across 12 signals covering:
 *   S1  — AI marker vocabulary (ChatGPT/LLM characteristic phrases)
 *   S2  — Sentence length uniformity  (AI writes unnaturally even sentences)
 *   S3  — Type-Token Ratio uniformity across paragraphs (AI = too consistent)
 *   S4  — Formal transition word overuse
 *   S5  — Absence of contractions in long text
 *   S6  — Passive voice overuse
 *   S7  — Intro / conclusion template pattern
 *   S8  — Trigram repetition (templated, copy-paste)
 *   S9  — Uniform sentence-start words (perplexity proxy)
 *   S10 — Vocabulary richness extremity (AI polishes vocab to high TTR)
 *   S11 — Cross-section style inconsistency (different authors for diff questions)
 *   S12 — Cross-student submission similarity (shared text across enrolment)
 *
 * Risk banding:  LOW 0–34 | MEDIUM 35–64 | HIGH 65–100
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class analyser {
    /* ── Thresholds ───────────────────────────────────────────────────────────── */

    /**
     * Similarity at or above which a pair of submissions is both scored and reported.
     *
     * V1.0.93. report.php used to list pairs from 0.30 while the scoring function awarded
     * nothing below 0.35, so the class report named pairs to a teacher, under a heading
     * about academic misconduct, that the engine itself scored at zero. One constant
     * serves both.
     */
    const S12_REPORT_THRESHOLD = 0.35;

    /**
     * Lower bounds of the MEDIUM and HIGH bands on the 0-100 score.
     *
     * Since v1.0.99 the score is a similarity percentage, so these are similarity
     * thresholds and they sit where the measurements put them: two students answering the
     * same closed procedural question independently measured 13%, a genuine copy with
     * light paraphrase 92%, two submissions on unrelated topics 0.7%.
     */
    const BAND_MEDIUM = 35;

    /** @var int Lower bound of the HIGH band. */
    const BAND_HIGH = 65;

    /**
     * Shortest quotation, in words, removed before a submission is compared with others.
     *
     * Two students who quote the same source at length will share that wording without
     * either copying the other, and the quotation is nobody's own writing. Removing long
     * quoted spans before comparison means the similarity figure describes the students'
     * own prose. Short quotations, scare-quoted terms and dialogue are left alone - they
     * cannot move the measure and they are the student's own writing in the sense that
     * matters.
     */
    const QUOTE_MIN_WORDS = 20;

    /* ── Public API ───────────────────────────────────────────────────────────── */

    /**
     * Analyse a full submission: extract text, parse sections, score each.
     *
     * @param \stored_file $file The submitted PDF or DOCX to analyse.
     * @param int $cmid Course module id of the activity the file was submitted to.
     * @param int $userid Id of the student who submitted the file.
     * @param int $submissionid Id of the {plagiarism_docguard_sub} row this analysis
     *                              belongs to, used to exclude the submission from its own
     *                              cross-submission comparison.
     * @return array Result record with keys status ('analysed' or 'error'), overall_riskscore,
     *               overall_risklevel, sections (per-section scoring), analysisjson (the
     *               serialised detail stored on the submission row), normtext (the
     *               normalised extracted text) and error (message when status is 'error').
     */
    public static function analyse_file(\stored_file $file, int $cmid, int $userid, int $submissionid): array {
        // Extract text.
        // FIX-DG-THROWABLE (v1.0.70): Changed catch(\Exception) to catch(\Throwable)
        // so PHP \Error subclasses (\TypeError, \ValueError, \DivisionByZeroError, etc.)
        // produced by the extractor or any downstream scoring code are caught here and
        // returned as status='error' rather than propagating uncaught through
        // analyse_and_store() and leaving the DB record permanently at status='pending'.
        try {
            $text = extractor::extract($file);
        } catch (\Throwable $e) {
            return [
                'status'             => 'error',
                'overall_riskscore'  => 0,
                'overall_risklevel'  => 'low',
                'sections'           => [],
                'analysisjson'       => null,
                'normtext'           => null,
                'error'              => $e->getMessage(),
            ];
        }

        if (strlen(trim($text)) < 40) {
            return [
                'status'             => 'error',
                'overall_riskscore'  => 0,
                'overall_risklevel'  => 'low',
                'sections'           => [],
                'analysisjson'       => null,
                'normtext'           => null,
                'error'              => 'Extracted text is too short or empty. The file may be scanned/image-based.',
            ];
        }

        // V1.0.80: refuse to score a document whose script the signals cannot read,
        // instead of returning a meaningless 0/100 LOW. See latin_letter_ratio(). The
        // threshold is deliberately low (half the letters) so that a normal English
        // submission quoting a passage of Greek, Arabic or Chinese is still analysed;
        // only a document that is substantially not in Latin script is declined. The
        // normalised text is still stored, so cross-student similarity — which is
        // script-independent since v1.0.80 — keeps working for these documents.
        $latinratio = self::latin_letter_ratio($text);
        if ($latinratio < 0.5) {
            return [
                'status'             => 'error',
                'overall_riskscore'  => 0,
                'overall_risklevel'  => 'low',
                'sections'           => [],
                'analysisjson'       => json_encode([
                    'unsupported_script' => true,
                    'latin_letter_ratio' => round($latinratio, 3),
                ]),
                'normtext'           => \core_text::substr(
                    question_parser::normalise_for_similarity($text),
                    0,
                    65000
                ),
                // Under 120 characters: lib.php truncates badge error text there.
                'error'              => 'Unsupported script: DocGuard\'s signals are English-only and cannot score this document.',
            ];
        }

        // Parse into sections.
        $sections = question_parser::parse($text);

        if (empty($sections)) {
            return [
                'status'             => 'error',
                'overall_riskscore'  => 0,
                'overall_risklevel'  => 'low',
                'sections'           => [],
                'analysisjson'       => null,
                'normtext'           => null,
                'error'              => 'No sections could be identified in the document.',
            ];
        }

        // Describe each section: the student's own prose, and how much of it there is.
        $scoredsections = [];
        foreach ($sections as $sec) {
            $result = self::score_section($sec['text']);
            $scoredsections[] = array_merge($sec, $result);
        }

        /*
         * V1.0.99. The submission score is no longer a weighted average of section
         * scores, because sections no longer carry a score - the style signals they are
         * built from do not separate machine-written text from human writing and have
         * stopped contributing points. See the note in score_section().
         *
         * The score is now copy evidence, and copy evidence is pairwise: it is not
         * knowable from this document alone. analyse_file() therefore returns zero here
         * and the caller sets the real score once the submission has been compared
         * against the others in the activity - see observer::apply_copy_evidence().
         *
         * Leaving it at zero is deliberate. A submission that has been extracted and
         * sectioned but not yet compared has no evidence against it, and zero is the
         * honest value for that state.
         */
        $overallscore = 0;
        $overalllevel = self::band($overallscore);

        /*
         * Normalised text for cross-submission comparison.
         *
         * V1.0.99. Quotations and the reference list are removed first. This machinery was
         * written to stop the style signals charging a student for an author they quoted;
         * with those signals gone it earns its place for a better reason.
         *
         * Two students who quote the same source at length share that wording without
         * either copying the other, and the quotation is neither student's writing. A
         * class working from the same unit materials, quoting the same legislation or the
         * same textbook passage, would otherwise show a similarity figure driven by text
         * nobody in the room wrote. Removing long quoted spans first means the number
         * describes the students' own prose, which is the thing in question.
         *
         * A shared reference list has the same effect and is removed for the same reason.
         * Short quotations and scare-quoted terms are left alone: they cannot move the
         * measure, and they are the student's own writing in the sense that matters.
         */
        $forcomparison = self::prepare_for_scoring($text);
        $norm          = question_parser::normalise_for_similarity($forcomparison['text']);

        $analysis = [
            'section_count'    => count($scoredsections),
            'overall_score'    => $overallscore,
            'overall_level'    => $overalllevel,
            'extraction_chars' => strlen($text),
            /*
             * V1.0.99. What the stored score MEANS, stamped on the row.
             *
             * Model 1, every release to 1.0.98, was a weighted average of style-signal
             * points - an inference about how the document was written. Model 2 is the
             * percentage of this submission that appears in another submission to the
             * same activity - a measurement a teacher can check by opening both.
             *
             * The two are not comparable, and 26 under model 1 is a different statement
             * from 26 under model 2. Rows already in the database carry no stamp, so the
             * reports label them as scored under the previous model rather than silently
             * reinterpreting them. Re-analyse rescores a submission under model 2.
             */
            'score_model'      => self::SCORE_MODEL,
        ];

        return [
            'status'            => 'analysed',
            'overall_riskscore' => $overallscore,
            'overall_risklevel' => $overalllevel,
            'sections'          => $scoredsections,
            'analysisjson'      => json_encode($analysis),
            // V1.0.80: core_text::substr, not substr — a byte-wise cut on now-Unicode
            // normalised text can sever a multi-byte character, and MySQL/utf8mb4 rejects
            // the result with "Incorrect string value" (the same class of bug as
            // FIX-DG-MB-TRUNCATE in observer.php).
            'normtext'          => \core_text::substr($norm, 0, 65000),
            'error'             => null,
        ];
    }

    /**
     * Describe one section of a submission: its own prose, and how much of it there is.
     *
     * V1.0.99. This used to score the section across eleven writing-style signals and
     * return a 0-100 risk figure. It no longer scores anything, because the signals did
     * not do what they claimed.
     *
     * Measured against 48 answers to real VET assessment prompts generated by three
     * different current language models - Claude, ChatGPT and Gemini, each answering
     * naturally and again rewritten to sound like a student who struggles with written
     * English, 129 to 196 words, the realistic length for a VET short answer:
     *
     *   genuine machine-written documents   0 to 14 of 100    0 of 48 flagged
     *   human documents                      0 to 27 of 100    0 of 11 flagged
     *
     * S1, the largest signal at 22 of the 84 attainable points, found zero markers in 48
     * of 48. The highest-scoring document in the whole exercise was a human
     * second-language student essay, at nearly twice the highest machine document. The
     * signals ranked formality and English proficiency, which is the failure mode Liang
     * et al. (Patterns, 2023) measured at a 61% false-positive rate against non-native
     * writers, and the published evidence for the individual signals is weak or
     * contradictory - GPT-4 uses FEWER discourse markers than students (Herbold et al.,
     * Scientific Reports 2023), and Claude emits contractions at 30,611 per million words
     * against GPT-3.5's 120.
     *
     * They were removed rather than left on display. Anything shown beside a copying
     * verdict is read as corroborating it, and that is how institutions came to over-rely
     * on detector output in the cases the OIA upheld in 2025. For a tool that feeds
     * misconduct decisions, leaving eleven discredited measurements on the page is a
     * safety problem, not an untidiness.
     *
     * What remains is what a marker can actually use: the text that was extracted, the
     * amount of the student's own prose in it, and what was set aside before comparison.
     *
     * @param string $text The section text.
     * @return array wordcount (of the student's own prose), excluded_words,
     *               excluded_reason, note ('insufficient_text' or 'mostly_not_own_prose'
     *               when there is too little to work with), and signals (always empty -
     *               the key is retained so stored rows and report code keep one shape).
     */
    public static function score_section(string $text): array {
        $prepared = self::prepare_for_scoring($text);
        $wcount   = count(self::words(\core_text::strtolower($prepared['text'])));

        $note = '';
        if ($wcount < 8) {
            $note = $prepared['excluded'] > 0 ? 'mostly_not_own_prose' : 'insufficient_text';
        }

        return [
            // Retained at zero: the section-level columns still exist and the submission
            // score comes from copy evidence, which is not knowable per section here.
            'riskscore'       => 0,
            'risklevel'       => 'low',
            'wordcount'       => $wcount,
            'excluded_words'  => $prepared['excluded'],
            'excluded_reason' => $prepared['reason'],
            'note'            => $note,
            'signals'         => [],
        ];
    }

    /* ── Helpers ──────────────────────────────────────────────────────────────── */


    /**
     * Reduce a section to the student's own prose before it is scored.
     *
     * Removes, in order: a reference list, and quoted material. See the note in
     * score_section() for why each distorts the measurement in both directions.
     *
     * The original text is untouched — only the copy the signals run on is reduced — so
     * the student report still shows the teacher everything that was submitted.
     *
     * @param string $text The section text as submitted.
     * @return array{text: string, excluded: int, reason: string} The prose to score, how
     *         many words were set aside, and why.
     */
    private static function prepare_for_scoring(string $text): array {
        $originalwords = count(self::words(\core_text::strtolower($text)));
        $reasons       = [];

        $working = self::strip_reference_list($text);
        if ($working !== $text) {
            $reasons[] = 'reference list';
        }

        $afterrefs = $working;
        $working   = self::strip_quotations($working);
        if ($working !== $afterrefs) {
            $reasons[] = 'quoted material';
        }

        $remaining = count(self::words(\core_text::strtolower($working)));
        $excluded  = max(0, $originalwords - $remaining);

        /*
         * If almost nothing of the student's own prose is left, report that rather than
         * scoring something.
         *
         * Two cases reach here and both have the same honest answer. A submission that is
         * mostly quotation cannot be assessed for writing style, because the style being
         * measured is somebody else's. A section that is entirely a reference list has no
         * prose in it at all — and scoring it anyway is how a measured bibliography of
         * eight ordinary VET titles came to score 11 of 22 on S1, purely because academic
         * titles are written in the register the marker list describes.
         *
         * Returning the original to be scored, which an earlier version of this did, keeps
         * exactly that false positive. Returning an empty string says what is true: there
         * is not enough of this student's writing here to measure.
         */
        if ($originalwords > 0 && $remaining < 40 && $remaining < $originalwords * 0.5) {
            return [
                'text'     => '',
                'excluded' => $excluded,
                'reason'   => 'too little of the student\'s own prose to measure: '
                    . 'this section is mostly quoted or cited material',
            ];
        }

        return [
            'text'     => $working,
            'excluded' => $excluded,
            'reason'   => $reasons ? implode(' and ', $reasons) : '',
        ];
    }

    /**
     * Remove a trailing reference list or bibliography.
     *
     * Two passes, because the two formats fail differently. A heading ("References",
     * "Bibliography", "Works cited") marks everything after it; where no heading exists,
     * individual lines are matched against the shape of a citation — an author surname, an
     * initial, and a year in brackets — which is what APA, Harvard and Chicago share.
     *
     * Deliberately conservative: a line is dropped only when it looks like a citation on
     * its own, so a sentence that merely mentions a year survives.
     *
     * @param string $text The section text.
     * @return string The text with the reference list removed.
     */
    private static function strip_reference_list(string $text): string {
        // A heading on its own line, with everything following it.
        $withoutheading = preg_replace(
            '/^[ \t]*(references|reference list|bibliography|works cited|citations)[ \t:]*$.*/imsu',
            '',
            $text
        );
        if ($withoutheading !== null && $withoutheading !== $text) {
            $text = $withoutheading;
        }

        // Individual citation-shaped lines: "Surname, A. (2019). Title. Source."
        $lines = preg_split('/\n/u', $text);
        if ($lines === false) {
            return $text;
        }
        $kept = [];
        foreach ($lines as $line) {
            $iscitation = preg_match(
                '/^\s*[A-Z][\p{L}\'-]+,\s*[A-Z]\.(\s*[A-Z]\.)*.*\(\d{4}[a-z]?\)/u',
                $line
            );
            if (!$iscitation) {
                $kept[] = $line;
            }
        }

        return implode("\n", $kept);
    }

    /**
     * Remove quoted material.
     *
     * Handles straight and typographic double quotes, and the long single-quoted spans
     * some house styles use for block quotations. A span is treated as a quotation only
     * when it runs to four words or more, so an ordinary scare-quoted term or a quoted
     * job title is left in the student's prose where it belongs.
     *
     * @param string $text The section text.
     * @return string The text with quoted spans removed.
     */
    private static function strip_quotations(string $text): string {
        /*
         * V1.0.94 FIX-DG-QUOTE-PAIRING. The first implementation used
         *     /"(?:[^"]{15,})"/u
         * which pairs quotation marks by proximity rather than by position, and a regex
         * engine scanning left to right will happily pair a CLOSING mark with the next
         * OPENING one. Measured on a submission using three short scare-quotes:
         *
         *   kept     "restructure"   "right-sized"   "opportunity"
         *   stripped " but everyone knew what that meant. She said the team was being "
         *            " and that we should see it as an "
         *
         * It removed the student's own narration and retained the quoted words - the exact
         * reverse of the intent - and silently discarded 20 of 74 words of a real answer.
         *
         * Quotation marks are now paired by position: split on the mark, and the odd-index
         * segments are the ones inside quotes. Where the marks do not balance, the trailing
         * segment has no closing mark and is left alone rather than swallowing the rest of
         * the document - a single stray quote from OCR or a typo must not cost a student
         * the tail of their answer.
         */
        $text = self::strip_paired_spans($text, '"', '"', self::QUOTE_MIN_WORDS);
        $text = self::strip_paired_spans($text, "\u{201C}", "\u{201D}", self::QUOTE_MIN_WORDS);
        $text = self::strip_paired_spans($text, "\u{2018}", "\u{2019}", self::QUOTE_MIN_WORDS * 2);

        return $text;
    }

    /**
     * Remove spans between paired delimiters, pairing them by position.
     *
     * @param string $text The text to process.
     * @param string $open The opening delimiter.
     * @param string $close The closing delimiter. May equal $open.
     * @param int $minwords Only spans of at least this many words are removed.
     * @return string The text with qualifying spans replaced by a space.
     */
    private static function strip_paired_spans(
        string $text,
        string $open,
        string $close,
        int $minwords
    ): string {
        if ($open === $close) {
            $parts = explode($open, $text);
            $n     = count($parts);

            // $parts[$i] for odd $i lies between mark $i and mark $i+1. That closing mark
            // exists only while $i <= $n - 2; the final segment of an unbalanced run has
            // no closing mark and is not a quotation.
            for ($i = 1; $i <= $n - 2; $i += 2) {
                if (count(self::words(\core_text::strtolower($parts[$i]))) >= $minwords) {
                    $parts[$i] = ' ';
                }
            }

            return implode($open, $parts);
        }

        // Distinct delimiters: a span runs from an opening mark to the next closing mark.
        $pattern = '/' . preg_quote($open, '/') . '([^' . preg_quote($close, '/') . ']*)'
            . preg_quote($close, '/') . '/u';

        $result = preg_replace_callback(
            $pattern,
            fn($m) => count(self::words(\core_text::strtolower($m[1]))) >= $minwords ? ' ' : $m[0],
            $text
        );

        return $result ?? $text;
    }


    /**
     * Compute cross-student Jaccard similarity between this submission and all others.
     * Returns array of ['userid', 'username', 'fullname', 'similarity', 'subid'].
     *
     * @param int $subid The submission record being compared.
     * @param int $cmid The course module the submission belongs to.
     * @param string $normtext The normalised text of this submission.
     * @return array Up to ten matches, highest similarity first, each with subid,
     *               userid, fullname, username, risklevel and similarity (0.0-1.0).
     */
    public static function cross_student_similarity(int $subid, int $cmid, string $normtext): array {
        global $DB;

        if (strlen($normtext) < 100) {
            return [];
        }

        // V1.0.80: bigram SETS, computed once for this document and once per other
        // document, compared with jaccard_sets(). Identical scores to the previous
        // bigrams()/jaccard() pair — see the harness in the release notes — without
        // flipping and merging arrays on every comparison.
        $bga    = question_parser::bigram_set($normtext);
        $others  = $DB->get_records_select(
            'plagiarism_docguard_sub',
            'cmid = :cmid AND id != :subid AND status = :status AND normtext IS NOT NULL',
            ['cmid' => $cmid, 'subid' => $subid, 'status' => 'analysed']
        );

        // V1.0.85 PERF-FIX-DG-SIMILARITY-N1: the user record was fetched inside the loop,
        // one query per match. On an activity with a shared source document - a class
        // working from the same template, which is exactly when this feature has anything
        // to report - most comparisons match, so the query count grew with the size of the
        // cohort, and the whole method already runs once per submission. Collect the
        // matches first, then fetch every user in one query.
        $matches = [];
        foreach ($others as $other) {
            $bgb  = question_parser::bigram_set((string)$other->normtext);
            $score = question_parser::jaccard_sets($bga, $bgb);
            if ($score >= self::S12_REPORT_THRESHOLD) {
                $matches[] = [$other, $score];
            }
        }

        if (empty($matches)) {
            return [];
        }

        $userids = array_values(
            array_unique(array_map(
                static fn($m) => (int)$m[0]->userid,
                $matches
                ))
        );
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $users = $DB->get_records_select(
            'user',
            "id $insql",
            $inparams,
            '',
            self::user_fields_for_fullname()
        );

        $results = [];
        foreach ($matches as [$other, $score]) {
            $user = $users[(int)$other->userid] ?? null;
            $results[] = [
                'subid'      => $other->id,
                'userid'     => $other->userid,
                'username'   => $user ? $user->username : '?',
                'fullname'   => $user ? fullname($user) : 'Unknown',
                'similarity' => $score,
                'risklevel'  => $other->overall_risklevel,
            ];
        }

        usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
        return array_slice($results, 0, 10);
    }


    /* ── Helpers ─────────────────────────────────────────────────────────────── */

    /**
     * The user columns fullname() requires, as a field list for $DB.
     *
     * V1.1.4 FIX-DG-FULLNAME-MISSING-NAME-FIELDS.
     *
     * cross_student_similarity() selected only 'id, username, firstname, lastname' and then
     * called fullname() on the result. fullname() needs every name field Moodle defines -
     * firstnamephonetic, lastnamephonetic, middlename and alternatename as well - and emits
     * a developer warning when handed an object without them. On a site with developer
     * debugging on, opening a student report with a flagged pair produced that warning for
     * every match.
     *
     * report.php and student_report.php already selected the full set, but as a hardcoded
     * eight-name string, in two places, which is how this one came to differ from them. The
     * list is not ours to maintain: \core_user\fields::for_name() is the authoritative
     * source, it is what fullname() itself is built around, and it stays correct if Moodle
     * ever adds a name field. All three call sites now come through here, so they cannot
     * drift apart again.
     *
     * Moodle 4.4 is the floor for this plugin and \core_user\fields arrived in 3.11, so
     * there is deliberately no fallback: a fallback would silently reinstate the bug on any
     * site where the class were missing, and failing loudly is the correct behaviour.
     *
     * @return string Field list for a $DB user query, suitable for passing to fullname().
     */
    public static function user_fields_for_fullname(): string {
        $namefields = \core_user\fields::for_name()->get_sql('', false, '', '', false)->selects;

        return 'id, username, ' . ltrim($namefields, ', ');
    }

    /**
     * The score for a submission: how similar it is to another submission, as a percentage.
     *
     * V1.0.99. This replaces a weighted average of style-signal points, and it is a
     * different kind of claim. The old number was an inference about how a document was
     * written. This one is a measurement of how much of it appears in somebody else's
     * submission to the same activity - which a teacher can check by opening both.
     *
     * The percentage is used directly as the 0-100 score, because the existing band
     * boundaries already sit where the measurements put them:
     *
     *   two students answering the same closed procedural question independently   13%
     *   a genuine copy with light paraphrase                                        92%
     *   two submissions on unrelated topics                                        0.7%
     *
     * So LOW below 35%, MEDIUM from 35%, HIGH from 65% needs no rescaling and no
     * invented mapping. S12_REPORT_THRESHOLD (35%) is the same number that governs
     * whether a pair is listed at all, so the score and the pair list cannot disagree.
     *
     * @param float $maxsimilarity Highest Jaccard bigram similarity against another
     *                             submission in the same activity, 0.0 to 1.0.
     * @return array riskscore (0-100), risklevel, and the similarity it came from.
     */
    public static function submission_score(float $maxsimilarity): array {
        $maxsimilarity = max(0.0, min(1.0, $maxsimilarity));
        $score         = round($maxsimilarity * 100, 2);

        return [
            'riskscore'      => $score,
            'risklevel'      => self::band($score),
            'max_similarity' => round($maxsimilarity, 4),
        ];
    }

    /**
     * Decide which submissions to rescore after a comparison, and to what.
     *
     * V1.1.0. This was a dozen lines of $DB->set_field() inside observer, which needs a
     * database and could therefore not be tested. That is the condition that hid both of
     * the defects shipped in 1.0.94 - S11 applied to unscored sections, and quotation
     * marks paired by proximity - so the decision is extracted here as a pure function
     * over plain arrays, and the observer is left with nothing but the writes.
     *
     * Two rules, both of which matter:
     *
     *   This submission takes the highest similarity found against it. If it matches
     *   nobody, that is 0, which is the honest value - no other submission to this
     *   activity shares significant wording with it.
     *
     *   A matched submission is RAISED ONLY. Copying is symmetric: if B matches A at 80%,
     *   A matches B at 80%, and A may have been analysed before B existed. Without
     *   raising A too, whoever submitted first keeps a clean badge however much of their
     *   work turns up in someone else's. But a later, lower match must never pull an
     *   existing higher one down, or a student could clear a 90% match by submitting
     *   again - which is both a correctness bug and an invitation.
     *
     * @param int $subid The submission just compared.
     * @param array $matches Each with 'subid' and 'similarity' (0.0-1.0), as returned by
     *                       cross_student_similarity().
     * @param array $existingscores Current stored riskscore keyed by submission id, for
     *                              the matched submissions.
     * @return array One entry per write to make: subid, riskscore, risklevel.
     */
    public static function plan_copy_evidence(int $subid, array $matches, array $existingscores): array {
        $max = 0.0;
        foreach ($matches as $m) {
            $max = max($max, (float)($m['similarity'] ?? 0));
        }

        $own    = self::submission_score($max);
        $writes = [[
            'subid'     => $subid,
            'riskscore' => $own['riskscore'],
            'risklevel' => $own['risklevel'],
        ]];

        $best = [];
        foreach ($matches as $m) {
            $otherid = (int)($m['subid'] ?? 0);
            if ($otherid <= 0 || $otherid === $subid) {
                continue;
            }
            // One write per submission even if it somehow appears twice.
            $best[$otherid] = max($best[$otherid] ?? 0.0, (float)($m['similarity'] ?? 0));
        }

        foreach ($best as $otherid => $sim) {
            $score = self::submission_score($sim);
            if ($score['riskscore'] > (float)($existingscores[$otherid] ?? 0)) {
                $writes[] = [
                    'subid'     => $otherid,
                    'riskscore' => $score['riskscore'],
                    'risklevel' => $score['risklevel'],
                ];
            }
        }

        return $writes;
    }

    /**
     * Version stamp for the meaning of a stored score.
     *
     * 1 = style-signal sum (every release up to 1.0.98). 2 = copy similarity percentage.
     * Rows carrying no stamp were written under model 1 and are not comparable with model
     * 2 rows, so the reports label them rather than silently reinterpreting them.
     */
    const SCORE_MODEL = 2;

    /**
     * Map a 0-100 risk score onto its risk band.
     *
     * @param float $score The risk score, 0-100.
     * @return string One of "low" (0-34), "medium" (35-64) or "high" (65-100).
     */
    public static function band(float $score): string {
        if ($score >= self::BAND_HIGH) {
            return 'high';
        }
        if ($score >= self::BAND_MEDIUM) {
            return 'medium';
        }
        return 'low';
    }

    /**
     * Tokenise text into words of two or more characters, in any script.
     *
     * @param string $text The text to tokenise.
     * @return string[] The words found, in document order.
     */
    private static function words(string $text): array {
        // V1.0.80: was /[^a-z0-9']+/ — an ASCII-only tokeniser. Anything outside a-z0-9
        // was a word separator, so "café" split into "caf", "Müller" into "ller", and a
        // Cyrillic or Greek paragraph produced NO words at all. Every downstream number
        // (word count, TTR, trigram uniqueness, sentence length) was therefore wrong for
        // accented Latin text and meaningless for other alphabets, while still being
        // reported to teachers as a risk score.
        //
        // \p{L}\p{N}\p{M} with /u keeps letters, digits and combining marks in any script.
        $split = preg_split('/[^\p{L}\p{N}\p{M}\']+/u', $text);
        if ($split === false) {
            // Malformed UTF-8 — keep the old behaviour rather than returning nothing.
            $split = preg_split('/[^a-z0-9\']+/', $text);
        }
        return array_values(
            array_filter(
                $split,
                fn($w) => \core_text::strlen($w) >= 2
                )
        );
    }

    /**
     * Proportion of the document's letters that are Latin-script.
     *
     * v1.0.80: DocGuard's twelve signals are English-language heuristics — an English
     * AI-marker phrase list, English transition words, English contractions, an English
     * passive-voice regex, English template openers. Run against a Chinese, Arabic,
     * Russian or Greek document they all stay silent, and the plugin confidently reports
     * "0/100 LOW risk" — a score that says only "this document is not in English", while
     * looking to a teacher exactly like a document that was checked and cleared. That is
     * worse than no answer.
     *
     * @param string $text The document text to measure.
     * @return float Proportion of the document's letters that are Latin-script, from 0.0 to
     *               1.0; 1.0 when the document contains no letters at all, so that an
     *               unmeasurable document is never treated as non-English.
     */
    private static function latin_letter_ratio(string $text): float {
        $letters = @preg_match_all('/\p{L}/u', $text);
        if ($letters === false || $letters === 0) {
            return 1.0; // Not measurable — do not block on it.
        }
        $latin = @preg_match_all('/\p{Latin}/u', $text);
        if ($latin === false) {
            return 1.0;
        }
        return $latin / $letters;
    }


}
