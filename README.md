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

```
$oAfs = new \HomeLan\Retro\Acorn\Disk\L3fsReader('l3_disk_image.img');
```

Once the L3fsReader object exists a few simple methods can be used to read data from it.

```
$oAfs->getCatalogue()
```

Gets the catalogue of what is on the disk *CAT

e.g. 
```
$aCatalogue = $oAfs->getCatalogue();
foreach($aCatalogue as $sDirectoy=>$aDir)
{
	echo $sDirectoy."\n==============\n";
	foreach($aDir as $sFileName=>$aEntryMetadata){
		echo $sFileName."  [".$aEntryMetadata['loadaddr'].' '.$aEntryMetadata['execaddr'].' '.$aEntryMetadata['size'].' '.$aEntryMetadata['startsector'].' '$aEntryMetadata['type']."\n";
		
	}
}


$oAfs->getFile('$.!BOOT');
```

The the contents of a give file 

e.g.
```
$sFileContents = $oAfs->getFile('$.!BOOT');


$oAfs->getStat('$.!BOOT');
```

Stats a file 

```
$oAfs->isFile('$.!BOOT');
```

Test if a given path is a file or not

e.g.
```
$bFile = $oAfs->isFile('$.!BOOT');
if($bFile){
	echo "!BOOT is file.\n" 
}

$oAfs->isDir('A');
```

Test if a given path is a file or not
```
$bDir = $oAfs->isDir('D');
if($bDir){
	echo "D is a dir.\n" 
}
```

L3fsRecovery Usage
------------------

The L3fsRecovery class extends L3fsReader, and is for disk images taken from failing disks where the sectors that could not be read contain only zero bytes.  Rather than starting at the root directory it reads every sector looking for metadata, and builds the best directory tree it can from what survives, even if the root directory has been lost.

```
$oAfs = new \HomeLan\Retro\Acorn\Disk\L3fsRecovery('damaged_disk_image.img');
$aCatalogue = $oAfs->getCatalogue();
$aReport = $oAfs->getRecoveryReport();
```

The catalogue has the same format as the one from L3fsReader, and getFile(), getStat(), isFile() and isDir() all work on the recovered tree.  Each entry has an extra 'recovery' key holding

* source - how the entry was found: 'entry' (the normal chain of directory entries), 'salvaged' (found in a slot of a damaged directory), 'parent-pointer' (a directory placed using the pointer it holds to its parent), 'orphan' (nothing says where it belongs) or 'fake' (a directory made up to hold other entries)
* status - 'ok', 'suspect' (a file with sectors that are all zero bytes, or a damaged map), 'damaged' (a directory with parts missing), 'lost' (the entry survives but the object does not) or 'fake'
* zero_sectors - the number of sectors of the object that contain only zero bytes

Objects that can not be placed in the tree are put in a directory named Lost+Found in the root, with names made from their SIN (e.g. file-14199).

getRecoveryReport() returns counts of what was found and a list of the problems met.  The on disk format, and the checks the recovery relies on, are described in docs/afs-disc-format.md

Tests
-----

```
make test
```

Runs the phpunit tests in unit-tests/, which build small synthetic disk images to test against.
