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

global $CFG;
require_once($CFG->dirroot . '/plagiarism/docguard/classes/authenticity.php');

/**
 * Tests for the authenticity checks.
 *
 * The centre of this file is test_honest_submissions_produce_no_findings(). Every other test
 * can fail and be fixed; that one failing means the plugin is accusing students who have done
 * nothing wrong, which is the only failure mode here that cannot be walked back.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \plagiarism_docguard\authenticity
 */
final class authenticity_test extends \advanced_testcase {

    /**
     * Real-looking honest submissions, including second-language writing.
     *
     * The ESL entries matter most. The signals this class replaced scored a human
     * second-language student higher than all 48 machine-generated documents in the corpus,
     * and the whole justification for checking artefacts instead of prose is that it cannot
     * do that. If any of these produce a finding, that justification is gone.
     *
     * @return array
     */
    public static function honest_submission_provider(): array {
        return [
            'VET answer, informal' => [
                'I always check the temperature of the cool room at the start of my shift and '
                . 'write it on the chart near the door. The danger zone is between 5 and 60 '
                . 'degrees because that is where bacteria grow fastest. If food sits in that '
                . 'range for more than four hours we have to throw it out. Last month the cool '
                . 'room read 8 degrees so I told the chef straight away and we moved the dairy.',
            ],
            'ESL, present tense and article slips' => [
                'In my work I am always careful for the client dignity. I knock the door first '
                . 'and I wait until they say come in. If the client not want the shower I am not '
                . 'forcing, I write in the notes and I tell the nurse at handover. This is '
                . 'important because the person have the right to choose for himself.',
            ],
            'ESL, formal register' => [
                'The employer must to provide the safe system of work for all the workers. This '
                . 'is meaning that the hazards must be identified and controlled before the work '
                . 'is starting. In my previous workplace we are doing the risk assessment every '
                . 'month and the supervisor is signing the form.',
            ],
            'confident native, formal and correct' => [
                'Section 19 of the Work Health and Safety Act 2011 imposes a primary duty of '
                . 'care on a person conducting a business or undertaking. In practice this means '
                . 'the employer must eliminate risks so far as is reasonably practicable, and '
                . 'where elimination is not possible, minimise them using the hierarchy of '
                . 'control. The Privacy Act 1988 governs how we handle client information.',
            ],
            'uses a bulleted list with hyphens' => [
                "When I do the opening checks I look at three things:\n"
                . "- the cool room temperature\n"
                . "- the date labels on the prepared food\n"
                . "- whether the hand wash station has soap and paper\n"
                . 'Then I sign the checklist and tell the supervisor if anything is wrong.',
            ],
            'quotes legislation at length' => [
                'The Act is clear on this point. Section 19 of the Work Health and Safety Act '
                . '2011 provides that a person conducting a business or undertaking must ensure, '
                . 'so far as is reasonably practicable, that the health and safety of workers is '
                . 'not put at risk from work carried out as part of the conduct of the business. '
                . 'In my own workplace this is why we hold a toolbox talk every morning.',
            ],
            'cites unit codes correctly' => [
                'This assessment covers SITXFSA005 and SITXFSA006, and builds on HLTWHS002 and '
                . 'BSBWHS211 which I completed last term. I also referred to the AS4674 standard '
                . 'for the design of food premises when answering question six.',
            ],
            'writes about AI, legitimately' => [
                'Our organisation policy says we are not allowed to use AI tools to write our '
                . 'assessments. I think this is fair because the assessment is meant to show what '
                . 'I can do. I did use the spell checker in Word, which the trainer said was '
                . 'fine, and I asked my supervisor to explain the hierarchy of control to me.',
            ],
        ];
    }

    /**
     * No honest submission may produce any finding.
     *
     * @dataProvider honest_submission_provider
     * @param string $text An honest submission.
     * @return void
     */
    public function test_honest_submissions_produce_no_findings(string $text): void {
        $findings = authenticity::text_findings($text);

        $this->assertSame([], $findings, 'Honest submission produced findings: '
            . implode('; ', array_map(
                fn($f) => $f['label'] . ' => "' . $f['matched'] . '"',
                $findings
            )));
    }

