<?php

namespace PantheonYmlEditor;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Composer\EventDispatcher\Event;
use Symfony\Component\Yaml\Yaml;
use Consolidation\Comments\Comments;

/**
 * Pantheon YML Editor plugin.
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{

    /**
     * @var Composer $composer
     */
    protected $composer;
    protected $extra;
    protected $comments;
    protected $pantheonYml;

    /**
     * Returns an array of event names this subscriber wants to listen to.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => ['updatePantheonYml'],
            ScriptEvents::POST_UPDATE_CMD => ['updatePantheonYml'],
            // @todo How to make uninstall work?
            PackageEvents::POST_PACKAGE_UNINSTALL => ['updatePantheonYml', 10],
        ];
    }

    /**
     * Update the Pantheon YAML file.
     *
     * @param Event $event
     */
    public function updatePantheonYml(Event $event)
    {
        $repositoryManager = $this->composer->getRepositoryManager();
        $localRepository = $repositoryManager->getLocalRepository();
        $packages = $localRepository->getPackages();
        // Quicksilver workflows as per https://pantheon.io/docs/quicksilver#hooks.
        $wf_info = [];

        foreach ($packages as $package) {
            if ($package->getType() !== 'quicksilver-script') {
                continue;
            }
            $package_name = $package->getName();
            $extra = $package->getExtra();
            if (!empty($extra['pantheon-quicksilver'])) {
                $keys = array_keys($extra['pantheon-quicksilver']);
                $script = reset($keys);
                $workflows = reset($extra['pantheon-quicksilver']);
                foreach ($workflows as $workflow) {
                    if (!$this->isValidWorkflow($workflow)) {
                        $event->getIO()->warning("Skipping invalid workflow info for package ${package_name}");
                        continue;
                    }
                    if (!isset($wf_info[$workflow['wf_type']])) {
                        // Create index if it does not exist.
                        $wf_info[$workflow['wf_type']] = [];
                    }
                    $wf_info[$workflow['wf_type']][$package_name] = $workflow;

                    // Handle optional script key.
                    $script_path = "private/scripts/quicksilver/${script}/";
                    if (!empty($workflow['script'])) {
                        $script_path .= $workflow['script'];
                    } else {
                        $script_path .= "${script}.php";
                    }
                    $wf_info[$workflow['wf_type']][$package_name]['script'] = $script_path;
                    $wf_info[$workflow['wf_type']][$package_name]['package_name'] = $package_name;
                }
            }
        }

        // Sort each wf_type.
        foreach ($wf_info as &$wf_type) {
            usort($wf_type, function ($wf_a, $wf_b) {
                $weight_a = !empty($wf_a['weight']) ? $wf_a['weight'] : 0;
                $weight_b = !empty($wf_b['weight']) ? $wf_b['weight'] : 0;
                if ($weight_a === $weight_b) {
                    return 0;
                }
                return ($weight_a > $weight_b) ? 1 : -1;
            });
        }

        $pantheon_yml = $this->getPantheonYmlContents();

        // Which hooks and stages may need ordering fix.
        $may_need_order_fix = [];
        foreach ($wf_info as $hook_name => $hook_contents) {
            foreach ($hook_contents as $hook) {
                $hook_descriptions = $this->getHookDescriptions($hook);
                $found = false;
                if (isset($pantheon_yml['workflows'][$hook_name])) {
                    foreach ($pantheon_yml['workflows'][$hook_name] as $stage_name => &$stage) {
                        foreach ($stage as &$item) {
                            if ($item['description'] === $hook_descriptions[0]) {
                                if (!isset($may_need_order_fix[$hook_name])) {
                                    $may_need_order_fix[$hook_name] = [];
                                }
                                $may_need_order_fix[$hook_name][$stage_name] = $stage_name;
                                $found = true;
                                $item['script'] = $hook['script'];
                            } elseif ($item['description'] === $hook_descriptions[1]) {
                                // Nothing to do.
                                $found = true;
                            }
                        }
                    }
                }
                if (!$found) {
                    if (!isset($may_need_order_fix[$hook_name])) {
                        $may_need_order_fix[$hook_name] = [];
                    }
                    $may_need_order_fix[$hook_name][$hook['stage']] = $hook['stage'];
                    $pantheon_yml['workflows'][$hook_name][$hook['stage']][] = [
                        'type' => 'webphp',
                        'script' => $hook['script'],
                        'description' => $hook_descriptions[0],
                    ];
                }
            }
        }
        if ($may_need_order_fix) {
            foreach ($may_need_order_fix as $hook_name => $hook) {
                foreach ($hook as $stage_name) {
                    $pantheon_yml_stage = &$pantheon_yml['workflows'][$hook_name][$stage_name];
                    usort($pantheon_yml_stage, function ($entry_a, $entry_b) use ($wf_info, $hook_name, $stage_name) {
                        $weight_a = 0;
                        $weight_b = 0;
                        // Try get the weights from the source and reorder as needed.
                        if ($wf_a = $this->findWorkflowFromPantheonYml($entry_a, $wf_info[$hook_name], $stage_name)) {
                            if (!empty($wf_a['weight'])) {
                                $weight_a = $wf_a['weight'];
                            }
                        }
                        if ($wf_b = $this->findWorkflowFromPantheonYml($entry_b, $wf_info[$hook_name], $stage_name)) {
                            if (!empty($wf_b['weight'])) {
                                $weight_b = $wf_b['weight'];
                            }
                        }
                        if ($weight_a === $weight_b) {
                            return 0;
                        }
                        return ($weight_a > $weight_b) ? 1 : -1;
                    });
                }
            }
        }

        $this->writePantheonYml($pantheon_yml);
    }

    /**
     * Find given workflow from pantheon yml in the workflows array.
     */
    protected function findWorkflowFromPantheonYml($pantheon_yml_entry, $workflows, $stage = null)
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
     * Get hook possible descriptions.
     */
    protected function getHookDescriptions($hook)
    {
        $package_name = $hook['package_name'];
        $wf_type = $hook['wf_type'];
        $base_description = "[${package_name}] ${wf_type}";
        return [
            // @todo Document this to allow people to hand-edit pantheon yml.
            $base_description . ' (default)',
            $base_description . ' (edited)',
        ];
    }

    /**
     * Get pantheon yml contents.
     */
    protected function getPantheonYmlContents()
    {
        $pantheon_yml = './pantheon.yml';

        // Load the pantheon.yml file
        if (file_exists($pantheon_yml)) {
            $pantheon_yml_contents = file_get_contents($pantheon_yml);
        } else {
            $example_pantheon_yml = __DIR__ . "/../templates/example.pantheon.yml";
            $pantheon_yml_contents = file_get_contents($example_pantheon_yml);
        }
        $this->pantheonYml = Yaml::parse($pantheon_yml_contents);
        $this->comments = new Comments();
        $this->comments->collect(explode("\n", $pantheon_yml_contents));

        return $this->pantheonYml;
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
     * Validate that workflow structure complies with pantheon-yml-editor.
     */
    protected function isValidWorkflow(array $workflow)
    {
        // Weight is being treated as optional.
        if (!isset($workflow['wf_type']) || !isset($workflow['stage'])) {
            return false;
        }
        // @todo More validations could be added (e.g. stage vs wf_type).
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
    }

    /**
     * {@inheritdoc}
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
        // Do nothing.
    }

    /**
     * {@inheritdoc}
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
        // Do nothing.
    }
}
