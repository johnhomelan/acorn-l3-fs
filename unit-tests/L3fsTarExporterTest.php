<?php
/**
 * Tests for the class that writes the contents of a disk image to a tar archive, in the layout used by the LocalFile plugin of aun-filestore
 *
 * The archives are read back with PharData, so the tests also show the archives can be read by something other than the code that wrote them.
*/

namespace HomeLan\Retro\Acorn\Disk\Tests;

use PharData;
use PHPUnit\Framework\TestCase;
use HomeLan\Retro\Acorn\Disk\L3fsReader;
use HomeLan\Retro\Acorn\Disk\L3fsRecovery;
use HomeLan\Retro\Acorn\Disk\L3fsTarExporter;

class L3fsTarExporterTest extends TestCase {

	private $aFiles = [];

	protected function tearDown(): void
	{
		foreach($this->aFiles as $sPath){
			if(file_exists($sPath)){
				unlink($sPath);
			}
		}
		$this->aFiles = [];
	}

	private function _write(AfsImageBuilder $oBuilder): string
	{
		$sPath = $oBuilder->write();
		$this->aFiles[] = $sPath;
		return $sPath;
	}

	/**
	 * Gets a path for an archive that is removed at the end of the test
	*/
	private function _tarPath(): string
	{
		$sPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('afs',true).'.tar';
		$this->aFiles[] = $sPath;
		return $sPath;
	}

	/**
	 * Reads an archive back
	 *
	 * @return array Indexed by the path in the archive, each being array('dir'=>,'data'=>,'mode'=>,'mtime'=>)
	*/
	private function _readTar(string $sTarPath): array
	{
		$aEntries = [];
		$sRoot = 'phar://'.$sTarPath.'/';
		foreach(new \RecursiveIteratorIterator(new PharData($sTarPath),\RecursiveIteratorIterator::SELF_FIRST) as $oFile){
			$sName = substr($oFile->getPathname(),strlen($sRoot));
			$aEntries[$sName] = ['dir'=>$oFile->isDir(),'data'=>$oFile->isDir() ? NULL : file_get_contents($oFile->getPathname()),'mode'=>$oFile->getPerms() & 0777,'mtime'=>$oFile->getMTime()];
		}
		ksort($aEntries);
		return $aEntries;
	}

	private function _standardBuilder(): AfsImageBuilder
	{
		$oBuilder = new AfsImageBuilder();
		$oBuilder->addFile('$.!Boot',"*EXEC Menu\r",0x00000000,0xFFFFFFFF,0x15);
		$oBuilder->addDirectory('$.Library');
		$oBuilder->addFile('$.Library.Fred',str_repeat("F",300),0xFFFF1900,0xFFFF8023,0x0D);
		$oBuilder->addDirectory('$.Library.Sub');
		$oBuilder->addFile('$.Library.Sub.Deep',"deep",0x1234,0xABCDEF01,0x0F);
		$oBuilder->addDirectory('$.Empty');
		return $oBuilder;
	}

	public function testEveryFileAndDirectoryIsWrittenWithASidecarForEachFile()
	{
		$sTarPath = $this->_tarPath();
		$oExporter = new L3fsTarExporter(new L3fsReader($this->_write($this->_standardBuilder())));
		$oExporter->export($sTarPath);
		$aEntries = $this->_readTar($sTarPath);
		$this->assertSame(['!Boot','!Boot.inf','Empty','Library','Library/Fred','Library/Fred.inf','Library/Sub','Library/Sub/Deep','Library/Sub/Deep.inf'],array_keys($aEntries));
		$this->assertTrue($aEntries['Library']['dir']);
		$this->assertTrue($aEntries['Empty']['dir']);
		$this->assertFalse($aEntries['Library/Fred']['dir']);
	}

	public function testFilesHoldTheirContents()
	{
		$sTarPath = $this->_tarPath();
		$oExporter = new L3fsTarExporter(new L3fsReader($this->_write($this->_standardBuilder())));
		$oExporter->export($sTarPath);
		$aEntries = $this->_readTar($sTarPath);
		$this->assertSame("*EXEC Menu\r",$aEntries['!Boot']['data']);
		$this->assertSame(str_repeat("F",300),$aEntries['Library/Fred']['data']);
		$this->assertSame("deep",$aEntries['Library/Sub/Deep']['data']);
	}

	public function testSidecarsHoldTheLoadAndExecAddressesInTheFormatAunFilestoreUses()
	{
		$sTarPath = $this->_tarPath();
		$oExporter = new L3fsTarExporter(new L3fsReader($this->_write($this->_standardBuilder())));
		$oExporter->export($sTarPath);
		$aEntries = $this->_readTar($sTarPath);
		$this->assertSame("TAPE file 00000000 ffffffff",$aEntries['!Boot.inf']['data']);
		$this->assertSame("TAPE file ffff1900 ffff8023",$aEntries['Library/Fred.inf']['data']);
		$this->assertSame("TAPE file 00001234 abcdef01",$aEntries['Library/Sub/Deep.inf']['data']);
		//The expression aun-filestore reads them with
		foreach(['!Boot.inf','Library/Fred.inf','Library/Sub/Deep.inf'] as $sName){
			$this->assertSame(1,preg_match('/^TAPE file ([0-9a-fA-F]+) ([0-9a-fA-F]+)/',$aEntries[$sName]['data']));
		}
	}

