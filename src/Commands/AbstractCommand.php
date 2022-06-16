<?php

namespace DiffyCli\Commands;

use Robo\Common\ConfigAwareTrait;
use Robo\Tasks;

class AbstractCommand extends Tasks
{
    use ConfigAwareTrait;
}
