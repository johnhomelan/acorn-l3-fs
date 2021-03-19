Acorn Disk
=========================

This package allows the reading of AFS disk images for the Econet Level 3 fileserver and Econet Filestores.  

Features
--------

* PSR-4 autoloading compliant structure
* Disk Image must be read from a file


Install
-------

composer requre homelan/L3fsReader

Overview
--------
The L3fsReader class, allows files and metadata to be read from a AFS disk image stored as a raw dump.  AFS filing systems are like modern filing systems in that there is a hierarchical directory structure, with '$' being the root of the fs.   

L3fsReader Usage
---------------

Creating a L3fsReader Object to directly open a file on disk

$oAfs = new \HomeLan\Retro\Acorn\Disk\L3fsReader('l3_disk_image.img');

Once the L3fsReader object exists a few simple methods can be used to read data from it.

$oAdfs->getCatalogue()

Gets the catalogue of what is on the disk *CAT

e.g. 

$aCatalogue = $oAdfs->getCatalogue();
foreach($aCatalogue as $sDirectoy=>$aDir)
{
	echo $sDirectoy."\n==============\n";
	foreach($aDir as $sFileName=>$aEntryMetadata){
		echo $sFileName."  [".$aEntryMetadata['loadaddr'].' '.$aEntryMetadata['execaddr'].' '.$aEntryMetadata['size'].' '.$aEntryMetadata['startsector'].' '$aEntryMetadata['type']."\n";
		
	}
}


$oAfs->getFile('$.!BOOT');

The the contents of a give file 

e.g.

$sFileContents = $oAdfs->getFile('$.!BOOT');


$oAdfs->getStat('$.!BOOT');

Stats a file 


$oAfs->isFile('$.!BOOT');

Test if a given path is a file or not

e.g.

$bFile = $oAfs->isFile('$.!BOOT');
if($bFile){
	echo "!BOOT is file.\n" 
}

$oAfs->isDir('A');

Test if a given path is a file or not

$bDir = $oAfs->isDir('D');
if($bDir){
	echo "D is a dir.\n" 
}
