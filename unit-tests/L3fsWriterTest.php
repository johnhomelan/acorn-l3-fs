<?php
/**
 * Tests for the Level 3 fileserver / Filestore disk image writer
 *
 * All the tests run against small synthetic images made by AfsImageBuilder.
*/

namespace HomeLan\Retro\Acorn\Disk\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use HomeLan\Retro\Acorn\Disk\L3fsReader;
use HomeLan\Retro\Acorn\Disk\L3fsRecovery;
use HomeLan\Retro\Acorn\Disk\L3fsWriter;

class L3fsWriterTest extends TestCase {

	const CLEAN = ['in_use_but_free'=>[],'allocated_but_unused'=>[],'cross_linked'=>[]];

	private $aImages = [];

	protected function tearDown(): void
	{
		foreach($this->aImages as $sPath){
			if(file_exists($sPath)){
				unlink($sPath);
			}
		}
		$this->aImages = [];
	}

	/**
	 * Writes the image for a builder to a temporary file that is removed at the end of the test
	*/
	private function _write(AfsImageBuilder $oBuilder): string
	{
		$sPath = $oBuilder->write();
		$this->aImages[] = $sPath;
		return $sPath;
	}

	/**
	 * An image holding a small tree used by most of the tests
	*/
	private function _standardImage(int $iSectorsPerCylinder=16, int $iCylinders=8): string
	{
		$oBuilder = new AfsImageBuilder($iSectorsPerCylinder,$iCylinders);
		$oBuilder->addFile('$.!Boot',"*EXEC Menu\r",0x00000000,0xFFFFFFFF,AfsImageBuilder::ACCESS_LOCKED | AfsImageBuilder::ACCESS_OWNER_READ | AfsImageBuilder::ACCESS_PUBLIC_READ);
		$oBuilder->addDirectory('$.Library');
		$oBuilder->addFile('$.Library.Fred',str_repeat("F",300),0xFFFF1900,0xFFFF8023);
		$oBuilder->addFile('$.Passwords',str_repeat("P",256),0,0,AfsImageBuilder::ACCESS_OWNER_WRITE | AfsImageBuilder::ACCESS_OWNER_READ);
		return $this->_write($oBuilder);
	}

	/**
	 * Some bytes that differ in every sector, so a file assembled in the wrong order is detected
	*/
	private function _pattern(int $iLength): string
	{
		$sData = "";
		for($i=0;strlen($sData)<$iLength;$i++){
			$sData .= str_pad("[sector ".$i."]",AfsImageBuilder::SECTOR_SIZE,chr(65+($i % 26)));
		}
		return substr($sData,0,$iLength);
	}

	/**
	 * Checks the image is consistent, both from the writer's view and from a reader opening the image afresh
	*/
	private function _assertConsistent(string $sImage)
	{
		$oWriter = new L3fsWriter($sImage);
		$this->assertSame(self::CLEAN,$oWriter->checkFreeSpaceMap());
		//The stub ADFS map must still have valid checksums
		foreach([0,1] as $iSector){
			$sSector = AfsImageBuilder::peek($sImage,$iSector,0,256);
			$this->assertSame(AfsImageBuilder::adfsChecksum($sSector),ord($sSector[255]));
		}
		//The recovery class checks every map and directory on the disk, not just what is reachable
		$oRecovery = new L3fsRecovery($sImage);
		$oRecovery->getCatalogue();
		$this->assertSame([],$oRecovery->getRecoveryReport()['problems']);
	}

	//********** The check its self **********

	public function testABuiltImageIsConsistent()
	{
		$this->_assertConsistent($this->_standardImage());
	}

	public function testCheckFreeSpaceMapSpotsSectorsWronglyMarked()
	{
		$sImage = $this->_standardImage();
		//Cylinder 7 is unused, claim sector 3 of it. Cylinder 1 holds the root, free everything in it
		AfsImageBuilder::patch($sImage,7*16,0,"\xF6");
		AfsImageBuilder::patch($sImage,1*16,0,"\xFE\xFF");
		$aCheck = (new L3fsWriter($sImage))->checkFreeSpaceMap();
		$this->assertSame([(7*16)+3],$aCheck['allocated_but_unused']);
		$this->assertContains(17,$aCheck['in_use_but_free']);
		$this->assertSame([],$aCheck['cross_linked']);
	}

