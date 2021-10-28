<?php

namespace PantheonYmlEditor;

use Symfony\Component\Yaml\Yaml;
use Consolidation\Comments\Comments;
use Composer\InstalledVersions;
use Composer\Installers\PantheonInstaller;

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
     *
     * @param $workflow
     * @param $script_name
     * @param $package
     * @param $composer
     * @param $io
     * @return string
     */
    public function getScriptPath($workflow, $script_name, $package, $composer, $io): string
    {
        $package_name = $package->getName();
        $installer = new PantheonInstaller($package, $composer, $io);
        $script_path = $installer->getInstallPath($package, 'pantheon-quicksilver');
        if (strpos($script_path, 'web/') === 0) {
            $script_path = substr($script_path, 4);
        }
        if (!empty($workflow['script'])) {
            $script_path .= $workflow['script'];
        } else {
            $script_path .= "${script_name}.php";
        }
        return $script_path;
    }

    /**
     * Get hook possible descriptions.
     *
     * @param $hook
     * @return array
     */
    public function getHookDescriptions($hook): array
    {
        $package_name = $hook['package_name'];
        $wf_type = $hook['wf_type'];
        $base_description = "[$package_name] $wf_type";
        return [
            $base_description . ' (default)',
            $base_description . ' (edited)',
        ];
    }

    /**
     * Get hook description.
     *
     * @param $hook
     * @return array
     */
    public function getHookDescription($hook): array
    {
        $package_name = $hook['package_name'];
        $package_description = $hook['package_description'];

        return [
            'description' => "[$package_name] $package_description",
            'package' => "[$package_name]",
        ];
    }

    /**
     * Find matching descriptions.
     * @param $haystack
     * @param $needle
     * @return bool
     */
    public function matchDescription($haystack, $needle): bool
    {
        $match = false;
        if (strpos($haystack, $needle) !== -1 ) {
            $match = true;
        }

        return $match;
    }

    /**
     * Find given workflow from pantheon.yml in the workflows array.
     *
     * @param $pantheon_yml_entry
     * @param $workflows
     * @param null $stage
     * @return mixed|null
     */
    public function findWorkflowFromPantheonYml($pantheon_yml_entry, $workflows, $stage = null)
    {
        $found_workflow = null;
        foreach ($workflows as $workflow) {
            // Validate the correct hook based on stage declaration
            if ($stage && $workflow['stage'] !== $stage) {
                continue;
            }
            // Get description.
            $descriptions = $this->getHookDescription($workflow);
            if ($this->matchDescription($pantheon_yml_entry['description'], $descriptions['package'])) {
                $found_workflow = $workflow;
                break;
            }
        }
        return $found_workflow;
    }

    /**
     * Validate that workflow structure complies with pantheon-yml-editor.
     *
     * @param array $workflow
     * @param $event
     * @return bool
     */
    public function isValidWorkflow(array $workflow, $event): bool
    {
        // Weight is being treated as optional.
        if (empty($workflow['wf_type']) || empty($workflow['stage'])) {
            return false;
        }

        // Get workflow validation schema.
        $workflows_file = __DIR__ . "/../templates/workflows.json";
        $workflows = json_decode(file_get_contents($workflows_file), true);

        // Test each workflow against the schema.
        foreach ($workflows as $wf) {
            $wf_type = array_keys($wf)[0];
            $wf_data = array_values($wf)[0];

            // Validate the options provided match the latest pantheon yml schema.
            // See reference to pantheon_yml_v1_schema in ygg.
            if ($wf_type == $workflow['wf_type'] && in_array($workflow['stage'], $wf_data['states'])) {
                return true;
            }
        }

        // If no workflows match, then workflow is invalid.
        $event->getIO()->error("Workflow declaration is invalid.");
        return false;
    }

    /**
     * Build workflows array from composer packages.
     *
     * @param $packages
     * @param $event
     * @param $composer
     * @return array
     */
    public function buildWorkflowsInfoArray($packages, $event, $composer): array
    {
        $wf_info = [];
        foreach ($packages as $package) {
            if (!in_array($package->getType(), ['quicksilver-script', 'quicksilver-module'])) {
                continue;
            }

            $package_name = $package->getName();
            $package_description = $package->getDescription();
            $extra = $package->getExtra();

            // Check if Pantheon Quicksilver extras exist.
            if (!empty($extra['pantheon-quicksilver'])) {
                $keys = array_keys($extra['pantheon-quicksilver']);
                $script = reset($keys);
                $workflows = reset($extra['pantheon-quicksilver']);

                // Process each workflow defined in extras.
                foreach ($workflows as $workflow) {
                    if (!$this->isValidWorkflow($workflow, $event)) {
                        $event->getIO()->warning("Skipping: Invalid workflow info for package $package_name");
                        continue;
                    }
                    if (!isset($wf_info[$workflow['wf_type']])) {
                        // Create index if it does not exist.
                        $wf_info[$workflow['wf_type']] = [];
                    }
                    $wf_info[$workflow['wf_type']][$package_name] = $workflow;

                    // Handle optional script key.
                    $wf_info[$workflow['wf_type']][$package_name]['script'] =
                        $this->getScriptPath($workflow, $script, $package, $composer, $event->getIO());

                    $wf_info[$workflow['wf_type']][$package_name]['package_name'] = $package_name;
                    $wf_info[$workflow['wf_type']][$package_name]['package_description'] = $package_description;
                }
            }
        }

        return $wf_info;
    }

    /**
     * Fix floats to print them as strings.
     *
     * @param $data
     * @return mixed
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
     *
     * @return mixed
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
     *
     * @param $pantheon_yml
     * @return false|int
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
