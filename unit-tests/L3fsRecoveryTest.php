<?php
/**
 * Tests for the class that recovers what it can from damaged Level 3 fileserver / Filestore disk images
 *
 * Each test builds a small synthetic image with AfsImageBuilder, then overwrites sectors with zeros as a failing disk would.
*/

namespace HomeLan\Retro\Acorn\Disk\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use HomeLan\Retro\Acorn\Disk\L3fsReader;
use HomeLan\Retro\Acorn\Disk\L3fsRecovery;

class L3fsRecoveryTest extends TestCase {

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

	private function _write(AfsImageBuilder $oBuilder): string
	{
		$sPath = $oBuilder->write();
		$this->aImages[] = $sPath;
		return $sPath;
	}

	/**
	 * Overwrites a whole sector with zeros, as is found in an image where the sector could not be read
	*/
	private function _loseSector(string $sPath, int $iSector)
	{
		AfsImageBuilder::patch($sPath,$iSector,0,str_repeat("\0",AfsImageBuilder::SECTOR_SIZE));
	}

	/**
	 * A builder holding a small tree used by most of the tests
	*/
	private function _standardBuilder(): AfsImageBuilder
	{
		$oBuilder = new AfsImageBuilder();
		$oBuilder->addFile('$.!Boot',"*EXEC Menu\r",0x00000000,0xFFFFFFFF,0x15);
		$oBuilder->addDirectory('$.Library');
		$oBuilder->addFile('$.Library.Fred',str_repeat("F",300),0xFFFF1900,0xFFFF8023);
		$oBuilder->addDirectory('$.Library.Sub');
		$oBuilder->addFile('$.Library.Sub.Deep',"deep");
		$oBuilder->addFile('$.Passwords',str_repeat("P",256),0,0,0x0C);
		return $oBuilder;
	}

	/**
	 * A builder with a root directory that is 4 sectors long, the entries are Adir, File00 to File29 and Zdir in that order
	 *
	 * Slot 0 is the parent pointer, so the entry with index n is in slot n+1 at offset 17+26*(n+1).  With the first
	 * sector lost, the first whole slot to survive is slot 10 which holds File08.
	*/
	private function _largeRootBuilder(): AfsImageBuilder
	{
		$oBuilder = new AfsImageBuilder(16,16);
		$oBuilder->addDirectory('$.Adir');
		$oBuilder->addFile('$.Adir.Inner',"inside Adir");
		for($i=0;$i<30;$i++){
			$oBuilder->addFile(sprintf('$.File%02d',$i),"content of file ".$i);
		}
		$oBuilder->addDirectory('$.Zdir');
		$oBuilder->addFile('$.Zdir.Inner',"inside Zdir");
		return $oBuilder;
	}

	/**
	 * Removes the recovery details from a catalogue, so it can be compared to one from the strict reader
	*/
	private function _stripRecovery(array $aCatalogue): array
	{
		foreach($aCatalogue as $sName=>$aEntry){
			unset($aCatalogue[$sName]['recovery']);
			if(isset($aEntry['dir'])){
				$aCatalogue[$sName]['dir'] = $this->_stripRecovery($aEntry['dir']);
			}
		}
		return $aCatalogue;
	}

	//********** Undamaged images **********

	public function testAnUndamagedImageGivesTheSameCatalogueAsTheStrictReader()
	{
		$sPath = $this->_write($this->_standardBuilder());
		$oReader = new L3fsReader($sPath);
		$oRecovery = new L3fsRecovery($sPath);
		$this->assertEquals($oReader->getCatalogue(),$this->_stripRecovery($oRecovery->getCatalogue()));
	}