    /**
     * A correctly cited Act must not be reported, in any grammatical frame.
     *
     * The regression this guards is specific and was live when written. The title pattern has
     * to permit lowercase connectors so it can match "Work Health and Safety Act"; that also
     * let it absorb the preposition carrying the citation into the sentence, so "under section
     * 19 of the Work Health and Safety Act 2011" was looked up as "of the work health and
     * safety act" and reported as an unrecognised reference. A student who cited the law
     * correctly was told their reference could not be verified.
     *
     * @return void
     */
    public function test_a_correctly_cited_act_is_never_reported(): void {
        $frames = [
            'The Work Health and Safety Act 2011 requires this.',
            'Under the Work Health and Safety Act 2011, employers must act.',
            'This is set out in section 19 of the Work Health and Safety Act 2011.',
            'See the Work Health and Safety Act 2011 for the full duty.',
            'According to the Privacy Act 1988 we must protect client information.',
            'The Fair Work Act 2009 and the Privacy Act 1988 both apply here.',
            'As required by the Aged Care Act 1997.',
            'The Occupational Health and Safety Act 2004 applies in Victoria.',
            'Reporting obligations come from the Children and Young Persons (Care and '
                . 'Protection) Act 1998 in New South Wales.',
        ];

        foreach ($frames as $frame) {
            $this->assertSame([], authenticity::legislation_references($frame),
                'Correctly cited Act reported as a finding: ' . $frame);
        }

        /*
         * And the stripping must survive being reached through more than one leading word,
         * which is why it loops rather than running once. Mutation testing showed that a
         * single pass and a loop were indistinguishable to the tests above, because none of
         * them stacked two connectors in front of the title.
         */
        $this->assertSame([], authenticity::legislation_references(
            'The duty is as set out in the Work Health and Safety Act 2011.'
        ), 'A title reached through several leading words must still be recognised.');
    }

    /**
     * The provenance read in the analyser must never fail a submission.
     *
     * Provenance is the weakest of these checks and by far the most environment-dependent:
     * ZipArchive for .docx, pdfinfo for PDFs, and a readable temp directory for either. If any
     * of that is missing the submission must still be analysed, with no provenance shown. A
     * submission left permanently unanalysed because its metadata could not be read would be a
     * far worse outcome than having no metadata.
     *
     * Checked at source level because the behavioural version needs a stored_file. Mutation
     * testing found this gap: making the catch rethrow failed no test.
     *
     * @return void
     */
    public function test_a_failed_provenance_read_cannot_fail_the_submission(): void {
        $src = file_get_contents(__DIR__ . '/../classes/analyser.php');
        $from = strpos($src, 'protected static function file_provenance');
        $this->assertNotFalse($from, 'file_provenance() must exist');
        $to = strpos($src, "\n    /**", $from + 10);
        $body = substr($src, $from, $to === false ? null : $to - $from);
        $code = implode("\n", array_filter(
            explode("\n", $body),
            fn($l) => !preg_match('~^\s*(\*|//|/\*)~', $l)
        ));

        $this->assertMatchesRegularExpression('/catch\s*\(\s*\\\\Throwable/', $code,
            'The provenance read must catch Throwable, not just Exception.');
        $this->assertMatchesRegularExpression('/catch[^{]*\{(?:[^{}]|\{[^{}]*\})*return\s+\$empty\s*;/s', $code,
            'The catch block must RETURN the empty result. Rethrowing would turn an unreadable '
            . 'metadata field into a failed submission.');
        $this->assertStringNotContainsString('throw $e', $code,
            'A provenance failure must never propagate.');
        $this->assertStringContainsString('@unlink($tmpfile)', $code,
            'The temporary copy must be removed whatever happens.');
    }

    /**
     * A real Act cited with a year it never had is a strong finding.
     *
     * @return void
     */
    public function test_a_real_act_with_a_wrong_year_is_strong(): void {
        $findings = authenticity::legislation_references(
            'The Work Health and Safety Act 2015 sets out the primary duty of care.'
        );

        $this->assertCount(1, $findings);
        $this->assertSame('reference_wrong_year', $findings[0]['check']);
        $this->assertSame(authenticity::SEVERITY_STRONG, $findings[0]['severity']);
        $this->assertStringContainsString('2011', $findings[0]['expected'],
            'The finding must tell the trainer which years do exist.');
    }

    /**
     * An Act absent from the registry is reported as unrecognised, never as fabricated.
     *
     * The registry is a curated list, not the statute book, so absence from it means the
     * plugin does not know the Act - which is a different statement from the Act not existing,
     * and the weaker of the two is the only one the data supports. The severity is NOTABLE and
     * the label must not assert fabrication.
     *
     * @return void
     */
    public function test_an_unknown_act_is_reported_as_unrecognised_not_fabricated(): void {
        $findings = authenticity::legislation_references(
            'Under Section 48 of the Workplace Safety Act 2019, employers must eliminate hazards.'
        );

        $this->assertCount(1, $findings);
        $this->assertSame('reference_unrecognised', $findings[0]['check']);
        $this->assertSame(authenticity::SEVERITY_NOTABLE, $findings[0]['severity']);
        foreach (['fabricat', 'invent', 'fake', 'false', 'made up'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $findings[0]['label'],
                'An unrecognised reference must not be labelled as fabricated: the registry is '
                . 'not the statute book.');
        }
    }

    /**
     * Conversational artefacts from a chat assistant are strong findings.
     *
     * @return void
     */
    public function test_assistant_artefacts_are_strong_findings(): void {
        $cases = [
            'As an AI language model, I cannot provide personal examples.',
            "Certainly! Here is a 150-200 word response in Australian English.",
            'Here is a revised version of your answer.',
            'Let me know if you would like me to adjust the tone.',
            'Word count: 187',
            'I hope this helps with your assessment.',
            'Would you like me to expand on any of these points?',
        ];

        foreach ($cases as $case) {
            $findings = authenticity::assistant_artefacts($case);
            $this->assertNotEmpty($findings, 'Missed an assistant artefact: ' . $case);
            $this->assertSame(authenticity::SEVERITY_STRONG, $findings[0]['severity']);
            $this->assertNotEmpty($findings[0]['evidence'],
                'Every finding must quote its surrounding text so a trainer can read it in place.');
        }
    }

