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

/**
 * Builds small PDFs that each isolate one thing the extractor has to get right.
 *
 * Why a builder and not a directory of .pdf files:
 *
 *  - A fixture file produced by LibreOffice is 30 KB of which perhaps 200 bytes are the
 *    point. Whoever next changes the extractor cannot see from the file WHICH property it
 *    was shipped to pin down, so they cannot tell whether their change should have broken
 *    it. These fixtures say what they are testing in their method name.
 *  - Isolation. A real PDF exercises twenty behaviours at once, so when it fails the test
 *    says "extraction changed" and not which defect came back.
 *
 * These are genuine PDFs as far as this plugin is concerned, and deliberately NOT as far
 * as a viewer is concerned: there is no cross-reference table, because extract_pdf_php()
 * indexes objects by scanning the raw bytes and never reads the xref. Leaving it out keeps
 * the fixtures short and honest about what the parser actually depends on. A fixture that
 * needs to be opened in a viewer would need one adding.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Assembles minimal single-purpose PDF files for the extractor tests.
 */
class docguard_pdf_fixture {
    /** @var array<int, string> Object bodies, keyed by object number. */
    private $objects = [];

    /** @var int Next free object number. */
    private $next = 1;

    /**
     * Add an object and return its number.
     *
     * @param string $body The object body, without the "N 0 obj" and "endobj".
     * @return int The object number it was given.
     */
    public function add(string $body): int {
        $num                 = $this->next++;
        $this->objects[$num] = $body;
        return $num;
    }

    /**
     * Add an object at a chosen number, so references can be written before the target.
     *
     * @param int $num The object number to use.
     * @param string $body The object body.
     * @return int The object number, for symmetry with add().
     */
    public function put(int $num, string $body): int {
        $this->objects[$num] = $body;
        if ($num >= $this->next) {
            $this->next = $num + 1;
        }
        return $num;
    }

    /**
     * Reserve an object number without defining it yet.
     *
     * @return int The reserved number.
     */
    public function reserve(): int {
        return $this->next++;
    }

    /**
     * Skip ahead so the next object number is at least the given value.
     *
     * Used where the NUMBER OF DIGITS in an object number is the point. The /Length
     * backtracking defect only bites when the referenced object number has two or more
     * digits, because a single digit cannot be backtracked into. A fixture that happens to
     * reference object 6 passes against the defect; one that references object 21 fails.
     *
     * @param int $minimum The lowest number the next object may take.
     * @return void
     */
    public function pad_to(int $minimum): void {
        if ($this->next < $minimum) {
            $this->next = $minimum;
        }
    }

    /**
     * Add a stream object.
     *
     * @param string $dict The dictionary contents, without the outer << >>.
     * @param string $data The stream bytes, already encoded for whatever /Filter says.
     * @param bool $lengthbefore True to write /Length before the caller's dict entries.
     * @param string|null $lengthoverride Literal text to use for the /Length value, for
     *                                    the tests that need an indirect reference there.
     * @param bool $eolbeforeendstream False to omit the newline before "endstream", which
     *                                 is legal and is what the word-boundary test needs.
     * @return int The object number.
     */
    public function add_stream(
        string $dict,
        string $data,
        bool $lengthbefore = true,
        ?string $lengthoverride = null,
        bool $eolbeforeendstream = true
    ): int {
        $length = $lengthoverride ?? (string)strlen($data);
        $entry  = '/Length ' . $length;
        $body   = '<<' . ($lengthbefore ? $entry . ' ' . $dict : $dict . ' ' . $entry) . '>>'
                . "\nstream\n" . $data . ($eolbeforeendstream ? "\n" : '') . 'endstream';
        return $this->add($body);
    }

    /**
     * Serialise the file.
     *
     * @return string The PDF bytes.
     */
    public function build(): string {
        $out = "%PDF-1.7\n";
        ksort($this->objects);
        foreach ($this->objects as $num => $body) {
            $out .= $num . " 0 obj\n" . $body . "\nendobj\n";
        }
        $out .= "trailer\n<</Size " . ($this->next) . ">>\n%%EOF\n";
        return $out;
    }

    /**
     * Write the file to a temporary path and return it.
     *
     * @return string Absolute path to the written file. The caller unlinks it.
     */
    public function write(): string {
        $path = tempnam(sys_get_temp_dir(), 'dgfix_') . '.pdf';
        file_put_contents($path, $this->build());
        return $path;
    }

    /* ── Pieces the fixtures are built from ──────────────────────────────────── */