	public function testAnUndamagedImageHasNothingRecovered()
	{
		$oRecovery = new L3fsRecovery($this->_write($this->_standardBuilder()));
		$aCatalogue = $oRecovery->getCatalogue();
		$this->assertArrayNotHasKey(L3fsRecovery::LOST_AND_FOUND,$aCatalogue);
		$this->assertSame(['source'=>'entry','zero_sectors'=>0,'status'=>'ok'],$aCatalogue['!Boot']['recovery']);
		$this->assertSame(['source'=>'entry','zero_sectors'=>0,'status'=>'ok'],$aCatalogue['Library']['recovery']);
		$this->assertSame(['source'=>'entry','zero_sectors'=>0,'status'=>'ok'],$aCatalogue['Library']['dir']['Sub']['dir']['Deep']['recovery']);
	}

	public function testTheReportForAnUndamagedImage()
	{
		$oBuilder = $this->_standardBuilder();
		$oRecovery = new L3fsRecovery($this->_write($oBuilder));
		$aReport = $oRecovery->getRecoveryReport();
		$this->assertSame('pointer',$aReport['geometry_source']);
		$this->assertSame(16*8,$aReport['sectors']);
		$this->assertSame(16,$aReport['sectors_per_cylinder']);
		$this->assertSame($oBuilder->getSin('$'),$aReport['root_sin']);
		//The root, 2 directories and 4 files
		$this->assertSame(7,$aReport['maps']);
		$this->assertSame(2,$aReport['directories']);
		$this->assertSame(4,$aReport['files']);
		$this->assertSame(0,$aReport['damaged_directories']);
		$this->assertSame(0,$aReport['fake_directories']);
		$this->assertSame(0,$aReport['suspect_files']);
		$this->assertSame(0,$aReport['lost_files']);
		$this->assertSame(0,$aReport['salvaged_entries']);
		$this->assertSame(0,$aReport['orphans']);
		$this->assertSame([],$aReport['problems']);
	}

	public function testFilesCanBeReadFromTheRecoveredTree()
	{
		$oBuilder = $this->_standardBuilder();
		$oRecovery = new L3fsRecovery($this->_write($oBuilder));
		$this->assertSame("*EXEC Menu\r",$oRecovery->getFile('$.!Boot'));
		$this->assertSame(str_repeat("F",300),$oRecovery->getFile('$.Library.Fred'));
		$this->assertSame("deep",$oRecovery->getFile('$.library.SUB.deep'));
		$this->assertTrue($oRecovery->isFile('$.Library.Fred'));
		$this->assertTrue($oRecovery->isDir('$.Library.Sub'));
		$this->assertSame(['size'=>300,'sector'=>$oBuilder->getSin('$.Library.Fred')],$oRecovery->getStat('$.Library.Fred'));
	}

	public function testAnEmptyDisk()
	{
		$oRecovery = new L3fsRecovery($this->_write(new AfsImageBuilder()));
		$this->assertSame([],$oRecovery->getCatalogue());
	}

	public function testRecoveryProducesNoOutput()
	{
		$this->expectOutputString('');
		$oBuilder = $this->_largeRootBuilder();
		$sPath = $this->_write($oBuilder);
		$this->_loseSector($sPath,$oBuilder->getExtents('$')[0]['sector']);
		$oRecovery = new L3fsRecovery($sPath);
		$oRecovery->getCatalogue();
		$oRecovery->getRecoveryReport();
	}

	//********** Loss of the info sectors **********

	public function testFindsTheInfoSectorBySearchingWhenThePointersAreLost()
	{
		$oBuilder = $this->_standardBuilder();
		$sPath = $this->_write($oBuilder);
		$this->_loseSector($sPath,0);
		$this->_loseSector($sPath,1);
		$sIntact = $this->_write($oBuilder);
		$oRecovery = new L3fsRecovery($sPath);
		$this->assertSame('scan',$oRecovery->getRecoveryReport()['geometry_source']);
		$this->assertEquals((new L3fsReader($sIntact))->getCatalogue(),$this->_stripRecovery($oRecovery->getCatalogue()));
	}