    /**
     * Markdown in a word-processed submission is notable, never strong.
     *
     * A student who drafts in a notes app, or who has learned to type asterisks for emphasis,
     * produces the same marks as one who pasted from a chat window. It is a reason to look at
     * how the document was assembled, not a conclusion about it.
     *
     * @return void
     */
    public function test_markdown_is_notable_and_grouped_by_kind(): void {
        $text = "### Question 4\n\n**The danger zone** is between 5 and 60 degrees.\n\n"
            . "* bacteria multiply rapidly\n* the two hour four hour rule applies\n\n"
            . "**Monitoring** is **essential** and **required**.\n\n---\n";

        $findings = authenticity::markdown_contamination($text);
        $this->assertNotEmpty($findings);

        $labels = array_column($findings, 'label');
        $this->assertSame($labels, array_unique($labels),
            'One finding per KIND of markdown. Twelve bold spans are one observation about the '
            . 'document, not twelve concerns.');

        foreach ($findings as $finding) {
            $this->assertSame(authenticity::SEVERITY_NOTABLE, $finding['severity'],
                'Markdown must never be a strong finding: honest drafting produces it.');
        }

        $bold = array_values(array_filter($findings, fn($f) => $f['label'] === 'markdown bold'));
        $this->assertCount(1, $bold);
        $this->assertSame(4, $bold[0]['count'], 'The count of occurrences must be carried.');
    }

    /**
     * Hyphen bullets are not markdown, because half of all honest writers use them.
     *
     * @return void
     */
    public function test_hyphen_bullets_are_not_treated_as_markdown(): void {
        $text = "I check three things:\n- the temperature\n- the labels\n- the hand wash station\n";

        $this->assertSame([], authenticity::markdown_contamination($text),
            'Hyphen bullets are ordinary typing and must never be flagged.');
    }

    /**
     * The unit-code format check is deliberately absent, and must stay absent.
     *
     * It was written and removed before release: the format was taken to be three to six
     * letters then three or four digits, which rejects SITXFSA005 and SITXFSA006 - seven
     * letters, both real. Widening it to cover the genuine spread across training packages
     * makes it approve everything, so it either produces false findings against real codes or
     * verifies nothing while appearing to. Either is worse than not having it.
     *
     * This test exists so that reintroducing it is a deliberate act. A real check needs a
     * training.gov.au lookup, which is scoped separately.
     *
     * @return void
     */
    public function test_no_unit_code_format_check_is_reintroduced(): void {
        $this->assertFalse(method_exists(authenticity::class, 'malformed_unit_codes'),
            'A unit-code format check cannot be made to work by pattern alone. It rejected '
            . 'SITXFSA005, a real code, and widening it makes it vacuous.');

        // And real codes must pass through the full check set untouched.
        $this->assertSame([], authenticity::text_findings(
            'This assessment covers SITXFSA005, SITXFSA006, CHCCCS031, HLTAID011, BSBWHS211, '
            . 'TLILIC0003 and CPCCWHS1001, and references AS4674 and ISO 9001.'
        ), 'Real unit codes and standards must produce no findings.');
    }

    /**
     * There is no composite score, and no route to one.
     *
     * The previous version of this plugin displayed a single confident badge built by summing
     * style signals that, measured, discriminated nothing. The tally is a count of findings by
     * severity precisely so that it cannot be read as a probability. This test exists to make
     * adding a weighted total a deliberate act that breaks a named guard, rather than a small
     * convenience somebody adds to make the panel look tidier.
     *
     * @return void
     */
    public function test_there_is_no_composite_score(): void {
        $findings = authenticity::text_findings(
            'As an AI language model I cannot help. **Bold** text. ### Heading. '
            . 'Under the Workplace Safety Act 2019 employers must act. Word count: 187'
        );
        $tally = authenticity::tally($findings);

        $this->assertSame(['strong', 'notable', 'context'], array_keys($tally),
            'The tally is three counts and nothing else.');
        foreach ($tally as $value) {
            $this->assertIsInt($value, 'Counts, not weights.');
        }

        $src = file_get_contents(__DIR__ . '/../classes/authenticity.php');
        $code = implode("\n", array_filter(
            explode("\n", $src),
            fn($l) => !preg_match('~^\s*(\*|//|/\*)~', $l)
        ));
        foreach (['riskscore', 'risklevel', 'confidence', 'probability', 'percent'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $code,
                'The authenticity checks must not produce a score, a band or a probability. '
                . '(Comments are stripped before this check: guards in this plugin have '
                . 'matched their own explanatory comments four times.)');
        }
    }

