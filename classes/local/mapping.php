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
 * Validated, immutable LTI tool to group mapping of one course.
 *
 * The mapping is persisted as JSON in the plugin table: an object whose keys are
 * enrol_lti_tools ids and whose values are lists of course group ids, for example
 * {"12":[3,4],"15":[5]}. Legacy data written by version 1.1 stored ids as strings;
 * numeric strings are accepted and normalised to integers.
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mapping {
    /** @var int Upper bound for mapped tools per course (sanity limit against manipulated input). */
    public const MAX_TOOLS = 1000;

    /** @var int Upper bound for groups per tool (sanity limit against manipulated input). */
    public const MAX_GROUPS_PER_TOOL = 1000;

    /** @var array<int, int[]> Tool id => sorted, unique list of group ids. Tools without groups are omitted. */
    private array $toolgroups;

    /**
     * Constructor.
     *
     * @param array $toolgroups Tool id => list of group ids. Keys and values may be ints or numeric strings.
     * @throws invalid_mapping_exception If the structure or any id is invalid.
     */
    public function __construct(array $toolgroups) {
        if (count($toolgroups) > self::MAX_TOOLS) {
            throw new invalid_mapping_exception('too many tools');
        }
        $normalised = [];
        foreach ($toolgroups as $toolid => $groupids) {
            $toolid = self::to_id($toolid);
            if (!is_array($groupids)) {
                throw new invalid_mapping_exception("groups of tool {$toolid} are not a list");
            }
            if (count($groupids) > self::MAX_GROUPS_PER_TOOL) {
                throw new invalid_mapping_exception("too many groups for tool {$toolid}");
            }
            $ids = [];
            foreach ($groupids as $groupid) {
                $ids[self::to_id($groupid)] = true;
            }
            if (!$ids) {
                continue;
            }
            $ids = array_keys($ids);
            sort($ids);
            $normalised[$toolid] = $ids;
        }
        ksort($normalised);
        $this->toolgroups = $normalised;
    }

    /**
     * Creates a mapping from its persisted JSON representation.
     *
     * @param string|null $json JSON as stored in tool_ltigroupautoenrol.settings; null or '' means "no mapping".
     * @return self
     * @throws invalid_mapping_exception If the JSON is malformed or contains invalid ids.
     */
    public static function from_json(?string $json): self {
        if ($json === null || trim($json) === '') {
            return new self([]);
        }
        try {
            $decoded = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new invalid_mapping_exception('malformed JSON: ' . $e->getMessage());
        }
        if (!is_array($decoded)) {
            throw new invalid_mapping_exception('JSON root is not an object');
        }
        return new self($decoded);
    }

    /**
     * Converts a scalar to a positive integer id.
     *
     * @param mixed $value
     * @return int
     * @throws invalid_mapping_exception
     */
    private static function to_id($value): int {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/', $value)) {
            return (int) $value;
        }
        throw new invalid_mapping_exception('invalid id ' . json_encode($value));
    }

    /**
     * Returns the group ids mapped to an LTI tool.
     *
     * @param int $toolid enrol_lti_tools id.
     * @return int[]
     */
    public function get_groupids(int $toolid): array {
        return $this->toolgroups[$toolid] ?? [];
    }

    /**
     * Returns all mapped tool ids.
     *
     * @return int[]
     */
    public function get_toolids(): array {
        return array_keys($this->toolgroups);
    }

    /**
     * Whether no tool is mapped to any group.
     *
     * @return bool
     */
    public function is_empty(): bool {
        return empty($this->toolgroups);
    }

    /**
     * Returns the mapping as array.
     *
     * @return array<int, int[]>
     */
    public function to_array(): array {
        return $this->toolgroups;
    }

    /**
     * Returns the canonical JSON representation for persistence.
     *
     * @return string
     */
    public function to_json(): string {
        // Force an object even for an empty mapping, so the stored format is stable.
        return json_encode((object) $this->toolgroups);
    }

    /**
     * Returns a stable fingerprint of the mapping, used to detect changes between preview and execution.
     *
     * @return string
     */
    public function get_hash(): string {
        return sha1($this->to_json());
    }

    /**
     * Returns the ids of this mapping that are not contained in the given allowlists.
     *
     * @param int[] $validtoolids Tool ids that belong to the course.
     * @param int[] $validgroupids Group ids that belong to the course.
     * @return array{tools: int[], groups: int[]} Unknown tool ids and unknown group ids.
     */
    public function find_unknown_ids(array $validtoolids, array $validgroupids): array {
        $validtools = array_flip(array_map('intval', $validtoolids));
        $validgroups = array_flip(array_map('intval', $validgroupids));
        $tools = [];
        $groups = [];
        foreach ($this->toolgroups as $toolid => $groupids) {
            if (!isset($validtools[$toolid])) {
                $tools[] = $toolid;
            }
            foreach ($groupids as $groupid) {
                if (!isset($validgroups[$groupid])) {
                    $groups[$groupid] = $groupid;
                }
            }
        }
        return ['tools' => $tools, 'groups' => array_values($groups)];
    }

    /**
     * Returns a copy restricted to the given tools and groups (drops stale ids).
     *
     * @param int[] $validtoolids Tool ids that belong to the course.
     * @param int[] $validgroupids Group ids that belong to the course.
     * @return self
     */
    public function restrict_to(array $validtoolids, array $validgroupids): self {
        $validtools = array_flip(array_map('intval', $validtoolids));
        $validgroups = array_flip(array_map('intval', $validgroupids));
        $result = [];
        foreach ($this->toolgroups as $toolid => $groupids) {
            if (!isset($validtools[$toolid])) {
                continue;
            }
            $result[$toolid] = array_values(array_filter($groupids, fn(int $g): bool => isset($validgroups[$g])));
        }
        return new self($result);
    }
}