	//********** Opening an image **********

	public function testImagesWithPackedBitmapsAreRefused()
	{
		$oBuilder = new AfsImageBuilder();
		$sImage = $this->_write($oBuilder);
		AfsImageBuilder::patch($sImage,$oBuilder->getInfoSector(1),0x26,"\x04");
		AfsImageBuilder::patch($sImage,$oBuilder->getInfoSector(2),0x26,"\x04");
		$this->expectException(Exception::class);
		new L3fsWriter($sImage);
	}

	//********** Writing files **********

	public function testPutFileCanBeReadBack()
	{
		$sImage = $this->_standardImage();
		$oWriter = new L3fsWriter($sImage);
		$iFree = $oWriter->getFreeSpace();
		$sData = $this->_pattern(700);
		$iSin = $oWriter->putFile('$.Library.New',$sData,0xFFFF1900,0xFFFF8023,L3fsWriter::ACCESS_OWNER_READ | L3fsWriter::ACCESS_PUBLIC_READ,L3fsWriter::encodeDate(15,3,1986));

		//3 sectors of data and one for the map
		$this->assertSame($iFree-(4*256),$oWriter->getFreeSpace());
		$this->assertSame($sData,$oWriter->getFile('$.Library.New'));

		$oReader = new L3fsReader($sImage);
		$this->assertSame($sData,$oReader->getFile('$.Library.New'));
		$aEntry = $oReader->getCatalogue()['Library']['dir']['New'];
		$this->assertSame($iSin,$aEntry['sector']);
		$this->assertSame(700,$aEntry['size']);
		$this->assertSame(0xFFFF1900,$aEntry['load']);
		$this->assertSame(0xFFFF8023,$aEntry['exec']);
		$this->assertSame(0x05,$aEntry['access']);
		$this->assertSame(0x530F,$aEntry['date']);
		//The other files are untouched
		$this->assertSame(str_repeat("F",300),$oReader->getFile('$.Library.Fred'));
		$this->assertSame("*EXEC Menu\r",$oReader->getFile('$.!Boot'));
		$this->_assertConsistent($sImage);
	}

	public function testTheMapOfANewFileIsLaidOutAsTheFileserverExpects()
	{
		$sImage = $this->_standardImage();
		$iSin = (new L3fsWriter($sImage))->putFile('$.New',str_repeat("x",300));
		$sMap = AfsImageBuilder::peek($sImage,$iSin,0,256);
		$this->assertSame("JesMap",substr($sMap,0,6));
		$this->assertSame($sMap[6],$sMap[255]);
		//300 bytes leaves 44 in the last sector
		$this->assertSame("\x2C\x00",substr($sMap,8,2));
		//One extent of two sectors straight after the map, then nothing
		$this->assertSame(chr($iSin+1)."\x00\x00\x02\x00",substr($sMap,10,5));
		$this->assertSame(str_repeat("\0",240),substr($sMap,15,240));
	}

	public function testAnEmptyFileHasAMapButNoData()
	{
		$sImage = $this->_standardImage();
		$oWriter = new L3fsWriter($sImage);
		$iFree = $oWriter->getFreeSpace();
		$oWriter->putFile('$.Empty',"");
		$this->assertSame($iFree-256,$oWriter->getFreeSpace());
		$oReader = new L3fsReader($sImage);
		$this->assertSame("",$oReader->getFile('$.Empty'));
		$this->assertSame(0,$oReader->getCatalogue()['Empty']['size']);
		$this->_assertConsistent($sImage);
	}

	public function testAFileThatIsAWholeNumberOfSectors()
	{
		$sImage = $this->_standardImage();
		$sData = $this->_pattern(512);
		(new L3fsWriter($sImage))->putFile('$.Whole',$sData);
		$this->assertSame($sData,(new L3fsReader($sImage))->getFile('$.Whole'));
		$this->_assertConsistent($sImage);
	}

