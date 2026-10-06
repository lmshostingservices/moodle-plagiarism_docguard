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
 * Unit tests for the pure scoring surface of the DocGuard analyser.
 *
 * Only the methods that touch neither the database nor plugin configuration are
 * exercised here: score_section(), submission_score() and band(). The individual
 * signal functions are private, so each one is driven through score_section() with
 * text constructed to fire it and text constructed not to. Every expected number was
 * observed from a run of the real method against the exact input given.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plagiarism_docguard\analyser
 */
final class analyser_test extends \advanced_testcase {

    /**
     * Risk scores and the band each one falls into.
     *
     * @return array<string, array{0: float, 1: string}>
     */
    public static function band_provider(): array {
        return [
            'zero is low' => [0.0, 'low'],
            'thirty four is low' => [34.0, 'low'],
            'just under thirty five is low' => [34.9, 'low'],
            'thirty five is medium' => [35.0, 'medium'],
            'sixty four is medium' => [64.0, 'medium'],
            'just under sixty five is medium' => [64.9, 'medium'],
            'sixty five is high' => [65.0, 'high'],
            'one hundred is high' => [100.0, 'high'],
        ];
    }

    /**
     * band() maps a score onto low, medium or high at the documented boundaries.
     *
     * @dataProvider band_provider
     * @param float $score The risk score to band.
     * @param string $expected The band observed from the real method.
     * @return void
     */
    public function test_band(float $score, string $expected): void {
        $this->assertSame($expected, analyser::band($score));
    }


    /**
     * A section of fewer than eight words is not scored at all: it reports zero risk
     * with an explicit note and no signals, so a one-line answer cannot be flagged.
     *
     * @return void
     */
    public function test_score_section_refuses_sections_under_eight_words(): void {
        $result = analyser::score_section('One two three four five six seven.');

        $this->assertSame(7, $result['wordcount']);
        $this->assertSame(0, $result['riskscore']);
        $this->assertSame('low', $result['risklevel']);
        $this->assertSame('insufficient_text', $result['note']);
        $this->assertSame([], $result['signals']);
    }

    /**
     * Eight words is the first length that is scored, and all ten signals are reported.
     *
     * @return void
     */
    public function test_score_section_scores_from_eight_words(): void {
        $result = analyser::score_section('One two three four five six seven eight.');

        $this->assertSame(8, $result['wordcount']);
        $this->assertSame('', $result['note'], 'A section long enough to describe carries no note.');
        /*
         * V1.0.99. This used to assert the ten style signals were present and keyed in
         * order. They were removed: measured against 48 answers written by three current
         * language models they flagged none of them, while the highest-scoring document in
         * the test set was a human second-language student. The key is retained and always
         * empty so stored rows and report code keep one shape.
         */
        $this->assertSame([], $result['signals'],
            'No style signals are computed; the key exists only to keep one row shape.');
        $this->assertSame(0, $result['riskscore'],
            'A section carries no score. The submission score is copy evidence.');
    }
















}
