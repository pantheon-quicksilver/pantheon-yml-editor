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
        $packages_info = [];

        foreach ($packages as $package) {
            if ($package->getType() !== 'quicksilver-script') {
                continue;
            }
            $extra = $package->getExtra();
            if (!empty($extra['pantheon-quicksilver'])) {
                $packages_info[$package->getName()] = $extra['pantheon-quicksilver'];
            }
        }
        var_dump($packages_info);

        /*$yaml_data = [
            // 'content-hash' => $content_hash,
            'packages' => $package_versions,
        ];

        $yaml = Yaml::dump($yaml_data);*/
//        file_put_contents('composer-manifest.yaml', $yaml);
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
