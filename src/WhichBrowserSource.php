<?php

namespace BrowscapHelper\Source;

use Monolog\Logger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Class DirectorySource
 *
 * @author  Thomas Mueller <mimmi20@live.de>
 */
class WhichBrowserSource implements SourceInterface
{
    /**
     * @param \Monolog\Logger                                   $logger
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param int                                               $limit
     *
     * @return \Generator
     */
    public function getUserAgents(Logger $logger, OutputInterface $output, $limit = 0)
    {
        $allAgents = [];

        foreach ($this->loadFromPath($output) as $dataFile) {
            if ($limit && count($allAgents) >= $limit) {
                break;
            }

            $agentsFromFile = $this->mapWhichbrowser($dataFile);

            $output->writeln(' [added ' . str_pad(number_format(count($allAgents)), 12, ' ', STR_PAD_LEFT) . ' agent' . (count($allAgents) !== 1 ? 's' : '') . ' so far]');

            $newAgents = array_diff($agentsFromFile, $allAgents);
            $allAgents = array_merge($allAgents, $newAgents);
        }

        $i = 0;
        foreach ($allAgents as $agent) {
            if ($limit && $i >= $limit) {
                return null;
            }

            ++$i;
            yield $agent;
        }
    }

    /**
     * @param \Monolog\Logger                                   $logger
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return \Generator
     */
    public function getTests(Logger $logger, OutputInterface $output)
    {
        $allTests = [];

        foreach ($this->loadFromPath($output) as $dataFile) {
            $agentsFromFile = $this->mapWhichbrowser($dataFile);

            foreach ($agentsFromFile as $ua) {
                if (array_key_exists($ua, $allTests)) {
                    continue;
                }

                $allTests[$ua] = [
                    'ua'         => $ua,
                    'properties' => [
                        'Browser_Name'            => null,
                        'Browser_Type'            => null,
                        'Browser_Bits'            => null,
                        'Browser_Maker'           => null,
                        'Browser_Modus'           => null,
                        'Browser_Version'         => null,
                        'Platform_Codename'       => null,
                        'Platform_Marketingname'  => null,
                        'Platform_Version'        => null,
                        'Platform_Bits'           => null,
                        'Platform_Maker'          => null,
                        'Platform_Brand_Name'     => null,
                        'Device_Name'             => null,
                        'Device_Maker'            => null,
                        'Device_Type'             => null,
                        'Device_Pointing_Method'  => null,
                        'Device_Dual_Orientation' => null,
                        'Device_Code_Name'        => null,
                        'Device_Brand_Name'       => null,
                        'RenderingEngine_Name'    => null,
                        'RenderingEngine_Version' => null,
                        'RenderingEngine_Maker'   => null,
                    ],
                ];
            }
        }

        $i = 0;
        foreach ($allTests as $ua => $test) {
            ++$i;
            yield [$ua => $test];
        }
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return \Generator
     */
    private function loadFromPath(OutputInterface $output = null)
    {
        $path = 'vendor/whichbrowser/parser/tests/data';

        if (!file_exists($path)) {
            return;
        }

        $output->writeln('    reading path ' . $path);

        $iterator = new \RecursiveDirectoryIterator($path);

        foreach (new \RecursiveIteratorIterator($iterator) as $file) {
            /** @var $file \SplFileInfo */
            if (!$file->isFile()) {
                continue;
            }

            $filepath = $file->getPathname();

            $output->write('    reading file ' . str_pad($filepath, 100, ' ', STR_PAD_RIGHT), false);
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
     * @param array $data
     *
     * @return array
     */
    private function mapWhichbrowser(array $data)
    {
        $allData = [];

        foreach ($data as $row) {
            if (!isset($row['headers']['User-Agent'])) {
                $headers = http_parse_headers($row['headers']);

                if (! isset($headers['User-Agent'])) {
                    continue;
                }

                $allData[] = $headers['User-Agent'];
                continue;
            }

            $allData[] = $row['headers']['User-Agent'];
        }

        return $allData;
    }
}
