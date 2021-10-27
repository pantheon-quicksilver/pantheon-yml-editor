<?php

namespace PantheonYmlEditor;

use Symfony\Component\Yaml\Yaml;
use Consolidation\Comments\Comments;

/**
 * Utilities functions for Pantheon YML Editor plugin.
 */
class Util
{

    /**
     * @var Comments $comments
     */
    protected $comments;

    /**
     * Get script path from workflow information.
     */
    public function getScriptPath($workflow, $script_name)
    {
        // @todo Get the base path from site extra.installer-paths
        $script_path = "private/scripts/quicksilver/${script_name}/";
        if (!empty($workflow['script'])) {
            $script_path .= $workflow['script'];
        } else {
            $script_path .= "${script_name}.php";
        }
        return $script_path;
    }

    /**
     * Get hook possible descriptions.
     */
    public function getHookDescriptions($hook)
    {
        $package_name = $hook['package_name'];
        $wf_type = $hook['wf_type'];
        $base_description = "[${package_name}] ${wf_type}";
        return [
            $base_description . ' (default)',
            $base_description . ' (edited)',
        ];
    }

    /**
     * Find given workflow from pantheon yml in the workflows array.
     */
    public function findWorkflowFromPantheonYml($pantheon_yml_entry, $workflows, $stage = null)
    {
        $found_workflow = null;
        foreach ($workflows as $workflow) {
            if ($stage && $workflow['stage'] !== $stage) {
                continue;
            }
            $descriptions = $this->getHookDescriptions($workflow);
            if (in_array($pantheon_yml_entry['description'], $descriptions)) {
                $found_workflow = $workflow;
                break;
            }
        }
        return $found_workflow;
    }

    /**
     * Validate that workflow structure complies with pantheon-yml-editor.
     */
    public function isValidWorkflow(array $workflow)
    {
        // Weight is being treated as optional.
        if (!isset($workflow['wf_type']) || !isset($workflow['stage'])) {
            return false;
        }
        // @todo More validations could be added (e.g. stage vs wf_type).
        return true;
    }

    /**
     * Fix floats to print them as strings.
     */
    protected function fixFloats($data)
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->fixFloats($value);
            } elseif (is_float($value)) {
                $data[$key] = (string)$value;
                // Integer values would not be a float if it did not have
                // a ".0" in the source data, so put that back.
                if ($value == floor($value)) {
                    $data[$key] .= '.0';
                }
            }
        }
        return $data;
    }

    /**
     * Get pantheon yml contents.
     */
    public function getPantheonYmlContents()
    {
        $pantheon_yml = './pantheon.yml';

        // Load the pantheon.yml file
        if (file_exists($pantheon_yml)) {
            $pantheon_yml_contents = file_get_contents($pantheon_yml);
        } else {
            $example_pantheon_yml = __DIR__ . "/../templates/example.pantheon.yml";
            $pantheon_yml_contents = file_get_contents($example_pantheon_yml);
        }
        $pantheon_yml = Yaml::parse($pantheon_yml_contents);
        $this->comments = new Comments();
        $this->comments->collect(explode("\n", $pantheon_yml_contents));

        return $pantheon_yml;
    }

    /**
     * Write a modified pantheon.yml file back to disk.
     */
    public function writePantheonYml($pantheon_yml)
    {
        // Convert floats in the data to strings so that we can preserve the ".0"
        $pantheon_yml = $this->fixFloats($pantheon_yml);

        $pantheon_yml_path = './pantheon.yml';
        $pantheon_yml = Yaml::dump($pantheon_yml, PHP_INT_MAX, 2);
        $pantheon_yml_lines = $this->comments->inject(explode("\n", $pantheon_yml));
        $pantheon_yml_text = implode("\n", $pantheon_yml_lines) . "\n";

        // Horrible workaround. We cannot get our yaml parser to output a
        // string such as '7.0' without wrapping it in quotes. If the data
        // type is numeric, then the yaml parser will output '7' rather than
        // '7.0'. We therefore convert floats to strings so that we
        // can retain the '.0' on the end; however, this causes the output
        // value to be wrapped in quotes, which the Pantheon schema parser
        // rejects. We therefore strip quotes from numeric types here.
        $pantheon_yml_text = preg_replace("#^([^:]+: *)'([0-9.]+)'$#m", '\1\2', $pantheon_yml_text);

        return file_put_contents($pantheon_yml_path, $pantheon_yml_text);
    }
}
