<?php

namespace DiffyCli\Commands;

use Diffy\Diff;
use Diffy\Diffy;
use Diffy\InvalidArgumentsException;
use Diffy\Project;
use Diffy\Screenshot;
use DiffyCli\Config;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Utils;
use Robo\ResultData;
use Robo\Tasks;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

class ProjectCommand extends Tasks
{
    /** @var SymfonyStyle */
    protected $io;

    /**
     * Verify the input JSON config
     *
     * @param string $configurationPath Path to the json config file.
     *
     * @throws InvalidArgumentException
     *
     * @return array
     */
    private function isValidJsonConfig(string $configurationPath): array
    {
        Diffy::setApiKey(Config::getConfig()['key']);

        $configuration = file_get_contents($configurationPath);

        if (!$configuration) {
            $this->getIO()->writeln(sprintf('Configuration not found on path : %s', $configurationPath));

            throw new InvalidArgumentException();
        }

        try {
            if (str_ends_with($configurationPath, '.yaml')) {
                return Yaml::parse($configuration, true);
            }

            return Utils::jsonDecode($configuration, true);
        } catch (InvalidArgumentException $exception) {
            $this->getIO()->writeln('<error>File can not be parsed.<error>');

            throw $exception;
        }
    }

    /**
     * Compare environments
     *
     * @command project:compare
     *
     * @param int    $projectId ID of the project
     * @param string $env1      First environment to compare
     * @param string $env2      Second environment to compare
     * @param array  $options
     *
     * @throws InvalidArgumentsException
     *
     * @option env1Url Url of the first environment if custom environment
     * @option env1User Basic auth user for env1Url
     * @option env1Pass Basic auth password for env1Url
     * @option env2Url Url of the second environment if custom environment
     * @option env2User Basic auth user for env2Url
     * @option env2Pass Basic auth password for env2Url
     * @option wait Wait for the diff to be completed
     * @option max-wait Maximum number of seconds to wait for the diff to be completed.
     * @option commit-sha GitHub commit SHA.
     * @option screenshot1 First existing screenshot
     * @option screenshot2 Second existing screenshot
     *
     * @usage project:compare 342 prod stage
     *   Compare production and stage environments.
     * @usage project:compare --wait 342 prod custom --env2Url="https://custom-environment.example.com"  --name="custom-environment"
     *   Compare production environment with https://custom-environment.example.com and set diff name "custom-environment"
     * @usage project:compare custom custom --env1Url="http://site.com" --env2Url="http://site2.com" --commit-sha="29b872765b21387b7adfd67fd16b7f11942e1a56"
     *   Compare http://site.com with http://site2.com with github check by commit-sha.
     */
    public function createCompare(
        int $projectId,
        string $env1,
        string $env2,
        array $options = [
            'wait' => false, 'max-wait' => 1200, 'commit-sha' => null, 'env1Url' => '', 'env1User' => null,
            'env1Pass' => null, 'env2Url' => '', 'env2User' => null, 'env2Pass' => null, 'name' => '',
            'screenshot1' => null, 'screenshot2' => null,
        ]
    ) {
        Diffy::setApiKey(Config::getConfig()['key']);

        $params = [
            'env1' => $env1,
            'env2' => $env2,
            'env1Url' => $options['env1Url'],
            'env1User' => $options['env1User'],
            'env1Pass' => $options['env1Pass'],
            'env2Url' => $options['env2Url'],
            'env2User' => $options['env2User'],
            'env2Pass' => $options['env2Pass']
        ];

        if (!empty($options['commit-sha']) && $options['commit-sha']) {
            $params['commitSha'] = $options['commit-sha'];
        }

        if ($params['env1'] === 'production') {
            $params['env1'] = 'prod';
        } elseif ($params['env1'] === 'development') {
            $params['env1'] = 'dev';
        } elseif ($params['env1'] === 'staging') {
            $params['env1'] = 'stage';
        }

        if ($params['env2'] === 'production') {
            $params['env2'] = 'prod';
        } elseif ($params['env2'] === 'development') {
            $params['env2'] = 'dev';
        } elseif ($params['env2'] === 'staging') {
            $params['env2'] = 'stage';
        }

        if ($env1 === 'existing' || $env2 === 'existing') {
            if ($env1 === 'existing' && empty($options['screenshot1'])) {
                throw new InvalidArgumentsException('Set screenshot1 value');
            } elseif ($env2 === 'existing' && empty($options['screenshot2'])) {
                throw new InvalidArgumentsException('Set screenshot2 value');
            }

            if ($env1 === 'existing') {
                $screenshot1 = $options['screenshot1'];
            } else {
                $screenshot1 = Screenshot::create($projectId, $env1);
            }

            if ($env2 === 'existing') {
                $screenshot2 = $options['screenshot2'];
            } else {
                $screenshot2 = Screenshot::create($projectId, $env2);
            }

            $diffId = Diff::create($projectId, $screenshot1, $screenshot2, $options['name']);
        } else {
            $diffId = Project::compare($projectId, $params);
        }

        if (!empty($options['name'])) {
            Diff::updateName($diffId, $options['name']);
        }

        if (!empty($options['wait']) && $options['wait'] == true) {
            $sleep = 10;
            sleep($sleep);
            $i = 0;
            $diff = Diff::retrieve($diffId);
            while ($i < (int)$options['max-wait'] / $sleep) {
                if ($diff->isCompleted()) {
                    break;
                }
                sleep($sleep);
                $diff->refresh();

                $i += $sleep;
            }
        }

        $this->getIO()->writeln($diffId);

        // Successful exit.
        return new ResultData();
    }