    /**
     * No style measurement may be reintroduced into these checks.
     *
     * Three separate measurements ruled style analysis out: the marker signals flagged 0 of 48
     * machine-generated answers; style statistics as absolute measures overlapped the human
     * range by 25-100%; and within-document comparison fails because one author's variation
     * between two halves of their own writing (36% on mean sentence length at p90) exceeds the
     * median difference between two different authors (20%). This guard keeps that decision
     * from being quietly reversed.
     *
     * @return void
     */
    public function test_no_style_measurement_is_reintroduced(): void {
        $src = file_get_contents(__DIR__ . '/../classes/authenticity.php');
        $code = implode("\n", array_filter(
            explode("\n", $src),
            fn($l) => !preg_match('~^\s*(\*|//|/\*)~', $l)
        ));

        foreach (['sentence_length', 'sentencelength', 'avg_sentence', 'type_token', 'ttr',
                  'burstiness', 'perplexity', 'readability', 'flesch', 'passive_voice',
                  'vocab_richness', 'lexical_diversity'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $code,
                $forbidden . ' is a writing-style measure. These were measured and do not '
                . 'separate AI from human writing at assessment-answer length.');
        }
    }

    /**
     * Verification questions are always produced, and always come from the submission.
     *
     * @return void
     */
    public function test_verification_questions_are_always_available(): void {
        $rich = 'Under section 19 of the Work Health and Safety Act 2011 the employer must '
            . 'eliminate risks so far as is reasonably practicable, and where that is not '
            . 'possible must minimise them by applying the hierarchy of control in order.';

        $questions = authenticity::verification_questions($rich);
        $this->assertNotEmpty($questions);
        $this->assertLessThanOrEqual(4, count($questions));
        foreach ($questions as $question) {
            $this->assertNotEmpty($question['question']);
            $this->assertNotEmpty($question['basis'], 'Each question must say what it was drawn from.');
        }
        $joined = implode(' ', array_column($questions, 'question'));
        $this->assertStringContainsString('reasonably practicable', $joined,
            'A term the learner used should be put back to them.');

        // Even a submission with nothing to hook onto must yield the general question.
        $bare = authenticity::verification_questions('I did the job and it was fine.');
        $this->assertNotEmpty($bare, 'A trainer must never be handed an empty list.');
    }

    /**
     * A verification question must never quote the assessment template back at the learner.
     *
     * The regression this guards was live when written. The question that reads a sentence
     * back took the longest sentence in the extracted text - which on a real submission is the
     * cover sheet, because the header carries no sentence punctuation and so parses as one
     * enormous sentence. The trainer was handed "You wrote: 'ASSESSMENT COVER SHEET Registered
     * Training Organisation code 41234 ... Instructions to the student ...' - can you talk me
     * through what you meant by that?" Asking a learner to explain their own cover sheet would
     * discredit the panel in a single line.
     *
     * @return void
     */
    public function test_questions_never_quote_the_assessment_template(): void {
        $submission = "ASSESSMENT COVER SHEET Registered Training Organisation code 41234 "
            . "Unit of competency SITXFSA005 Use hygienic practices for food safety "
            . "Assessment task 1 of 3 written questions Student Noor Haddad Student ID VET-20503 "
            . "Instructions to the student Answer all questions in your own words in the spaces "
            . "provided Student declaration I declare that this assessment is my own work and "
            . "that I have not copied from any other student\n\n"
            . "My answer\n\n"
            . 'In my kitchen the danger zone is from 5 degrees up to 60 degrees and I check the '
            . 'cool room with the probe at the start of every shift because the bacteria grow '
            . 'very fast in that range. One time the cool room was 9 degrees in the morning so I '
            . 'told the chef straight away and we moved the milk to the other fridge.';

        $questions = authenticity::verification_questions($submission);
        $joined = implode(' ', array_column($questions, 'question'));

        foreach (['ASSESSMENT COVER SHEET', 'Registered Training Organisation',
                  'Instructions to the student', 'Student ID', 'I declare that this assessment',
                  'Assessment task 1 of 3'] as $boilerplate) {
            $this->assertStringNotContainsString($boilerplate, $joined,
                'A verification question quoted the assessment template: ' . $boilerplate);
        }

        // And it must still find the learner's own sentence to read back.
        $this->assertStringContainsString('cool room', $joined,
            'The question should quote the learner\'s own account, which is what the template '
            . 'did not supply.');
    }

    /**
     * Provenance degrades honestly: an unreadable or unsupported file reports no data.
     *
     * Absence of metadata must never present as a clean result. Plenty of ordinary pipelines
     * strip it.
     *
     * @return void
     */
    public function test_provenance_reports_absence_as_absence(): void {
        $this->resetAfterTest();
        $dir = make_request_directory();

        $txt = $dir . '/answer.txt';
        file_put_contents($txt, 'plain text, not a supported container');
        $result = authenticity::provenance($txt, 'answer.txt', 300);
        $this->assertFalse($result['available']);
        $this->assertSame([], $result['fields']);
        $this->assertSame([], $result['findings']);

        // A file claiming to be a .docx that is not a zip at all.
        $broken = $dir . '/broken.docx';
        file_put_contents($broken, 'not a zip');
        $result = authenticity::provenance($broken, 'broken.docx', 300);
        $this->assertFalse($result['available'],
            'An unreadable container must report no data rather than raising.');

        // A missing file.
        $result = authenticity::provenance($dir . '/absent.docx', 'absent.docx', 300);
        $this->assertFalse($result['available']);
    }

