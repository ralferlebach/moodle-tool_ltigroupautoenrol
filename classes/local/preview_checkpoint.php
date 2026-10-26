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
 * Server-side checkpoint of a backfill preview.
 *
 * The preview page stores the time the preview was computed and the mapping fingerprint in the
 * user's session and only sends a random one-time token to the browser. The confirmation looks the
 * values up by token, so the approved set ("enrolments existing at preview time") can not be widened
 * by changing request parameters. A token is bound to the session, the user and the course, expires
 * after {@see self::LIFETIME} seconds and can be used once (no replay).
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class preview_checkpoint {
    /** @var int Seconds a preview can be confirmed. */
    public const LIFETIME = 3600;

    /** @var int Maximum number of open previews kept per session (oldest are dropped). */
    private const MAX_OPEN = 20;

    /** @var string Key in $SESSION. */
    private const SESSION_KEY = 'tool_ltigroupautoenrol_previews';

    /**
     * Stores a preview and returns the token to confirm it.
     *
     * @param int $courseid
     * @param string $hash Mapping fingerprint the preview was computed for.
     * @param int $time Time the preview was computed (taken before the preview queries).
     * @return string Token (alphanumeric).
     */
    public static function create(int $courseid, string $hash, int $time): string {
        global $SESSION, $USER;
        $previews = self::open_previews($time);
        $token = random_string(32);
        $previews[$token] = [
            'courseid' => $courseid,
            'userid' => (int) $USER->id,
            'hash' => $hash,
            'time' => $time,
            'expires' => $time + self::LIFETIME,
        ];
        $SESSION->{self::SESSION_KEY} = array_slice($previews, -self::MAX_OPEN, null, true);
        return $token;
    }

    /**
     * Takes a preview out of the session (one-time use).
     *
     * @param int $courseid Course of the confirmation; a token of another course is rejected.
     * @param string $token
     * @param int|null $now Defaults to time().
     * @return \stdClass|null Object with "hash" and "time", or null if the token is unknown, used, expired,
     *     belongs to another course or was created for another user (e.g. after "log in as").
     */
    public static function consume(int $courseid, string $token, ?int $now = null): ?\stdClass {
        global $SESSION, $USER;
        $now = $now ?? time();
        $previews = self::open_previews($now);
        $preview = $previews[$token] ?? null;
        if ($preview === null || $preview['courseid'] !== $courseid || ($preview['userid'] ?? null) !== (int) $USER->id) {
            $SESSION->{self::SESSION_KEY} = $previews;
            return null;
        }
        unset($previews[$token]);
        $SESSION->{self::SESSION_KEY} = $previews;
        return (object) ['hash' => $preview['hash'], 'time' => $preview['time']];
    }

    /**
     * Returns the open, not expired previews of the session.
     *
     * @param int $now
     * @return array<string, array{courseid: int, userid: int, hash: string, time: int, expires: int}>
     */
    private static function open_previews(int $now): array {
        global $SESSION;
        $previews = $SESSION->{self::SESSION_KEY} ?? [];
        if (!is_array($previews)) {
            return [];
        }
        return array_filter($previews, fn($preview): bool => is_array($preview) && ($preview['expires'] ?? 0) >= $now);
    }
}
