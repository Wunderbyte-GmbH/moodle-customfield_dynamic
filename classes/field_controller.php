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
 * Class field
 *
 * @package   customfield_dynamic
 * @copyright 2020 Sooraj Singh
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace customfield_dynamic;

defined('MOODLE_INTERNAL') || die;

/**
 * Class field
 *
 * @package customfield_dynamic
 * @copyright 2020 Sooraj Singh
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class field_controller extends \core_customfield\field_controller {
    /**
     * Customfield type
     */
    const TYPE = 'dynamic';

    /**
     * Add fields for editing a dynamic field.
     *
     * @param \MoodleQuickForm $mform
     */
    public function config_form_definition(\MoodleQuickForm $mform) {
        $mform->addElement('header', 'header_specificsettings', get_string('specificsettings', 'customfield_dynamic'));
        $mform->setExpanded('header_specificsettings', true);

        $mform->addElement('textarea', 'configdata[dynamicsql]', get_string('sqlquery', 'customfield_dynamic'),
         array('rows' => 7, 'cols' => 52));
        $mform->setType('configdata[dynamicsql]', PARAM_RAW);

        $mform->addElement('advcheckbox', 'configdata[autocomplete]', get_string('autocomplete', 'customfield_dynamic'),
         '', array('group' => 1), array(0, 1));
        $mform->addHelpButton('configdata[autocomplete]', 'autocomplete', 'customfield_dynamic');

        $mform->addElement('text', 'configdata[defaultvalue]', get_string('defaultvalue', 'core_customfield'), 'size="50"');
        $mform->setType('configdata[defaultvalue]', PARAM_RAW);

        $mform->addHelpButton('configdata[defaultvalue]', 'defaultvalue', 'customfield_dynamic');

        $mform->addElement('advcheckbox', 'configdata[multiselect]', get_string('enablemultiselect', 'customfield_dynamic'),
         '', array('group' => 1), array(0, 1));
    }

    /**
     * Returns the options available as an array.
     *
     * @param \core_customfield\field_controller $field
     * @return array
     */
    public static function get_options_array(\core_customfield\field_controller $field) : array {
        global $DB;
        if ($field->get_configdata_property('dynamicsql')) {
            $resultset = $DB->get_records_sql($field->get_configdata_property('dynamicsql'));
            $options = [];
            foreach ($resultset as $key => $option) {
                $options[format_string($key)] = format_string($option->data);// Multilang formatting.
            }
        } else {
            $options = [];
        }
        return ['' => get_string('choose')] + $options;
    }

    /**
     * Validate the data from the config form.
     * Sub classes must reimplement it.
     *
     * @param array $data from the add/edit profile field form
     * @param array $files
     * @return array associative array of error messages
     */
    public function config_form_validation(array $data, $files = []): array {
        global $DB;
        $err = [];
        try {
            $sql = $data['configdata']['dynamicsql'];
            if (!isset($sql) || $sql == '') {
                $err['configdata[dynamicsql]'] = get_string('err_required', 'form');
            } else {
                // First thing, we need to sanitze the sql.
                if (!$this->sanitize_sql($sql)) {
                    $err['configdata[dynamicsql]'] = get_string('queryerrorfalse', 'customfield_dynamic');
                } else {
                    $resultset = $DB->get_records_sql($sql);
                    if (!$resultset) {
                        $err['configdata[dynamicsql]'] = get_string('queryerrorfalse', 'customfield_dynamic');
                    } else {
                        if (count($resultset) == 0) {
                            $err['configdata[dynamicsql]'] = get_string('queryerrorempty', 'customfield_dynamic');
                        } else {
                            $firstval = reset($resultset);
                            if (!object_property_exists($firstval, 'id')) {
                                $err['configdata[dynamicsql]'] = get_string('queryerroridmissing', 'customfield_dynamic');
                            } else {
                                if (!object_property_exists($firstval, 'data')) {
                                    $err['configdata[dynamicsql]'] = get_string('queryerrordatamissing', 'customfield_dynamic');
                                } else if (!empty($data['configdata']['defaultvalue'])) {
                                    // Def missing.
                                    $defaultvalue = $data['configdata']['defaultvalue'];
                                    $options = array_column($resultset, 'data', 'id');
                                    $values = explode(',', $defaultvalue);

                                    if ($data['configdata']['multiselect'] == 0 && count($values) > 1) {
                                        $err['configdata[defaultvalue]'] = get_string(
                                            'queryerrormulipledefault',
                                            'customfield_dynamic',
                                            count($values)
                                        );
                                    } else if ($data['configdata']['multiselect'] == 0 && !array_key_exists($defaultvalue, $options)) {
                                        $err['configdata[defaultvalue]'] = get_string(
                                            'queryerrordefaultmissing',
                                            'customfield_dynamic',
                                            $defaultvalue
                                        );
                                    } else {
                                        // In this version of this plugin, we don't validate the default value, as ist can come from a filter.
                                    }

                                }
                            }
                        }
                    }
                }
            }

        } catch (\Exception $e) {
            $err['configdata[dynamicsql]'] = get_string('sqlerror', 'customfield_dynamic') . ': ' .$e->getMessage();
        }
        return $err;
    }

    /**
     * Function to sanitize the sql.
     *
     * @param mixed $sql
     *
     * @return [type]
     *
     */
    private function sanitize_sql($sql) {
        // Normalize whitespace: convert tabs, newlines, multiple spaces to single space.
        $normalized = preg_replace('/\s+/', ' ', strtolower($sql));

        // List of forbidden SQL keywords (whole words only).
        $forbidden = ['insert', 'update', 'delete', 'drop', 'alter', 'truncate', 'create', 'replace', 'merge', 'grant', 'revoke'];

        foreach ($forbidden as $keyword) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $normalized)) {
                return false;
            }
        }

        // No chained statements.
        if (strpos($normalized, ';') !== false) {
            return false;
        }

        // Optional: Ensure it starts with SELECT.
        if (stripos(trim($normalized), 'select') !== 0) {
            return false;
        }

        return true;
    }
}
