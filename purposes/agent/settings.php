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
 * Settings for aipurpose_agent.
 *
 * @package    aipurpose_agent
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

global $CFG;

if ($hassiteconfig) {
    $settings->add(
        new admin_setting_configtextarea(
            'aipurpose_agent/agentprompt',
            new lang_string('agentpromptsetting', 'aipurpose_agent'),
            new lang_string('agentpromptsettingdesc', 'aipurpose_agent'),
            \aipurpose_agent\purpose::get_default_agentprompt()
        )
    );
}
