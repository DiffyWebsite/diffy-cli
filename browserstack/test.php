<?php

include 'BrowserStack.php';

$username = 'sergeygrigorenko1';
$password = 'bfsqmbvQQBkLCYfgzzwY';

$browserStack = new BrowserStack($username, $password);


//$browsers = $browserStack->getBrowsers();

//$jobId = $browserStack->createScreenshot("https://grigorenko-geo.ru/ob-avtorah");

$browserStack->getListOfScreenshots('951ab738d5f2c753ecfd75462cc11e4f168ae7cb');