	public function testFindsTheRootByItsNameWhenThereIsNoInfoSectorLeft()
	{
		$oBuilder = $this->_standardBuilder();
		$sPath = $this->_write($oBuilder);
		$this->_loseSector($sPath,0);
		$this->_loseSector($sPath,1);
		$this->_loseSector($sPath,$oBuilder->getInfoSector(1));
		$this->_loseSector($sPath,$oBuilder->getInfoSector(2));
		$sIntact = $this->_write($oBuilder);
		$oRecovery = new L3fsRecovery($sPath);
		$aReport = $oRecovery->getRecoveryReport();
		$this->assertSame('none',$aReport['geometry_source']);
		$this->assertSame($oBuilder->getSin('$'),$aReport['root_sin']);
		$this->assertEquals((new L3fsReader($sIntact))->getCatalogue(),$this->_stripRecovery($oRecovery->getCatalogue()));
		$this->assertSame("deep",$oRecovery->getFile('$.Library.Sub.Deep'));
	}

	public function testAnImageWithNothingOnItGivesAnEmptyCatalogue()
	{
		$sPath = tempnam(sys_get_temp_dir(),'afs');
		$this->aImages[] = $sPath;
		file_put_contents($sPath,str_repeat("\0",256*64));
		$oRecovery = new L3fsRecovery($sPath);
		$this->assertSame([],$oRecovery->getCatalogue());
		$this->assertSame(64,$oRecovery->getRecoveryReport()['zero_sectors']);
	}

	//********** Loss of part of the root directory **********

	public function testEntriesAreSalvagedFromTheSurvivingSectorsOfTheRoot()
	{
		$oBuilder = $this->_largeRootBuilder();
		$sPath = $this->_write($oBuilder);
		$this->_loseSector($sPath,$oBuilder->getExtents('$')[0]['sector']);
		$oRecovery = new L3fsRecovery($sPath);
		$aCatalogue = $oRecovery->getCatalogue();

		//File08 onwards are in slots that are wholly in the surviving sectors
		for($i=8;$i<30;$i++){
			$sName = sprintf('File%02d',$i);
			$this->assertArrayHasKey($sName,$aCatalogue);
			$this->assertSame('salvaged',$aCatalogue[$sName]['recovery']['source']);
			$this->assertSame('ok',$aCatalogue[$sName]['recovery']['status']);
			$this->assertSame("content of file ".$i,$oRecovery->getFile('$.'.$sName));
		}
		$this->assertSame('salvaged',$aCatalogue['Zdir']['recovery']['source']);
		$this->assertSame("inside Zdir",$oRecovery->getFile('$.Zdir.Inner'));
		//The salvaged entries keep the details from the directory
		$this->assertSame(0xFFFF0E00,$aCatalogue['File20']['load']);
		$this->assertSame(0xFFFF802B,$aCatalogue['File20']['exec']);
		$this->assertSame(0x0D,$aCatalogue['File20']['access']);
	}

	public function testADirectoryWhoseEntryIsLostIsPlacedUsingItsParentPointer()
	{
		$oBuilder = $this->_largeRootBuilder();
		$sPath = $this->_write($oBuilder);
		$this->_loseSector($sPath,$oBuilder->getExtents('$')[0]['sector']);
		$oRecovery = new L3fsRecovery($sPath);
		$aCatalogue = $oRecovery->getCatalogue();
		$this->assertArrayHasKey('Adir',$aCatalogue);
		$this->assertSame('dir',$aCatalogue['Adir']['type']);
		$this->assertSame('parent-pointer',$aCatalogue['Adir']['recovery']['source']);
		$this->assertSame($oBuilder->getSin('$.Adir'),$aCatalogue['Adir']['sector']);
		$this->assertSame("inside Adir",$oRecovery->getFile('$.Adir.Inner'));
	}

