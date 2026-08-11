<?php

declare(strict_types=1);

namespace Queue\Installer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

/**
 * Composer-плагин: после install/update разворачивает в корне проекта
 * стартовый набор файлов из README (точка приёма, воркер, хук, админка).
 *
 * Существующие файлы никогда не перезаписываются, поэтому повторные
 * запуски composer безопасны.
 */
final class Plugin implements PluginInterface, EventSubscriberInterface
{
    /** Имя самого пакета — чтобы не разворачивать заготовки внутри него самого. */
    private const PACKAGE_NAME = 'integrat/queue';

    public function activate(Composer $composer, IOInterface $io): void
    {
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'scaffold',
            ScriptEvents::POST_UPDATE_CMD  => 'scaffold',
        ];
    }

    public function scaffold(Event $event): void
    {
        $composer = $event->getComposer();
        $io       = $event->getIO();

        // Разработка самого пакета — заготовки не нужны.
        if ($composer->getPackage()->getName() === self::PACKAGE_NAME) {
            return;
        }

        if (!$this->isEnabled($composer)) {
            return;
        }

        $projectRoot = $this->projectRoot($composer);

        $scaffolder = new Scaffolder(
            __DIR__ . '/stubs',
            $projectRoot,
            ['{{VENDOR}}' => $this->vendorPath($composer, $projectRoot)],
        );

        $created = $scaffolder->run();

        if ($created === []) {
            return;
        }

        $io->write('<info>integrat/queue:</info> созданы стартовые файлы очереди:');
        foreach ($created as $path) {
            $io->write('  - ' . $path);
        }
        $io->write('Настройте крон для <comment>scripts/cron-queue-worker.php</comment> — см. README пакета.');
    }

    /**
     * Корень проекта — каталог его composer.json (вычислять от vendor-dir нельзя:
     * его можно переопределить на вложенный путь).
     */
    private function projectRoot(Composer $composer): string
    {
        $composerFile = realpath(Factory::getComposerFile());

        if ($composerFile !== false) {
            return \dirname($composerFile);
        }

        return \dirname((string) $composer->getConfig()->get('vendor-dir'));
    }

    /**
     * Путь до vendor относительно корня проекта — подставляется в require заготовок.
     * Обычно это просто `vendor`, но `config.vendor-dir` можно переопределить.
     */
    private function vendorPath(Composer $composer, string $projectRoot): string
    {
        $vendorDir = (string) $composer->getConfig()->get('vendor-dir');
        $normalize = static fn (string $path): string => rtrim(str_replace('\\', '/', $path), '/');

        $vendorDir   = $normalize($vendorDir);
        $projectRoot = $normalize($projectRoot);

        if ($projectRoot !== '' && str_starts_with($vendorDir . '/', $projectRoot . '/')) {
            return ltrim(substr($vendorDir, \strlen($projectRoot)), '/');
        }

        // vendor вне проекта — относительный путь не построить, оставляем значение по умолчанию.
        return 'vendor';
    }

    /**
     * Отключение: `QUEUE_NO_SCAFFOLD=1` в окружении либо
     * `"extra": { "integrat/queue": { "scaffold": false } }` в composer.json проекта.
     */
    private function isEnabled(Composer $composer): bool
    {
        $env = getenv('QUEUE_NO_SCAFFOLD');
        if ($env !== false && $env !== '' && $env !== '0') {
            return false;
        }

        $extra = $composer->getPackage()->getExtra();
        $own   = $extra[self::PACKAGE_NAME] ?? null;

        if (\is_array($own) && \array_key_exists('scaffold', $own)) {
            return (bool) $own['scaffold'];
        }

        return true;
    }
}
