<?php
/**
 * Bootstrap for the unit tests
 *
 * Uses the composer autoloader if the dependencies have been installed, otherwise loads the classes directly
 * so the tests can also be run with a system wide phpunit.
*/

if(file_exists(__DIR__.'/../vendor/autoload.php')){
	require_once(__DIR__.'/../vendor/autoload.php');
}
if(!class_exists('HomeLan\Retro\Acorn\Disk\L3fsReader')){
	require_once(__DIR__.'/../src/L3fsReader.php');
}
if(!class_exists('HomeLan\Retro\Acorn\Disk\L3fsRecovery')){
	require_once(__DIR__.'/../src/L3fsRecovery.php');
}
if(!class_exists('HomeLan\Retro\Acorn\Disk\L3fsTarExporter')){
	require_once(__DIR__.'/../src/L3fsTarExporter.php');
}
if(!class_exists('HomeLan\Retro\Acorn\Disk\Tests\AfsImageBuilder')){
	require_once(__DIR__.'/AfsImageBuilder.php');
}