    /**
     * Update single project configuration from YAML file
     *
     * @command project:update
     *
     * @param int    $projectId         Id of the project.
     * @param string $configurationPath Path to the YAML config file.
     *
     * @usage project:update 342 ./examples/diffy_update_project.yaml
     *   Configuration can be downloaded from Project's settings page.
     *
     * @throws InvalidArgumentException
     */
    public function updateProject(int $projectId, string $configurationPath)
    {
        $data = $this->isValidJsonConfig($configurationPath);
        if (empty($data)) {
            return new ResultData(ResultData::EXITCODE_ERROR);
        }
        if (str_ends_with($configurationPath, '.yaml')) {
            Project::updateYaml($projectId, $configurationPath);
        } else {
            Project::update($projectId, $this->isValidJsonConfig($configurationPath));
        }

        $this->getIO()->writeln('Project <info>' . $projectId . '</info> updated.');

        // Successful exit.
        return new ResultData();
    }

    /**
     * Update multiple projects configurations by one json config file
     *
     * @command projects:update
     *
     * @param string $configurationPath Path to the json config file.
     *
     * @usage projects:update ./examples/diffy_update_projects.json
     *   Updates given projects ID with the diffy config.
     *
     * @throws InvalidArgumentException
     */
    public function updateProjects(string $configurationPath)
    {
        $configuration = $this->isValidJsonConfig($configurationPath);

        foreach ($configuration as $projectId => $projectConfig) {
            if (is_array($projectConfig)) {
                Project::update($projectId, $projectConfig);

                $this->getIO()->writeln('Project <info>' . $projectId . '</info> updated.');
            }
        }

        // Successful exit.
        return new ResultData();
    }

    /**
     * Create project
     *
     * @command project:create
     *
     * @param string $configurationPath Path to the json config file.
     *
     * @usage project:create ./examples/diffy_update_project.json
     *   Create a project with the diffy config.
     *
     * @throws InvalidArgumentException
     */
    public function createProject(string $configurationPath)
    {
        $configuration = $this->isValidJsonConfig($configurationPath);

        // Multiple projects (detect with mandatory data 'urls').
        if (!empty($configuration[0]) && is_array($configuration[0]) && !empty($configuration[0]['urls'])) {
            foreach ($configuration as $project_config) {
                $project_id = Project::createFromData($project_config);
                $this->getIO()->writeln('[<info>' . $project_id . '</info>] <comment>' . $project_config['name'] . '</comment> created.');
            }
        } else {
            // Single project.
            $project_id = Project::createFromData($configuration);
            $this->getIO()->writeln('[<info>' . $project_id . '</info>] <comment>' . $configuration['name'] . '</comment> created.');
        }

        // Successful exit.
        return new ResultData();
    }

    /**
     * Get project settings
     *
     * @command project:get
     *
     * @param int $projectId Id of the project.
     *
     * @usage project:get 342
     *   Gets the settings of the project 342.
     *
     * @throws InvalidArgumentException
     */
    public function getProject(int $projectId)
    {
        Diffy::setApiKey(Config::getConfig()['key']);

        $project = Project::get($projectId);

        $this->getIO()->writeln(json_encode($project));

        // Successful exit.
        return new ResultData();
    }

    protected function getIO(): SymfonyStyle
    {
        if (!$this->io) {
            $this->io = new SymfonyStyle($this->input(), $this->output());
        }

        return $this->io;
    }
}
