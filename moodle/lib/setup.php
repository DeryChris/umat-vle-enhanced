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
 * Setup file migration helper.
 *
 * @package    core
 * @copyright  2024 Andrew Lyons <andrew@nicols.co.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// When Moodle is loaded, config.php normally calls: require_once(__DIR__.'/lib/setup.php');
// which means $CFG already exists and is a stdClass.
//
// In your custom layout we are acting as a wrapper around the real
// implementation in /moodle/public/lib/setup.php.
//
// Fix: make sure we don't try to use an uninitialised $CFG->libdir.
// Some sites/plugins can require this wrapper before $CFG->libdir is set.
// Initialise $CFG->libdir for early bootstrap use.
// In Moodle's real bootstrap, $CFG->libdir is normally set in public/lib/setup.php,
// but your wrapper may be included before that.
if (isset($CFG->dirroot) && is_string($CFG->dirroot) && !str_ends_with($CFG->dirroot, '/public')) {
    if (!isset($CFG->libdir) || $CFG->libdir === '') {
        $CFG->libdir = rtrim($CFG->dirroot, '/\\') . '/lib';
    } elseif (!str_ends_with($CFG->libdir, '/lib')) {
        $CFG->libdir = rtrim($CFG->libdir, '/\\') . '/lib';
    }
}


require_once(dirname(__DIR__) . '/public/lib/setup.php');