	public function testEntriesAreKeptInAlphabeticalOrderIgnoringCase()
	{
		$sImage = $this->_standardImage();
		$oWriter = new L3fsWriter($sImage);
		foreach(['zebra','Apple','mango','!Aaa','Zz'] as $sName){
			$oWriter->putFile('$.'.$sName,$sName);
		}
		$this->assertSame(['!Aaa','!Boot','Apple','Library','mango','Passwords','zebra','Zz'],array_keys((new L3fsReader($sImage))->getCatalogue()));
		$this->_assertConsistent($sImage);
	}

	public function testReplacingAFileKeepsItsAttributesAndReleasesTheOldSpace()
	{
		$sImage = $this->_standardImage();
		$oWriter = new L3fsWriter($sImage);
		$iFree = $oWriter->getFreeSpace();
		//The name is matched without regard to case, and the existing entry is kept
		$oWriter->putFile('$.library.FRED',$this->_pattern(1000));

		//The file has gone from 2 sectors to 4
		$this->assertSame($iFree-(2*256),$oWriter->getFreeSpace());
		$oReader = new L3fsReader($sImage);
		$this->assertSame($this->_pattern(1000),$oReader->getFile('$.Library.Fred'));
		$aDir = $oReader->getCatalogue()['Library']['dir'];
		$this->assertSame(['Fred'],array_keys($aDir));
		$this->assertSame(0xFFFF1900,$aDir['Fred']['load']);
		$this->assertSame(0xFFFF8023,$aDir['Fred']['exec']);
		$this->assertSame(0x0D,$aDir['Fred']['access']);
		$this->_assertConsistent($sImage);
	}

	public function testALockedFileIsNotReplacedOrDeletedUntilUnlocked()
	{
		$sImage = $this->_standardImage();
		$oWriter = new L3fsWriter($sImage);
		foreach(['putFile','delete'] as $sMethod){
			try {
				$sMethod=='putFile' ? $oWriter->putFile('$.!Boot',"new") : $oWriter->delete('$.!Boot');
				$this->fail("A locked file was altered by ".$sMethod);
			}catch(Exception $oException){
				$this->assertStringContainsString('locked',$oException->getMessage());
			}
		}
		$this->assertSame("*EXEC Menu\r",(new L3fsReader($sImage))->getFile('$.!Boot'));

		$oWriter->setAttributes('$.!Boot',0x1234,NULL,L3fsWriter::ACCESS_OWNER_READ | L3fsWriter::ACCESS_OWNER_WRITE);
		$oWriter->putFile('$.!Boot',"new");
		$aEntry = (new L3fsReader($sImage))->getCatalogue()['!Boot'];
		$this->assertSame(0x1234,$aEntry['load']);
		$this->assertSame(0xFFFFFFFF,$aEntry['exec']);
		$this->assertSame(0x0C,$aEntry['access']);
		$this->assertSame("new",(new L3fsReader($sImage))->getFile('$.!Boot'));
		$this->_assertConsistent($sImage);
	}

	public function testBadPathsAndNamesAreRefused()
	{
		$sImage = $this->_standardImage();
		$sBefore = md5_file($sImage);
		$oWriter = new L3fsWriter($sImage);
		foreach(['$','$.NoSuchDir.File','$.ElevenChars','$.Bad*Name','$.Has Space','$.Passwords.File','$..File'] as $sPath){
			try {
				$oWriter->putFile($sPath,"data");
				$this->fail("Wrote to ".$sPath);
			}catch(Exception $oException){
				$this->assertSame($sBefore,md5_file($sImage));
			}
		}
		//A file can not be written over a directory, or a directory made over a file
		foreach([['putFile','$.Library'],['makeDirectory','$.Passwords'],['makeDirectory','$.Library']] as $aCall){
			try {
				$aCall[0]=='putFile' ? $oWriter->putFile($aCall[1],"data") : $oWriter->makeDirectory($aCall[1]);
				$this->fail($aCall[0]." worked on ".$aCall[1]);
			}catch(Exception $oException){
				$this->assertSame($sBefore,md5_file($sImage));
			}
		}
	}

	//********** Directories **********

