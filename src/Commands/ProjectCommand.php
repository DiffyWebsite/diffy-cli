<?php

namespace DiffyCli\Commands;

use Diffy\Diff;
use Diffy\Diffy;
use Diffy\Project;
use DiffyCli\Config;
use GuzzleHttp\Exception\InvalidArgumentException;
use Symfony\Component\Console\Style\SymfonyStyle;
use function GuzzleHttp\json_decode;

class ProjectCommand extends \Robo\Tasks
{
    /**
     * Verify the inpout Json config.
     *
     * @param string $configurationPath Path to the json config file.
     * @throws \GuzzleHttp\Exception\InvalidArgumentException
     * @return string
     */
    private function isValidJsonConfig(
        string $configurationPath
    ) {
        $io = new SymfonyStyle($this->input(), $this->output());
        $apiKey = Config::getConfig()['key'];
        Diffy::setApiKey($apiKey);

        $configuration = file_get_contents($configurationPath);

        if (!$configuration) {
            $io->writeln(sprintf('Configuration not found on path : %s', $configurationPath));
            throw new InvalidArgumentException();
        }

        try {
            $configuration = json_decode($configuration, true);
        } catch (InvalidArgumentException $exception) {
            $io->writeln('<error>Configuration is not valid JSON<error>');
            throw $exception;
        }
        
        return $configuration;
    }

    /**
     * Compare environments.
     *
     * @command project:compare
     *
     * @param int $projectId ID of the project
     * @param string $env1 First environment to compare
     * @param string $env2 Second environment to compare
     *
     * @param array $options
     * @throws \Diffy\InvalidArgumentsException
     * @option env1Url Url of the first environment if custom environment
     * @option env2Url Url of the second environment if custom environment
     * @option wait Wait for the diff to be completed
     * @option max-wait Maximum number of seconds to wait for the diff to be completed.
     * @option commit-sha Github commit SHA.
     *
     * @usage project:compare 342 prod stage
     *   Compare production and stage environments.
     * @usage project:compare --wait 342 prod custom --env2Url="https://custom-environment.example.com"
     *   Compare production environment with https://custom-environment.example.com.
     * @usage project:compare custom custom --env1Url="http://site.com" --env2Url="http://site2.com" --commit-sha="29b872765b21387b7adfd67fd16b7f11942e1a56"
     *   Compare http://site.com withhttp://site2.com with github check by commit-sha.
     */
    public function createCompare(
        int $projectId,
        string $env1,
        string $env2,
        array $options = ['wait' => false, 'max-wait' => 1200, 'env1Url' => '', 'env2Url' => '', 'commit-sha' => null]
    ) {
        $io = new SymfonyStyle($this->input(), $this->output());
        $apiKey = Config::getConfig()['key'];

        $params = [
            'env1' => $env1,
            'env2' => $env2,
            'env1Url' => $options['env1Url'],
            'env2Url' => $options['env2Url'],
        ];

        if (!empty($options['commit-sha']) && $options['commit-sha']) {
            $params['commitSha'] = $options['commit-sha'];
        }

        Diffy::setApiKey($apiKey);
        $diffId = Project::compare($projectId, $params);

        if (!empty($options['wait']) && $options['wait'] == true) {
            $sleep = 10;
            $max_wait = (int) $options['max-wait'];
            sleep($sleep);
            $i = 0;
            $diff = Diff::retrieve($diffId);
            while ($i < $max_wait / $sleep) {
                if ($diff->isCompleted()) {
                    break;
                }
                sleep($sleep);
                $diff->refresh();

                $i += $sleep;
            }
        }

        $io->writeln($diffId);
    }

    /**
     * Update single project configuration
     *
     * @command project:update
     *
     * @param int $projectId Id of the project.
     * @param string $configurationPath Path to the json config file.
     *
     * @usage project:update 342 ./examples/diffy_update_project.json
     *   Updates given project ID with the diffy config.
     *
     * @throws \GuzzleHttp\Exception\InvalidArgumentException
     */
    public function updateProject(
        int $projectId,
        string $configurationPath
    ) {
        $configuration = $this->isValidJsonConfig($configurationPath);
        
        Project::update($projectId, $configuration);
        $io->writeln('Project <info>' . $projectId . '</info> updated.' );
    }

    /**
     * Update multiple projects configurations by one json config file.
     *
     * @command projects:update
     *
     * @param string $configurationPath Path to the json config file.
     *
     * @usage projects:update ./examples/diffy_update_projects.json
     *   Updates given projects ID with the diffy config.
     *
     * @throws \GuzzleHttp\Exception\InvalidArgumentException
     */
    public function updateProjects(
        string $configurationPath
    ) {
        $configuration = $this->isValidJsonConfig($configurationPath);

        if(!empty($configuration) && is_array($configuration)) {
            foreach($configuration as $projectId => $project_config) {
                Project::update($projectId, $project_config);
                $io->writeln('Project <info>' . $projectId . '</info> updated.' );
            }
        }
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
     * @throws \GuzzleHttp\Exception\InvalidArgumentException
     */
    public function createProject(
        string $configurationPath
    ) {
        $configuration = $this->isValidJsonConfig($configurationPath);

        // Multiple projects (detect with mandatory data 'urls').
        if(!empty($configuration[0]) && is_array($configuration[0]) && !empty($configuration[0]['urls'])) {
            foreach($configuration as $project_config) {
                $project_id = Project::createFromData($project_config);
                $io->writeln('[<info>' . $project_id . '</info>] <comment>'. $project_config['name'] . '</comment> created.');
            }
        }
        else {
            // Single project.
            $project_id = Project::createFromData($configuration);
            $io->writeln('[<info>' . $project_id . '</info>] <comment>'. $project_config['name'] . '</comment> created.');
        }
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
     * @throws \GuzzleHttp\Exception\InvalidArgumentException
     */
    public function getProject(
        int $projectId
    ) {
        $io = new SymfonyStyle($this->input(), $this->output());
        $apiKey = Config::getConfig()['key'];
        Diffy::setApiKey($apiKey);

        $project = Project::get($projectId);

        $io->writeln(json_encode($project));
    }
}
