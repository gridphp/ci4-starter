<?php

use Composer\Script\Event;
use Composer\Util\HttpDownloader;

/**
 * Composer script helper for the ci4-starter project.
 * Loaded via "classmap" autoload (see composer.json), no namespace,
 * so it can live standalone at the project root.
 *
 * Storage model:
 *   - vendor/gridphp/gridphp-community/lib/   <- source of truth (PHP engine + JS/CSS)
 *   - public/gridphp/assets/                  <- JS/CSS only, published by publishAssets()
 *
 * fetchCoreFiles() and publishAssets() run on both post-install-cmd and
 * post-update-cmd so every `composer install` or `composer update` keeps
 * the engine and public assets up to date.
 */
class ComposerScript
{
    private const VENDOR_LIB = __DIR__ . '/vendor/gridphp/gridphp-community/lib';
    private const PUBLIC_LIB = __DIR__ . '/public/gridphp/assets';

    private const FILES_TO_FETCH = [
        'https://www.gridphp.com/secure/free/jqgrid_dist.phps' => self::VENDOR_LIB . '/inc/jqgrid_dist.php',
        'https://www.gridphp.com/secure/free/ai_grid.phps'     => self::VENDOR_LIB . '/inc/ai/ai_grid.php',
    ];

    /**
     * Fetch the gated GridPHP core files into vendor/gridphp/gridphp-community/lib/inc/.
     *
     * NOTE: this only refreshes the two dynamically-licensed PHP files.
     * The static lib/js (jQuery, jqGrid) + lib/css assets aren't fetched
     * here - they need to be placed into vendor/gridphp/gridphp-community/lib/ once,
     * from the full archive at https://www.gridphp.com/download/, since
     * there's no direct-download URL for that bundle (only the two gated
     * PHP files have one). If that base lib/ isn't present yet, this
     * writes the inc/ files anyway but publishAssets() will warn that
     * js/css assets are still missing.
     */
    public static function fetchCoreFiles(Event $event): void
    {
        $io = $event->getIO();

        try {
            $downloader = new HttpDownloader($io, $event->getComposer()->getConfig());
        } catch (\Exception $e) {
            $io->writeError('<error>GridPHP: failed to initialize downloader - ' . $e->getMessage() . '</error>');
            return;
        }

        foreach (self::FILES_TO_FETCH as $remoteUrl => $targetPath) {
            try {
                $content = $downloader->get($remoteUrl)->getBody();
            } catch (\Exception $e) {
                $io->writeError("<error>GridPHP: failed to fetch file from $remoteUrl - " . $e->getMessage() . "</error>");
                continue;
            }

            if ($content === null || trim($content) === '') {
                $io->writeError("<error>GridPHP: empty response returned from $remoteUrl</error>");
                continue;
            }

            $directory = dirname($targetPath);
            if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
                $io->writeError("<error>GridPHP: failed to create directory: $directory</error>");
                continue;
            }

            $bytes = @file_put_contents($targetPath, $content);
            if ($bytes === false || $bytes !== strlen($content)) {
                $io->writeError("<error>GridPHP: could not write file to $targetPath</error>");
                continue;
            }

            @chmod($targetPath, 0644);
        }