	public function testFilesWhoseEntriesAreLostGoInLostAndFound()
	{
		$oBuilder = $this->_largeRootBuilder();
		$sPath = $this->_write($oBuilder);
		$this->_loseSector($sPath,$oBuilder->getExtents('$')[0]['sector']);
		$oRecovery = new L3fsRecovery($sPath);
		$aCatalogue = $oRecovery->getCatalogue();
		$this->assertArrayHasKey(L3fsRecovery::LOST_AND_FOUND,$aCatalogue);
		$this->assertSame('fake',$aCatalogue[L3fsRecovery::LOST_AND_FOUND]['recovery']['status']);
		$aLost = $aCatalogue[L3fsRecovery::LOST_AND_FOUND]['dir'];
		//File00 to File07 were in the lost sector (File07 was in the slot that runs over the end of it)
		$this->assertCount(8,$aLost);
		for($i=0;$i<8;$i++){
			$this->assertArrayNotHasKey(sprintf('File%02d',$i),$aCatalogue);
			$sName = 'file-'.$oBuilder->getSin(sprintf('$.File%02d',$i));
			$this->assertArrayHasKey($sName,$aLost);
			$this->assertSame('orphan',$aLost[$sName]['recovery']['source']);
			$this->assertSame("content of file ".$i,$oRecovery->getFile('$.'.L3fsRecovery::LOST_AND_FOUND.'.'.$sName));
		}
	}

	public function testTheReportForADamagedRoot()
	{
		$oBuilder = $this->_largeRootBuilder();
		$sPath = $this->_write($oBuilder);
		$this->_loseSector($sPath,$oBuilder->getExtents('$')[0]['sector']);
		$oRecovery = new L3fsRecovery($sPath);
		$aReport = $oRecovery->getRecoveryReport();
		//22 files and Zdir
		$this->assertSame(23,$aReport['salvaged_entries']);
		$this->assertSame(1,$aReport['parent_pointer_directories']);
		$this->assertSame(8,$aReport['orphans']);
		$this->assertSame(32,$aReport['files']);
		$this->assertSame(0,$aReport['lost_files']);
		//Adir, Zdir and Lost+Found
		$this->assertSame(3,$aReport['directories']);
		$this->assertSame(1,$aReport['fake_directories']);
		$this->assertCount(1,$aReport['problems']);
		$this->assertStringContainsString('root directory',$aReport['problems'][0]);
	}

	public function testALaterSectorOfTheRootBeingLostKeepsTheEntriesEitherSideOfIt()
	{
		$oBuilder = $this->_largeRootBuilder();
		$sPath = $this->_write($oBuilder);
		//The second sector holds slots 10 to 18 whole, that is File08 to File16
		$this->_loseSector($sPath,$oBuilder->getExtents('$')[0]['sector']+1);
		$oRecovery = new L3fsRecovery($sPath);
		$aCatalogue = $oRecovery->getCatalogue();
		//The chain can be followed up to the lost sector
		$this->assertSame('entry',$aCatalogue['Adir']['recovery']['source']);
		$this->assertSame('entry',$aCatalogue['File06']['recovery']['source']);
		//And the entries after it are found in their slots
		$this->assertSame('salvaged',$aCatalogue['File19']['recovery']['source']);
		$this->assertSame('salvaged',$aCatalogue['Zdir']['recovery']['source']);
		$this->assertSame("content of file 25",$oRecovery->getFile('$.File25'));
		//Those in the lost sector are still recovered, but without their names
		$this->assertArrayNotHasKey('File10',$aCatalogue);
		$sName = 'file-'.$oBuilder->getSin('$.File10');
		$this->assertSame("content of file 10",$oRecovery->getFile('$.'.L3fsRecovery::LOST_AND_FOUND.'.'.$sName));
	}