	public function testADirectoryGrowsWhenItsSlotsAreUsedUp()
	{
		$sImage = $this->_standardImage(16,12);
		$oBuilder = new AfsImageBuilder();
		$oWriter = new L3fsWriter($sImage);
		$iRootSin = (new L3fsRecovery($sImage))->getStat('$.Passwords')['sector'];
		//A new directory has 18 slots for entries, the root already uses 3 of them
		$aNames = ['!Boot','Library','Passwords'];
		for($i=0;$i<40;$i++){
			$sName = sprintf("File%02d",$i);
			$oWriter->putFile('$.'.$sName,"contents of ".$sName);
			$aNames[] = $sName;
		}
		$oReader = new L3fsReader($sImage);
		$aCatalogue = $oReader->getCatalogue();
		usort($aNames,function($sA,$sB){ return strcmp(strtoupper($sA),strtoupper($sB)); });
		$this->assertSame($aNames,array_keys($aCatalogue));
		foreach($aNames as $sName){
			if(substr($sName,0,4)=='File'){
				$this->assertSame("contents of ".$sName,$oReader->getFile('$.'.$sName));
			}
		}
		$this->_assertConsistent($sImage);

		//Deleting returns the slots to the free list where they get used again without the directory growing
		$iFree = $oWriter->getFreeSpace();
		for($i=0;$i<40;$i=$i+2){
			$oWriter->delete(sprintf("\$.File%02d",$i));
		}
		for($i=0;$i<40;$i=$i+2){
			$oWriter->putFile(sprintf("\$.Again%02d",$i),"x");
		}
		$this->assertSame($iFree,$oWriter->getFreeSpace());
		$this->assertCount(43,(new L3fsReader($sImage))->getCatalogue());
		$this->_assertConsistent($sImage);
	}

	public function testAGrownDirectoryIsTheSizeTheFileserverExpects()
	{
		$oBuilder = new AfsImageBuilder(16,12);
		$sImage = $this->_write($oBuilder);
		$iRootSin = $oBuilder->getSin('$');
		$oWriter = new L3fsWriter($sImage);
		for($i=0;$i<19;$i++){
			$oWriter->putFile(sprintf("\$.File%02d",$i),"x");
		}
		//3 sectors hold 28 slots, 17 + (28 * 26) + 1 = 746 bytes, so 234 are used in the last sector
		$sMap = AfsImageBuilder::peek($sImage,$iRootSin,0,256);
		$this->assertSame($sMap[6],$sMap[255]);
		$this->assertSame("\xEA\x00",substr($sMap,8,2));
		$sRoot = (new L3fsReader($sImage))->getFileBySin($iRootSin);
		$this->assertSame(746,strlen($sRoot));
		$this->assertSame($sRoot[2],$sRoot[745]);
		$this->assertSame("\x13\x00",substr($sRoot,15,2));
		$this->_assertConsistent($sImage);
	}

	public function testMakeDirectory()
	{
		$sImage = $this->_standardImage();
		$oWriter = new L3fsWriter($sImage);
		$iFree = $oWriter->getFreeSpace();
		$iLibrarySin = (new L3fsReader($sImage))->getCatalogue()['Library']['sector'];
		$iSin = $oWriter->makeDirectory('$.Library.Games');
		$oWriter->putFile('$.Library.Games.Elite',$this->_pattern(600));

		//A map and 2 sectors for the directory, a map and 3 sectors for the file
		$this->assertSame($iFree-(7*256),$oWriter->getFreeSpace());
		$oReader = new L3fsReader($sImage);
		$this->assertTrue($oReader->isDir('$.Library.Games'));
		$this->assertSame($this->_pattern(600),$oReader->getFile('$.Library.Games.Elite'));
		$this->assertSame(0x30,$oReader->getCatalogue()['Library']['dir']['Games']['access']);

		$sDir = $oReader->getFileBySin($iSin);
		$this->assertSame(512,strlen($sDir));
		$this->assertSame("Games     ",substr($sDir,3,10));
		//The first slot holds the SIN of the parent directory
		$this->assertSame("\xFF\xFF",substr($sDir,17,2));
		$this->assertSame($iLibrarySin,ord($sDir[40]) | (ord($sDir[41]) << 8) | (ord($sDir[42]) << 16));
		$this->_assertConsistent($sImage);
	}

