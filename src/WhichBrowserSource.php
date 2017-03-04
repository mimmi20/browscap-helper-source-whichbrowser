<?php
/**
 * This file is part of the browscap-helper-source-whichbrowser package.
 *
 * Copyright (c) 2016-2017, Thomas Mueller <mimmi20@live.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);
namespace BrowscapHelper\Source;

use BrowserDetector\Loader\NotFoundException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;
use UaDataMapper\BrowserNameMapper;
use UaDataMapper\BrowserTypeMapper;
use UaDataMapper\BrowserVersionMapper;
use UaDataMapper\DeviceMarketingnameMapper;
use UaDataMapper\DeviceTypeMapper;
use UaDataMapper\EngineNameMapper;
use UaDataMapper\EngineVersionMapper;
use UaDataMapper\PlatformNameMapper;
use UaDataMapper\PlatformVersionMapper;
use UaResult\Browser\Browser;
use UaResult\Device\Device;
use UaResult\Engine\Engine;
use UaResult\Os\Os;
use UaResult\Result\Result;
use Wurfl\Request\GenericRequestFactory;

/**
 * Class DirectorySource
 *
 * @author  Thomas Mueller <mimmi20@live.de>
 */
class WhichBrowserSource implements SourceInterface
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger = null;

    /**
     * @var \Psr\Cache\CacheItemPoolInterface|null
     */
    private $cache = null;

    /**
     * @param \Psr\Log\LoggerInterface          $logger
     * @param \Psr\Cache\CacheItemPoolInterface $cache
     */
    public function __construct(LoggerInterface $logger, CacheItemPoolInterface $cache)
    {
        $this->logger = $logger;
        $this->cache  = $cache;
    }

    /**
     * @param int $limit
     *
     * @return string[]
     */
    public function getUserAgents($limit = 0)
    {
        $counter = 0;

        foreach ($this->loadFromPath() as $row) {
            if ($limit && $counter >= $limit) {
                return;
            }

            $row = json_decode($row, false);

            yield $row->{'User-Agent'};
            ++$counter;
        }
    }

    /**
     * @return \UaResult\Result\Result[]
     */
    public function getTests()
    {
        foreach ($this->loadFromPath() as $row) {
            $row     = json_decode($row, false);
            $agent   = $row->{'User-Agent'};

            $request     = (new GenericRequestFactory())->createRequestForUserAgent($agent);
            $browserName = (new BrowserNameMapper())->mapBrowserName($row->browser->name);

            if (empty($row->browser->version->value)) {
                $browserVersion = null;
            } else {
                $browserVersion = (new BrowserVersionMapper())->mapBrowserVersion($row->browser->version->value, $browserName);
            }

            if (!empty($row->browser->type)) {
                try {
                    $browserType = (new BrowserTypeMapper())->mapBrowserType($this->cache, $row->browser->type);
                } catch (NotFoundException $e) {
                    $this->logger->critical($e);
                    $browserType = null;
                }
            } else {
                $browserType = null;
            }

            $browser = new Browser(
                $browserName,
                null,
                $browserVersion,
                $browserType
            );

            try {
                $deviceType = (new DeviceTypeMapper())->mapDeviceType($this->cache, $row->device->type);
            } catch (NotFoundException $e) {
                $this->logger->critical($e);
                $deviceType = null;
            }

            $device = new Device(
                $row->device->model,
                (new DeviceMarketingnameMapper())->mapDeviceMarketingName($row->device->model),
                null,
                null,
                $deviceType
            );

            $platform = (new PlatformNameMapper())->mapOsName($row->os->name);

            if (empty($row->os->version->value)) {
                $platformVersion = null;
            } else {
                $platformVersion = (new PlatformVersionMapper())->mapOsVersion($row->os->version->value, $platform);
            }

            $os = new Os($platform, null, null, $platformVersion);

            if (empty($row->engine->version->value)) {
                $engineVersion = null;
            } else {
                $engineVersion = (new EngineVersionMapper())->mapEngineVersion($row->engine->version->value);
            }

            $engine = new Engine(
                (new EngineNameMapper())->mapEngineName($row->engine->name),
                null,
                $engineVersion
            );

            yield $agent => new Result($request, $device, $os, $browser, $engine);
        }
    }

    /**
     * @return array[]
     */
    private function loadFromPath()
    {
        $path = 'vendor/whichbrowser/parser/tests/data';

        if (!file_exists($path)) {
            return;
        }

        $this->logger->info('    reading path ' . $path);

        $allTests = [];
        $finder   = new Finder();
        $finder->files();
        $finder->name('*.yaml');
        $finder->ignoreDotFiles(true);
        $finder->ignoreVCS(true);
        $finder->sortByName();
        $finder->ignoreUnreadableDirs();
        $finder->in($path);

        foreach ($finder as $file) {
            /** @var \Symfony\Component\Finder\SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }

            if ('yaml' !== $file->getExtension()) {
                continue;
            }

            $filepath = $file->getPathname();

            $this->logger->info('    reading file ' . str_pad($filepath, 100, ' ', STR_PAD_RIGHT));
            $data = Yaml::parse(file_get_contents($filepath));

            if (!is_array($data)) {
                continue;
            }

            foreach ($data as $row) {
                $agent = $this->getAgentFromRow($row);

                if (empty($agent)) {
                    continue;
                }

                if (array_key_exists($agent, $allTests)) {
                    continue;
                }

                unset($row['headers']);
                $row['User-Agent'] = $agent;

                yield json_encode($row, JSON_FORCE_OBJECT);
                $allTests[$agent] = 1;
            }
        }
    }

    /**
     * @param array $row
     *
     * @return string
     */
    private function getAgentFromRow(array $row)
    {
        if (isset($row['headers']['User-Agent'])) {
            return $row['headers']['User-Agent'];
        }

        if (class_exists('\http\Header')) {
            // pecl_http versions 2.x/3.x
            $headers = \http\Header::parse($row['headers']);
        } elseif (function_exists('\http_parse_headers')) {
            // pecl_http version 1.x
            $headers = \http_parse_headers($row['headers']);
        } else {
            return '';
        }

        if (isset($headers['User-Agent'])) {
            return $headers['User-Agent'];
        }

        return '';
    }
}