	public function testTheRemainsOfADeletedEntryAreNotSalvaged()
	{
		$oBuilder = $this->_largeRootBuilder();
		$sPath = $this->_write($oBuilder);
		$this->_loseSector($sPath,$oBuilder->getExtents('$')[0]['sector']);
		//What the fileserver leaves in the map of a deleted object
		$iSin = $oBuilder->getSin('$.File20');
		AfsImageBuilder::patch($sPath,$iSin,0x06,"\x00\x00\x00\x00".str_repeat("\0",5));
		AfsImageBuilder::patch($sPath,$iSin,0xFF,"\x01");
		$oRecovery = new L3fsRecovery($sPath);
		$aCatalogue = $oRecovery->getCatalogue();
		$this->assertArrayNotHasKey('File20',$aCatalogue);
		$this->assertArrayHasKey('File21',$aCatalogue);
		$this->assertSame(1,$oRecovery->getRecoveryReport()['deleted_maps']);
	}

	//********** Loss of the map of the root directory **********

	public function testAFakeRootIsMadeWhenTheMapOfTheRootIsLost()
	{
		$oBuilder = $this->_standardBuilder();
		$sPath = $this->_write($oBuilder);
		$this->_loseSector($sPath,$oBuilder->getSin('$'));
		$oRecovery = new L3fsRecovery($sPath);
		$aCatalogue = $oRecovery->getCatalogue();

		//Library knows its parent was the root
		$this->assertSame('parent-pointer',$aCatalogue['Library']['recovery']['source']);
		$this->assertSame(['Fred','Sub'],array_keys($aCatalogue['Library']['dir']));
		$this->assertSame('entry',$aCatalogue['Library']['dir']['Fred']['recovery']['source']);
		$this->assertSame("deep",$oRecovery->getFile('$.Library.Sub.Deep'));

		//The two files from the root have nothing to say where they came from
		$aLost = $aCatalogue[L3fsRecovery::LOST_AND_FOUND]['dir'];
		$this->assertSame(['file-'.$oBuilder->getSin('$.!Boot'),'file-'.$oBuilder->getSin('$.Passwords')],array_keys($aLost));
		$this->assertSame("*EXEC Menu\r",$oRecovery->getFile('$.'.L3fsRecovery::LOST_AND_FOUND.'.file-'.$oBuilder->getSin('$.!Boot')));
		$this->assertStringContainsString('fake root',implode("\n",$oRecovery->getRecoveryReport()['problems']));
	}

	public function testAFakeRootIsMadeWhenThereIsNoInfoSectorAndNoRootDirectory()
	{
		$oBuilder = $this->_standardBuilder();
		$sPath = $this->_write($oBuilder);
		$this->_loseSector($sPath,0);
		$this->_loseSector($sPath,1);
		$this->_loseSector($sPath,$oBuilder->getInfoSector(1));
		$this->_loseSector($sPath,$oBuilder->getInfoSector(2));
		$this->_loseSector($sPath,$oBuilder->getSin('$'));
		$oRecovery = new L3fsRecovery($sPath);
		$aCatalogue = $oRecovery->getCatalogue();
		$this->assertSame(0,$oRecovery->getRecoveryReport()['root_sin']);
		//Nothing says which SIN the root had, so what pointed to it is gathered under a made up directory
		$this->assertSame([L3fsRecovery::LOST_AND_FOUND],array_keys($aCatalogue));
		$aLost = $aCatalogue[L3fsRecovery::LOST_AND_FOUND]['dir'];
		$sFake = 'dir-'.$oBuilder->getSin('$');
		$this->assertArrayHasKey($sFake,$aLost);
		$this->assertSame('fake',$aLost[$sFake]['recovery']['status']);
		$this->assertSame(['Library'],array_keys($aLost[$sFake]['dir']));
		$this->assertSame("deep",$oRecovery->getFile('$.'.L3fsRecovery::LOST_AND_FOUND.'.'.$sFake.'.Library.Sub.Deep'));
	}

	//********** Loss of parts of other directories **********

