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
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class question_parser {
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
     */
    private static function try_labelled(string $text): array {
        $pattern = '/^(?:'
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
                $label_word = preg_match('/^(Question|Q\.?|Activity|Task|Section|Part|Answer|Response)/i', $line, $lm)
                    ? $lm[1] : 'Section';
                $current = ['label' => trim($label_word . ' ' . $num), 'num' => $num];
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
     */
    private static function try_numbered(string $text): array {
        $pattern = '/^(\d{1,2})[.)]\s+\S/m';
        $lines   = explode("\n", $text);
        $chunks  = [];
        $current = null;
        $buf     = [];

        foreach ($lines as $line) {
            if (preg_match('/^(\d{1,2})[.)]\s/', $line, $m)) {
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
     */
    public static function normalise_for_similarity(string $text): string {
        $t = strtolower($text);
        $t = preg_replace('/[^a-z0-9\s]/', '', $t);
        $t = preg_replace('/\s+/', ' ', $t);
        return trim($t);
    }

    /**
     * Compute similarity ratio between two normalised text strings.
     * Returns 0.0 – 1.0.
     */
    public static function similarity(string $a, string $b): float {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        similar_text($a, $b, $pct);
        return round($pct / 100, 4);
    }

    /**
     * Generate a set of word bigrams from normalised text (for Jaccard similarity).
     */
    public static function bigrams(string $norm_text): array {
        $words = explode(' ', $norm_text);
        $bg    = [];
        for ($i = 0, $n = count($words) - 1; $i < $n; $i++) {
            if ($words[$i] !== '' && $words[$i + 1] !== '') {
                $bg[$words[$i] . '_' . $words[$i + 1]] = true;
            }
        }
        return array_keys($bg);
    }

    /**
     * Jaccard similarity of two bigram arrays.
     */
    public static function jaccard(array $a, array $b): float {
        if (empty($a) || empty($b)) {
            return 0.0;
        }
        $setA      = array_flip($a);
        $setB      = array_flip($b);
        $intersect = count(array_intersect_key($setA, $setB));
        $union     = count(array_unique(array_merge($a, $b)));
        return $union > 0 ? round($intersect / $union, 4) : 0.0;
    }
}
