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
 * EN language file for tool_ltigroupautoenrol
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2024 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

$string['auto_group_enrol_form_no_group_found'] = 'Bitte legen Sie zunächst Gruppen an!';
$string['auto_group_form_enable_enrol'] = 'Automatische Gruppenzuordnung via LTI für diesen Kurs aktivieren';
$string['auto_group_form_enable_enrol_help'] = 'Wenn aktiviert, werden Nutzer/innen, die neu über eines der unten aufgeführten LTI-1.3-Tools eingeschrieben werden, den für dieses Tool gewählten Gruppen hinzugefügt. Die Zuordnung ist rein additiv: Niemand wird aus Gruppen entfernt, auch nicht beim Deaktivieren. Bereits früher eingeschriebene Teilnehmende lassen sich nach dem Speichern über „Bestehende LTI-Teilnehmende zuordnen“ nachträglich zuordnen.';
$string['auto_group_form_groupslist_intro'] = 'Wählen Sie je LTI-Tool eine oder mehrere Gruppen. Mehrfachauswahl mit gedrückter Strg-Taste (macOS: Cmd) oder mit Umschalt und Pfeiltasten. Die Auswahl bleibt beim Deaktivieren erhalten.';
$string['auto_group_form_intro'] = 'Nutzer/innen, die über ein freigegebenes LTI-1.3-Tool in diesen Kurs eingeschrieben werden, können abhängig vom Tool automatisch Gruppen zugeordnet werden.';
$string['auto_group_form_no_tools'] = 'Dieser Kurs stellt noch kein LTI-1.3-Tool bereit, daher gibt es nichts zuzuordnen.';
$string['auto_group_form_no_tools_link'] = 'Einschreibemethoden verwalten';
$string['auto_group_form_page_title'] = 'Gruppeneinschreibungen via LTI bearbeiten';
$string['auto_group_form_tool_disabled'] = '(Einschreibemethode deaktiviert)';
$string['backfill_changed'] = 'Die Konfiguration wurde nach der Vorschau geändert. Bitte prüfen Sie die Vorschau erneut und bestätigen Sie.';
$string['backfill_col_eligible'] = 'Aktive LTI-Einschreibungen';
$string['backfill_col_groups'] = 'Gruppen';
$string['backfill_col_missing'] = 'Fehlende Gruppenmitgliedschaften';
$string['backfill_col_tool'] = 'LTI-Tool';
$string['backfill_confirm'] = 'Hinzuzufügende Gruppenmitgliedschaften: {$a}. Nutzer/innen werden Gruppen nur hinzugefügt, nie entfernt. Fortfahren?';
$string['backfill_heading'] = 'Bestehende LTI-Teilnehmende zuordnen';
$string['backfill_intro'] = 'Teilnehmende, die vor dem Speichern der aktuellen Konfiguration über ein LTI-Tool eingeschrieben wurden, werden nicht automatisch zugeordnet. Die Vorschau zeigt, wie viele aktive LTI-Einschreibungen betroffen sind. Gesperrte, abgelaufene und noch nicht begonnene Einschreibungen sind ausgenommen.';
$string['backfill_lastrun'] = 'Letzter Lauf: {$a->time}. Hinzugefügt: {$a->added}, bereits Mitglied: {$a->alreadymember}, übersprungen: {$a->skipped}, Fehler: {$a->errors}.';
$string['backfill_notenabled'] = 'Die automatische Gruppenzuordnung ist für diesen Kurs nicht aktiviert oder keinem LTI-Tool ist eine Gruppe zugeordnet.';
$string['backfill_nothingtodo'] = 'Alle aktiven LTI-Teilnehmenden sind bereits Mitglied ihrer konfigurierten Gruppen.';
$string['backfill_pending'] = 'Für diesen Kurs ist bereits ein Zuordnungslauf eingeplant. Er wird beim nächsten Cron-Lauf verarbeitet.';
$string['backfill_preview'] = 'Bestehende LTI-Teilnehmende zuordnen …';
$string['backfill_queued'] = 'Die Zuordnung bestehender LTI-Teilnehmender wurde eingeplant und wird beim nächsten Cron-Lauf verarbeitet.';
$string['backfill_run'] = 'Teilnehmende zuordnen';
$string['backfill_status_queued'] = 'Zuordnung bestehender LTI-Teilnehmender am {$a->time} eingeplant. Sie wird beim nächsten Cron-Lauf verarbeitet.';
$string['backfill_status_running'] = 'Zuordnung bestehender LTI-Teilnehmender läuft (gestartet {$a->time}).';
$string['backfill_status_skipped'] = 'Die vor {$a->time} eingeplante Zuordnung wurde nicht ausgeführt, weil die Konfiguration danach geändert oder deaktiviert wurde. Bitte prüfen Sie die Vorschau und bestätigen Sie erneut.';
$string['backfill_tablecaption'] = 'Vorschau je LTI-Tool';
$string['coursemenu_item'] = 'Gruppeneinschreibungen via LTI';
$string['error_backfilllocked'] = 'Für Kurs {$a} läuft bereits eine Zuordnung.';
$string['error_backfillpartial'] = '{$a} Gruppenmitgliedschaften konnten nicht angelegt werden; der Lauf wird wiederholt.';
$string['error_invalidgroups'] = 'Es können nur Gruppen dieses Kurses ausgewählt werden.';
$string['error_invalidmapping'] = 'Die Zuordnung von LTI-Tools zu Gruppen ist ungültig.';
$string['error_storedmappinginvalid'] = 'Die gespeicherte Zuordnung von LTI-Tools zu Gruppen in diesem Kurs ist ungültig und wird ignoriert. Speichern Sie das Formular, um sie zu ersetzen.';
$string['error_upgradebackup'] = 'Die Upgrade-Sicherungsdatei {$a} konnte nicht geschrieben werden.';
$string['event_errors'] = 'Seit {$a->time} konnten {$a->count} Gruppenmitgliedschaft(en) neuer LTI-Einschreibungen nicht angelegt werden. Ordnen Sie die Betroffenen über „Bestehende LTI-Teilnehmende zuordnen“ zu; diese Meldung verschwindet nach einem vollständigen Lauf.';
$string['form_groupsfortool'] = 'Gruppen für LTI-Tool: {$a}';
$string['ltigroupautoenrol:manage'] = 'Automatische Gruppenzuordnung von LTI-Teilnehmenden konfigurieren';
$string['menu_auto_groups'] = 'Gruppeneinschreibungen via LTI';
$string['pluginname'] = 'Automatische Gruppeneinschreibungen via LTI-Zugriff';
$string['privacy:reason'] = 'Das Plugin speichert ausschließlich Kurseinstellungen (welches LTI-Tool welchen Gruppen zugeordnet ist) sowie Status und Zähler der letzten Zuordnung bestehender LTI-Teilnehmender, aber keine personenbezogenen Daten. Die von ihm angelegten Gruppenmitgliedschaften werden vom Moodle-Gruppensystem (core_group) gespeichert und exportiert.';
$string['task_backfill'] = 'Bestehende LTI-Teilnehmende Gruppen zuordnen';
$string['tool_fallbackname'] = 'LTI-Tool {$a}';