	public function testADirectoryWithALostMapKeepsItsNameAndItsSubDirectories()
	{
		$oBuilder = $this->_standardBuilder();
		$sPath = $this->_write($oBuilder);
		$this->_loseSector($sPath,$oBuilder->getSin('$.Library'));
		$oRecovery = new L3fsRecovery($sPath);
		$aCatalogue = $oRecovery->getCatalogue();
		//The root still lists Library, and Sub still points to it as its parent
		$this->assertSame('entry',$aCatalogue['Library']['recovery']['source']);
		$this->assertSame('lost',$aCatalogue['Library']['recovery']['status']);
		$this->assertSame(['Sub'],array_keys($aCatalogue['Library']['dir']));
		$this->assertSame('parent-pointer',$aCatalogue['Library']['dir']['Sub']['recovery']['source']);
		$this->assertSame("deep",$oRecovery->getFile('$.Library.Sub.Deep'));
		//Fred was only listed in the lost directory
		$sName = 'file-'.$oBuilder->getSin('$.Library.Fred');
		$this->assertSame(str_repeat("F",300),$oRecovery->getFile('$.'.L3fsRecovery::LOST_AND_FOUND.'.'.$sName));
	}

	public function testADirectoryWithItsFirstSectorLostIsStillListedByItsParent()
	{
		$oBuilder = $this->_standardBuilder();
		$sPath = $this->_write($oBuilder);
		$this->_loseSector($sPath,$oBuilder->getExtents('$.Library')[0]['sector']);
		$oRecovery = new L3fsRecovery($sPath);
		$aCatalogue = $oRecovery->getCatalogue();
		$this->assertSame('entry',$aCatalogue['Library']['recovery']['source']);
		$this->assertSame('damaged',$aCatalogue['Library']['recovery']['status']);
		$this->assertSame(1,$aCatalogue['Library']['recovery']['zero_sectors']);
		$this->assertSame(['Sub'],array_keys($aCatalogue['Library']['dir']));
		$this->assertSame("deep",$oRecovery->getFile('$.Library.Sub.Deep'));
	}

	public function testAFakeDirectoryIsMadeForAParentThatHasGoneCompletely()
	{
		$oBuilder = $this->_standardBuilder();
		$sPath = $this->_write($oBuilder);
		//Lose the root's entry for Library, and the map of Library
		$this->_loseSector($sPath,$oBuilder->getExtents('$')[0]['sector']);
		$this->_loseSector($sPath,$oBuilder->getSin('$.Library'));
		$oRecovery = new L3fsRecovery($sPath);
		$aCatalogue = $oRecovery->getCatalogue();
		$this->assertArrayNotHasKey('Library',$aCatalogue);
		$aLost = $aCatalogue[L3fsRecovery::LOST_AND_FOUND]['dir'];
		$sFake = 'dir-'.$oBuilder->getSin('$.Library');
		$this->assertSame('fake',$aLost[$sFake]['recovery']['source']);
		$this->assertSame('fake',$aLost[$sFake]['recovery']['status']);
		$this->assertSame(['Sub'],array_keys($aLost[$sFake]['dir']));
		$this->assertSame("deep",$oRecovery->getFile('$.'.L3fsRecovery::LOST_AND_FOUND.'.'.$sFake.'.Sub.Deep'));
	}

