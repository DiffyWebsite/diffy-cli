<?php

namespace DiffyCli\Cli;

class ExampleCommands extends \Robo\Tasks
{
    /**
     * Multiply two numbers together
     *
     * @command multiply
     */
    public function multiply($a, $b)
    {
        $model = new \DiffyCli\Example($a);
        $result = $model->multiply($b);

        $this->io()->text("$a times $b is $result");
    }
}
