<?php
/**
 * Extracts the fileserver's password file from a (possibly damaged) Level 3 fileserver / Filestore disk image
 *
 * Usage: php extract-passwords.php <path to disk image> [path to write the password file to]
 *
 * The file is written out exactly as it is on the disk.  Any part of it that was in a sector containing only
 * zero bytes is reported, as on an image taken from a failing disk that part has probably been lost.
*/

require_once(__DIR__.'/../src/L3fsReader.php');
require_once(__DIR__.'/../src/L3fsRecovery.php');

use HomeLan\Retro\Acorn\Disk\L3fsRecovery;

/**
 * Searches a directory tree for files with a given name, ignoring case
 *
 * @return array The paths of the files found, with the entry for each as the value
*/
function findFiles(array $aDirectory, string $sName, string $sPath='$'):array
{
	$aFound = [];
	foreach($aDirectory as $sEntryName=>$aEntry){
		if($aEntry['type']=='dir'){
			$aFound = $aFound + findFiles($aEntry['dir'],$sName,$sPath.'.'.$sEntryName);
		}elseif(strcasecmp($sEntryName,$sName)==0){
			$aFound[$sPath.'.'.$sEntryName] = $aEntry;
		}
	}
	return $aFound;
}

/**
 * Prints a hex dump, leaving out the lines that are all zero bytes
*/
function hexDump(string $sData)
{
	$bSkipping = false;
	foreach(str_split($sData,16) as $iLine=>$sLine){
		if(trim($sLine,"\0")==""){
			if(!$bSkipping){
				echo "  *\n";
			}
			$bSkipping = true;
			continue;
		}
		$bSkipping = false;
		$sHex = implode(' ',str_split(bin2hex($sLine),2));
		$sText = preg_replace('/[^\x20-\x7E]/','.',$sLine);
		printf("  %06X  %-47s  %s\n",$iLine*16,$sHex,$sText);
	}
}

/**
 * Reads a string that is ended early by a carriage return if it is shorter than the field holding it
*/
function readField(string $sField):string
{
	$iEnd = strpos($sField,"\r");
	if($iEnd!==false){
		$sField = substr($sField,0,$iEnd);
	}
	return preg_replace('/[^\x20-\x7E]/','?',rtrim($sField,"\0"));
}

/**
 * Prints the users held in the password file
 *
 * Each entry is 31 bytes: the user id (20 bytes), the password (6 bytes), the user's free space (4 bytes) and
 * a flag byte (bit 7 the entry is in use, bit 6 system privilege, bits 0-1 the boot option).
 * @param string $sData The password file
 * @param array $aZeroRanges The parts of the file that are probably lost
*/
function printUsers(string $sData, array $aZeroRanges)
{
	$iEntrySize = 31;
	$iLost = 0;
	$iFree = 0;
	printf("  %-6s %-20s %-8s %-10s %-6s %s\n",'Offset','User','Password','Free space','System','Boot option');
	for($iOffset=0;($iOffset+$iEntrySize)<=strlen($sData);$iOffset=$iOffset+$iEntrySize){
		$iOverlap = 0;
		foreach($aZeroRanges as $aRange){
			$iOverlap = $iOverlap+max(0,min($iOffset+$iEntrySize,$aRange['offset']+$aRange['length'])-max($iOffset,$aRange['offset']));
		}
		if($iOverlap==$iEntrySize){
			$iLost++;
			continue;
		}
		$sEntry = substr($sData,$iOffset,$iEntrySize);
		$iFlag = ord($sEntry[30]);
		if(($iFlag & 0x80)==0 AND $iOverlap==0){
			$iFree++;
			continue;
		}
		$sUser = readField(substr($sEntry,0,20));
		$sPassword = readField(substr($sEntry,20,6));
		$aFreeSpace = unpack('Vfree',substr($sEntry,26,4));
		printf("  &%04X  %-20s %-8s %10d %-6s %d%s\n",$iOffset,$sUser,$sPassword=='' ? '(none)' : $sPassword,$aFreeSpace['free'],($iFlag & 0x40) ? 'yes' : 'no',$iFlag & 0x03,$iOverlap>0 ? '  [partly lost, the first '.$iOverlap.' bytes are missing or zero]' : '');
	}
	echo "\n  ".$iFree." unused entries";
	if($iLost>0){
		echo ", ".$iLost." entries in the part of the file that is probably lost";
	}
	echo "\n";
}

if($argc<2 OR $argc>3){
	fwrite(STDERR,"Usage: php ".$argv[0]." <path to disk image> [path to write the password file to]\n");
	exit(2);
}
$sImagePath = $argv[1];
$sOutputPath = isset($argv[2]) ? $argv[2] : 'Passwords';

try {
	$oReader = new L3fsRecovery($sImagePath);
	$aCatalogue = $oReader->getCatalogue();

	//The password file lives in the root, but look everywhere in case the tree had to be pieced back together
	$aFound = findFiles($aCatalogue,'Passwords');
	if(count($aFound)==0){
		fwrite(STDERR,"No file named Passwords was found.\n");
		if(isset($aCatalogue[L3fsRecovery::LOST_AND_FOUND])){
			fwrite(STDERR,"The entry for it may have been lost, in which case it is one of the ".count($aCatalogue[L3fsRecovery::LOST_AND_FOUND]['dir'])." unnamed objects in \$.".L3fsRecovery::LOST_AND_FOUND."\n");
		}
		exit(1);
	}
	$sPath = isset($aFound['$.Passwords']) ? '$.Passwords' : array_keys($aFound)[0];
	if(count($aFound)>1){
		fwrite(STDERR,"More than one file named Passwords was found (".implode(', ',array_keys($aFound))."), using ".$sPath."\n");
	}
	$aEntry = $aFound[$sPath];

	echo "Found      ".$sPath." (SIN ".$aEntry['sector'].")\n";
	echo "Recovered  from ".$aEntry['recovery']['source'].", status ".$aEntry['recovery']['status']."\n";
	if($aEntry['recovery']['status']=='lost'){
		fwrite(STDERR,"The directory entry for the password file survives, but the map saying where its contents are does not, so it can not be extracted.\n");
		exit(1);
	}

	$sData = $oReader->getFile($sPath);
	echo "Size       ".strlen($sData)." bytes\n";
	$aZeroRanges = $oReader->getZeroRanges($sPath);
	foreach($aZeroRanges as $aRange){
		printf("Zero bytes offset &%04X to &%04X (%d bytes), probably a sector that could not be read\n",$aRange['offset'],$aRange['offset']+$aRange['length']-1,$aRange['length']);
	}

	if(file_put_contents($sOutputPath,$sData)===false){
		fwrite(STDERR,"Unable to write to ".$sOutputPath."\n");
		exit(1);
	}
	echo "Written to ".$sOutputPath."\n\nUsers\n";
	printUsers($sData,$aZeroRanges);
	echo "\nContents\n";
	hexDump($sData);
	exit(count($aZeroRanges)>0 ? 3 : 0);
}catch(Exception $oException){
	fwrite(STDERR,"Failed: ".$oException->getMessage()."\n");
	exit(1);
}