	public function testTheAccessIsCarriedInTheMode()
	{
		$oBuilder = new AfsImageBuilder();
		$oBuilder->addFile('$.LockedRR',"x",0,0,0x15);
		$oBuilder->addFile('$.WRR',"x",0,0,0x0D);
		$oBuilder->addFile('$.WRWR',"x",0,0,0x0F);
		$oBuilder->addFile('$.WR',"x",0,0,0x0C);
		$oBuilder->addFile('$.None',"x",0,0,0x00);
		$oBuilder->addDirectory('$.Dir');
		$sTarPath = $this->_tarPath();
		$oExporter = new L3fsTarExporter(new L3fsReader($this->_write($oBuilder)));
		$oExporter->export($sTarPath);
		$aEntries = $this->_readTar($sTarPath);
		$this->assertSame(0404,$aEntries['LockedRR']['mode']);
		$this->assertSame(0604,$aEntries['WRR']['mode']);
		$this->assertSame(0606,$aEntries['WRWR']['mode']);
		$this->assertSame(0600,$aEntries['WR']['mode']);
		//The owner can always read, so the file is usable once unpacked
		$this->assertSame(0400,$aEntries['None']['mode']);
		$this->assertSame(0755,$aEntries['Dir']['mode']);
	}

	public function testTheDateOfTheEntryIsUsedAsTheModificationTime()
	{
		$sTarPath = $this->_tarPath();
		$oExporter = new L3fsTarExporter(new L3fsReader($this->_write($this->_standardBuilder())));
		$oExporter->export($sTarPath);
		$aEntries = $this->_readTar($sTarPath);
		//The builder dates everything the 15th of March 1986
		$this->assertSame(gmmktime(12,0,0,3,15,1986),$aEntries['!Boot']['mtime']);
		$this->assertSame(gmmktime(12,0,0,3,15,1986),$aEntries['!Boot.inf']['mtime']);
		$this->assertSame(gmmktime(12,0,0,3,15,1986),$aEntries['Library']['mtime']);
	}

	public function testASlashInAnAcornNameBecomesADot()
	{
		$oBuilder = new AfsImageBuilder();
		$oBuilder->addFile('$.Prog/bas',"10 PRINT");
		$sTarPath = $this->_tarPath();
		$oExporter = new L3fsTarExporter(new L3fsReader($this->_write($oBuilder)));
		$oExporter->export($sTarPath);
		$this->assertSame(['Prog.bas','Prog.bas.inf'],array_keys($this->_readTar($sTarPath)));
	}

	public function testANameThatWouldBeTakenForASidecarIsSkipped()
	{
		$oBuilder = new AfsImageBuilder();
		$oBuilder->addFile('$.Data/inf',"x");
		$oBuilder->addFile('$.Other',"y");
		$sTarPath = $this->_tarPath();
		$oExporter = new L3fsTarExporter(new L3fsReader($this->_write($oBuilder)));
		$aResult = $oExporter->export($sTarPath);
		$this->assertSame(['Other','Other.inf'],array_keys($this->_readTar($sTarPath)));
		$this->assertCount(1,$aResult['skipped']);
		$this->assertSame('$.Data/inf',$aResult['skipped'][0]['path']);
	}

	public function testEverythingCanBePutInADirectoryInsideTheArchive()
	{
		$sTarPath = $this->_tarPath();
		$oExporter = new L3fsTarExporter(new L3fsReader($this->_write($this->_standardBuilder())));
		$oExporter->export($sTarPath,'disc0');
		$aEntries = $this->_readTar($sTarPath);
		$this->assertTrue($aEntries['disc0']['dir']);
		$this->assertSame("deep",$aEntries['disc0/Library/Sub/Deep']['data']);
		$this->assertArrayNotHasKey('!Boot',$aEntries);
	}

	public function testTheResultCountsWhatWasWritten()
	{
		$sTarPath = $this->_tarPath();
		$oExporter = new L3fsTarExporter(new L3fsReader($this->_write($this->_standardBuilder())));
		$aResult = $oExporter->export($sTarPath);
		$this->assertSame(['directories'=>3,'files'=>3,'bytes'=>315,'suspect'=>[],'skipped'=>[]],$aResult);
	}

	public function testAnEmptyDiskGivesAnEmptyArchive()
	{
		$sTarPath = $this->_tarPath();
		$oExporter = new L3fsTarExporter(new L3fsReader($this->_write(new AfsImageBuilder())));
		$aResult = $oExporter->export($sTarPath);
		$this->assertSame(0,$aResult['files']);
		//Just the two empty blocks that end an archive
		$this->assertSame(1024,filesize($sTarPath));
	}