	//********** Deleting **********

	public function testDeletingAFileReleasesItsSpace()
	{
		$sImage = $this->_standardImage();
		$oWriter = new L3fsWriter($sImage);
		$iFree = $oWriter->getFreeSpace();
		$iSin = (new L3fsReader($sImage))->getCatalogue()['Library']['dir']['Fred']['sector'];
		$oWriter->delete('$.Library.Fred');

		$this->assertSame($iFree+(3*256),$oWriter->getFreeSpace());
		$oReader = new L3fsReader($sImage);
		$this->assertFalse($oReader->isFile('$.Library.Fred'));
		$this->assertSame([],$oReader->getCatalogue()['Library']['dir']);
		//The map is left with the identifier, no extents, and the sequence numbers out of step
		$this->assertSame("JesMap\x00".str_repeat("\0",248)."\x01",AfsImageBuilder::peek($sImage,$iSin,0,256));
		$this->_assertConsistent($sImage);
	}

	public function testOnlyEmptyDirectoriesCanBeDeleted()
	{
		$sImage = $this->_standardImage();
		$oWriter = new L3fsWriter($sImage);
		$iFree = $oWriter->getFreeSpace();
		try {
			$oWriter->delete('$.Library');
			$this->fail("A directory with files in was deleted");
		}catch(Exception $oException){
			$this->assertStringContainsString('not empty',$oException->getMessage());
		}
		$oWriter->delete('$.Library.Fred');
		$oWriter->delete('$.Library');
		//3 sectors for the file and 3 for the directory
		$this->assertSame($iFree+(6*256),$oWriter->getFreeSpace());
		$this->assertSame(['!Boot','Passwords'],array_keys((new L3fsReader($sImage))->getCatalogue()));
		$this->_assertConsistent($sImage);
	}

	public function testDeletingSomethingThatIsNotThere()
	{
		$sImage = $this->_standardImage();
		$this->expectException(Exception::class);
		(new L3fsWriter($sImage))->delete('$.Library.Nothing');
	}

	//********** Free space **********

	public function testAFileLargerThanACylinderIsSplitIntoExtents()
	{
		$sImage = $this->_standardImage(16,12);
		$oWriter = new L3fsWriter($sImage);
		$iFree = $oWriter->getFreeSpace();
		$sData = $this->_pattern(40*256);
		$oWriter->putFile('$.Big',$sData);
		$this->assertSame($iFree-(41*256),$oWriter->getFreeSpace());
		$this->assertSame($sData,(new L3fsReader($sImage))->getFile('$.Big'));
		$this->_assertConsistent($sImage);

		$oWriter->setAttributes('$.Big',NULL,NULL,L3fsWriter::ACCESS_OWNER_READ);
		$oWriter->delete('$.Big');
		$this->assertSame($iFree,$oWriter->getFreeSpace());
		$this->_assertConsistent($sImage);
	}

