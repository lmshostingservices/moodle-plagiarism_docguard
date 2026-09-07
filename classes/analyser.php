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
    /* ── AI Marker Vocabulary ─────────────────────────────────────────────────── */

    /**
     * Words and phrases that occur far more often in generated prose than in student writing.
     *
     * signal_ai_markers() (S1) counts occurrences of each entry, so the list must stay free
     * of duplicates — a repeated entry is counted twice for a single occurrence in the text.
     *
     * @var string[]
     */
    const AI_MARKERS = [
        'delve', 'delving', 'delved',
        'it is important to note', "it's important to note",
        'it is worth noting', "it's worth noting",
        'it is crucial', 'it is essential', 'it is vital',
        'certainly', 'crucially', 'fundamentally', 'essentially', 'ultimately',
        'leverage', 'leveraging', 'leveraged',
        'utilize', 'utilise', 'utilizing', 'utilising',
        'in conclusion', 'in summary', 'to summarize', 'to summarise', 'in closing', 'to conclude',
        'furthermore', 'moreover', 'additionally', 'consequently',
        'in today\'s world', 'in today\'s society', 'in modern times', 'in the modern era',
        'a comprehensive', 'a holistic', 'a nuanced',
        'robust', 'multifaceted', 'intricate complexities', 'complexities',
        'navigating', 'streamline', 'streamlining', 'transformative', 'innovative',
        'groundbreaking', 'cutting-edge', 'state-of-the-art',
        'best practices', 'key takeaways', 'actionable insights', 'actionable',
        'this essay will', 'this paper will', 'this report will', 'this assignment will',
        'in the realm of', 'in the context of', 'in the field of',
        'it goes without saying', 'needless to say',
        'undoubtedly', 'unequivocally',
        'henceforth', 'thereupon', 'aforementioned',
        'as previously mentioned', 'as stated above', 'as discussed above',
        'plays a crucial role', 'plays a vital role', 'plays an important role',
        'it should be noted', 'it must be noted', 'it can be argued',
        'in order to', 'with regard to', 'with respect to',
        'shed light on', 'shed light', 'delve deeper',
        'key stakeholders', 'stakeholder', 'paradigm', 'paradigm shift',
        'synergy', 'synergies', 'ecosystem', 'landscape',
        'empower', 'empowering', 'empowers',
        'foster', 'fostering', 'fosters',
        'proactive', 'proactively',
        'seamless', 'seamlessly',
        'at its core', 'at the core',
        'moving forward', 'going forward',
        'harness', 'harnessing', 'harnessed',
        'unlock', 'unlocking', 'unlocks',
        'resonate', 'resonates', 'resonating',
        // V1.0.84 FIX-DG-MARKER-DUPLICATE: 'landscape' was listed twice (also at the
        // 'synergy/ecosystem' line above). signal_ai_markers() walks this list with
        // strpos(), so ONE occurrence of the word in a student's text was counted as two
        // distinct markers and shown to the teacher twice - and that single duplicate
        // was enough to move the signal from the one-marker band (3 points) to the
        // two-marker band (8). Deduplicated here rather than in the loop so the list
        // stays the single source of truth.
        'journey', 'tapestry',
        'firstly', 'secondly', 'thirdly', 'fourthly', 'lastly',
        'overall', 'in essence', 'in brief',
    ];

    /**
     * Formal connectors whose density signal_transitions() (S4) measures per 100 words.
     *
     * Counted with substr_count() per entry, so duplicates inflate the rate — see the
     * FIX-DG-TRANSITION-DUPLICATE note below.
     *
     * @var string[]
     */
    const TRANSITION_WORDS = [
        'furthermore', 'moreover', 'additionally', 'however', 'therefore',
        'thus', 'hence', 'consequently', 'accordingly', 'subsequently',
        'in addition', 'as a result', 'in contrast', 'on the other hand',
        'nevertheless', 'nonetheless', 'notwithstanding', 'alternatively',
        // V1.0.84 FIX-DG-TRANSITION-DUPLICATE: 'meanwhile' was listed twice.
        // signal_transitions() sums substr_count() per entry, so one "Meanwhile" in a
        // student's text counted as two connectors and appeared twice in the hits shown
        // to the teacher. In a 51-word section that doubled the rate from 1.96 to 3.92
        // per 100 words, crossing the 3.0 threshold and awarding 5 points that a single
        // connector should not earn.
        'conversely', 'meanwhile', 'similarly', 'likewise',
        'in particular', 'specifically', 'notably', 'evidently', 'clearly',
    ];

    /**
     * Everyday contractions whose absence signal_contractions() (S5) treats as a signal.
     *
     * @var string[]
     */
    const CONTRACTIONS = [
        "don't", "doesn't", "didn't", "can't", "won't", "wouldn't", "couldn't",
        "shouldn't", "isn't", "aren't", "wasn't", "weren't", "haven't", "hasn't",
        "hadn't", "it's", "that's", "there's", "they're", "we're", "i'm", "i've",
        "i'll", "i'd", "you're", "you've", "you'll", "he's", "she's", "they've",
        "we've", "we'll", "let's", "what's", "who's", "how's",
    ];

    /**
     * Sentence openers signal_uniform_starts() (S9) counts when judging opener variety.
     *
     * @var string[]
     */
    const UNIFORM_STARTS = ['the ', 'it ', 'this ', 'in ', 'for ', 'there ', 'these '];

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
     * @return array Result record with keys status ('ok' or 'error'), overall_riskscore,
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

        // Score each section individually.
        $scoredsections = [];
        foreach ($sections as $sec) {
            $result = self::score_section($sec['text']);
            $scoredsections[] = array_merge($sec, $result);
        }

        // S11: Cross-section style inconsistency.
        if (count($scoredsections) >= 2) {
            $s11 = self::signal_cross_section_inconsistency($scoredsections);
            // Distribute S11 points equally across sections.
            foreach ($scoredsections as &$sec) {
                $sec['signals']['s11_cross_section'] = $s11;
                $sec['riskscore'] = min(100, $sec['riskscore'] + $s11['points']);
                $sec['risklevel'] = self::band($sec['riskscore']);
            }
            unset($sec);
        }

        // Overall score = weighted average (longer sections weigh more).
        $totalweight = 0;
        $totalscore  = 0;
        foreach ($scoredsections as $sec) {
            $w = max(1, $sec['wordcount']);
            $totalscore  += $sec['riskscore'] * $w;
            $totalweight += $w;
        }
        $overallscore = $totalweight > 0 ? round($totalscore / $totalweight, 2) : 0;
        $overalllevel = self::band($overallscore);

        // Normalised text for cross-student similarity.
        $norm = question_parser::normalise_for_similarity($text);

        $analysis = [
            'section_count'    => count($scoredsections),
            'overall_score'    => $overallscore,
            'overall_level'    => $overalllevel,
            'extraction_chars' => strlen($text),
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
     * Score a single text section across signals S1–S10.
     *
     * @param string $text The section text to score.
     * @return array riskscore (0-100), risklevel, wordcount, signals (keyed S1-S11), and
     *               for sections under 8 words a "note" of "insufficient_text".
     */
    public static function score_section(string $text): array {
        $lower  = strtolower($text);
        $words  = self::words($lower);
        $wcount = count($words);

        if ($wcount < 8) {
            return [
                'riskscore' => 0,
                'risklevel' => 'low',
                'wordcount' => $wcount,
                'signals'   => [],
                'note'      => 'insufficient_text',
            ];
        }

        $signals     = [];
        $totalscore = 0;

        // S1 — AI marker vocabulary (0–22 pts).
        $s1 = self::signal_ai_markers($lower, $wcount);
        $signals['s1_ai_markers'] = $s1;
        $totalscore += $s1['points'];

        // S2 — Sentence length uniformity (0–10 pts).
        $s2 = self::signal_sentence_uniformity($text);
        $signals['s2_sentence_uniformity'] = $s2;
        $totalscore += $s2['points'];

        // S3 — TTR uniformity across paragraphs (0–8 pts).
        $s3 = self::signal_ttr_uniformity($text);
        $signals['s3_ttr_uniformity'] = $s3;
        $totalscore += $s3['points'];

        // S4 — Formal transition overuse (0–8 pts).
        $s4 = self::signal_transitions($lower, $wcount);
        $signals['s4_transitions'] = $s4;
        $totalscore += $s4['points'];

        // S5 — Contraction absence (0–6 pts).
        $s5 = self::signal_contraction_absence($lower, $wcount);
        $signals['s5_contractions'] = $s5;
        $totalscore += $s5['points'];

        // S6 — Passive voice ratio (0–6 pts).
        $s6 = self::signal_passive_voice($lower, $wcount);
        $signals['s6_passive_voice'] = $s6;
        $totalscore += $s6['points'];

        // S7 — Intro/conclusion template pattern (0–10 pts).
        $s7 = self::signal_template_pattern($lower);
        $signals['s7_template'] = $s7;
        $totalscore += $s7['points'];

        // S8 — Trigram repetition (0–8 pts).
        $s8 = self::signal_trigram_repetition($words);
        $signals['s8_trigrams'] = $s8;
        $totalscore += $s8['points'];

        // S9 — Uniform sentence starts (0–6 pts).
        $s9 = self::signal_sentence_starts($text);
        $signals['s9_sentence_starts'] = $s9;
        $totalscore += $s9['points'];

        // S10 — Vocabulary richness extremity (0–6 pts).
        $s10 = self::signal_vocab_richness($words, $wcount);
        $signals['s10_vocab_richness'] = $s10;
        $totalscore += $s10['points'];

        $totalscore = (int)min(100, $totalscore);
        $level       = self::band($totalscore);

        return [
            'riskscore' => $totalscore,
            'risklevel' => $level,
            'wordcount' => $wcount,
            'signals'   => $signals,
        ];
    }

    /* ── Signal Implementations ──────────────────────────────────────────────── */

    /**
     * S1 — count phrases from the AI marker vocabulary present in the section.
     *
     * Scores 0-22 points on how many distinct markers appear, and reports the ten
     * matches found so the teacher can see what triggered it.
     *
     * @param string $lower The section text, already lower-cased.
     * @param int $wcount Number of recognised words in the section, used for density.
     * @return array Signal result: points, max, label, description, fired, plus the signal's own measurements.
     */
    private static function signal_ai_markers(string $lower, int $wcount): array {
        $hits   = [];
        $count  = 0;
        foreach (self::AI_MARKERS as $marker) {
            if (strpos($lower, strtolower($marker)) !== false) {
                $hits[] = $marker;
                $count++;
            }
        }
        $density = $wcount > 0 ? ($count / $wcount * 100) : 0;
        $points  = 0;
        if ($count >= 7) {
            $points = 22;
        } else if ($count >= 5) {
            $points = 17;
        } else if ($count >= 3) {
            $points = 12;
        } else if ($count >= 2) {
            $points = 8;
        } else if ($count === 1) {
            $points = 3;
        }

        return [
            'points'         => $points,
            'max'            => 22,
            'marker_count'   => $count,
            'density_per100' => round($density, 2),
            'matches'        => array_slice($hits, 0, 10),
            'label'          => 'AI / LLM marker vocabulary',
            'description'    => 'Detects phrases and vocabulary statistically overrepresented in ChatGPT/LLM output.',
            'fired'          => $points > 0,
        ];
    }

    /**
     * S2 — measure how uniform the sentence lengths are across the section.
     *
     * A low standard deviation of sentence word-counts is characteristic of generated
     * text; human writing varies sentence length far more.
     *
     * @param string $text The section text.
     * @return array Signal result: points, max, label, description, fired, plus the signal's own measurements.
     */
    private static function signal_sentence_uniformity(string $text): array {
        $sentences = self::split_sentences($text);
        $lengths   = array_map(fn($s) => count(self::words(strtolower($s))), $sentences);
        $lengths   = array_filter($lengths, fn($l) => $l > 2);
        $lengths   = array_values($lengths);
        $n         = count($lengths);

        if ($n < 4) {
            return ['points' => 0, 'max' => 10, 'sentence_count' => $n, 'std_dev' => null,
                    'mean' => null, 'label' => 'Sentence length uniformity', 'fired' => false,
                    'description' => 'Too few sentences to evaluate (need 4+).'];
        }

        $mean    = array_sum($lengths) / $n;
        $variance = array_sum(array_map(fn($l) => ($l - $mean) ** 2, $lengths)) / $n;
        $stddev = sqrt($variance);

        $points = 0;
        if ($stddev < 2.0 && $mean > 8) {
            $points = 10;
        } else if ($stddev < 3.0 && $mean > 8) {
            $points = 6;
        } else if ($stddev < 4.0 && $mean > 8) {
            $points = 3;
        }

        return [
            'points'         => $points,
            'max'            => 10,
            'sentence_count' => $n,
            'mean_words'     => round($mean, 1),
            'std_dev'        => round($stddev, 2),
            'label'          => 'Sentence length uniformity',
            'description'    => 'AI tends to write sentences of near-identical length. Low standard deviation across '
                . 'sentence word-counts is suspicious.',
            'fired'          => $points > 0,
        ];
    }

    /**
     * S3 — measure how consistent vocabulary richness is between paragraphs.
     *
     * Computes the type-token ratio of each paragraph of 20 or more words and scores
     * the standard deviation across them. Returns zero points for fewer than two
     * qualifying paragraphs.
     *
     * @param string $text The section text.
     * @return array Signal result: points, max, label, description, fired, plus the signal's own measurements.
     */
    private static function signal_ttr_uniformity(string $text): array {
        $paras = array_filter(
            preg_split('/\n{2,}/', $text),
            fn($p) => str_word_count(trim($p)) >= 20
        );
        $paras = array_values($paras);
        $n     = count($paras);

        if ($n < 2) {
            return ['points' => 0, 'max' => 8, 'para_count' => $n, 'ttr_std_dev' => null,
                    'label' => 'TTR uniformity across paragraphs', 'fired' => false,
                    'description' => 'Need 2+ paragraphs of 20+ words.'];
        }

        $ttrs = [];
        foreach ($paras as $p) {
            $ws   = self::words(strtolower($p));
            $ttrs[] = count($ws) > 0 ? count(array_unique($ws)) / count($ws) : 0;
        }
        $mean    = array_sum($ttrs) / count($ttrs);
        $variance = array_sum(array_map(fn($t) => ($t - $mean) ** 2, $ttrs)) / count($ttrs);
        $stddev  = sqrt($variance);

        $points = 0;
        if ($stddev < 0.025) {
            $points = 8;
        } else if ($stddev < 0.05) {
            $points = 4;
        }

        return [
            'points'      => $points,
            'max'         => 8,
            'para_count'  => $n,
            'ttr_values'  => array_map(fn($t) => round($t, 3), $ttrs),
            'ttr_std_dev' => round($stddev, 4),
            'label'       => 'Type-Token Ratio uniformity across paragraphs',
            'description' => 'Humans vary their vocabulary richness between paragraphs. AI maintains a suspiciously '
                . 'consistent TTR.',
            'fired'       => $points > 0,
        ];
    }

    /**
     * S4 — detect over-use of formal academic connectors.
     *
     * Counts occurrences of the transition word list and scores the rate per 100 words.
     *
     * @param string $lower The section text, already lower-cased.
     * @param int $wcount Number of recognised words in the section.
     * @return array Signal result: points, max, label, description, fired, plus the signal's own measurements.
     */
    private static function signal_transitions(string $lower, int $wcount): array {
        $count = 0;
        $hits  = [];
        foreach (self::TRANSITION_WORDS as $tw) {
            $occ = substr_count($lower, $tw);
            if ($occ > 0) {
                $count += $occ;
                $hits[]  = $tw . ' ×' . $occ;
            }
        }
        $per100 = $wcount > 0 ? round($count / $wcount * 100, 2) : 0;
        $points = 0;
        if ($per100 >= 4) {
            $points = 8;
        } else if ($per100 >= 3) {
            $points = 5;
        } else if ($per100 >= 2) {
            $points = 2;
        }

        return [
            'points'      => $points,
            'max'         => 8,
            'count'       => $count,
            'per_100'     => $per100,
            'hits'        => array_slice($hits, 0, 8),
            'label'       => 'Formal transition word overuse',
            'description' => 'AI over-uses academic connectors (furthermore, moreover, consequently, etc.).',
            'fired'       => $points > 0,
        ];
    }

    /**
     * S5 — detect the complete absence of contractions in a long section.
     *
     * Only evaluated for sections of 100 words or more; shorter text cannot support
     * the inference.
     *
     * @param string $lower The section text, already lower-cased.
     * @param int $wcount Number of recognised words in the section.
     * @return array Signal result: points, max, label, description, fired, plus the signal's own measurements.
     */
    private static function signal_contraction_absence(string $lower, int $wcount): array {
        if ($wcount < 100) {
            return ['points' => 0, 'max' => 6, 'found' => [], 'label' => 'Contraction absence', 'fired' => false,
                    'description' => 'Requires 100+ words.'];
        }
        $found = [];
        foreach (self::CONTRACTIONS as $c) {
            if (strpos($lower, $c) !== false) {
                $found[] = $c;
            }
        }
        $points = 0;
        if (empty($found) && $wcount >= 300) {
            $points = 6;
        } else if (empty($found) && $wcount >= 150) {
            $points = 3;
        }

        return [
            'points'      => $points,
            'max'         => 6,
            'found'       => $found,
            'label'       => 'Absence of contractions',
            'description' => 'Students naturally use contractions in informal writing. Formal AI-generated text often '
                . 'avoids them entirely.',
            'fired'       => $points > 0,
        ];
    }

    /**
     * S6 — measure the proportion of passive-voice constructions.
     *
     * @param string $lower The section text, already lower-cased.
     * @param int $wcount Number of recognised words in the section.
     * @return array Signal result: points, max, label, description, fired, plus the signal's own measurements.
     */
    private static function signal_passive_voice(string $lower, int $wcount): array {
        // Simple heuristic: "was/were/is/are/been/be [word]ed" or "was/were [word]en".
        $passive = preg_match_all(
            '/\b(was|were|is|are|been|be|being)\s+\w+(ed|en)\b/',
            $lower,
            $m
        );
        $ratio = $wcount > 0 ? round($passive / $wcount, 4) : 0;
        $points = 0;
        if ($ratio >= 0.04) {
            $points = 6;
        } else if ($ratio >= 0.025) {
            $points = 3;
        }

        return [
            'points'       => $points,
            'max'          => 6,
            'passive_count' => $passive,
            'ratio'        => $ratio,
            'label'        => 'Passive voice overuse',
            'description'  => 'AI-generated academic text tends to use significantly more passive voice than human writers.',
            'fired'        => $points > 0,
        ];
    }

    /**
     * S7 — detect templated essay openers and closers.
     *
     * Looks for stock opening phrases in the first 300 characters and stock closing
     * phrases in the last 300 characters of the section.
     *
     * @param string $lower The section text, already lower-cased.
     * @return array Signal result: points, max, label, description, fired, plus the signal's own measurements.
     */
    private static function signal_template_pattern(string $lower): array {
        $openers = [
            'this essay will', 'this paper will', 'this report will', 'this assignment will',
            'i will discuss', 'i will explore', 'in this essay', 'in this report',
            'this essay explores', 'this report explores', 'aims to explore', 'aims to discuss',
            'the purpose of this', 'the aim of this',
        ];
        $closers = [
            'in conclusion', 'in summary', 'to conclude', 'in closing',
            'to summarize', 'to summarise', 'in closing,', 'overall,',
            'as discussed', 'as outlined above', 'having examined',
        ];

        // Check first 300 chars and last 300 chars.
        $intro = substr($lower, 0, 300);
        $outro = substr($lower, -300);

        $foundopener = false;
        $foundcloser = false;
        $openerhit   = '';
        $closerhit   = '';

        foreach ($openers as $o) {
            if (strpos($intro, $o) !== false) {
                $foundopener = true;
                $openerhit = $o;
                break;
            }
        }
        foreach ($closers as $c) {
            if (strpos($outro, $c) !== false || strpos($lower, $c) !== false) {
                $foundcloser = true;
                $closerhit = $c;
                break;
            }
        }

        $points = 0;
        if ($foundopener && $foundcloser) {
            $points = 10;
        } else if ($foundopener || $foundcloser) {
            $points = 5;
        }

        return [
            'points'       => $points,
            'max'          => 10,
            'opener_found' => $foundopener,
            'closer_found' => $foundcloser,
            'opener_hit'   => $openerhit,
            'closer_hit'   => $closerhit,
            'label'        => 'Intro/conclusion template pattern',
            'description'  => 'AI consistently adds generic introductory sentences and conclusion paragraphs even to '
                . 'short answers.',
            'fired'        => $points > 0,
        ];
    }

    /**
     * S8 — measure word-trigram uniqueness across the section.
     *
     * Low uniqueness indicates repeated or templated phrasing. Returns zero points
     * for sections of fewer than 20 words.
     *
     * @param string[] $words Tokenised section words, in order, as returned by words().
     * @return array Signal result: points, max, label, description, fired, plus the signal's own measurements.
     */
    private static function signal_trigram_repetition(array $words): array {
        $n = count($words);
        if ($n < 20) {
            return ['points' => 0, 'max' => 8, 'unique_ratio' => null, 'label' => 'Trigram repetition', 'fired' => false,
                    'description' => 'Need 20+ words.'];
        }
        $trigrams = [];
        for ($i = 0; $i < $n - 2; $i++) {
            $tg = $words[$i] . '_' . $words[$i + 1] . '_' . $words[$i + 2];
            $trigrams[] = $tg;
        }
        $total  = count($trigrams);
        $unique = count(array_unique($trigrams));
        $ratio  = $total > 0 ? round($unique / $total, 4) : 1.0;
        $points = 0;
        if ($ratio < 0.50) {
            $points = 8;
        } else if ($ratio < 0.65) {
            $points = 4;
        } else if ($ratio < 0.75) {
            $points = 2;
        }

        return [
            'points'       => $points,
            'max'          => 8,
            'total'        => $total,
            'unique'       => $unique,
            'unique_ratio' => $ratio,
            'label'        => 'Trigram repetition',
            'description'  => 'Copy-paste and templated content produces low trigram uniqueness. Authentic writing '
                . 'has high phrase diversity.',
            'fired'        => $points > 0,
        ];
    }

    /**
     * S9 — measure how many sentences begin with the same small set of openers.
     *
     * Returns zero points for sections of fewer than five sentences.
     *
     * @param string $text The section text.
     * @return array Signal result: points, max, label, description, fired, plus the signal's own measurements.
     */
    private static function signal_sentence_starts(string $text): array {
        $sentences = self::split_sentences($text);
        $n         = count($sentences);
        if ($n < 5) {
            return ['points' => 0, 'max' => 6, 'uniform_ratio' => null, 'label' => 'Sentence-start uniformity', 'fired' => false,
                    'description' => 'Need 5+ sentences.'];
        }
        $uniform = 0;
        foreach ($sentences as $s) {
            $sl = strtolower(ltrim($s));
            foreach (self::UNIFORM_STARTS as $starter) {
                if (strpos($sl, $starter) === 0) {
                    $uniform++;
                    break;
                }
            }
        }
        $ratio = round($uniform / $n, 4);
        $points = 0;
        if ($ratio >= 0.65) {
            $points = 6;
        } else if ($ratio >= 0.50) {
            $points = 3;
        }

        return [
            'points'        => $points,
            'max'           => 6,
            'uniform_count' => $uniform,
            'total'         => $n,
            'uniform_ratio' => $ratio,
            'label'         => 'Sentence-start uniformity (perplexity proxy)',
            'description'   => 'AI often starts many consecutive sentences with "The", "It", "This", "In", etc. — a '
                . 'low-perplexity pattern.',
            'fired'         => $points > 0,
        ];
    }

    /**
     * S10 — flag type-token ratios at either extreme.
     *
     * A very high ratio in long text suggests machine polishing; a very low ratio
     * suggests verbatim copying. Returns zero points below 80 words.
     *
     * @param string[] $words Tokenised section words as returned by words().
     * @param int $wcount Number of recognised words in the section.
     * @return array Signal result: points, max, label, description, fired, plus the signal's own measurements.
     */
    private static function signal_vocab_richness(array $words, int $wcount): array {
        if ($wcount < 80) {
            return ['points' => 0, 'max' => 6, 'ttr' => null, 'label' => 'Vocabulary richness extremity', 'fired' => false,
                    'description' => 'Need 80+ words.'];
        }
        $ttr    = round(count(array_unique($words)) / $wcount, 4);
        $points = 0;
        // Very high TTR in long text = AI polishing; very low = copying.
        if ($ttr > 0.90 && $wcount >= 100) {
            $points = 6;
        } else if ($ttr > 0.85 && $wcount >= 150) {
            $points = 3;
        } else if ($ttr < 0.35 && $wcount >= 150) {
            $points = 4;
        } // copy-paste repetition

        return [
            'points'      => $points,
            'max'         => 6,
            'ttr'         => $ttr,
            'word_count'  => $wcount,
            'label'       => 'Vocabulary richness extremity',
            'description' => 'Extremely high Type-Token Ratio in long text suggests AI polish. Very low TTR suggests '
                . 'verbatim copying.',
            'fired'       => $points > 0,
        ];
    }

    /**
     * S11 — compare style measurements between the sections of one document.
     *
     * Uses the mean sentence length from S2 and the type-token ratio from S10 of each
     * section; a large spread suggests the sections were not all written the same way.
     *
     * @param array $sections Per-section results from score_section(), each with a
     *                        "signals" sub-array.
     * @return array Signal result: points, max, label, description, fired, plus the signal's own measurements.
     */
    private static function signal_cross_section_inconsistency(array $sections): array {
        $means    = [];
        $ttrs     = [];
        foreach ($sections as $sec) {
            if (!empty($sec['signals']['s2_sentence_uniformity']['mean_words'])) {
                $means[] = $sec['signals']['s2_sentence_uniformity']['mean_words'];
            }
            if (!empty($sec['signals']['s10_vocab_richness']['ttr'])) {
                $ttrs[] = $sec['signals']['s10_vocab_richness']['ttr'];
            }
        }

        $meanstd = count($means) >= 2 ? self::std_dev($means) : 0;
        $ttrstd  = count($ttrs) >= 2 ? self::std_dev($ttrs) : 0;

        $points = 0;
        if ($meanstd > 6 || $ttrstd > 0.15) {
            $points = 10;
        } else if ($meanstd > 4 || $ttrstd > 0.10) {
            $points = 5;
        }

        return [
            'points'       => $points,
            'max'          => 10,
            'mean_std_dev' => round($meanstd, 2),
            'ttr_std_dev'  => round($ttrstd, 4),
            'label'        => 'Cross-section writing style inconsistency',
            'description'  => 'Large variation in sentence length or vocabulary richness across questions suggests '
                . 'different sources or authors per question.',
            'fired'        => $points > 0,
        ];
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
            if ($score >= 0.30) {
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
            'id, username, firstname, lastname'
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

    /**
     * Compute S12 (cross-student) score for a submission given similarity results.
     * Pure: it reads the highest similarity found and returns the signal record; the
     * caller is responsible for folding the points into the submission's overall score.
     *
     * @param float $maxsimilarity The highest similarity found against any other
     *                              submission in the same activity, 0.0-1.0.
     * @return array Signal result: points, max, label, description, fired, plus the signal's own measurements.
     */
    public static function compute_s12_score(float $maxsimilarity): array {
        $points = 0;
        if ($maxsimilarity >= 0.75) {
            $points = 15;
        } else if ($maxsimilarity >= 0.55) {
            $points = 8;
        } else if ($maxsimilarity >= 0.35) {
            $points = 3;
        }

        return [
            'points'         => $points,
            'max'            => 15,
            'max_similarity' => $maxsimilarity,
            'label'          => 'Cross-student submission similarity',
            'description'    => 'Compares this submission against all other students in the same assignment using '
                . 'Jaccard bigram similarity.',
            'fired'          => $points > 0,
        ];
    }

    /* ── Helpers ─────────────────────────────────────────────────────────────── */

    /**
     * Map a 0-100 risk score onto its risk band.
     *
     * @param float $score The risk score, 0-100.
     * @return string One of "low" (0-34), "medium" (35-64) or "high" (65-100).
     */
    public static function band(float $score): string {
        if ($score >= 65) {
            return 'high';
        }
        if ($score >= 35) {
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

    /**
     * Split text into sentences on terminal punctuation.
     *
     * Fragments of five characters or fewer are discarded as noise.
     *
     * @param string $text The text to split.
     * @return string[] The sentences found, in document order.
     */
    private static function split_sentences(string $text): array {
        return array_values(
            array_filter(
                preg_split('/(?<=[.!?])\s+/', $text),
                fn($s) => strlen(trim($s)) > 5
                )
        );
    }

    /**
     * Population standard deviation of a list of numbers.
     *
     * @param float[] $values The values to measure.
     * @return float The standard deviation, or 0.0 for fewer than two values.
     */
    private static function std_dev(array $values): float {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($values) / $n;
        $var  = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $values)) / $n;
        return sqrt($var);
    }
}