	public function testDirectoriesThatPointToEachOtherDoNotCauseALoop()
	{
		$oBuilder = $this->_standardBuilder();
		$sPath = $this->_write($oBuilder);
		//Lose the root's entry for Library, then make Library claim its own sub directory as its parent
		$this->_loseSector($sPath,$oBuilder->getExtents('$')[0]['sector']);
		$iSubSin = $oBuilder->getSin('$.Library.Sub');
		AfsImageBuilder::patch($sPath,$oBuilder->getExtents('$.Library')[0]['sector'],17+23,chr($iSubSin & 0xFF).chr(($iSubSin >> 8) & 0xFF).chr(($iSubSin >> 16) & 0xFF));
		$oRecovery = new L3fsRecovery($sPath);
		$aCatalogue = $oRecovery->getCatalogue();
		//Both directories must still be reachable somewhere under Lost+Found
		$aLost = $aCatalogue[L3fsRecovery::LOST_AND_FOUND]['dir'];
		$sName = 'Library~'.$oBuilder->getSin('$.Library');
		$this->assertArrayHasKey($sName,$aLost);
		$this->assertSame(str_repeat("F",300),$oRecovery->getFile('$.'.L3fsRecovery::LOST_AND_FOUND.'.'.$sName.'.Fred'));
		$this->assertSame("deep",$oRecovery->getFile('$.'.L3fsRecovery::LOST_AND_FOUND.'.'.$sName.'.Sub.Deep'));
	}

	//********** Damage to files **********

	public function testAFileWithALostMapIsListedButCanNotBeRead()
	{
		$oBuilder = $this->_standardBuilder();
		$sPath = $this->_write($oBuilder);
		$this->_loseSector($sPath,$oBuilder->getSin('$.!Boot'));
		$oRecovery = new L3fsRecovery($sPath);
		$aCatalogue = $oRecovery->getCatalogue();
		$this->assertSame('lost',$aCatalogue['!Boot']['recovery']['status']);
		$this->assertSame(0,$aCatalogue['!Boot']['size']);
		$this->assertSame(0x15,$aCatalogue['!Boot']['access']);
		$this->assertSame(1,$oRecovery->getRecoveryReport()['lost_files']);
		$this->expectException(Exception::class);
		$oRecovery->getFile('$.!Boot');
	}

	public function testAFileWithALostDataSectorIsMarkedAsSuspect()
	{
		$sData = str_repeat("A",256).str_repeat("B",256).str_repeat("C",100);
		$oBuilder = new AfsImageBuilder();
		$oBuilder->addFile('$.Holed',$sData);
		$oBuilder->addFile('$.Whole',"untouched");
		$sPath = $this->_write($oBuilder);
		$this->_loseSector($sPath,$oBuilder->getExtents('$.Holed')[0]['sector']+1);
		$oRecovery = new L3fsRecovery($sPath);
		$aCatalogue = $oRecovery->getCatalogue();
		$this->assertSame('suspect',$aCatalogue['Holed']['recovery']['status']);
		$this->assertSame(1,$aCatalogue['Holed']['recovery']['zero_sectors']);
		$this->assertSame('ok',$aCatalogue['Whole']['recovery']['status']);
		//The file keeps its length, with zeros where the lost sector was
		$this->assertSame(str_repeat("A",256).str_repeat("\0",256).str_repeat("C",100),$oRecovery->getFile('$.Holed'));
		$this->assertSame(1,$oRecovery->getRecoveryReport()['suspect_files']);
	}

	public function testGetZeroRangesGivesThePartsOfAFileThatMayBeLost()
	{
		$oBuilder = new AfsImageBuilder();
		$oBuilder->addFile('$.Holed',str_repeat("A",(5*256)+100));
		$oBuilder->addFile('$.Whole',"untouched");
		$sPath = $this->_write($oBuilder);
		$iFirst = $oBuilder->getExtents('$.Holed')[0]['sector'];
		//Two neighbouring sectors in the middle, and the part used last sector
		$this->_loseSector($sPath,$iFirst+1);
		$this->_loseSector($sPath,$iFirst+2);
		$this->_loseSector($sPath,$iFirst+5);
		$oRecovery = new L3fsRecovery($sPath);
		$this->assertSame([['offset'=>256,'length'=>512],['offset'=>1280,'length'=>100]],$oRecovery->getZeroRanges('$.Holed'));
		$this->assertSame([],$oRecovery->getZeroRanges('$.Whole'));
	}

