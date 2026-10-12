<?php
/**
 * Tests for the Level 3 fileserver / Filestore disk image reader
 *
 * All the tests run against small synthetic images made by AfsImageBuilder.
*/

namespace HomeLan\Retro\Acorn\Disk\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use HomeLan\Retro\Acorn\Disk\L3fsReader;

class L3fsReaderTest extends TestCase {

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
	 * A builder holding a small tree used by most of the tests
	*/
	private function _standardBuilder(): AfsImageBuilder
	{
		$oBuilder = new AfsImageBuilder();
		$oBuilder->addFile('$.!Boot',"*EXEC Menu\r",0x00000000,0xFFFFFFFF,AfsImageBuilder::ACCESS_LOCKED | AfsImageBuilder::ACCESS_OWNER_READ | AfsImageBuilder::ACCESS_PUBLIC_READ);
		$oBuilder->addDirectory('$.Library');
		$oBuilder->addFile('$.Library.Fred',str_repeat("F",300),0xFFFF1900,0xFFFF8023);
		$oBuilder->addDirectory('$.Library.Sub');
		$oBuilder->addFile('$.Library.Sub.Deep',"deep");
		$oBuilder->addFile('$.Passwords',str_repeat("P",256),0,0,AfsImageBuilder::ACCESS_OWNER_WRITE | AfsImageBuilder::ACCESS_OWNER_READ);
		return $oBuilder;
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

	//********** The builder its self **********

	public function testBuilderMakesAnImageOfTheExpectedSizeWithValidChecksums()
	{
		$oBuilder = $this->_standardBuilder();
		$sImage = $oBuilder->build();
		$this->assertSame(16*8*256,strlen($sImage));
		foreach([0,1] as $iSector){
			$sSector = substr($sImage,$iSector*256,256);
			$this->assertSame(AfsImageBuilder::adfsChecksum($sSector),ord($sSector[255]));
		}
		$this->assertSame("AFS0",substr($sImage,$oBuilder->getInfoSector(1)*256,4));
		$this->assertSame("AFS0",substr($sImage,$oBuilder->getInfoSector(2)*256,4));
	}

	//********** Opening an image **********

	public function testOpensAValidImage()
	{
		$oReader = new L3fsReader($this->_write($this->_standardBuilder()));
		$this->assertInstanceOf(L3fsReader::class,$oReader);
	}

	public function testOpeningProducesNoOutput()
	{
		$this->expectOutputString('');
		$oReader = new L3fsReader($this->_write($this->_standardBuilder()));
		$oReader->getCatalogue();
	}

	public function testRejectsAnImageThatIsNotAFileserverDisk()
	{
		$sPath = tempnam(sys_get_temp_dir(),'afs');
		$this->aImages[] = $sPath;
		file_put_contents($sPath,str_repeat("\0",256*64));
		$this->expectException(Exception::class);
		new L3fsReader($sPath);
	}

	public function testUsesTheSecondInfoSectorWhenThePointerInSectorZeroIsLost()
	{
		$oBuilder = $this->_standardBuilder();
		$sPath = $this->_write($oBuilder);
		//A zeroed sector 0, its checksum is now wrong and its pointer is useless
		AfsImageBuilder::patch($sPath,0,0,str_repeat("\0",256));
		$oReader = new L3fsReader($sPath);
		$this->assertTrue($oReader->isFile('$.!Boot'));
	}

	public function testUsesTheSecondInfoSectorWhenTheFirstCopyIsLost()
	{
		$oBuilder = $this->_standardBuilder();
		$sPath = $this->_write($oBuilder);
		AfsImageBuilder::patch($sPath,$oBuilder->getInfoSector(1),0,str_repeat("\0",256));
		$oReader = new L3fsReader($sPath);
		$this->assertTrue($oReader->isFile('$.!Boot'));
	}

	public function testUsesTheFirstInfoSectorWhenTheSecondCopyIsLost()
	{
		$oBuilder = $this->_standardBuilder();
		$sPath = $this->_write($oBuilder);
		AfsImageBuilder::patch($sPath,$oBuilder->getInfoSector(2),0,str_repeat("\0",256));
		AfsImageBuilder::patch($sPath,1,0,str_repeat("\0",256));
		$oReader = new L3fsReader($sPath);
		$this->assertTrue($oReader->isFile('$.!Boot'));
	}

	public function testFailsWhenBothInfoSectorsAreLost()
	{
		$oBuilder = $this->_standardBuilder();
		$sPath = $this->_write($oBuilder);
		AfsImageBuilder::patch($sPath,$oBuilder->getInfoSector(1),0,str_repeat("\0",256));
		AfsImageBuilder::patch($sPath,$oBuilder->getInfoSector(2),0,str_repeat("\0",256));
		$this->expectException(Exception::class);
		new L3fsReader($sPath);
	}

	public function testPrefersThePointerFromTheSectorWithAGoodChecksum()
	{
		$oBuilder = $this->_standardBuilder();
		$sPath = $this->_write($oBuilder);
		//Point sector 0 at something that is not an info sector without fixing its checksum, and remove the copy it used to point to
		AfsImageBuilder::patch($sPath,0,0xF6,"\x05\x00\x00");
		AfsImageBuilder::patch($sPath,$oBuilder->getInfoSector(1),0,str_repeat("\0",256));
		$oReader = new L3fsReader($sPath);
		$this->assertTrue($oReader->isDir('$.Library'));
	}

	//********** The catalogue **********

	public function testCatalogueListsTheRootDirectory()
	{
		$oReader = new L3fsReader($this->_write($this->_standardBuilder()));
		$aCatalogue = $oReader->getCatalogue();
		$this->assertSame(['!Boot','Library','Passwords'],array_keys($aCatalogue));
		$this->assertSame('file',$aCatalogue['!Boot']['type']);
		$this->assertSame('dir',$aCatalogue['Library']['type']);
		$this->assertSame('file',$aCatalogue['Passwords']['type']);
	}

	public function testCatalogueRecursesIntoSubDirectories()
	{
		$oReader = new L3fsReader($this->_write($this->_standardBuilder()));
		$aCatalogue = $oReader->getCatalogue();
		$this->assertSame(['Fred','Sub'],array_keys($aCatalogue['Library']['dir']));
		$this->assertSame(['Deep'],array_keys($aCatalogue['Library']['dir']['Sub']['dir']));
	}

	public function testCatalogueOfAnEmptyDisk()
	{
		$oReader = new L3fsReader($this->_write(new AfsImageBuilder()));
		$this->assertSame([],$oReader->getCatalogue());
	}

	public function testCatalogueEntryHoldsTheLoadAndExecAddresses()
	{
		$oReader = new L3fsReader($this->_write($this->_standardBuilder()));
		$aEntry = $oReader->getCatalogue()['Library']['dir']['Fred'];
		$this->assertSame(0xFFFF1900,$aEntry['load']);
		$this->assertSame(0xFFFF8023,$aEntry['exec']);
	}

	public function testCatalogueEntryHoldsTheSinOfTheObject()
	{
		$oBuilder = $this->_standardBuilder();
		$oReader = new L3fsReader($this->_write($oBuilder));
		$aCatalogue = $oReader->getCatalogue();
		$this->assertSame($oBuilder->getSin('$.!Boot'),$aCatalogue['!Boot']['sector']);
		$this->assertSame($oBuilder->getSin('$.Library'),$aCatalogue['Library']['sector']);
		$this->assertSame($oBuilder->getSin('$.Library.Fred'),$aCatalogue['Library']['dir']['Fred']['sector']);
	}

	public function testFileSizeIsExactRatherThanRoundedUpToAWholeSector()
	{
		$oReader = new L3fsReader($this->_write($this->_standardBuilder()));
		$aCatalogue = $oReader->getCatalogue();
		$this->assertSame(11,$aCatalogue['!Boot']['size']);
		$this->assertSame(300,$aCatalogue['Library']['dir']['Fred']['size']);
		$this->assertSame(256,$aCatalogue['Passwords']['size']);
	}

	public function testNamesOfTheFullTenCharactersAreKept()
	{
		$oBuilder = new AfsImageBuilder();
		$oBuilder->addFile('$.TenLetters',"x");
		$oReader = new L3fsReader($this->_write($oBuilder));
		$this->assertSame(['TenLetters'],array_keys($oReader->getCatalogue()));
	}

	public function testNamesPaddedWithNullsAreTrimmed()
	{
		$oBuilder = new AfsImageBuilder();
		$oBuilder->addFile('$.Nul',"x");
		$sPath = $this->_write($oBuilder);
		//The only entry is in the slot after the parent pointer, replace the space padding of its name
		$aRoot = $oBuilder->getExtents('$');
		AfsImageBuilder::patch($sPath,$aRoot[0]['sector'],17+26+2,"Nul\0\0\0\0\0\0\0");
		$oReader = new L3fsReader($sPath);
		$this->assertSame(['Nul'],array_keys($oReader->getCatalogue()));
	}

	public function testADirectoryWithMoreEntriesThanFitInTwoSectors()
	{
		$oBuilder = new AfsImageBuilder(16,16);
		$aExpected = [];
		for($i=0;$i<30;$i++){
			$sName = sprintf("File%02d",$i);
			$oBuilder->addFile('$.'.$sName,"content ".$i);
			$aExpected[] = $sName;
		}
		$oReader = new L3fsReader($this->_write($oBuilder));
		$this->assertSame($aExpected,array_keys($oReader->getCatalogue()));
		$this->assertSame("content 29",$oReader->getFile('$.File29'));
	}

	//********** The access byte **********

	/**
	 * @dataProvider accessProvider
	*/
	#[\PHPUnit\Framework\Attributes\DataProvider('accessProvider')]
	public function testAccessBitsAreDecoded(int $iAccess, bool $bLocked, bool $bUserRead, bool $bUserWrite, bool $bPublicRead, bool $bPublicWrite)
	{
		$oBuilder = new AfsImageBuilder();
		$oBuilder->addFile('$.File',"x",0,0,$iAccess);
		$oReader = new L3fsReader($this->_write($oBuilder));
		$aEntry = $oReader->getCatalogue()['File'];
		$this->assertSame($iAccess,$aEntry['access']);
		$this->assertSame($bLocked,(bool) $aEntry['locked']);
		$this->assertSame($bUserRead,(bool) $aEntry['user_read']);
		$this->assertSame($bUserWrite,(bool) $aEntry['user_write']);
		$this->assertSame($bPublicRead,(bool) $aEntry['public_read']);
		$this->assertSame($bPublicWrite,(bool) $aEntry['public_write']);
	}

	public static function accessProvider(): array
	{
		return [
			'LR/R (0x15)'  => [0x15,true,true,false,true,false],
			'WR/R (0x0D)'  => [0x0D,false,true,true,true,false],
			'WR/ (0x0C)'   => [0x0C,false,true,true,false,false],
			'LR/ (0x14)'   => [0x14,true,true,false,false,false],
			'WR/WR (0x0F)' => [0x0F,false,true,true,true,true],
			'/W (0x02)'    => [0x02,false,false,false,false,true],
			'/R (0x01)'    => [0x01,false,false,false,true,false],
			'none (0x00)'  => [0x00,false,false,false,false,false],
		];
	}

	public function testTheDirectoryBitSetsTheType()
	{
		$oBuilder = new AfsImageBuilder();
		$oBuilder->addDirectory('$.Locked');
		$oBuilder->addDirectory('$.Unlocked',AfsImageBuilder::ACCESS_DIR);
		$oReader = new L3fsReader($this->_write($oBuilder));
		$aCatalogue = $oReader->getCatalogue();
		$this->assertSame('dir',$aCatalogue['Locked']['type']);
		$this->assertSame(0x30,$aCatalogue['Locked']['access']);
		$this->assertSame('dir',$aCatalogue['Unlocked']['type']);
		$this->assertSame(0x20,$aCatalogue['Unlocked']['access']);
	}

	//********** Reading files **********

	public function testGetFileReturnsTheContentsOfAFileInTheRoot()
	{
		$oReader = new L3fsReader($this->_write($this->_standardBuilder()));
		$this->assertSame("*EXEC Menu\r",$oReader->getFile('$.!Boot'));
	}

	public function testGetFileReturnsTheContentsOfAFileInASubDirectory()
	{
		$oReader = new L3fsReader($this->_write($this->_standardBuilder()));
		$this->assertSame(str_repeat("F",300),$oReader->getFile('$.Library.Fred'));
		$this->assertSame("deep",$oReader->getFile('$.Library.Sub.Deep'));
	}

	public function testGetFileWorksWithoutTheLeadingRoot()
	{
		$oReader = new L3fsReader($this->_write($this->_standardBuilder()));
		$this->assertSame("deep",$oReader->getFile('Library.Sub.Deep'));
	}

	public function testGetFileIsNotCaseSensitive()
	{
		$oReader = new L3fsReader($this->_write($this->_standardBuilder()));
		$this->assertSame("deep",$oReader->getFile('$.LIBRARY.sub.dEEP'));
	}

	public function testGetFileForAFileThatIsAWholeNumberOfSectors()
	{
		$sData = $this->_pattern(512);
		$oBuilder = new AfsImageBuilder();
		$oBuilder->addFile('$.Whole',$sData);
		$oReader = new L3fsReader($this->_write($oBuilder));
		$this->assertSame(512,$oReader->getCatalogue()['Whole']['size']);
		$this->assertSame($sData,$oReader->getFile('$.Whole'));
	}

	public function testGetFileForAnEmptyFile()
	{
		$oBuilder = new AfsImageBuilder();
		$oBuilder->addFile('$.Empty',"");
		$oReader = new L3fsReader($this->_write($oBuilder));
		$this->assertSame(0,$oReader->getCatalogue()['Empty']['size']);
		$this->assertSame("",$oReader->getFile('$.Empty'));
	}

	public function testGetFileForAFileThatSpansACylinderBoundary()
	{
		//Larger than a cylinder, so it has to be split around a bitmap sector
		$sData = $this->_pattern((20*256)+17);
		$oBuilder = new AfsImageBuilder();
		$oBuilder->addFile('$.Big',$sData);
		$oReader = new L3fsReader($this->_write($oBuilder));
		$this->assertGreaterThan(1,count($oBuilder->getExtents('$.Big')));
		$this->assertSame($sData,$oReader->getFile('$.Big'));
	}

	public function testGetFileForAFragmentedFile()
	{
		$sData = $this->_pattern((9*256)+100);
		$oBuilder = new AfsImageBuilder();
		$oBuilder->addFile('$.Frag',$sData,0,0,0x0D,true);
		$oReader = new L3fsReader($this->_write($oBuilder));
		$this->assertCount(10,$oBuilder->getExtents('$.Frag'));
		$this->assertSame(strlen($sData),$oReader->getCatalogue()['Frag']['size']);
		$this->assertSame($sData,$oReader->getFile('$.Frag'));
	}

	public function testGetFileForAFileWithExactlyFortyEightExtents()
	{
		//Fills every data entry in the map block, leaving the link to the next block empty
		$sData = $this->_pattern((47*256)+5);
		$oBuilder = new AfsImageBuilder(16,16);
		$oBuilder->addFile('$.Full',$sData,0,0,0x0D,true);
		$oReader = new L3fsReader($this->_write($oBuilder));
		$this->assertCount(48,$oBuilder->getExtents('$.Full'));
		$this->assertSame($sData,$oReader->getFile('$.Full'));
	}

	public function testGetFileFollowsAChainOfMapBlocks()
	{
		//More than 48 extents will not fit in one map block
		$sData = $this->_pattern((59*256)+33);
		$oBuilder = new AfsImageBuilder(16,16);
		$oBuilder->addFile('$.Chained',$sData,0,0,0x0D,true);
		$sPath = $this->_write($oBuilder);
		$this->assertCount(60,$oBuilder->getExtents('$.Chained'));
		//The last entry of the first block must be a link to the second block, which has no identifier
		$iSin = $oBuilder->getSin('$.Chained');
		$sLink = AfsImageBuilder::peek($sPath,$iSin,0xFA,3);
		$iNextBlock = ord($sLink[0]) + (ord($sLink[1]) << 8) + (ord($sLink[2]) << 16);
		$this->assertGreaterThan(0,$iNextBlock);
		$this->assertSame("\0\0\0\0\0\0",AfsImageBuilder::peek($sPath,$iNextBlock,0,6));

		$oReader = new L3fsReader($sPath);
		$this->assertSame(strlen($sData),$oReader->getCatalogue()['Chained']['size']);
		$this->assertSame($sData,$oReader->getFile('$.Chained'));
	}

	public function testGetFileThrowsWhenTheFileDoesNotExist()
	{
		$oReader = new L3fsReader($this->_write($this->_standardBuilder()));
		$this->expectException(Exception::class);
		$oReader->getFile('$.Library.Missing');
	}

	//********** getStat, isFile and isDir **********

	public function testGetStatReturnsTheSizeAndSin()
	{
		$oBuilder = $this->_standardBuilder();
		$oReader = new L3fsReader($this->_write($oBuilder));
		$this->assertSame(['size'=>11,'sector'=>$oBuilder->getSin('$.!Boot')],$oReader->getStat('$.!Boot'));
	}

	public function testGetStatForAFileInASubDirectory()
	{
		$oBuilder = $this->_standardBuilder();
		$oReader = new L3fsReader($this->_write($oBuilder));
		$this->assertSame(['size'=>4,'sector'=>$oBuilder->getSin('$.Library.Sub.Deep')],$oReader->getStat('$.Library.Sub.Deep'));
	}

	public function testGetStatThrowsWhenTheFileDoesNotExist()
	{
		$oReader = new L3fsReader($this->_write($this->_standardBuilder()));
		$this->expectException(Exception::class);
		$oReader->getStat('$.Missing');
	}

	public function testIsFile()
	{
		$oReader = new L3fsReader($this->_write($this->_standardBuilder()));
		$this->assertTrue($oReader->isFile('$.!Boot'));
		$this->assertTrue($oReader->isFile('$.Library.Sub.Deep'));
		$this->assertTrue($oReader->isFile('library.fred'));
		$this->assertFalse($oReader->isFile('$.Library'));
		$this->assertFalse($oReader->isFile('$.Library.Sub'));
		$this->assertFalse($oReader->isFile('$.Missing'));
		$this->assertFalse($oReader->isFile('$.Library.Missing'));
	}

	public function testIsDir()
	{
		$oReader = new L3fsReader($this->_write($this->_standardBuilder()));
		$this->assertTrue($oReader->isDir('$.Library'));
		$this->assertTrue($oReader->isDir('$.Library.Sub'));
		$this->assertTrue($oReader->isDir('LIBRARY.SUB'));
		$this->assertFalse($oReader->isDir('$.!Boot'));
		$this->assertFalse($oReader->isDir('$.Library.Fred'));
		$this->assertFalse($oReader->isDir('$.Missing'));
	}

	//********** Damaged maps **********

	public function testAMapWithoutItsIdentifierIsRejected()
	{
		$oBuilder = $this->_standardBuilder();
		$sPath = $this->_write($oBuilder);
		AfsImageBuilder::patch($sPath,$oBuilder->getSin('$.!Boot'),0,"NotMap");
		$oReader = new L3fsReader($sPath);
		$this->expectException(Exception::class);
		$oReader->getFile('$.!Boot');
	}

	public function testAMapWithSequenceNumbersOutOfStepIsRejected()
	{
		$oBuilder = $this->_standardBuilder();
		$sPath = $this->_write($oBuilder);
		AfsImageBuilder::patch($sPath,$oBuilder->getSin('$.!Boot'),0xFF,"\x7F");
		$oReader = new L3fsReader($sPath);
		$this->expectException(Exception::class);
		$oReader->getFile('$.!Boot');
	}

	public function testADeletedMapIsRejected()
	{
		$oBuilder = $this->_standardBuilder();
		$sPath = $this->_write($oBuilder);
		//What the server leaves behind when an object is deleted, the identifier stays but the sequence numbers are 0 and 1
		$iSin = $oBuilder->getSin('$.!Boot');
		AfsImageBuilder::patch($sPath,$iSin,0x06,"\x00");
		AfsImageBuilder::patch($sPath,$iSin,0xFF,"\x01");
		$oReader = new L3fsReader($sPath);
		$this->expectException(Exception::class);
		$oReader->getFile('$.!Boot');
	}

	public function testAFileWithABrokenMapIsStillListedWithASizeOfZero()
	{
		$oBuilder = $this->_standardBuilder();
		$sPath = $this->_write($oBuilder);
		AfsImageBuilder::patch($sPath,$oBuilder->getSin('$.!Boot'),0,str_repeat("\0",256));
		$oReader = new L3fsReader($sPath);
		$aCatalogue = $oReader->getCatalogue();
		$this->assertSame(['!Boot','Library','Passwords'],array_keys($aCatalogue));
		$this->assertSame(0,$aCatalogue['!Boot']['size']);
		//The rest of the disk is unaffected
		$this->assertSame("deep",$oReader->getFile('$.Library.Sub.Deep'));
	}

	public function testABrokenLinkInAChainOfMapBlocksIsRejected()
	{
		$oBuilder = new AfsImageBuilder(16,16);
		$oBuilder->addFile('$.Chained',$this->_pattern(60*256),0,0,0x0D,true);
		$sPath = $this->_write($oBuilder);
		$sLink = AfsImageBuilder::peek($sPath,$oBuilder->getSin('$.Chained'),0xFA,3);
		$iNextBlock = ord($sLink[0]) + (ord($sLink[1]) << 8) + (ord($sLink[2]) << 16);
		AfsImageBuilder::patch($sPath,$iNextBlock,0xFF,"\xEE");
		$oReader = new L3fsReader($sPath);
		$this->expectException(Exception::class);
		$oReader->getFile('$.Chained');
	}

	public function testAMapPointingBeyondTheEndOfTheImageIsRejected()
	{
		$oBuilder = $this->_standardBuilder();
		$sPath = $this->_write($oBuilder);
		//Move the only extent of the file to a sector past the end of the disk
		AfsImageBuilder::patch($sPath,$oBuilder->getSin('$.!Boot'),0x0A,"\xFF\xFF\x0F");
		$oReader = new L3fsReader($sPath);
		$this->expectException(Exception::class);
		$oReader->getFile('$.!Boot');
	}

	//********** Damaged directories **********

	public function testADirectoryWithSequenceNumbersOutOfStepIsRejected()
	{
		$oBuilder = $this->_standardBuilder();
		$sPath = $this->_write($oBuilder);
		$aRoot = $oBuilder->getExtents('$');
		AfsImageBuilder::patch($sPath,$aRoot[0]['sector'],0x02,"\x63");
		$oReader = new L3fsReader($sPath);
		$this->expectException(Exception::class);
		$oReader->getCatalogue();
	}

	public function testADirectoryWithItsFirstSectorLostIsRejected()
	{
		$oBuilder = $this->_standardBuilder();
		$sPath = $this->_write($oBuilder);
		$aRoot = $oBuilder->getExtents('$');
		AfsImageBuilder::patch($sPath,$aRoot[0]['sector'],0,str_repeat("\0",256));
		$oReader = new L3fsReader($sPath);
		$this->expectException(Exception::class);
		$oReader->getCatalogue();
	}

	public function testABrokenSubDirectoryIsRejected()
	{
		$oBuilder = $this->_standardBuilder();
		$sPath = $this->_write($oBuilder);
		$aLibrary = $oBuilder->getExtents('$.Library');
		AfsImageBuilder::patch($sPath,$aLibrary[0]['sector'],0x02,"\x63");
		$oReader = new L3fsReader($sPath);
		$this->expectException(Exception::class);
		$oReader->getCatalogue();
	}

	public function testAnEntryPointerThatIsNotOnASlotBoundaryIsRejected()
	{
		$oBuilder = $this->_standardBuilder();
		$sPath = $this->_write($oBuilder);
		$aRoot = $oBuilder->getExtents('$');
		//The first entry should be at 17+26, move the pointer on by one byte
		AfsImageBuilder::patch($sPath,$aRoot[0]['sector'],0x00,"\x2C\x00");
		$oReader = new L3fsReader($sPath);
		$this->expectException(Exception::class);
		$oReader->getCatalogue();
	}

	public function testAnEntryPointerBeyondTheEndOfTheDirectoryIsRejected()
	{
		$oBuilder = $this->_standardBuilder();
		$sPath = $this->_write($oBuilder);
		$aRoot = $oBuilder->getExtents('$');
		//Slot 19 would start where the trailing sequence number is (17+19*26=511)
		AfsImageBuilder::patch($sPath,$aRoot[0]['sector'],0x00,"\xFF\x01");
		$oReader = new L3fsReader($sPath);
		$this->expectException(Exception::class);
		$oReader->getCatalogue();
	}

	public function testTheParentPointerEntryIsNotListed()
	{
		$oBuilder = $this->_standardBuilder();
		$sPath = $this->_write($oBuilder);
		//The slot at 17 of a sub directory is the unchained parent pointer
		$aLibrary = $oBuilder->getExtents('$.Library');
		$this->assertSame("\xFF\xFF",AfsImageBuilder::peek($sPath,$aLibrary[0]['sector'],17,2));
		$sParent = AfsImageBuilder::peek($sPath,$aLibrary[0]['sector'],17+23,3);
		$this->assertSame($oBuilder->getSin('$'),ord($sParent[0]) + (ord($sParent[1]) << 8) + (ord($sParent[2]) << 16));
		$oReader = new L3fsReader($sPath);
		$this->assertSame(['Fred','Sub'],array_keys($oReader->getCatalogue()['Library']['dir']));
	}
}
