<?php

namespace PantheonYmlEditor;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Composer\EventDispatcher\Event;

/**
 * Pantheon YML Editor plugin.
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{

    /**
     * @var Composer $composer
     */
    protected $composer;

    /**
     * @var Util $util
     */
    protected $util;

    /**
     * Returns an array of event names this subscriber wants to listen to.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => ['updatePantheonYml'],
            ScriptEvents::POST_UPDATE_CMD => ['updatePantheonYml'],
            PackageEvents::POST_PACKAGE_UNINSTALL => ['removeWorkflows', 10],
        ];
    }

    /**
     * Remove corresponding workflows when the package is uninstalled.
     */
    public function removeWorkflows(Event $event)
    {
        $package = $event->getOperation()->getPackage();
        if (!in_array($package->getType(), ['quicksilver-script', 'quicksilver-module'])) {
            return;
        }
        $wf_info = $this->util->buildWorkflowsInfoArray([$package], $event);
        if ($wf_info) {
            $pantheon_yml = $this->util->getPantheonYmlContents();

            foreach ($wf_info as $hook_name => $hook_contents) {
                foreach ($hook_contents as $hook) {
                    $hook_descriptions = $this->util->getHookDescriptions($hook);
                    if (isset($pantheon_yml['workflows'][$hook_name])) {
                        foreach ($pantheon_yml['workflows'][$hook_name] as $stage_name => $stage) {
                            foreach ($stage as $key => $item) {
                                if ($item['description'] === $hook_descriptions[0]) {
                                    unset($pantheon_yml['workflows'][$hook_name][$stage_name][$key]);
                                }
                            }
                        }
                    }
                }
            }
            $this->util->writePantheonYml($pantheon_yml);
        }
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
        $wf_info = $this->util->buildWorkflowsInfoArray($packages, $event);

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

        $pantheon_yml = $this->util->getPantheonYmlContents();

        // Which hooks and stages may need ordering fix.
        $may_need_order_fix = [];
        foreach ($wf_info as $hook_name => $hook_contents) {
            foreach ($hook_contents as $hook) {
                $hook_descriptions = $this->util->getHookDescriptions($hook);
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
                        if ($wf_a = $this->util->findWorkflowFromPantheonYml(
                            $entry_a,
                            $wf_info[$hook_name],
                            $stage_name
                        )) {
                            if (!empty($wf_a['weight'])) {
                                $weight_a = $wf_a['weight'];
                            }
                        }
                        if ($wf_b = $this->util->findWorkflowFromPantheonYml(
                            $entry_b,
                            $wf_info[$hook_name],
                            $stage_name
                        )) {
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

        $this->util->writePantheonYml($pantheon_yml);
    }

    /**
     * {@inheritdoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->util = new Util();
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
