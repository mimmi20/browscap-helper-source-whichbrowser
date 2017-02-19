<?php

namespace BrowscapHelper\Source;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
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
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    private $output = null;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger = null;

    /**
     * @param \Psr\Log\LoggerInterface                          $logger
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    public function __construct(LoggerInterface $logger, OutputInterface $output)
    {
        $this->logger = $logger;
        $this->output = $output;
    }

    /**
     * @param int $limit
     *
     * @return string[]
     */
    public function getUserAgents($limit = 0)
    {
        $counter   = 0;
        $allAgents = [];

        foreach ($this->loadFromPath() as $data) {
            if ($limit && $counter >= $limit) {
                return;
            }

            foreach ($data as $row) {
                if ($limit && $counter >= $limit) {
                    return;
                }

                $agent = $this->getAgentFromRow($row);

                if (empty($agent)) {
                    continue;
                }

                if (array_key_exists($agent, $allAgents)) {
                    continue;
                }

                yield $agent;
                $allAgents[$agent] = 1;
                ++$counter;
            }
        }
    }

    /**
     * @return \UaResult\Result\Result[]
     */
    public function getTests()
    {
        $allTests = [];

        foreach ($this->loadFromPath() as $data) {
            foreach ($data as $row) {
                $agent = $this->getAgentFromRow($row);

                if (empty($agent)) {
                    continue;
                }

                if (array_key_exists($agent, $allTests)) {
                    continue;
                }

                $request  = (new GenericRequestFactory())->createRequestForUserAgent($agent);
                $browser  = new Browser(null);
                $device   = new Device(null, null);
                $platform = new Os(null, null);
                $engine   = new Engine(null);

                yield $agent => new Result($request, $device, $platform, $browser, $engine);
                $allTests[$agent] = 1;
            }
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

        $this->output->writeln('    reading path ' . $path);

        $iterator = new \RecursiveDirectoryIterator($path);

        foreach (new \RecursiveIteratorIterator($iterator) as $file) {
            /** @var $file \SplFileInfo */
            if (!$file->isFile()) {
                continue;
            }

            $filepath = $file->getPathname();

            $this->output->writeln('    reading file ' . str_pad($filepath, 100, ' ', STR_PAD_RIGHT));
            switch ($file->getExtension()) {
                case 'yaml':
                    yield Yaml::parse(file_get_contents($filepath));
                    break;
                default:
                    // do nothing here
                    break;
            }
        }
    }

    /**
     * @param array $row
     *
     * @return string|null
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
            return null;
        }

        if (isset($headers['User-Agent'])) {
            return $headers['User-Agent'];
        }

        return null;
    }
}