	public function testAFragmentedFileChainsMapBlocks()
	{
		$oBuilder = new AfsImageBuilder(16,12);
		$sImage = $this->_write($oBuilder);
		$oWriter = new L3fsWriter($sImage);
		//Fill the disk with files of one sector (just a map), then delete every other one to leave single sector holes
		$oWriter->makeDirectory('$.A');
		$oWriter->makeDirectory('$.B');
		$aNames = [];
		try {
			for($i=0;$i<400;$i++){
				$sName = (($i % 2)==0 ? '$.A' : '$.B').sprintf(".F%03d",$i);
				$oWriter->putFile($sName,"");
				$aNames[] = $sName;
			}
			$this->fail("The disk never filled up");
		}catch(Exception $oException){
			$this->assertSame("Disk full",$oException->getMessage());
		}
		$this->assertSame(0,$oWriter->getFreeSpace());
		$this->_assertConsistent($sImage);
		foreach($aNames as $iIndex=>$sName){
			if(substr($sName,0,3)=='$.A'){
				$oWriter->delete($sName);
			}
		}
		$iFree = $oWriter->getFreeSpace();
		$this->assertGreaterThan(62*256,$iFree);

		//60 sectors of data in 60 extents needs a second map block
		$sData = $this->_pattern((60*256)-10);
		$iSin = $oWriter->putFile('$.Big',$sData);
		$this->assertSame($iFree-(62*256),$oWriter->getFreeSpace());
		$this->assertSame($sData,(new L3fsReader($sImage))->getFile('$.Big'));
		$sMap = AfsImageBuilder::peek($sImage,$iSin,0,256);
		$iNext = ord($sMap[250]) | (ord($sMap[251]) << 8) | (ord($sMap[252]) << 16);
		$this->assertNotSame(0,$iNext);
		$sNext = AfsImageBuilder::peek($sImage,$iNext,0,256);
		//The second block has no identifier, 12 extents and no link onwards
		$this->assertSame(str_repeat("\0",6),substr($sNext,0,6));
		$this->assertSame($sNext[6],$sNext[255]);
		$this->assertNotSame("\0\0\0",substr($sNext,10+(11*5),3));
		$this->assertSame(str_repeat("\0",185),substr($sNext,10+(12*5),185));
		$this->_assertConsistent($sImage);

		//Replacing it with something small gives back all the space, including the second map block
		$oWriter->putFile('$.Big',"small");
		$this->assertSame($iFree-(2*256),$oWriter->getFreeSpace());
		$this->assertSame("\x00\x01",$sNext = AfsImageBuilder::peek($sImage,$iNext,6,1).AfsImageBuilder::peek($sImage,$iNext,255,1));
		$this->_assertConsistent($sImage);
	}

	public function testAFailedWriteLeavesTheImageUntouched()
	{
		$sImage = $this->_standardImage();
		$oWriter = new L3fsWriter($sImage);
		$iFree = $oWriter->getFreeSpace();
		$sBefore = md5_file($sImage);
		try {
			//Replacing a file frees the old copy before the lack of space is found
			$oWriter->putFile('$.Library.Fred',str_repeat("x",$iFree+(3*256)));
			$this->fail("A file larger than the free space was written");
		}catch(Exception $oException){
			$this->assertSame("Disk full",$oException->getMessage());
		}
		$this->assertSame($sBefore,md5_file($sImage));
		$this->assertSame($iFree,$oWriter->getFreeSpace());
		//The writer carries on working afterwards
		$oWriter->putFile('$.Library.Fred',str_repeat("x",$iFree+(2*256)));
		$this->assertSame(0,$oWriter->getFreeSpace());
		$this->_assertConsistent($sImage);
	}

	public function testFreeingSpaceInACylinderFlaggedAsFullClearsTheFlag()
	{
		$oBuilder = new AfsImageBuilder(16,8);
		$oBuilder->addFile('$.Filler',$this->_pattern(24*256));
		$oBuilder->addFile('$.Second',$this->_pattern(14*256));
		$sImage = $this->_write($oBuilder);
		//Cylinder 3 holds only the file Second and is full, flag in the bitmap of cylinder 1 that the next 2 are full
		$this->assertSame(3*16+1,$oBuilder->getSin('$.Second'));
		$this->assertSame("\0\0",AfsImageBuilder::peek($sImage,3*16,0,2));
		AfsImageBuilder::patch($sImage,1*16,0xFF,"\x02");

		$oWriter = new L3fsWriter($sImage);
		$oWriter->setAttributes('$.Second',NULL,NULL,L3fsWriter::ACCESS_OWNER_READ);
		$oWriter->delete('$.Second');
		//Cylinder 2 is still skipped but 3 no longer is
		$this->assertSame("\x01",AfsImageBuilder::peek($sImage,1*16,0xFF,1));
		$this->assertSame("\xFE\xFF",AfsImageBuilder::peek($sImage,3*16,0,2));
	}

	//********** Dates **********

	public function testEncodeDate()
	{
		$this->assertSame(0x530F,L3fsWriter::encodeDate(15,3,1986));
		//From 1997 the year overflows into the top bits of the day byte
		$this->assertSame(0xDA4C,L3fsWriter::encodeDate(12,10,2026));
	}
}