    /**
     * A ToUnicode CMap mapping single-byte codes to characters, as a subset font does.
     *
     * @param array<int, string> $map Code => the character it means.
     * @param int $codebytes 1 or 2, written into the codespacerange.
     * @return string The CMap program.
     */
    public static function tounicode(array $map, int $codebytes = 1): string {
        $width = $codebytes === 2 ? 4 : 2;
        $lo    = str_repeat('00', $codebytes);
        $hi    = str_repeat('FF', $codebytes);

        // A real two-byte CMap carries an entry for code 0, and it is what makes the
        // code-width defect visible. With <0000> mapped, reading a two-byte code as two
        // one-byte codes turns the high byte into a NUL that lands in the output - which
        // is how "Assessment Submission" became "A\0s\0s\0e\0s\0s\0m\0e\0n\0t\0".
        //
        // Without that entry the high byte is merely unmapped, and apply_cmap() DROPS
        // unmapped bytes, so the text decodes correctly even at the wrong width and a
        // fixture built this way passes against the defect. Verified: it did.
        if ($codebytes === 2 && !isset($map[0])) {
            $map = [0 => "\0"] + $map;
        }

        $entries = '';
        foreach ($map as $code => $char) {
            // The destination is UTF-16BE, and may be more than one code unit - which is
            // how a ligature glyph is written, and what FIX-DG-PDF-LIGATURE is about.
            $dest = '';
            foreach (preg_split('//u', $char, -1, PREG_SPLIT_NO_EMPTY) as $c) {
                $cp = self::codepoint($c);
                if ($cp > 0xFFFF) {
                    $cp -= 0x10000;
                    $dest .= sprintf('%04X%04X', 0xD800 + ($cp >> 10), 0xDC00 + ($cp & 0x3FF));
                } else {
                    $dest .= sprintf('%04X', $cp);
                }
            }
            $entries .= '<' . sprintf('%0' . $width . 'X', $code) . '> <' . $dest . ">\n";
        }

        return "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
            . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
            . "1 begincodespacerange\n<" . $lo . "> <" . $hi . ">\nendcodespacerange\n"
            . count($map) . " beginbfchar\n" . $entries . "endbfchar\nendcmap\n"
            . "CMapName currentdict /CMap defineresource pop\nend\nend\n";
    }

    /**
     * The Unicode code point of a single character.
     *
     * @param string $char One UTF-8 character.
     * @return int Its code point.
     */
    private static function codepoint(string $char): int {
        $ords = unpack('N', mb_convert_encoding($char, 'UCS-4BE', 'UTF-8'));
        return (int)$ords[1];
    }

    /**
     * Encode text as hex codes against a code => character map, for a Tj operator.
     *
     * @param string $text The text to encode.
     * @param array<int, string> $map The font's code => character map.
     * @param int $codebytes 1 or 2 bytes per code.
     * @return string Hex digits.
     */
    public static function encode(string $text, array $map, int $codebytes = 1): string {
        $reverse = [];
        foreach ($map as $code => $char) {
            if (!isset($reverse[$char])) {
                $reverse[$char] = $code;
            }
        }
        $width = $codebytes === 2 ? 4 : 2;
        $hex   = '';
        $i     = 0;
        $len   = mb_strlen($text, 'UTF-8');
        while ($i < $len) {
            // Prefer the longest match, so a two-character ligature is used when the font
            // has one, exactly as a real producer would.
            $matched = false;
            foreach ([2, 1] as $take) {
                $piece = mb_substr($text, $i, $take, 'UTF-8');
                if (isset($reverse[$piece])) {
                    $hex    .= sprintf('%0' . $width . 'X', $reverse[$piece]);
                    $i      += $take;
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $i++;
            }
        }
        return $hex;
    }

    /**
     * ASCII85-encode bytes as a PDF /ASCII85Decode stream.
     *
     * @param string $data The bytes to encode.
     * @return string The armoured text, terminated with "~>".
     */
    public static function ascii85(string $data): string {
        $out    = '';
        $chunks = str_split($data, 4);
        foreach ($chunks as $chunk) {
            $pad = 4 - strlen($chunk);
            $chunk .= str_repeat("\0", $pad);
            $num = unpack('N', $chunk)[1];
            if ($num === 0 && $pad === 0) {
                $out .= 'z';
                continue;
            }
            $group = '';
            for ($i = 4; $i >= 0; $i--) {
                $group = chr(33 + (int)($num % 85)) . $group;
                $num   = (int)($num / 85);
            }
            $out .= $pad > 0 ? substr($group, 0, 5 - $pad) : $group;
        }
        return $out . '~>';
    }
}
