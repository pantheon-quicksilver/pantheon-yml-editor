<?php

namespace PantheonYmlEditor;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Composer\EventDispatcher\Event as BaseEvent;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Parser;

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

    /**
     * Returns an array of event names this subscriber wants to listen to.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => ['updatePantheonYml'],
            ScriptEvents::POST_UPDATE_CMD => ['updatePantheonYml'],
            PackageEvents::POST_PACKAGE_UNINSTALL => ['updatePantheonYml', 10],
        ];
    }

    /**
     * Update the Pantheon YAML file.
     *
     * @param BaseEvent $event
     */
    public function updatePantheonYml(BaseEvent $event)
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
                $workflows = reset($extra['pantheon-quicksilver']);
                foreach ($workflows as $workflow) {
                    if (!$this->isValidWorkflow($workflow)) {
                        // @todo Does this work?
                        $event->getIO()->warning('Invalid workflow info for package !package', [
                            '!package' => $package_name,
                        ]);
                    }
                    if (!isset($wf_info[$workflow['wf_type']])) {
                        // Create index if it does not exist.
                        $wf_info[$workflow['wf_type']] = [];
                    }
                    // This assumes there is only one $wf_type per package. @todo confirm.
                    $wf_info[$workflow['wf_type']][$package_name] = $workflow;
                }
            }
        }
        // Sort each wf_type.
        // @todo validate sorting works as expected.
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
        var_dump($wf_info);

        /*$yaml_data = [
            // 'content-hash' => $content_hash,
            'packages' => $package_versions,
        ];

        $yaml = Yaml::dump($yaml_data);*/
//        file_put_contents('composer-manifest.yaml', $yaml);
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