	public function testAFileIsCutShortWhenALaterMapBlockIsLost()
	{
		$sData = "";
		for($i=0;$i<60;$i++){
			$sData .= str_pad("[sector ".$i."]",256,chr(65+($i % 26)));
		}
		$oBuilder = new AfsImageBuilder(16,16);
		$oBuilder->addFile('$.Chained',$sData,0,0,0x0D,true);
		$sPath = $this->_write($oBuilder);
		$sLink = AfsImageBuilder::peek($sPath,$oBuilder->getSin('$.Chained'),0xFA,3);
		$this->_loseSector($sPath,ord($sLink[0]) + (ord($sLink[1]) << 8) + (ord($sLink[2]) << 16));
		$oRecovery = new L3fsRecovery($sPath);
		$aCatalogue = $oRecovery->getCatalogue();
		//The first map block describes 48 sectors
		$this->assertSame('suspect',$aCatalogue['Chained']['recovery']['status']);
		$this->assertSame(48*256,$aCatalogue['Chained']['size']);
		$this->assertSame(substr($sData,0,48*256),$oRecovery->getFile('$.Chained'));
		$this->assertStringContainsString('chain of map blocks',implode("\n",$oRecovery->getRecoveryReport()['problems']));
	}

	public function testAnIntactChainOfMapBlocksIsFollowed()
	{
		$sData = "";
		for($i=0;$i<60;$i++){
			$sData .= str_pad("[sector ".$i."]",256,chr(65+($i % 26)));
		}
		$sData = substr($sData,0,(59*256)+40);
		$oBuilder = new AfsImageBuilder(16,16);
		$oBuilder->addFile('$.Chained',$sData,0,0,0x0D,true);
		$oRecovery = new L3fsRecovery($this->_write($oBuilder));
		$this->assertSame('ok',$oRecovery->getCatalogue()['Chained']['recovery']['status']);
		$this->assertSame($sData,$oRecovery->getFile('$.Chained'));
		//The second map block has no identifier and must not be counted as an object
		$this->assertSame(2,$oRecovery->getRecoveryReport()['maps']);
	}

	public function testFileDataThatLooksLikeAMapIsNotTreatedAsOne()
	{
		$oBuilder = new AfsImageBuilder();
		//A sector that starts with the map identifier and has matching bytes where the sequence numbers go
		$oBuilder->addFile('$.Tricky',"JesMap".str_repeat("X",250));
		$oRecovery = new L3fsRecovery($this->_write($oBuilder));
		$aCatalogue = $oRecovery->getCatalogue();
		$this->assertSame(['Tricky'],array_keys($aCatalogue));
		$aReport = $oRecovery->getRecoveryReport();
		$this->assertSame(2,$aReport['maps']);
		$this->assertSame(1,$aReport['rejected_maps']);
		$this->assertSame(0,$aReport['orphans']);
	}

	public function testAMapWithAnExtentBeyondTheEndOfTheImageIsNotUsed()
	{
		$oBuilder = $this->_standardBuilder();
		$sPath = $this->_write($oBuilder);
		AfsImageBuilder::patch($sPath,$oBuilder->getSin('$.!Boot'),0x0A,"\xFF\xFF\x0F");
		$oRecovery = new L3fsRecovery($sPath);
		$this->assertSame('lost',$oRecovery->getCatalogue()['!Boot']['recovery']['status']);
		$this->assertSame(1,$oRecovery->getRecoveryReport()['rejected_maps']);
	}

	public function testAMapWithAnExtentOnABitmapSectorIsNotUsed()
	{
		$oBuilder = $this->_standardBuilder();
		$sPath = $this->_write($oBuilder);
		//Sector 32 is the first sector of cylinder 2, which is its bitmap
		AfsImageBuilder::patch($sPath,$oBuilder->getSin('$.!Boot'),0x0A,"\x20\x00\x00");
		$oRecovery = new L3fsRecovery($sPath);
		$this->assertSame('lost',$oRecovery->getCatalogue()['!Boot']['recovery']['status']);
	}
}
