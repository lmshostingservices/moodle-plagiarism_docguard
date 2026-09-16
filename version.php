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
 * plagiarism_docguard file.
 *
 * @package    plagiarism_docguard
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// FIX-DG-VERSION-FREEZE (v1.0.78): Releases 1.0.74 to 1.0.77 all shipped the same
// version integer, and db/upgrade.php still carries three separate savepoint blocks
// guarded on it. Moodle only runs a plugin's install/upgrade — and therefore only
// re-syncs db/access.php capabilities via update_capabilities() — when the integer
// below INCREASES. On a site already holding that value, replacing the plugin files
// had no effect whatsoever on capabilities or schema, producing "upgrades" that
// silently changed nothing.
//
// Any future release that touches db/access.php or db/install.xml MUST raise this
// number, and it must always be greater than or equal to the highest savepoint in
// db/upgrade.php (currently 2026091600).
// v1.0.85: this said 2026082803, which was never a savepoint in db/upgrade.php - the
// highest is and was 2026082800. A comment that misstates the number it exists to
// track is worse than no comment, because the next person bumping this file trusts it.
//
// v1.0.80: raised from 2026081502 to 2026082803, matching the new savepoint in
// db/upgrade.php exactly. 2026081502 was itself an example of the mistake described
// above — it was higher than every savepoint in db/upgrade.php (2026081501), so a site
// taking that release ran an upgrade with no steps in it. This release's upgrade step
// has real work to do (it writes the site-wide 'enabled' value that now gates all
// processing), so version and savepoint are deliberately identical.
//
// Raising it is also necessary but NOT sufficient on its own: a bump with no
// matching savepoint above the site's recorded version produces an upgrade that
// runs no steps. Add a savepoint block in db/upgrade.php for every bump.
$plugin->component = 'plagiarism_docguard';
// V1.0.88: raised to 2026082906, which is also the savepoint added to db/upgrade.php for
// this release. That step has real work to do — it purges the DocGuard records left behind
// by activities and courses deleted before FIX-DG-ORPHAN-ON-DELETE existed, and clears the
// legacy submission-wide "unsupported" markers that FIX-DG-BACKFILL-FILE-GRANULARITY
// replaces with per-file ones — so version and savepoint are deliberately identical, as the
// note above requires.
//
// v1.0.87 shipped 2026082905 with no matching savepoint (the highest was 2026082800), which
// is the mistake this comment block warns about: a site taking that release ran an upgrade
// with no steps in it.
$plugin->version   = 2026091600;
$plugin->release   = '1.0.92';

// V1.0.85 / v1.2.225 FIX-REQUIRES-UNDERSTATED: this declared 2022041900 (Moodle 4.0) and
// supported = [400, 501]. Both numbers were wrong, in opposite directions.
//
// TOO LOW. Moodle 4.0's minimum PHP is 7.3, and this plugin uses arrow functions
// (`fn($x) => ...`), which are PHP 7.4. On a Moodle 4.0 or 4.1 site running PHP 7.3 the
// parse error is fatal in lib.php, which the plagiarism dispatcher loads on EVERY page -
// so the whole site goes white, not just this plugin. Declaring 4.0 invited exactly that.
//
// The real floor is Moodle 4.4: the three output hook classes this plugin registers in
// db/hooks.php - before_standard_head_html_generation,
// before_standard_top_of_body_html_generation and before_footer_html_generation - do not
// exist before 4.4. Below it the registrations are inert, silently: in Essay Guard that
// costs the floating report button AND the risk badges on the quiz grading overview, with
// no error and no log line.
//
// Set to 4.5 LTS rather than 4.4, deliberately. 4.5 is the lowest branch still receiving
// any support (security fixes to October 2027), so it is the only sub-5.0 branch worth
// testing; and 4.5 is where core deleted plagiarism_update_status() and
// plagiarism_plugin::update_status(), which is what the legacy no-op callbacks and the
// standalone plugin class in this plugin exist to satisfy. Requiring 4.5 makes that
// compatibility scaffolding dead code rather than something to keep reasoning about.
//
// TOO HIGH at the other end. supported = [400, 501] excludes branch 502, so on a Moodle
// 5.2 site - which is what the author runs in production - both plugins were listed as
// unsupported in Site administration > Plugins, and the plugin directory would not
// advertise 5.2 compatibility.

// V1.2.233 / v1.0.91 FLOOR LOWERED TO 4.4, reversing part of the decision above.
//
// The 4.5 floor was a POLICY choice, not a technical one. The comment above says so
// explicitly: "The real floor is Moodle 4.4". 4.5 was picked for two reasons - it is the
// lowest branch still receiving security fixes, and it is where core deleted
// plagiarism_update_status(), which would have made this plugin's legacy scaffolding dead
// code. Neither reason is a code requirement, and the scaffolding was never actually
// deleted, so it still works on 4.4.
//
// What that policy choice cost: it silently made the plugin un-upgradeable on Moodle 4.4,
// which is what the author's own site runs. Moodle's dependency check reports the
// `supported` range as a hard requirement and refuses the install - "Moodle 405 - 502
// Fails" - with no indication that the floor is a preference rather than a constraint.
//
// Verified before lowering: all three output hooks registered in db/hooks.php
// (before_standard_head_html_generation, before_standard_top_of_body_html_generation,
// before_footer_html_generation) exist in 4.4; the legacy update_status() scaffolding and
// the standalone plugin class are both still present; and 4.4's minimum PHP is 8.1, so the
// arrow functions that forced the floor up off Moodle 4.0 are not a problem here.
//
// NOTE FOR THE SITE OWNER: Moodle 4.4 is out of general support. Lowering this floor
// unblocks the upgrade; it does not make 4.4 a good place to stay.
$plugin->requires  = 2024042200;   // Moodle 4.4.
$plugin->maturity  = MATURITY_STABLE;
$plugin->supported = [404, 502];
