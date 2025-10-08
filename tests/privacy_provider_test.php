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
 * Unit tests for the privacy provider of tool_ltigroupautoenrol.
 *
 * This file contains tests to ensure that the privacy provider for the
 * LTI Group Auto Enrol plugin correctly implements the required privacy
 * interfaces and does not expose user data inappropriately.
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2025 Ralf Erlebach
 * @author     Ralf Erlebach <ralf.erlebach@gmx.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ltigroupautoenrol;
use core_privacy\tests\provider_testcase;

/**
 * Unit tests for the privacy provider of tool_ltigroupautoenrol.
 *
 * This test class verifies that the privacy provider for the LTI Group Auto Enrol
 * tool either implements the null_provider interface (indicating no personal data is stored)
 * or does not implement the userlist_provider interface (indicating it does not expose user lists).
 * This is important for GDPR compliance and data protection.
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2025 Ralf Erlebach
 * @author     Ralf Erlebach <ralf.erlebach@gmx.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class privacy_provider_test extends provider_testcase {

    /**
     * Tests that the privacy provider is either a null provider or does not implement userlist provider.
     *
     * Ensures that the privacy provider for tool_ltigroupautoenrol either:
     * 1. Implements the null_provider interface (indicating no personal data is stored), OR
     * 2. Does not implement the userlist_provider interface (indicating it doesn't expose user lists).
     *
     * @return void
     * @covers \tool_ltigroupautoenrol\privacy\provider
     */
    public function test_provider_is_null_provider_or_has_no_userlists(): void {
        $this->resetAfterTest(true);

        $providerclass = '\\tool_ltigroupautoenrol\\privacy\\provider';
        $this->assertTrue(class_exists($providerclass), 'Privacy provider class must exist');

        // Check if it's a null provider (stores no personal data).
        $interfaces = class_implements($providerclass);
        $isnullprovider = isset($interfaces[\core_privacy\local\metadata\null_provider::class]);

        // Check if it implements userlist provider (can expose user lists).
        $hasuserlistprovider = isset($interfaces[\core_privacy\local\request\userlist_provider::class]);

        // Assert that the provider does not expose user list data.
        $this->assertFalse($hasuserlistprovider,
            'Privacy provider should not implement userlist_provider interface');

        // Assert that it's either a null provider OR doesn't have userlist capabilities.
        $this->assertTrue($isnullprovider || !$hasuserlistprovider,
            'Privacy provider must be either a null_provider or not implement userlist_provider');
    }
}
