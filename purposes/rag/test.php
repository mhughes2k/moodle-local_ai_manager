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
 * TODO describe file test
 *
 * @package    aipurpose_rag
 * @copyright  2025 University Of Strathclyde <learning-technologies@strath.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use tool_brickfield\local\areas\mod_choice\option;

require('../../../../config.php');

require_login();
$context = context_system::instance();

$url = new moodle_url('/local/ai_manager/purposes/rag/test.php', []);
$PAGE->set_url($url);
$PAGE->set_context($context);

$PAGE->set_heading($SITE->fullname);
echo $OUTPUT->header();

$manager = new \local_ai_manager\manager('rag');
echo \html_writer::start_tag('pre');
$action = optional_param('action', '', PARAM_ALPHA);
if ($action == "store") {
    // Test 1 store a doc.
    
    $storeprompt = json_encode([
        'content' => 'This is a test document. It is only a test document.',
        'metadata' => [
            'title' => 'Test Document',
            'author' => 'Moodle AI Manager',
            'source' => 'Generated',
        ],
    ]);
    $storeoptions = [
        'action' => 'store',
    ];
    $storeresponse = $manager->perform_request(
        $storeprompt,
        'aitool_rag',
        $context->id,
        $storeoptions
    );

    $storeresult = $storeresponse->get_content();

    var_dump($storeresult);
}
if ($action == "fetch") {
    // Test 2 retrive a doc.
    $output = [];
    $testvalues = ["Test Document", 'random'];
    foreach($testvalues as $retrieveprompt) {
        $retrieveoptions = [
            'action' => 'retrieve',
            'topk' => 1,
        ];
        $retrieveresponse = $manager->perform_request(
            $retrieveprompt,
            'aitool_rag',
            $context->id,
            $retrieveoptions
        );
        $retrieveresult = json_decode($retrieveresponse->get_content());
        
        $results = $retrieveresult->result->points;
        $docs = array_map(function($r) use ($retrieveprompt) {
            return "\"{$retrieveprompt}\": {$r->payload->title} ({$r->score})";
        }, $results);
        // Append $docs to $output
        $output = array_merge($output, $docs);
    }

}
echo \html_writer::end_tag('pre');
echo \html_writer::alist($output);
echo $OUTPUT->footer();
