<?php
/**
 * Extracts every file from a (possibly damaged) Level 3 fileserver / Filestore disk image into a tar archive
 *
 * Usage: php extract-to-tar.php <path to disk image> [path of the tar file to write] [directory inside the archive]
 *
 * The archive uses the layout of the LocalFile plugin of aun-filestore (files with .inf sidecar files holding the
 * load and exec addresses), so it can be unpacked into the directory that plugin serves.  The recovery class is used
 * to read the image, so objects that could not be placed in the tree are in a Lost+Found directory.
*/

require_once(__DIR__.'/../src/L3fsReader.php');
require_once(__DIR__.'/../src/L3fsRecovery.php');
require_once(__DIR__.'/../src/L3fsTarExporter.php');

use HomeLan\Retro\Acorn\Disk\L3fsRecovery;
use HomeLan\Retro\Acorn\Disk\L3fsTarExporter;

if($argc<2 OR $argc>4){
	fwrite(STDERR,"Usage: php ".$argv[0]." <path to disk image> [path of the tar file to write] [directory inside the archive]\n");
	exit(2);
}
$sImagePath = $argv[1];
$sTarPath = isset($argv[2]) ? $argv[2] : preg_replace('/\.[^.\/]*$/','',basename($sImagePath)).'.tar';
$sPrefix = isset($argv[3]) ? $argv[3] : '';

try {
	$oReader = new L3fsRecovery($sImagePath);
	$oExporter = new L3fsTarExporter($oReader);
	$aResult = $oExporter->export($sTarPath,$sPrefix);
	$aReport = $oReader->getRecoveryReport();
}catch(Exception $oException){
	fwrite(STDERR,"Failed: ".$oException->getMessage()."\n");
	exit(1);
}

echo "Written ".$sTarPath."\n";
printf("  %d files (%d bytes) in %d directories\n",$aResult['files'],$aResult['bytes'],$aResult['directories']);
printf("  %d entries salvaged from damaged directories, %d directories placed by their parent pointer, %d objects in Lost+Found\n",$aReport['salvaged_entries'],$aReport['parent_pointer_directories'],$aReport['orphans']);

if(count($aReport['problems'])>0){
	echo "\nProblems with the image\n";
	foreach($aReport['problems'] as $sProblem){
		echo "  ".$sProblem."\n";
	}
}
if(count($aResult['skipped'])>0){
	echo "\nNot written to the archive\n";
	foreach($aResult['skipped'] as $aSkipped){
		echo "  ".$aSkipped['path']." (".$aSkipped['reason'].")\n";
	}
}
if(count($aResult['suspect'])>0){
	echo "\nFiles that may have parts missing (they contain sectors that are all zero bytes, or have a damaged map)\n";
	foreach($aResult['suspect'] as $sPath){
		echo "  ".$sPath."\n";
	}
}
exit((count($aResult['skipped'])>0 OR count($aResult['suspect'])>0 OR count($aReport['problems'])>0) ? 3 : 0);