    /**
     * Provenance reads a real .docx package and puts short editing time in proportion.
     *
     * @return void
     */
    public function test_provenance_reads_docx_fields(): void {
        $this->resetAfterTest();
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required to read .docx packages.');
        }
        $path = make_request_directory() . '/sample.docx';

        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('docProps/app.xml',
            '<?xml version="1.0"?><Properties><TotalTime>2</TotalTime>'
            . '<Application>Microsoft Office Word</Application><AppVersion>16.0000</AppVersion>'
            . '</Properties>');
        $zip->addFromString('docProps/core.xml',
            '<?xml version="1.0"?><cp:coreProperties '
            . 'xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" '
            . 'xmlns:dcterms="http://purl.org/dc/terms/">'
            . '<dc:creator>A Student</dc:creator><cp:lastModifiedBy>Someone Else</cp:lastModifiedBy>'
            . '<cp:revision>1</cp:revision>'
            . '<dcterms:created>2026-10-14T09:00:00Z</dcterms:created>'
            . '<dcterms:modified>2026-10-14T09:00:30Z</dcterms:modified>'
            . '</cp:coreProperties>');
        $zip->addFromString('word/document.xml', '<w:document/>');
        $zip->close();

        $result = authenticity::provenance($path, 'sample.docx', 1200);

        $this->assertTrue($result['available']);
        $this->assertSame('2', $result['fields']['editing_minutes']);
        $this->assertSame('Microsoft Office Word', $result['fields']['application']);
        $this->assertSame('A Student', $result['fields']['creator']);

        $labels = array_column($result['findings'], 'label');
        $this->assertContains('recorded editing time is short for the length', $labels);
        $this->assertContains('document records a single save', $labels);
        $this->assertContains('whole document lifespan under two minutes', $labels);
        $this->assertContains('created and last saved by different names', $labels);

