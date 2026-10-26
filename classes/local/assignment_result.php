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

namespace tool_ltigroupautoenrol\local;

/**
 * Counters describing the outcome of a group assignment run (event or backfill).
 *
 * All counters count (user, group) pairs except {@see $eligible}, which counts users.
 * The object deliberately holds no user ids, so it can be logged without personal data.
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class assignment_result {
    /** @var int Users that are eligible for assignment. */
    public int $eligible = 0;

    /** @var int Memberships that already existed. */
    public int $alreadymember = 0;

    /** @var int Memberships that were added. */
    public int $added = 0;

    /** @var int Memberships skipped (feature disabled, no mapping, stale group, user not eligible). */
    public int $skipped = 0;

    /** @var int Memberships that could not be added. */
    public int $errors = 0;

    /**
     * Adds the counters of another result to this one.
     *
     * @param assignment_result $other
     * @return self
     */
    public function merge(assignment_result $other): self {
        $this->eligible += $other->eligible;
        $this->alreadymember += $other->alreadymember;
        $this->added += $other->added;
        $this->skipped += $other->skipped;
        $this->errors += $other->errors;
        return $this;
    }

    /**
     * Returns the counters as array (for language strings and logs).
     *
     * @return array<string, int>
     */
    public function to_array(): array {
        return [
            'eligible' => $this->eligible,
            'alreadymember' => $this->alreadymember,
            'added' => $this->added,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
        ];
    }
}
