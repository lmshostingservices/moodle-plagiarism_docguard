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
 * Parses extracted document text into labelled sections.
 *
 * Handles common RTO/VET assessment document formats:
 *   - "Question 1:" / "Question 1." / "Q1:" / "Q.1"
 *   - "Activity 1:" / "Task 1:" / "Section 1:"
 *   - Standalone numbered lines: "1." / "1)" at line-start
 *   - "Answer 1:" / "Response 1:"
 *
 * Falls back to a whole-document single section if no markers are found.
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_parser {
    /**
     * Minimum length, in bytes, for a parsed section to be kept.
     *
     * filter_sections() drops anything shorter: a fragment this small is a stray heading
     * or page artefact rather than a student answer, and scoring it produces noise.
     *
     * @var int
     */
    const MIN_SECTION_CHARS = 40;

    /**
     * Parse text into sections.
     *
     * @param string $text  Full extracted document text.
     * @return array  Array of ['label' => string, 'text' => string, 'num' => int]
     */
    public static function parse(string $text): array {
        // Normalise line endings.
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $sections = self::try_labelled($text);
        if (count($sections) >= 2) {
            return self::filter_short($sections);
        }

        $sections = self::try_numbered($text);
        if (count($sections) >= 2) {
            return self::filter_short($sections);
        }

        // No clear structure — treat entire document as a single section.
        $cleaned = trim($text);
        if ($cleaned === '') {
            return [];
        }
        return [[
            'num'   => 0,
            'label' => 'Full Document',
            'text'  => $cleaned,
        ]];
    }

    /**
     * Try to split by labelled section markers:
     * "Question N", "Q N:", "Activity N", "Task N", "Section N",
     * "Answer N:", "Response N:"
     *
     * The ^ anchor is load-bearing and stays. When FIX-DG-PDF-NEWLINES (v1.0.85) was
     * investigated — the pure-PHP PDF fallback returned a whole page with no "\n" in it,
     * so this method matched nothing and every submission was reported as one "Full
     * Document" section — the obvious second line of defence was to relax the anchor and
     * find markers mid-string. It was considered and rejected, because it trades a safe
     * failure for an unsafe one.
     *
     * These words are ordinary prose. "the same hazard described in Question 1", "Part 2
     * of the Act", "Section 5 of the WHS Regulations", "Task 1 is complete" all appear
     * inside real answers, and "Q" on its own matches almost anything. Unanchored, each
     * would open a new section mid-sentence, truncate the real one, and attribute a
     * student's text to the wrong question — a wrong per-question similarity score that
     * reads as authoritative. Anchored and wrong, the parser returns the whole document
     * as one section: everything is still analysed, nothing is mis-attributed, and the
     * teacher can see what happened. Requiring the marker to start its own line is the
     * structural signal that separates a heading from a mention of one.
     *
     * The right place to fix a document with no line breaks is the extractor that lost
     * them, which is where it was fixed.
     *
     * @param string $text The extracted document text.
     * @return array Sections, each with section_num, section_label and text; empty when
     *               no labelled markers were found.
     */
    private static function try_labelled(string $text): array {
        // V1.0.86 FIX-DG-INDENTED-MARKERS: the anchor was a bare `^`, which requires the
        // marker to start at COLUMN ZERO. Real extracted text is indented far more often
        // than not, and every indented document silently fell through to one "Full
        // Document" section:
        //
        // - Ghostscript's txtwrite device (extraction path 2) prefixes EVERY line with
        // several spaces. On a host with Ghostscript but no poppler - common on
        // managed Moodle hosting - per-question analysis had never once worked.
        // - `pdftotext -layout` (path 1, the preferred one) deliberately PRESERVES the
        // source document's indentation. So any assessment whose questions sit in a
        // table, under a hanging indent, or inside a numbered list - which is most of
        // them in the VET sector this plugin serves - failed on every host, poppler
        // or not.
        //
        // Found on a live site: a document with three "Question N:" headings reported
        // "1 section(s) analysed / Full Document". The same text with the indentation
        // stripped parsed into all three.
        //
        // `^[ \t]*` keeps the whole of the protection the anchor exists for. The marker
        // must still START ITS OWN LINE, so "the hazard described in Question 1" mid-
        // sentence still cannot open a section; only leading horizontal whitespace is
        // now tolerated. \t as well as space, because tab-indented text is common in
        // DOCX extraction.
        $pattern = '/^[ \t]*(?:'
            . 'Question\s+(\d+)'
            . '|Q\.?\s*(\d+)'
            . '|Activity\s+(\d+)'
            . '|Task\s+(\d+)'
            . '|Section\s+(\d+)'
            . '|Part\s+(\d+)'
            . '|Answer\s+(\d+)'
            . '|Response\s+(\d+)'
            . ')[\s:.\-)]*/im';

        $lines   = explode("\n", $text);
        $chunks  = [];
        $current = null;
        $buf     = [];

        foreach ($lines as $line) {
            if (preg_match($pattern, $line, $m)) {
                if ($current !== null) {
                    $chunks[] = ['label' => $current['label'], 'num' => $current['num'], 'text' => implode("\n", $buf)];
                }
                // Determine the number from whichever capture group matched.
                $num = 0;
                foreach (array_slice($m, 1) as $cap) {
                    if ($cap !== '') {
                        $num = (int)$cap;
                        break;
                    }
                }
                // V1.0.86: same anchor fix - on an indented line this returned false and
                // every section was labelled "Section N" regardless of what it actually
                // said, so a teacher looking at "Question 2" in the document saw
                // "Section 2" in the report.
                $labelword = preg_match('/^[ \t]*(Question|Q\.?|Activity|Task|Section|Part|Answer|Response)/i', $line, $lm)
                    ? $lm[1] : 'Section';
                $current = ['label' => trim($labelword . ' ' . $num), 'num' => $num];
                $buf     = [$line];
            } else {
                if ($current !== null) {
                    $buf[] = $line;
                }
            }
        }
        if ($current !== null) {
            $chunks[] = ['label' => $current['label'], 'num' => $current['num'], 'text' => implode("\n", $buf)];
        }
        return $chunks;
    }

    /**
     * Try to split by standalone numbered lines at the start:
     * "1." / "1)" / "1 " (alone on a line followed by content)
     *
     * @param string $text The extracted document text.
     * @return array Sections, each with section_num, section_label and text; empty when
     *               no numbered lines were found.
     */
    private static function try_numbered(string $text): array {
        // V1.0.86 FIX-DG-INDENTED-MARKERS: see try_labelled(). A numbered list is if
        // anything MORE likely to be indented than a heading.
        $pattern = '/^[ \t]*(\d{1,2})[.)]\s+\S/m';
        $lines   = explode("\n", $text);
        $chunks  = [];
        $current = null;
        $buf     = [];

        foreach ($lines as $line) {
            if (preg_match('/^[ \t]*(\d{1,2})[.)]\s/', $line, $m)) {
                if ($current !== null) {
                    $chunks[] = ['label' => 'Question ' . $current['num'], 'num' => $current['num'], 'text' => implode("\n", $buf)];
                }
                $current = ['num' => (int)$m[1]];
                $buf     = [$line];
            } else {
                if ($current !== null) {
                    $buf[] = $line;
                }
            }
        }
        if ($current !== null) {
            $chunks[] = ['label' => 'Question ' . $current['num'], 'num' => $current['num'], 'text' => implode("\n", $buf)];
        }
        return $chunks;
    }

    /**
     * Remove sections that are too short to analyse.
     *
     * @param array $sections Candidate sections to filter.
     * @return array Only the sections of at least MIN_SECTION_CHARS characters.
     */
    private static function filter_short(array $sections): array {
        $out = [];
        foreach ($sections as $s) {
            if (strlen(trim($s['text'])) >= self::MIN_SECTION_CHARS) {
                $out[] = $s;
            }
        }
        return $out;
    }

    /**
     * Build a normalised (lowercase, no punctuation) fingerprint of text
     * for cross-student similarity comparison.
     *
     * @param string $text The text to normalise.
     * @return string Lower-cased text with punctuation removed, in any script.
     */
    public static function normalise_for_similarity(string $text): string {
        // V1.0.80: was strtolower() + /[^a-z0-9\s]/ — ASCII-only, so a Chinese, Arabic,
        // Greek, Hindi or Cyrillic submission normalised to an EMPTY string and every
        // similarity comparison involving it silently returned 0%. Two students who had
        // submitted identical non-Latin documents were reported as unrelated. Accented
        // Latin fared no better: "café" became "caf".
        //
        // core_text::strtolower() is multibyte-aware, and \p{L}\p{N}\p{M} with the /u
        // modifier keeps letters, numbers and combining marks in every script while still
        // stripping punctuation. ASCII text normalises to exactly the same string it did
        // before, so stored normtext from earlier releases stays comparable.
        $t = \core_text::strtolower($text);
        $stripped = preg_replace('/[^\p{L}\p{N}\p{M}\s]/u', '', $t);
        if ($stripped === null) {
            // Preg_* with /u returns null on malformed UTF-8 (extraction can produce it).
            // Fall back to the old byte-wise behaviour rather than losing the text.
            \debugging(
                'DocGuard: similarity normalisation fell back to ASCII — input is not valid UTF-8.',
                DEBUG_DEVELOPER
            );
            $stripped = preg_replace('/[^a-z0-9\s]/', '', $t);
        }
        $t = preg_replace('/\s+/u', ' ', $stripped);
        if ($t === null) {
            $t = preg_replace('/\s+/', ' ', $stripped);
        }
        return trim($t);
    }

    /**
     * Compute similarity ratio between two normalised text strings.
     * Returns 0.0 – 1.0.
     *
     * @param string $a First normalised text.
     * @param string $b Second normalised text.
     * @return float Similarity from 0.0 to 1.0; 0.0 when either string is empty.
     */
    public static function similarity(string $a, string $b): float {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        similar_text($a, $b, $pct);
        return round($pct / 100, 4);
    }

    /**
     * Split normalised text into comparison tokens.
     *
     * v1.0.84 FIX-DG-CJK-SIMILARITY: bigrams() and bigram_set() both did a bare
     * explode(' '), which assumes a script that separates words with spaces. Chinese,
     * Japanese and Thai do not. A normalised Chinese document is therefore ONE token,
     * which yields ZERO bigrams, which makes jaccard_sets() return 0.0 - even when
     * comparing a document against itself.
     *
     * That is the second half of the v1.0.80 defect. v1.0.80 fixed normalise_for_similarity()
     * so non-Latin text survives normalisation instead of becoming an empty string, and
     * the comment there describes exactly this scenario: "Two students who had submitted
     * identical non-Latin documents were reported as unrelated." For spaced scripts -
     * Cyrillic, Greek, Arabic - that fix was enough. For the space-free scripts it was
     * not, because the tokeniser downstream still could not see any words. Cross-student
     * similarity has been silently returning 0% for every Chinese, Japanese and Thai
     * cohort since the feature shipped.
     *
     * A run of CJK/Thai characters is split per character, so a bigram becomes a
     * character pair - the standard approach for these scripts, and the direct analogue
     * of a word pair in a spaced one. Latin, Cyrillic, Greek, Arabic and Hebrew text is
     * unaffected: it contains no characters in these ranges, so it takes the same
     * whitespace split it always did and every stored comparison stays valid.
     *
     * @param string $normtext Text already passed through normalise_for_similarity().
     * @return string[] The comparison tokens, in order.
     */
    private static function tokenise(string $normtext): array {
        $tokens = [];

        foreach (explode(' ', $normtext) as $word) {
            if ($word === '') {
                continue;
            }

            // CJK Unified Ideographs (+ Ext A), Hiragana, Katakana, Hangul syllables and
            // Thai. A token containing any of these is split per character; anything else
            // is kept whole.
            $cjk = '\x{3040}-\x{30ff}\x{3400}-\x{4dbf}\x{4e00}-\x{9fff}'
                 . '\x{ac00}-\x{d7af}\x{0e00}-\x{0e7f}\x{f900}-\x{faff}';

            if (!preg_match('/[' . $cjk . ']/u', $word)) {
                $tokens[] = $word;
                continue;
            }

            // Preg_split with /u can return false on malformed UTF-8; fall back to
            // keeping the token whole rather than losing the text entirely.
            $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
            if ($chars === false) {
                $tokens[] = $word;
                continue;
            }
            foreach ($chars as $ch) {
                $tokens[] = $ch;
            }
        }

        return $tokens;
    }

    /**
     * Generate a set of word bigrams from normalised text (for Jaccard similarity).
     *
     * @param string $normtext Text already passed through normalise_for_similarity().
     * @return string[] The distinct word bigrams, each joined by an underscore.
     */
    public static function bigrams(string $normtext): array {
        return array_keys(self::bigram_set($normtext));
    }

    /**
     * Jaccard similarity of two bigram arrays.
     *
     * @param string[] $a Bigrams of the first document.
     * @param string[] $b Bigrams of the second document.
     * @return float Jaccard similarity from 0.0 to 1.0; 0.0 when either set is empty.
     */
    public static function jaccard(array $a, array $b): float {
        if (empty($a) || empty($b)) {
            return 0.0;
        }
        $seta      = array_flip($a);
        $setb      = array_flip($b);
        $intersect = count(array_intersect_key($seta, $setb));
        $union     = count(array_unique(array_merge($a, $b)));
        return $union > 0 ? round($intersect / $union, 4) : 0.0;
    }

    /**
     * Bigram SET: [bigram => true]. Same content as bigrams(), one step earlier.
     *
     * v1.0.80: bigrams() builds this map internally and then throws it away with
     * array_keys(), which forces every consumer to array_flip() it back. The class report
     * compares every pair of submissions, so it paid for that round trip O(n²) times.
     * Callers that compare one document against many should build the set ONCE per
     * document with this method and compare with jaccard_sets().
     *
     * @param string $normtext Normalised text (see normalise_for_similarity()).
     * @return array<string,bool>
     */
    public static function bigram_set(string $normtext): array {
        $tokens = self::tokenise($normtext);
        $bg     = [];
        for ($i = 0, $n = count($tokens) - 1; $i < $n; $i++) {
            $bg[$tokens[$i] . '_' . $tokens[$i + 1]] = true;
        }
        return $bg;
    }

    /**
     * Jaccard similarity of two bigram SETS as returned by bigram_set().
     *
     * v1.0.80: mathematically identical to jaccard() — |A∩B| / |A∪B|, rounded to 4dp —
     * but takes the sets as given instead of flipping and merging arrays on every call.
     * The union is computed as |A| + |B| - |A∩B| rather than by materialising a merged
     * array, so a comparison allocates nothing beyond the intersection.
     *
     * @param array $a Bigram set from bigram_set(): bigram string => true.
     * @param array $b The other bigram set to compare against, in the same shape.
     * @return float Jaccard similarity in the range 0.0 (nothing in common) to 1.0
     *               (identical sets); 0.0 whenever either set is empty.
     */
    public static function jaccard_sets(array $a, array $b): float {
        if (empty($a) || empty($b)) {
            return 0.0;
        }
        // Intersect the smaller set into the larger one — array_intersect_key() costs
        // O(size of first argument).
        if (count($a) > count($b)) {
            [$a, $b] = [$b, $a];
        }
        $intersect = count(array_intersect_key($a, $b));
        $union     = count($a) + count($b) - $intersect;
        return $union > 0 ? round($intersect / $union, 4) : 0.0;
    }
}
