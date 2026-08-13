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

defined('MOODLE_INTERNAL') || die();

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
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class analyser {
    // ── AI Marker Vocabulary ───────────────────────────────────────────────────

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
        'journey', 'landscape', 'tapestry',
        'firstly', 'secondly', 'thirdly', 'fourthly', 'lastly',
        'overall', 'in essence', 'in brief',
    ];

    const TRANSITION_WORDS = [
        'furthermore', 'moreover', 'additionally', 'however', 'therefore',
        'thus', 'hence', 'consequently', 'accordingly', 'subsequently',
        'in addition', 'as a result', 'in contrast', 'on the other hand',
        'nevertheless', 'nonetheless', 'notwithstanding', 'alternatively',
        'conversely', 'meanwhile', 'meanwhile', 'similarly', 'likewise',
        'in particular', 'specifically', 'notably', 'evidently', 'clearly',
    ];

    const CONTRACTIONS = [
        "don't", "doesn't", "didn't", "can't", "won't", "wouldn't", "couldn't",
        "shouldn't", "isn't", "aren't", "wasn't", "weren't", "haven't", "hasn't",
        "hadn't", "it's", "that's", "there's", "they're", "we're", "i'm", "i've",
        "i'll", "i'd", "you're", "you've", "you'll", "he's", "she's", "they've",
        "we've", "we'll", "let's", "what's", "who's", "how's",
    ];

    const UNIFORM_STARTS = ['the ', 'it ', 'this ', 'in ', 'for ', 'there ', 'these '];

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Analyse a full submission: extract text, parse sections, score each.
     *
     * @param \stored_file $file
     * @param int          $cmid
     * @param int          $userid
     * @param int          $submissionid
     * @return array  ['status', 'overall_riskscore', 'overall_risklevel', 'sections', 'analysisjson', 'normtext', 'error']
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
        $scored_sections = [];
        foreach ($sections as $sec) {
            $result = self::score_section($sec['text']);
            $scored_sections[] = array_merge($sec, $result);
        }

        // S11: Cross-section style inconsistency.
        if (count($scored_sections) >= 2) {
            $s11 = self::signal_cross_section_inconsistency($scored_sections);
            // Distribute S11 points equally across sections.
            foreach ($scored_sections as &$sec) {
                $sec['signals']['s11_cross_section'] = $s11;
                $sec['riskscore'] = min(100, $sec['riskscore'] + $s11['points']);
                $sec['risklevel'] = self::band($sec['riskscore']);
            }
            unset($sec);
        }

        // Overall score = weighted average (longer sections weigh more).
        $total_weight = 0;
        $total_score  = 0;
        foreach ($scored_sections as $sec) {
            $w = max(1, $sec['wordcount']);
            $total_score  += $sec['riskscore'] * $w;
            $total_weight += $w;
        }
        $overall_score = $total_weight > 0 ? round($total_score / $total_weight, 2) : 0;
        $overall_level = self::band($overall_score);

        // Normalised text for cross-student similarity.
        $norm = question_parser::normalise_for_similarity($text);

        $analysis = [
            'section_count'    => count($scored_sections),
            'overall_score'    => $overall_score,
            'overall_level'    => $overall_level,
            'extraction_chars' => strlen($text),
        ];

        return [
            'status'            => 'analysed',
            'overall_riskscore' => $overall_score,
            'overall_risklevel' => $overall_level,
            'sections'          => $scored_sections,
            'analysisjson'      => json_encode($analysis),
            'normtext'          => substr($norm, 0, 65000),
            'error'             => null,
        ];
    }

    /**
     * Score a single text section across signals S1–S10.
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
        $total_score = 0;

        // S1 — AI marker vocabulary (0–22 pts)
        $s1 = self::signal_ai_markers($lower, $wcount);
        $signals['s1_ai_markers'] = $s1;
        $total_score += $s1['points'];

        // S2 — Sentence length uniformity (0–10 pts)
        $s2 = self::signal_sentence_uniformity($text);
        $signals['s2_sentence_uniformity'] = $s2;
        $total_score += $s2['points'];

        // S3 — TTR uniformity across paragraphs (0–8 pts)
        $s3 = self::signal_ttr_uniformity($text);
        $signals['s3_ttr_uniformity'] = $s3;
        $total_score += $s3['points'];

        // S4 — Formal transition overuse (0–8 pts)
        $s4 = self::signal_transitions($lower, $wcount);
        $signals['s4_transitions'] = $s4;
        $total_score += $s4['points'];

        // S5 — Contraction absence (0–6 pts)
        $s5 = self::signal_contraction_absence($lower, $wcount);
        $signals['s5_contractions'] = $s5;
        $total_score += $s5['points'];

        // S6 — Passive voice ratio (0–6 pts)
        $s6 = self::signal_passive_voice($lower, $wcount);
        $signals['s6_passive_voice'] = $s6;
        $total_score += $s6['points'];

        // S7 — Intro/conclusion template pattern (0–10 pts)
        $s7 = self::signal_template_pattern($lower);
        $signals['s7_template'] = $s7;
        $total_score += $s7['points'];

        // S8 — Trigram repetition (0–8 pts)
        $s8 = self::signal_trigram_repetition($words);
        $signals['s8_trigrams'] = $s8;
        $total_score += $s8['points'];

        // S9 — Uniform sentence starts (0–6 pts)
        $s9 = self::signal_sentence_starts($text);
        $signals['s9_sentence_starts'] = $s9;
        $total_score += $s9['points'];

        // S10 — Vocabulary richness extremity (0–6 pts)
        $s10 = self::signal_vocab_richness($words, $wcount);
        $signals['s10_vocab_richness'] = $s10;
        $total_score += $s10['points'];

        $total_score = (int)min(100, $total_score);
        $level       = self::band($total_score);

        return [
            'riskscore' => $total_score,
            'risklevel' => $level,
            'wordcount' => $wcount,
            'signals'   => $signals,
        ];
    }

    // ── Signal Implementations ────────────────────────────────────────────────

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
        if ($count >= 7)      { $points = 22; }
        elseif ($count >= 5)  { $points = 17; }
        elseif ($count >= 3)  { $points = 12; }
        elseif ($count >= 2)  { $points = 8; }
        elseif ($count === 1) { $points = 3; }

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
        $std_dev = sqrt($variance);

        $points = 0;
        if ($std_dev < 2.0 && $mean > 8)      { $points = 10; }
        elseif ($std_dev < 3.0 && $mean > 8)  { $points = 6; }
        elseif ($std_dev < 4.0 && $mean > 8)  { $points = 3; }

        return [
            'points'         => $points,
            'max'            => 10,
            'sentence_count' => $n,
            'mean_words'     => round($mean, 1),
            'std_dev'        => round($std_dev, 2),
            'label'          => 'Sentence length uniformity',
            'description'    => 'AI tends to write sentences of near-identical length. Low standard deviation across sentence word-counts is suspicious.',
            'fired'          => $points > 0,
        ];
    }

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
        $std_dev  = sqrt($variance);

        $points = 0;
        if ($std_dev < 0.025)     { $points = 8; }
        elseif ($std_dev < 0.05)  { $points = 4; }

        return [
            'points'      => $points,
            'max'         => 8,
            'para_count'  => $n,
            'ttr_values'  => array_map(fn($t) => round($t, 3), $ttrs),
            'ttr_std_dev' => round($std_dev, 4),
            'label'       => 'Type-Token Ratio uniformity across paragraphs',
            'description' => 'Humans vary their vocabulary richness between paragraphs. AI maintains a suspiciously consistent TTR.',
            'fired'       => $points > 0,
        ];
    }

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
        if ($per100 >= 4)     { $points = 8; }
        elseif ($per100 >= 3) { $points = 5; }
        elseif ($per100 >= 2) { $points = 2; }

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
        if (empty($found) && $wcount >= 300)      { $points = 6; }
        elseif (empty($found) && $wcount >= 150)  { $points = 3; }

        return [
            'points'      => $points,
            'max'         => 6,
            'found'       => $found,
            'label'       => 'Absence of contractions',
            'description' => 'Students naturally use contractions in informal writing. Formal AI-generated text often avoids them entirely.',
            'fired'       => $points > 0,
        ];
    }

    private static function signal_passive_voice(string $lower, int $wcount): array {
        // Simple heuristic: "was/were/is/are/been/be [word]ed" or "was/were [word]en"
        $passive = preg_match_all(
            '/\b(was|were|is|are|been|be|being)\s+\w+(ed|en)\b/',
            $lower, $m
        );
        $ratio = $wcount > 0 ? round($passive / $wcount, 4) : 0;
        $points = 0;
        if ($ratio >= 0.04)     { $points = 6; }
        elseif ($ratio >= 0.025){ $points = 3; }

        return [
            'points'       => $points,
            'max'          => 6,
            'passive_count'=> $passive,
            'ratio'        => $ratio,
            'label'        => 'Passive voice overuse',
            'description'  => 'AI-generated academic text tends to use significantly more passive voice than human writers.',
            'fired'        => $points > 0,
        ];
    }

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

        $found_opener = false;
        $found_closer = false;
        $opener_hit   = '';
        $closer_hit   = '';

        foreach ($openers as $o) {
            if (strpos($intro, $o) !== false) { $found_opener = true; $opener_hit = $o; break; }
        }
        foreach ($closers as $c) {
            if (strpos($outro, $c) !== false || strpos($lower, $c) !== false) {
                $found_closer = true; $closer_hit = $c; break;
            }
        }

        $points = 0;
        if ($found_opener && $found_closer) { $points = 10; }
        elseif ($found_opener || $found_closer) { $points = 5; }

        return [
            'points'       => $points,
            'max'          => 10,
            'opener_found' => $found_opener,
            'closer_found' => $found_closer,
            'opener_hit'   => $opener_hit,
            'closer_hit'   => $closer_hit,
            'label'        => 'Intro/conclusion template pattern',
            'description'  => 'AI consistently adds generic introductory sentences and conclusion paragraphs even to short answers.',
            'fired'        => $points > 0,
        ];
    }

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
        if ($ratio < 0.50)     { $points = 8; }
        elseif ($ratio < 0.65) { $points = 4; }
        elseif ($ratio < 0.75) { $points = 2; }

        return [
            'points'       => $points,
            'max'          => 8,
            'total'        => $total,
            'unique'       => $unique,
            'unique_ratio' => $ratio,
            'label'        => 'Trigram repetition',
            'description'  => 'Copy-paste and templated content produces low trigram uniqueness. Authentic writing has high phrase diversity.',
            'fired'        => $points > 0,
        ];
    }

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
                if (strpos($sl, $starter) === 0) { $uniform++; break; }
            }
        }
        $ratio = round($uniform / $n, 4);
        $points = 0;
        if ($ratio >= 0.65)     { $points = 6; }
        elseif ($ratio >= 0.50) { $points = 3; }

        return [
            'points'        => $points,
            'max'           => 6,
            'uniform_count' => $uniform,
            'total'         => $n,
            'uniform_ratio' => $ratio,
            'label'         => 'Sentence-start uniformity (perplexity proxy)',
            'description'   => 'AI often starts many consecutive sentences with "The", "It", "This", "In", etc. — a low-perplexity pattern.',
            'fired'         => $points > 0,
        ];
    }

    private static function signal_vocab_richness(array $words, int $wcount): array {
        if ($wcount < 80) {
            return ['points' => 0, 'max' => 6, 'ttr' => null, 'label' => 'Vocabulary richness extremity', 'fired' => false,
                    'description' => 'Need 80+ words.'];
        }
        $ttr    = round(count(array_unique($words)) / $wcount, 4);
        $points = 0;
        // Very high TTR in long text = AI polishing; very low = copying.
        if ($ttr > 0.90 && $wcount >= 100)    { $points = 6; }
        elseif ($ttr > 0.85 && $wcount >= 150){ $points = 3; }
        elseif ($ttr < 0.35 && $wcount >= 150){ $points = 4; } // copy-paste repetition

        return [
            'points'      => $points,
            'max'         => 6,
            'ttr'         => $ttr,
            'word_count'  => $wcount,
            'label'       => 'Vocabulary richness extremity',
            'description' => 'Extremely high Type-Token Ratio in long text suggests AI polish. Very low TTR suggests verbatim copying.',
            'fired'       => $points > 0,
        ];
    }

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

        $mean_std = count($means) >= 2 ? self::std_dev($means) : 0;
        $ttr_std  = count($ttrs)  >= 2 ? self::std_dev($ttrs)  : 0;

        $points = 0;
        if ($mean_std > 6 || $ttr_std > 0.15)    { $points = 10; }
        elseif ($mean_std > 4 || $ttr_std > 0.10) { $points = 5; }

        return [
            'points'       => $points,
            'max'          => 10,
            'mean_std_dev' => round($mean_std, 2),
            'ttr_std_dev'  => round($ttr_std, 4),
            'label'        => 'Cross-section writing style inconsistency',
            'description'  => 'Large variation in sentence length or vocabulary richness across questions suggests different sources or authors per question.',
            'fired'        => $points > 0,
        ];
    }

    /**
     * Compute cross-student Jaccard similarity between this submission and all others.
     * Returns array of ['userid', 'username', 'fullname', 'similarity', 'subid'].
     */
    public static function cross_student_similarity(int $subid, int $cmid, string $normtext): array {
        global $DB;

        if (strlen($normtext) < 100) {
            return [];
        }

        $bg_a    = question_parser::bigrams($normtext);
        $others  = $DB->get_records_select(
            'plagiarism_docguard_sub',
            'cmid = :cmid AND id != :subid AND status = :status AND normtext IS NOT NULL',
            ['cmid' => $cmid, 'subid' => $subid, 'status' => 'analysed']
        );

        $results = [];
        foreach ($others as $other) {
            $bg_b  = question_parser::bigrams((string)$other->normtext);
            $score = question_parser::jaccard($bg_a, $bg_b);
            if ($score >= 0.30) {
                $user = $DB->get_record('user', ['id' => $other->userid], 'id, username, firstname, lastname', IGNORE_MISSING);
                $results[] = [
                    'subid'      => $other->id,
                    'userid'     => $other->userid,
                    'username'   => $user ? $user->username : '?',
                    'fullname'   => $user ? fullname($user) : 'Unknown',
                    'similarity' => $score,
                    'risklevel'  => $other->overall_risklevel,
                ];
            }
        }

        usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
        return array_slice($results, 0, 10);
    }

    /**
     * Compute S12 (cross-student) score for a submission given similarity results.
     * Mutates $sub_record's overall score in-place.
     */
    public static function compute_s12_score(float $max_similarity): array {
        $points = 0;
        if ($max_similarity >= 0.75)     { $points = 15; }
        elseif ($max_similarity >= 0.55) { $points = 8; }
        elseif ($max_similarity >= 0.35) { $points = 3; }

        return [
            'points'         => $points,
            'max'            => 15,
            'max_similarity' => $max_similarity,
            'label'          => 'Cross-student submission similarity',
            'description'    => 'Compares this submission against all other students in the same assignment using Jaccard bigram similarity.',
            'fired'          => $points > 0,
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function band(float $score): string {
        if ($score >= 65) { return 'high'; }
        if ($score >= 35) { return 'medium'; }
        return 'low';
    }

    private static function words(string $text): array {
        return array_values(array_filter(
            preg_split('/[^a-z0-9\']+/', $text),
            fn($w) => strlen($w) >= 2
        ));
    }

    private static function split_sentences(string $text): array {
        return array_values(array_filter(
            preg_split('/(?<=[.!?])\s+/', $text),
            fn($s) => strlen(trim($s)) > 5
        ));
    }

    private static function std_dev(array $values): float {
        $n = count($values);
        if ($n < 2) { return 0.0; }
        $mean = array_sum($values) / $n;
        $var  = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $values)) / $n;
        return sqrt($var);
    }
}