        foreach ($result['findings'] as $finding) {
            $this->assertSame(authenticity::SEVERITY_CONTEXT, $finding['severity'],
                'Provenance is context only. It says how the file was assembled, never who '
                . 'wrote the words.');
        }
    }

    /**
     * The editing-time check is calibrated to transcription speed, not composition speed.
     *
     * The threshold decides who gets looked at, so it is asserted directly rather than left to
     * whatever the constant happens to be. 60 words a minute is copy-typing; a learner who
     * drafted on paper and typed it up sits at or below it and must pass. Composition of
     * considered prose runs 15-25 wpm, so anything in that range must obviously pass too.
     *
     * The first version of this check used 100 wpm and let 3 minutes for 285 words through
     * unremarked, which is 95 words a minute.
     *
     * @return void
     */
    public function test_editing_time_threshold_is_transcription_speed(): void {
        $this->assertSame(60, authenticity::COMPOSITION_WPM_CEILING,
            'Changing this changes who gets looked at. 60 wpm is copy-typing; composition is '
            . '15-25. Set below copy-typing and honest learners who draft on paper get flagged.');

        $fire = new \ReflectionMethod(authenticity::class, 'provenance_findings');
        $fire->setAccessible(true);
        $labels = static fn(array $fields, int $words) => array_column(
            $fire->invoke(null, $fields, $words), 'label'
        );
        $needle = 'recorded editing time is short for the length';

        // 285 words in 3 minutes = 95 wpm. Nobody composes at that rate.
        $this->assertContains($needle, $labels(['editing_minutes' => '3'], 285));
        // 285 words in 20 minutes = 14 wpm, ordinary considered writing.
        $this->assertNotContains($needle, $labels(['editing_minutes' => '20'], 285));
        // 1200 words in 94 minutes = 13 wpm.
        $this->assertNotContains($needle, $labels(['editing_minutes' => '94'], 1200));
        // 1200 words in 2 minutes = 600 wpm.
        $this->assertContains($needle, $labels(['editing_minutes' => '2'], 1200));
        // Exactly at the ceiling: 600 words in 10 minutes = 60 wpm. Must PASS - a learner
        // typing up a draft sits here, and the check is for text that arrived, not text typed.
        $this->assertNotContains($needle, $labels(['editing_minutes' => '10'], 600));
    }

    /**
     * A short document does not get provenance findings about its length.
     *
     * A 40-word answer legitimately takes under a minute, so the proportionality checks must
     * not fire on one.
     *
     * @return void
     */
    public function test_short_documents_get_no_proportionality_findings(): void {
        $this->resetAfterTest();
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required to read .docx packages.');
        }
        $path = make_request_directory() . '/short.docx';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('docProps/app.xml',
            '<?xml version="1.0"?><Properties><TotalTime>0</TotalTime></Properties>');
        $zip->addFromString('docProps/core.xml',
            '<?xml version="1.0"?><cp:coreProperties '
            . 'xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties">'
            . '<cp:revision>1</cp:revision></cp:coreProperties>');
        $zip->close();

        $result = authenticity::provenance($path, 'short.docx', 40);

        $this->assertTrue($result['available'], 'The fields are still read and shown.');
        $this->assertSame([], $result['findings'],
            'A 40-word answer written in under a minute is not a finding.');
    }

    /**
     * Findings are ordered so the decisive ones are read first.
     *
     * @return void
     */
    public function test_findings_are_sorted_strongest_first(): void {
        $sorted = authenticity::sort_findings([
            ['severity' => authenticity::SEVERITY_CONTEXT, 'offset' => 10],
            ['severity' => authenticity::SEVERITY_NOTABLE, 'offset' => 500],
            ['severity' => authenticity::SEVERITY_STRONG,  'offset' => 900],
            ['severity' => authenticity::SEVERITY_NOTABLE, 'offset' => 20],
        ]);

        $this->assertSame(
            [authenticity::SEVERITY_STRONG, authenticity::SEVERITY_NOTABLE,
             authenticity::SEVERITY_NOTABLE, authenticity::SEVERITY_CONTEXT],
            array_column($sorted, 'severity')
        );
        // Within a severity, document order.
        $this->assertSame(20, $sorted[1]['offset']);
        $this->assertSame(500, $sorted[2]['offset']);
    }

    /**
     * One pathological file cannot flood a report.
     *
     * @return void
     */
    public function test_findings_are_capped(): void {
        $text = str_repeat('As an AI language model I cannot do that. ', 60);

        $findings = authenticity::assistant_artefacts($text);
        $this->assertLessThanOrEqual(authenticity::MAX_PER_CHECK, count($findings));
    }

    /**
     * Evidence must quote the right passage in a document containing multi-byte characters.
     *
     * preg_match_all() with PREG_OFFSET_CAPTURE returns BYTE offsets, with or without /u.
     * evidence() cut the window with core_text::substr(), which is character-based, so the
     * excerpt drifted by one position per multi-byte character earlier in the document.
     * Measured before the fix: 35 bytes of drift still landed inside the window and looked
     * correct; at 140 bytes the excerpt came out EMPTY, and beyond that it quoted an unrelated
     * part of the submission.
     *
     * 140 bytes is about 45 accented letters, or a couple of dozen uses of °C - a learner
     * called Zoë describing a café fridge reaches it inside one paragraph. The panel's entire
     * claim is that its evidence is quoted so it can be checked, so quoting the wrong passage
     * is worse than showing no panel at all.
     *
     * @return void
     */
    public function test_evidence_is_correct_in_multibyte_documents(): void {
        foreach ([0, 5, 20, 60, 200, 800] as $repeats) {
            $prefix = str_repeat('Zoë Müller checked the café fridge at 4°C — nothing over 60°C. ', $repeats);
            $text = $prefix . 'Let me know if you would like me to adjust the tone.';
            $drift = strlen($prefix) - \core_text::strlen($prefix);

            $findings = authenticity::assistant_artefacts($text);
            $this->assertNotEmpty($findings, "no finding at drift $drift");

            $evidence = $findings[0]['evidence'];
            $this->assertStringContainsString('Let me know', $evidence,
                "Evidence quoted the wrong passage at $drift bytes of multi-byte drift.");
            $this->assertTrue(mb_check_encoding($evidence, 'UTF-8'),
                "Evidence is not valid UTF-8 at $drift bytes of drift. Invalid UTF-8 is "
                . 'rejected by MySQL under utf8mb4 and breaks the JSON encode.');
        }

        /*
         * The window's START boundary must be swept for orphaned continuation bytes.
         *
         * The window begins a fixed number of BYTES before the match, so whether that lands on
         * a character boundary depends on the byte lengths of everything before it. The prefix
         * lengths below are chosen to put the cut at every offset within a multi-byte sequence:
         * a 3-byte em dash and a 2-byte accent, padded by single bytes, so the start lands
         * inside a character in some combinations and between characters in others.
         *
         * Without the sweep, a cut inside a sequence leaves leading continuation bytes that no
         * amount of trimming from the END can repair, so the whole excerpt is discarded and the
         * trainer sees a finding with no evidence. Mutation testing found this: removing the
         * sweep failed no test, because the cases above happened never to misalign.
         */
        $misaligned = 0;
        for ($pad = 0; $pad <= 2; $pad++) {
            /*
             * Constructed so the cut provably lands inside a character, not by chance.
             *
             * The prefix is 40 em dashes (3 bytes each, so 120 bytes) plus $pad single-byte
             * spaces, which puts the match at byte 120 + $pad and the window start at 60 + $pad.
             * Byte 60 is the start of the 21st dash, but bytes 61 and 62 are its continuation
             * bytes - so $pad of 1 and 2 cut inside a character and $pad of 0 does not. All
             * three residues are covered by construction.
             *
             * SPACES, not letters. Padding with 'x' put a word character immediately before
             * "As an AI language model", which removed the \b word boundary the pattern needs,
             * so nothing matched and the test failed for a reason that had nothing to do with
             * byte alignment.
             *
             * The version before that used a mixed-width filler and never misaligned at all, so
             * it could not catch the mutation that removed the leading sweep. Mutation testing
             * found both faults; the arithmetic is spelled out so the next person to touch this
             * fixture can see what it is for.
             */
            $prefix = str_repeat('—', 40) . str_repeat(' ', $pad);
            $text = $prefix . 'As an AI language model I cannot do that.';

            $offset = strpos($text, 'As an AI');
            $start = max(0, $offset - (int)floor(authenticity::EVIDENCE_CHARS / 3));
            $startbyte = ord($text[$start]);
            if ($startbyte >= 0x80 && $startbyte <= 0xBF) {
                $misaligned++;
            }

            $findings = authenticity::assistant_artefacts($text);
            $evidence = $findings[0]['evidence'];
            $label = "pad=$pad";

            $this->assertTrue(mb_check_encoding($evidence, 'UTF-8'),
                "Evidence is not valid UTF-8 ($label)");
            $this->assertNotSame('', $evidence,
                "Evidence was discarded entirely ($label): a cut inside a multi-byte sequence "
                . 'must be repaired, not thrown away, or the trainer sees a finding with no '
                . 'evidence at all.');
            $this->assertStringContainsString('As an AI language model', $evidence,
                "Evidence lost the matched phrase ($label)");
            $this->assertNotFalse(json_encode($evidence),
                "Evidence breaks json_encode() ($label)");

            /*
             * A document that is wholly valid UTF-8 must produce evidence with no replacement
             * characters in it. This is what distinguishes sweeping the orphaned bytes at the
             * window start from leaving them to the catch-all repair: both yield valid UTF-8,
             * but the catch-all substitutes "?" for each severed byte, so a trainer reading an
             * ordinary submission sees "…??——— As an AI language model". Mutation testing
             * showed that without this assertion, removing the sweep broke nothing a test
             * could see.
             */
            $this->assertSame(0, preg_match_all('/\x{FFFD}|\?/u', $evidence),
                "Evidence contains replacement characters ($label) although the document is "
                . 'entirely valid UTF-8. The window start must be swept to a character '
                . 'boundary, not patched up afterwards.');
        }

        $this->assertGreaterThan(0, $misaligned,
            'The fixture must actually cut inside a multi-byte character, or this test proves '
            . 'nothing about the repair. If EVIDENCE_CHARS changes, redo the byte arithmetic.');
    }

    /**
     * Evidence survives a damaged extraction rather than disappearing.
     *
     * Invalid bytes in the extracted text mean the file or the extractor is damaged, not that
     * the window was cut badly - a mislabelled encoding, a broken PDF. Discarding the excerpt
     * leaves the trainer looking at a finding with no context, which is the same failure the
     * byte-offset bug produced, so the readable part is kept.
     *
     * @return void
     */
    public function test_evidence_survives_a_damaged_extraction(): void {
        $cases = [
            'invalid two-byte lead' => "lead-in \xC3\x28 broken \xA0 bytes then "
                . 'As an AI language model I cannot help you here at all',
            'long run of bad bytes'  => str_repeat("\xFF\xFE", 80) . ' As an AI language model.',
            'lone surrogate'         => "Certainly! Here is a 150-200 word response \xED\xA0\x80 "
                . 'with a lone surrogate in it.',
        ];

        foreach ($cases as $label => $text) {
            $findings = authenticity::text_findings($text);
            $this->assertNotEmpty($findings, "no finding for $label");

            $evidence = $findings[0]['evidence'];
            $this->assertTrue(mb_check_encoding((string)$evidence, 'UTF-8'),
                "Evidence is not valid UTF-8 ($label). Invalid UTF-8 is rejected by MySQL under "
                . 'utf8mb4 and would fail the row write.');
            $this->assertNotFalse(json_encode($findings),
                "Findings break json_encode() ($label)");
            $this->assertNotSame('', (string)$evidence,
                "Evidence was discarded entirely ($label): the readable part of a damaged "
                . 'extraction must still be shown.');
        }
    }

    /**
     * A crafted document cannot bloat the stored analysis.
     *
     * Every provenance value comes out of the uploaded file, so its length is the student's to
     * choose. Uncapped, a .docx carrying a 500 KB dc:creator was stored verbatim - measured -
     * and analysisjson is a TEXT column, 65,535 bytes on MySQL. One upload would fail the row
     * write and lose that submission's analysis entirely.
     *
     * @return void
     */
    public function test_a_crafted_metadata_field_cannot_bloat_the_stored_analysis(): void {
        $this->resetAfterTest();
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required to read .docx packages.');
        }
        $dir = make_request_directory();

        $build = function (string $creator) use ($dir): array {
            $path = $dir . '/crafted.docx';
            @unlink($path);
            $zip = new \ZipArchive();
            $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            $zip->addFromString('docProps/core.xml',
                '<?xml version="1.0"?><cp:coreProperties '
                . 'xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
                . 'xmlns:dc="http://purl.org/dc/elements/1.1/" '
                . 'xmlns:dcterms="http://purl.org/dc/terms/">'
                . '<dc:creator>' . $creator . '</dc:creator><cp:revision>1</cp:revision>'
                . '<dcterms:created>2026-10-14T09:00:00Z</dcterms:created>'
                . '<dcterms:modified>2026-10-14T09:00:10Z</dcterms:modified>'
                . '</cp:coreProperties>');
            $zip->addFromString('word/document.xml', '<w:document/>');
            $zip->close();

            return authenticity::provenance($path, 'crafted.docx', 600);
        };

        foreach ([1000, 100000] as $length) {
            $result = $build(str_repeat('A', $length));
            $this->assertLessThanOrEqual(
                authenticity::PROVENANCE_FIELD_MAX + 1,
                \core_text::strlen($result['fields']['creator']),
                "A $length-character field was stored at full length."
            );
        }

        // Truncation must not sever a multi-byte character.
        $result = $build(str_repeat('é', 5000));
        $this->assertTrue(mb_check_encoding($result['fields']['creator'], 'UTF-8'),
            'Truncation cut a multi-byte sequence in half.');

        // Control characters have no legitimate purpose and break the JSON encode.
        $result = $build('Bad' . chr(0) . chr(7) . 'Name');
        $this->assertSame('BadName', $result['fields']['creator']);
        $this->assertNotFalse(json_encode($result['fields']));

        // And the findings, computed before truncation, must be unaffected.
        $this->assertContains('whole document lifespan under two minutes',
            array_column($result['findings'], 'label'),
            'Dates must be parsed before any value is truncated.');
    }

    /**
     * A hostile document cannot inject markup into the report.
     *
     * The metadata fields are attacker-controlled and are rendered in the trainer's report.
     * The parser decodes XML entities, so an encoded script tag in dc:creator becomes a literal
     * one in the stored value; it is the report's escaping that has to hold.
     *
     * @return void
     */
    public function test_hostile_metadata_is_stored_as_inert_text(): void {
        $this->resetAfterTest();
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required to read .docx packages.');
        }
        $path = make_request_directory() . '/hostile.docx';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('docProps/core.xml',
            '<?xml version="1.0"?><cp:coreProperties '
            . 'xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/">'
            . '<dc:creator>&lt;script&gt;alert(1)&lt;/script&gt;</dc:creator>'
            . '<cp:lastModifiedBy>"&gt;&lt;img src=x onerror=alert(1)&gt;</cp:lastModifiedBy>'
            . '<cp:revision>1</cp:revision></cp:coreProperties>');
        $zip->addFromString('word/document.xml', '<w:document/>');
        $zip->close();

        $result = authenticity::provenance($path, 'hostile.docx', 600);

        /*
         * The payload IS present in the stored value, and that is correct: the parser decodes
         * XML entities, so an encoded script tag in dc:creator becomes a literal one. Storing
         * it verbatim is right - the report must be able to show the trainer what the file
         * actually claims, including that it has been tampered with. Dropping the field would
         * hide the tampering.
         *
         * So what is asserted here is the real contract: the value is inert DATA (valid UTF-8,
         * no control characters, survives JSON), and the renderer escapes it. Escaping is
         * asserted at source rather than by calling s(), which belongs to Moodle and is not
         * this class's responsibility - and a stubbed s() in a harness would prove nothing
         * about what the real page emits.
         */
        $this->assertArrayHasKey('creator', $result['fields'],
            'A tampered field must be shown, not silently dropped.');
        foreach ($result['fields'] as $name => $value) {
            $this->assertTrue(mb_check_encoding((string)$value, 'UTF-8'), "Field $name is not UTF-8");
            $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', (string)$value,
                "Field $name kept a control character");
            $this->assertNotFalse(json_encode($value), "Field $name breaks json_encode()");
        }

        // Every provenance value rendered by the report must pass through s().
        $report = file_get_contents(__DIR__ . '/../student_report.php');
        $from = strpos($report, 'authprovheading');
        $this->assertNotFalse($from, 'The provenance block must exist in the report.');
        $to = strpos($report, 'authquestionsheading', $from);
        $block = substr($report, $from, ($to === false ? strlen($report) : $to) - $from);

        $this->assertMatchesRegularExpression('/s\(\s*\(string\)\s*\$dgv\s*\)/', $block,
            'The provenance VALUE comes out of a student-supplied file and must be escaped.');
        $this->assertMatchesRegularExpression('/s\(\s*str_replace\(/', $block,
            'The provenance field NAME must be escaped too.');
        $this->assertDoesNotMatchRegularExpression('/\.\s*\$dgv\s*\./', $block,
            'No provenance value may be concatenated into the page unescaped.');
    }

    /**
     * The check set carries a version, so stored findings keep their meaning.
     *
     * @return void
     */
    public function test_checks_are_versioned(): void {
        $this->assertIsInt(authenticity::CHECKS_VERSION);
        $this->assertGreaterThanOrEqual(1, authenticity::CHECKS_VERSION);
    }
}
