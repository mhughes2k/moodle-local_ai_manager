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

namespace local_ai_manager;

use context;
use context_system;
use core\clock;
use core_plugin_manager;
use local_ai_manager\event\get_ai_response_failed;
use local_ai_manager\event\get_ai_response_succeeded;
use local_ai_manager\hook\additional_user_restriction;
use local_ai_manager\local\config_manager;
use local_ai_manager\local\connector_factory;
use local_ai_manager\local\prompt_response;
use local_ai_manager\local\userinfo;
use local_ai_manager\local\userusage;
use stdClass;

/**
 * Main class for handling requests to external AI tools.
 *
 * @package    local_ai_manager
 * @copyright  ISB Bayern, 2024
 * @author     Dr. Peter Mayer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {

    /** @var base_purpose The purpose which is being used for this request */
    private base_purpose $purpose;

    /** @var base_connector The tool connector object. */
    private base_connector $connector;

    /** @var local\connector_factory the connector factory for retrieving necessary objects */
    private connector_factory $factory;

    /** @var config_manager the config manager object */
    private config_manager $configmanager;

    /** @var clock the clock object to retrieve time from. */
    private clock $clock;

    /**
     * Create the manager for a specific purpose.
     *
     * @param string $purpose the purpose name of the purpose to use
     */
    public function __construct(string $purpose) {
        global $USER;
        $userinfo = new userinfo($USER->id);
        $this->factory = \core\di::get(connector_factory::class);
        $this->purpose = $this->factory->get_purpose_by_purpose_string($purpose);
        $toolconnector = $this->factory->get_connector_by_purpose($purpose, $userinfo->get_role());
        if (!empty($toolconnector)) {
            $this->connector = $toolconnector;
        } else {
            throw new \moodle_exception('error_noaitoolassignedforpurpose', 'local_ai_manager', '', $purpose);
        }
        $this->configmanager = \core\di::get(config_manager::class);
        $this->clock = \core\di::get(clock::class);
    }

    /**
     * Helper function to determine the available tool plugins for a given purpose.
     *
     * @param string $purpose the name of the purpose
     * @return array list of connector plugin display names
     */
    public static function get_tools_for_purpose(string $purpose): array {
        $tools = [];
        foreach (core_plugin_manager::instance()->get_enabled_plugins('aitool') as $tool) {
            $toolplugininfo = core_plugin_manager::instance()->get_plugin_info('aitool_' . $tool);
            $classname = "\\aitool_" . $tool . "\\connector";
            $toolconnector = new $classname();
            $supportspurpose = in_array($purpose, $toolconnector->supported_purposes());
            if ($supportspurpose) {
                $tools[$tool] = $toolplugininfo->displayname;
            }
        }
        return $tools;
    }

    /**
     * Get the prompt completion from the LLM.
     *
     * @param string $prompttext The prompt text.
     * @param string $component The component from which the request is being done
     * @param int $contextid The context id of the context from which the request is being done, use 0 for system context
     * @param array $options Options to be used during processing.
     * @return prompt_response The generated prompt response object
     */
    public function perform_request(string $prompttext, string $component, int $contextid, array $options = []): prompt_response {
        global $DB, $USER;

        if ($options === null) {
            $options = [];
        }
        try {
            $context = $contextid === 0 ? \context_system::instance() : context::instance_by_id($contextid);
        } catch (\moodle_exception $exception) {
            return prompt_response::create_from_error(400, get_string('error_http400contextmissing', 'local_ai_manager'),
                    $exception->getMessage());
        }
        $requestoptions = new request_options($this->purpose, $context, $component, $options);

        if (!has_capability('local/ai_manager:use', $context)) {
            return prompt_response::create_from_error(403, get_string('error_http403nocapability', 'local_ai_manager'), '');
        }

        try {
            $requestoptions->sanitize_options();
        } catch (\Exception $exception) {
            return prompt_response::create_from_error(
                    400,
                    get_string('error_http400', 'local_ai_manager'),
                    $exception->getMessage()
            );
        }

        if (!$this->configmanager->is_tenant_enabled()) {
            return prompt_response::create_from_error(403, get_string('error_http403disabled', 'local_ai_manager'), '');
        }

        $userinfo = new userinfo($USER->id);
        if ($userinfo->is_locked()) {
            return prompt_response::create_from_error(403, get_string('error_http403blocked', 'local_ai_manager'), '');
        }

        $connectorfactory = \core\di::get(connector_factory::class);
        $instance = $connectorfactory->get_connector_instance_by_purpose($this->purpose->get_plugin_name(), $userinfo->get_role());
        if (is_null($instance)) {
            return prompt_response::create_from_error(403, get_string('error_purposenotconfigured', 'local_ai_manager'), '');
        }
        if (!$instance->is_enabled()) {
            return prompt_response::create_from_error(403, get_string('exception_instanceunavailable', 'local_ai_manager'), '');
        }

        if (!$userinfo->is_confirmed()) {
            return prompt_response::create_from_error(403, get_string('error_http403notconfirmed', 'local_ai_manager'), '');
        }

        if ($userinfo->get_scope() === userinfo::SCOPE_COURSES_ONLY) {
            $parentcoursecontext = ai_manager_utils::find_closest_parent_course_context($context);
            if (is_null($parentcoursecontext)) {
                // That means we are not in a subcontext of a course context.
                return prompt_response::create_from_error(403, get_string('error_http403coursesonly', 'local_ai_manager'), '');
            }
        }

        // Provide an additional hook for further limiting access.
        $restrictionhook = new additional_user_restriction($userinfo, $context, $this->purpose);
        \core\di::get(\core\hook\manager::class)->dispatch($restrictionhook);
        if (!$restrictionhook->is_allowed()) {
            return prompt_response::create_from_error(
                    $restrictionhook->get_code(),
                    $restrictionhook->get_message(),
                    $restrictionhook->get_debuginfo());
        }

        if (intval($this->configmanager->get_max_requests($this->purpose, $userinfo->get_role())) === 0) {
            return prompt_response::create_from_error(403, get_string('error_http403usertype', 'local_ai_manager'), '');
        }

        $userusage = new userusage($this->purpose, $USER->id);

        if ($userusage->get_currentusage() >= $this->configmanager->get_max_requests($this->purpose, $userinfo->get_role())) {
            $period = format_time($this->configmanager->get_max_requests_period());
            return prompt_response::create_from_error(
                    429,
                    get_string(
                            'error_http429',
                            'local_ai_manager',
                            ['count' => $this->configmanager->get_max_requests($this->purpose, $userinfo->get_role()),
                                    'period' => $period]
                    ),
                    ''
            );
        }
        $promptdata = $this->connector->get_prompt_data($prompttext, $requestoptions);
        $starttime = microtime(true);
        try {
            $requestresult = $this->connector->make_request($promptdata, $requestoptions);
        } catch (\Exception $exception) {
            // This hopefully very rarely happens, because we catch exceptions already inside the make_request method.
            // So we do not do any more beautifying of exceptions here.
            $endtime = microtime(true);
            $duration = round($endtime - $starttime, 2);
            $promptresponse = prompt_response::create_from_error(500, $exception->getMessage(), $exception->getTraceAsString());
            get_ai_response_failed::create_from_prompt_response($promptdata, $promptresponse, $duration)->trigger();
            return $promptresponse;
        }
        $endtime = microtime(true);
        $duration = round($endtime - $starttime, 2);
        if ($requestresult->get_code() !== 200) {
            $promptresponse = prompt_response::create_from_error($requestresult->get_code(), $requestresult->get_errormessage(),
                    $requestresult->get_debuginfo());
            get_ai_response_failed::create_from_prompt_response($promptdata, $promptresponse, $duration)->trigger();
            return $promptresponse;
        }
        $promptcompletion = $this->connector->execute_prompt_completion($requestresult->get_response(), $requestoptions);
  
        if (!empty($promptcompletion->get_errormessage())) {
            get_ai_response_failed::create_from_prompt_response($promptdata, $promptcompletion, $duration)->trigger();
            return $promptcompletion;
        }
        // Check if an itemid already exists if the option 'forcenewitemid' is being used.
        // This is to avoid race conditions and to ensure that a new itemid is being used for logging if the plugin needs unique
        // itemids.
        if (!empty($options['forcenewitemid']) && !empty($options['itemid'])) {
            if ($DB->record_exists('local_ai_manager_request_log',
                    ['component' => $component, 'contextid' => $context->id, 'itemid' => $options['itemid']])) {
                $existingitemid = $options['itemid'];
                unset($options['itemid']);
                $this->log_request($prompttext, $promptcompletion, $duration, $requestoptions);
                $promptresponse = prompt_response::create_from_error(409, get_string('error_http409', 'local_ai_manager',
                        $existingitemid), '');
                get_ai_response_failed::create_from_prompt_response($promptdata, $promptresponse, $duration)->trigger();
                return $promptresponse;
            }
        }

        $logrecordid = $this->log_request($prompttext, $promptcompletion, $duration, $requestoptions);
        get_ai_response_succeeded::create_from_prompt_response($promptcompletion, $logrecordid)->trigger();

        $promptcompletion->set_content($this->purpose->format_output($promptcompletion->get_content()));

        return $promptcompletion;
    }

    /**
     * Log the request to the request_log table.
     *
     * @param string $prompttext the prompt text which has been sent to the external AI tool
     * @param prompt_response $promptcompletion The prompt response object from which information will be extracted and stored
     *  in the log table
     * @param float $executiontime the duration that the request has taken
     * @return int the record id of the log record which has been stored to the database
     */
    public function log_request(string $prompttext, prompt_response $promptcompletion, float $executiontime,
            request_options $requestoptions): int {
        global $DB, $USER;

        // phpcs:disable moodle.Commenting.TodoComment.MissingInfoInline
        // TODO Move this handling to a data class "log_entry".
        // phpcs:enable moodle.Commenting.TodoComment.MissingInfoInline

        $data = new stdClass();
        $data->userid = $USER->id;
        $data->tenant = $this->configmanager->get_tenant()->get_sql_identifier();
        $data->value = $promptcompletion->get_usage()->value;
        $data->connector = $this->connector->get_instance()->get_connector();
        if ($this->connector->has_customvalue1()) {
            $data->customvalue1 = $promptcompletion->get_usage()->customvalue1;
        }
        if ($this->connector->has_customvalue2()) {
            $data->customvalue2 = $promptcompletion->get_usage()->customvalue2;
        }
        $data->purpose = $this->purpose->get_plugin_name();
        $data->model = $this->connector->get_instance()->get_model();
        $data->modelinfo = $promptcompletion->get_modelinfo();
        $data->prompttext = $prompttext;
        $data->promptcompletion = $promptcompletion->get_content();
        $data->duration = $executiontime;
        if (!empty($requestoptions->get_options())) {
            $data->requestoptions = json_encode($requestoptions->get_options());
        }
        $data->component = $requestoptions->get_component();
        $data->contextid = $requestoptions->get_context()->id;
        $context = \context_helper::instance_by_id($data->contextid);
        $coursecontext = ai_manager_utils::find_closest_parent_course_context($context);
        $data->coursecontextid = $coursecontext ? $coursecontext->id : SYSCONTEXTID;
        if (array_key_exists('itemid', $requestoptions->get_options())) {
            $data->itemid = intval($requestoptions->get_options()['itemid']);
        }
        $data->timecreated = $this->clock->time();
        $recordid = $DB->insert_record('local_ai_manager_request_log', $data);

        // Check if we already have a userinfo object for this. If not we need to create one to initially set the correct role.
        $userinfo = new userinfo($data->userid);
        if (!$userinfo->record_exists()) {
            $userinfo->store();
        }

        $userusage = new userusage($this->purpose, $USER->id);
        $userusage->set_currentusage($userusage->get_currentusage() + 1);
        $userusage->store();
        return $recordid;
    }
}
