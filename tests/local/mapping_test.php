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
 * Tests for the mapping value object and its validation.
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltigroupautoenrol\local\mapping
 */
final class mapping_test extends \basic_testcase {
    /**
     * Legacy JSON (string ids, as written by version 1.1) is normalised to integers.
     */
    public function test_legacy_string_ids_are_normalised(): void {
        $mapping = mapping::from_json('{"12":["4","3","4"],"5":[]}');
        $this->assertSame([12 => [3, 4]], $mapping->to_array());
        $this->assertSame('{"12":[3,4]}', $mapping->to_json());
    }

    /**
     * Empty input means "no mapping"; the canonical form of an empty mapping is an object.
     */
    public function test_empty(): void {
        foreach ([null, '', '   ', '{}', '[]'] as $json) {
            $mapping = mapping::from_json($json);
            $this->assertTrue($mapping->is_empty());
            $this->assertSame('{}', $mapping->to_json());
        }
    }

    /**
     * Data provider for invalid persisted mappings.
     *
     * @return array
     */
    public static function invalid_json_provider(): array {
        return [
            'malformed' => ['{"12":[3'],
            'scalar root' => ['42'],
            'zero tool id' => ['{"0":[3]}'],
            'negative group id' => ['{"12":[-3]}'],
            'non numeric group id' => ['{"12":["abc"]}'],
            'float group id' => ['{"12":[3.5]}'],
            'groups not a list' => ['{"12":3}'],
            'nested too deep' => ['{"12":[[[[3]]]]}'],
        ];
    }

    /**
     * Invalid structures raise a defined exception instead of being used.
     *
     * @dataProvider invalid_json_provider
     * @param string $json
     */
    public function test_invalid_json_is_rejected(string $json): void {
        $this->expectException(invalid_mapping_exception::class);
        mapping::from_json($json);
    }

    /**
     * Manipulated input above the size limits is rejected.
     */
    public function test_size_limits(): void {
        $this->expectException(invalid_mapping_exception::class);
        new mapping([1 => range(1, mapping::MAX_GROUPS_PER_TOOL + 1)]);
    }

    /**
     * Unknown ids are reported and can be removed against the course allowlists.
     */
    public function test_find_unknown_and_restrict(): void {
        $mapping = new mapping([1 => [10, 11], 2 => [12], 3 => [99]]);
        $this->assertSame(['tools' => [3], 'groups' => [99]], $mapping->find_unknown_ids([1, 2], [10, 11, 12]));
        $this->assertSame([1 => [10], 2 => [12]], $mapping->restrict_to([1, 2], [10, 12])->to_array());
        $this->assertSame([], $mapping->restrict_to([], [])->to_array());
    }

    /**
     * The fingerprint depends on the content only, not on input order or id types.
     */
    public function test_hash_is_canonical(): void {
        $a = new mapping([2 => [5, 4], 1 => [3]]);
        $b = mapping::from_json('{"1":["3"],"2":["4","5"]}');
        $this->assertSame($a->get_hash(), $b->get_hash());
        $this->assertNotSame($a->get_hash(), (new mapping([1 => [3]]))->get_hash());
    }
}