        $io->write('<info>GridPHP: core files fetched into vendor/gridphp/gridphp-community/lib/inc/.</info>');
    }

    /**
     * Publish vendor/gridphp/gridphp-community/lib/js -> public/gridphp/assets/ so JS/CSS assets
     * are web-servable while core PHP files remain private in vendor/. Safe to re-run; overwrites.
     */
    public static function publishAssets($event = null): void
    {
        $io = $event ? $event->getIO() : null;
        $vendorJs = self::VENDOR_LIB . '/js';

        if (!is_dir(self::VENDOR_LIB)) {
            self::log($io, '<comment>GridPHP: vendor/gridphp/gridphp-community/lib/ not found yet - nothing to publish. '
                . 'Grab the free archive from https://www.gridphp.com/download/ and extract its lib/ folder there once.</comment>');
            return;
        }

        if (!is_dir($vendorJs)) {
            self::log($io, '<comment>GridPHP: public/gridphp/assets not published - lib/js wasn\'t present in vendor/gridphp/gridphp-community/lib/ - '
                . 'the grid will load without its JS/CSS until you add it (see README).</comment>');
            return;
        }

        self::copyDir($vendorJs, self::PUBLIC_LIB);
        self::log($io, '<info>GridPHP: JS/CSS assets published to public/gridphp/assets/.</info>');
    }

    /** Copy env -> .env once, if .env doesn't already exist. Also copy sample DB. */
    public static function setupEnv(Event $event): void
    {
        $io = $event->getIO();
        
        // Copy sample database
        $dbSrc = __DIR__ . '/vendor/gridphp/gridphp-community/demos/sample-db/database.db';
        $dbDir = __DIR__ . '/writable/db';
        $dbDst = $dbDir . '/database.db';
        
        if (file_exists($dbSrc)) {
            if (!is_dir($dbDir)) {
                @mkdir($dbDir, 0775, true);
            }
            if (!file_exists($dbDst)) {
                copy($dbSrc, $dbDst);
                $io->write('<info>GridPHP: Copied sample database to writable/db/database.db.</info>');
            }
        }

        // Copy env file
        $src = __DIR__ . '/env';
        $dst = __DIR__ . '/.env';

        if (file_exists($dst)) {
            $io->write('<comment>GridPHP: .env already exists, leaving it untouched.</comment>');
            return;
        }
        if (!file_exists($src)) {
            $io->writeError('<error>GridPHP: env template not found, skipping.</error>');
            return;
        }
        copy($src, $dst);
        $io->write('<info>GridPHP: .env created from env template (SQLite demo defaults).</info>');

        // Set permissions on writable directory based on OS architecture
        self::setWritablePermissions($io);
    }

    private static function setWritablePermissions($io): void
    {
        $writableDir = __DIR__ . '/writable';
        if (!is_dir($writableDir)) {
            return;
        }

        $os = php_uname('s');
        $webUser = null;
        $webGroup = null;
        $platform = 'Unknown';

        if (stripos($os, 'Windows') !== false) {
            return; // Windows handles permissions differently, skip
        } elseif (stripos($os, 'Darwin') !== false) {
            $webUser = '_www';
            $webGroup = '_www';
            $platform = 'macOS';
        } elseif (stripos($os, 'Linux') !== false) {
            $osRelease = @file_get_contents('/etc/os-release') ?: '';
            if (preg_match('/(?:ID|ID_LIKE)="?.*(?:ubuntu|debian).*"?/i', $osRelease)) {
                $webUser = 'www-data';
                $webGroup = 'www-data';
                $platform = 'Ubuntu/Debian';
            } elseif (preg_match('/(?:ID|ID_LIKE)="?.*(?:centos|rhel|fedora|almalinux|rocky).*"?/i', $osRelease)) {
                $webUser = 'apache';
                $webGroup = 'apache';
                $platform = 'CentOS/RHEL/Fedora';
            } else {
                // Fallback for unknown Linux distros
                $webUser = 'www-data';
                $webGroup = 'www-data';
                $platform = 'Linux';
            }
        }

        if ($webUser && $webGroup) {
            $io->write("<info>GridPHP: $platform detected. Setting writable/ owner to $webUser:$webGroup and permissions to 775...</info>");
            
            $writablePath = escapeshellarg($writableDir);
            
            // Try natively first (works if already root/admin)
            exec("chown -R {$webUser}:{$webGroup} $writablePath 2>/dev/null", $out, $chownRes);
            exec("chmod -R 775 $writablePath 2>/dev/null", $out, $chmodRes);
            
            // Fallback to sudo if permissions are denied
            if ($chownRes !== 0 || $chmodRes !== 0) {
                $io->write("<comment>GridPHP: Insufficient permissions for chown/chmod. Requesting sudo access (you may be prompted for your password)...</comment>");
                passthru("sudo chown -R {$webUser}:{$webGroup} $writablePath");
                passthru("sudo chmod -R 775 $writablePath");
            }
        }
    }

    public static function showSuccessBanner(Event $event): void
    {
        $fg = "\033[37m";
        $bg = "\033[44m";
        $rs = "\033[0m";

        $blue = "\033[34m";
        $reset = "\033[0m";
        $projectName = basename(getcwd());
        $title = 'GridPHP + CodeIgniter 4 Starter installed successfully!';
        
        $width = strlen($title) + 8;
        $paddedTitle = str_pad(' ' . $title . ' ', $width, ' ', STR_PAD_BOTH);

        $lines = [
            "",
            "Start your local server using:",
            "",
            "1. cd {$projectName}",
            "2. php spark serve",
            "",
            "Then open http://localhost:8080/",
            "",
            "New to GridPHP? Check out our documentation.",
            "",
            "Build something amazing!",
        ];

        echo "\n " . $fg . $bg . $paddedTitle . $rs . "\n\n";
        echo " ┌ {$blue}Application ready{$reset} ───────────────────────────────────────────┐\n";
        foreach ($lines as $line) {
            $paddedLine = str_pad($line, 61);
            if (strpos($paddedLine, 'documentation') !== false) {
                // Add terminal hyperlink for "documentation" with blue and underline ANSI codes
                $paddedLine = str_replace(
                    'documentation',
                    "\033]8;;https://gridphp.com/docs\033\\\033[34;4mdocumentation\033[0m\033]8;;\033\\",
                    $paddedLine
                );
            }
            echo " │ " . $paddedLine . "│\n";
        }
        echo " └──────────────────────────────────────────────────────────────┘\n";
        echo "\n";
    }

    private static function copyDir(string $src, string $dst): void
    {
        if (!is_dir($dst)) {
            mkdir($dst, 0775, true);
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $target = $dst . DIRECTORY_SEPARATOR . $it->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0775, true);
                }
            } else {
                copy($item, $target);
            }
        }
    }

    private static function log($io, string $message): void
    {
        if ($io) {
            $io->write($message);
        } else {
            echo strip_tags($message) . "\n";
        }
    }
}