	public function testALargeFileIsWrittenWhole()
	{
		$sData = "";
		for($i=0;$i<40;$i++){
			$sData .= str_pad("[sector ".$i."]",256,chr(65+($i % 26)));
		}
		$sData = substr($sData,0,(39*256)+77);
		$oBuilder = new AfsImageBuilder(16,8);
		$oBuilder->addFile('$.Big',$sData);
		$oBuilder->addFile('$.After',"after");
		$sTarPath = $this->_tarPath();
		$oExporter = new L3fsTarExporter(new L3fsReader($this->_write($oBuilder)));
		$oExporter->export($sTarPath);
		$aEntries = $this->_readTar($sTarPath);
		$this->assertSame($sData,$aEntries['Big']['data']);
		//What follows a file that is not a whole number of tar blocks must still be found
		$this->assertSame("after",$aEntries['After']['data']);
		$this->assertSame(0,filesize($sTarPath) % 512);
	}

	public function testPathsLongerThanAHundredCharactersAreStored()
	{
		$oBuilder = new AfsImageBuilder(16,16);
		$sPath = '$';
		$sExpected = '';
		for($i=0;$i<12;$i++){
			$sPath .= '.Level'.sprintf('%04d',$i);
			$sExpected .= 'Level'.sprintf('%04d',$i).'/';
			$oBuilder->addDirectory($sPath);
		}
		$oBuilder->addFile($sPath.'.TheFile',"at the bottom");
		$sTarPath = $this->_tarPath();
		$oExporter = new L3fsTarExporter(new L3fsReader($this->_write($oBuilder)));
		$aResult = $oExporter->export($sTarPath);
		$this->assertSame([],$aResult['skipped']);
		$aEntries = $this->_readTar($sTarPath);
		$this->assertGreaterThan(100,strlen($sExpected.'TheFile'));
		$this->assertSame("at the bottom",$aEntries[$sExpected.'TheFile']['data']);
		$this->assertSame("TAPE file ffff0e00 ffff802b",$aEntries[$sExpected.'TheFile.inf']['data']);
	}

	//********** Exporting from a damaged image with the recovery class **********

	public function testADamagedImageIsExportedWithALostAndFoundDirectory()
	{
		$oBuilder = $this->_standardBuilder();
		$sImagePath = $this->_write($oBuilder);
		//Lose the map of the root, so its files have no names and Library is found from its parent pointer
		AfsImageBuilder::patch($sImagePath,$oBuilder->getSin('$'),0,str_repeat("\0",256));
		$sTarPath = $this->_tarPath();
		$oExporter = new L3fsTarExporter(new L3fsRecovery($sImagePath));
		$aResult = $oExporter->export($sTarPath);
		$aEntries = $this->_readTar($sTarPath);
		$this->assertSame("deep",$aEntries['Library/Sub/Deep']['data']);
		$sLost = L3fsRecovery::LOST_AND_FOUND.'/file-'.$oBuilder->getSin('$.!Boot');
		$this->assertSame("*EXEC Menu\r",$aEntries[$sLost]['data']);
		//Nothing is known of the load and exec addresses of a file without a directory entry
		$this->assertSame("TAPE file 00000000 00000000",$aEntries[$sLost.'.inf']['data']);
		$this->assertSame(3,$aResult['files']);
		$this->assertSame([],$aResult['skipped']);
	}

	public function testAFileWithLostContentsIsSkippedAndReported()
	{
		$oBuilder = $this->_standardBuilder();
		$sImagePath = $this->_write($oBuilder);
		AfsImageBuilder::patch($sImagePath,$oBuilder->getSin('$.!Boot'),0,str_repeat("\0",256));
		$sTarPath = $this->_tarPath();
		$oExporter = new L3fsTarExporter(new L3fsRecovery($sImagePath));
		$aResult = $oExporter->export($sTarPath);
		$aEntries = $this->_readTar($sTarPath);
		$this->assertArrayNotHasKey('!Boot',$aEntries);
		$this->assertArrayNotHasKey('!Boot.inf',$aEntries);
		$this->assertSame('$.!Boot',$aResult['skipped'][0]['path']);
		$this->assertSame(2,$aResult['files']);
	}

	public function testAFileWithAZeroSectorIsWrittenAndReportedAsSuspect()
	{
		$oBuilder = new AfsImageBuilder();
		$oBuilder->addFile('$.Holed',str_repeat("A",256).str_repeat("B",256).str_repeat("C",100));
		$oBuilder->addFile('$.Whole',"untouched");
		$sImagePath = $this->_write($oBuilder);
		AfsImageBuilder::patch($sImagePath,$oBuilder->getExtents('$.Holed')[0]['sector']+1,0,str_repeat("\0",256));
		$sTarPath = $this->_tarPath();
		$oExporter = new L3fsTarExporter(new L3fsRecovery($sImagePath));
		$aResult = $oExporter->export($sTarPath);
		$aEntries = $this->_readTar($sTarPath);
		$this->assertSame(str_repeat("A",256).str_repeat("\0",256).str_repeat("C",100),$aEntries['Holed']['data']);
		$this->assertSame(['$.Holed'],$aResult['suspect']);
	}
}
