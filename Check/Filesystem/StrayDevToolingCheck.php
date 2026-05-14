<?php

/**
 * IronCart_Scan — IC-034: stray developer tooling under `pub/`.
 *
 * `pub/` is the public webroot. Anything in it is fetchable over HTTP. Files
 * like `pub/profiler.php`, a checked-in `.git` directory, or `composer.json`
 * leaking dependency info are classic Magento misconfigurations that have
 * been used in real-world breaches.
 *
 * v0 looks for a curated list of high-signal artefacts; later versions may
 * extend with operator-supplied globs.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Filesystem;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Report\Severity;

/**
 * IC-034: flag stray dev artefacts that have leaked under the webroot.
 */
class StrayDevToolingCheck implements CheckInterface
{
    public const ID = 'IC-034';

    private const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-034';

    /**
     * Relative paths (under the project root) that should never be reachable
     * over HTTP. Mix of files and directories.
     *
     * @var list<array{path:string,kind:string,title:string}>
     */
    private const SUSPECTS = [
        ['path' => 'pub/profiler.php',  'kind' => 'file', 'title' => 'Magento profiler entrypoint exposed under pub/'],
        ['path' => 'pub/phpinfo.php',   'kind' => 'file', 'title' => 'phpinfo() entrypoint exposed under pub/'],
        ['path' => 'pub/info.php',      'kind' => 'file', 'title' => 'phpinfo() entrypoint exposed under pub/'],
        ['path' => 'pub/test.php',      'kind' => 'file', 'title' => 'Test entrypoint exposed under pub/'],
        ['path' => 'pub/adminer.php',   'kind' => 'file', 'title' => 'Adminer DB tool exposed under pub/'],
        ['path' => 'pub/composer.json', 'kind' => 'file', 'title' => 'composer.json leaked into pub/ webroot'],
        ['path' => 'pub/composer.lock', 'kind' => 'file', 'title' => 'composer.lock leaked into pub/ webroot'],
        ['path' => 'pub/.env',          'kind' => 'file', 'title' => '.env file leaked into pub/ webroot'],
        ['path' => 'pub/.git',          'kind' => 'dir',  'title' => '.git directory exposed under pub/'],
        ['path' => 'pub/.svn',          'kind' => 'dir',  'title' => '.svn directory exposed under pub/'],
        ['path' => 'pub/.hg',           'kind' => 'dir',  'title' => '.hg directory exposed under pub/'],
    ];

    public function __construct(private readonly MagentoRoot $root)
    {
    }

    /**
     * @inheritDoc
     */
    public function run(): array
    {
        $findings = [];

        foreach (self::SUSPECTS as $suspect) {
            $absolute = $this->root->join($suspect['path']);
            $exists = $suspect['kind'] === 'dir'
                ? is_dir($absolute)
                : is_file($absolute);

            if (!$exists) {
                continue;
            }

            $findings[] = [
                'id' => self::ID,
                'title' => $suspect['title'],
                'severity' => Severity::HIGH,
                'evidence' => [
                    'path' => $absolute,
                    'kind' => $suspect['kind'],
                ],
                'remediation_url' => self::REMEDIATION_URL,
            ];
        }

        return $findings;
    }
}
