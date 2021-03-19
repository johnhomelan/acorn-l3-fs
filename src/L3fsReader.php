<?php
/**
 * Reads Level 3 fileserver disk images 
 *
*/

namespace \HomeLan\Retro\Acorn\Disk;

class L3fsReader {

	const TRACKS_PER_SIDE = 80;
	const SECTOR_SIZE = 256;
	const FILE_NAME_LEN = 10;	
	const METADATA_SIZE = 26;

	private $sImagePath = NULL;

	private $sTitle = NULL;
	
	private $iTrackCount = NULL;
	
	private $iSectorCount = NULL;

	private $iDiskCount = NULL ; 
	
	private $iSectorsPerTrack = NULL;

	private $iSectorsPerBitMap = NULL;
	
	private $iIncrementToNextDrive = NULL;
	
	private $iRootDirSin = NULL;

	private $iInitData = NULL;

	private $iFirstFreeTrack  = NULL;

	private $aCatalogue = NULL;

	private $bInterleaved = false;

	/**
	 * Creates a new instance of the reader
	 *
	 * @param string $sPath The path to the disk image to read
	 * @param string $sDiskImage A binary string of the disk image (don't supply this and the path a the same time)
	*/
	public function __construct(string $sPath)
	{
		$this->sImagePath = $sPath;
		$this->_readMetaData();
	}

	private function _readMetaData()
	{

		$aSector0 = $this->_getSectorAsByteArray(0);

		//Jump to the location (0xF6) where the pointer to the beging of AFS0 is (well its begining +1)
		$iAFS0Start = $this->_read24BitInt($aSector0,0xF6);

		//Jump to the location of the second pointer (0x1F6) to the beging of AFS0 is (well its begining +1)
		$aSector1 = $this->_getSectorAsByteArray(1);
		$iAFS0Start2 = $this->_read24BitInt($aSector1,0xF6);

		//Check both pointer are same
		/*if($iAFS0Start2!=$iAFS0Start){
			throw new Exception("First and second pointers to the start of the fileserver partition dont match");
		}*/
		$this->iNfsPartitionStart = $iAFS0Start;
		$aAFSSector = $this->_getSectorAsByteArray($this->iNfsPartitionStart);

		//Read first 4 bytes to check the image is acctually a L3 fs
		//00-03: "AFS0" identifier.
		$sIdentifyier = $this->_readString($aAFSSector,0x0,4);
		if($sIdentifyier != "AFS0"){
			throw new Exception("The volume lable is not AFS0 thus this is not a valid LS filesystem");
		}
		//Read Disk title, the next 16 bytes (0x04)
		$this->sTitle = $this->_readString($aAFSSector,0x04,16);
		//14-15: Number of tracks (cylinders) on whole disk.
		$this->iTrackCount = $this->_read16BitInt($aAFSSector,0x14);
		//16-18: Number of sectors on whole disk, ie &000A00 for a 640K floppy.
		$this->iSectorCount = $this->_read24BitInt($aAFSSector,0x16);
		//19 : Number of physical disks on file server, usually 1.
		$this->iDiskCount = $aAFSSector[0x19];
		//1A-1B: Number of sectors per track, ie 16 for a 640K floppy.
		$this->iSectorsPerTrack = $this->_read16BitInt($aAFSSector,0x1A);
		//1C : Number of sectors per bitmap, usually 1.
		$this->iSectorsPerBitMap = $aAFSSector[0x1C];
		//1D : Increment to next drive, usually 0 for only one drive.
		$this->iIncrementToNextDrive = $aAFSSector[0x1D];
		//Next bye should be 1
		//1E : Unused, set to zero.
		if($aAFSSector[0x1E]!=1){
			 throw new Exception("Volume head fault, a byte that should be 0 is none 0");
		}
		//1F-21: System Internal Name (SIN) of root directory.
		$this->iRootDirSin = $this->_read24BitInt($aAFSSector,0x1F);
		//22-23: Initialisation date in filing system standard date format.
		$this->iInitData = $this->_read16BitInt($aAFSSector,0x22);
		//24-25: First free cylinder.
		$this->iFirstFreeTrack  = $this->_read16BitInt($aAFSSector,0x24);
		if ($this->iSectorCount % $this->iSectorsPerTrack != 0){
			throw new Exception("The number of sectors not on an integer number of tracks");
		}

	}

	/**
	 * Gets a raw sector from the disk image
	 *
	 * This method calculates the location in the disk image of the sector and returns the bytes contained in that sector
	 * @param int $iSector The sector number 
	 * @return string Binary String 
	*/
	private function _getSectorRaw(int $iSector):string 
	{
		if($this->bInterleaved){
			$iTrack = floor($iSector/$this->iSectorsPerTrack);
			if ($iTrack < (self::TRACKS_PER_SIDE-1)){
				$iStart = (2 * $iTrack * $this->iSectorsPerTrack * self::SECTOR_SIZE) + (($iSector - ($this->iSectorsPerTrack*$iTrack)) * self::SECTOR_SIZE) ;
			}else{
				$iStart = (self::SECTOR_SIZE * $this->iSectorsPerTrack) + (2 * ($iTrack - self::TRACKS_PER_SIDE) * $this->iSectorsPerTrack * self::SECTOR_SIZE) + (($iSector - ($this->iSectorsPerTrack*$iTrack)) * self::SECTOR_SIZE) ;
			}
		}else{
			$iStart = self::SECTOR_SIZE * $iSector;
		}
		$iFileHandle = fopen($this->sImagePath,'r');
		fseek($iFileHandle,$iStart,SEEK_CUR);
		$sBytes = fread($iFileHandle,self::SECTOR_SIZE);
		fclose($iFileHandle);
		return $sBytes;
	
	}

	/**
	 * Gets a sector from the disk as an array of bytes
	 *
	 * @param int $iSector The sector number
	 * @return array
	*/
	private function _getSectorAsByteArray(int $iSector):array
	{
		return array_values(unpack('C*',$this->_getSectorRaw($iSector)));
	}

	/**
	 * Reads a 32 bit int by reading 4 bytes from the current file handel position
	 *
	 * @param int $iFileHandle
	 * @return int
	*/
	private function _read32BitInt(array $aBytes, int $iStart):int
	{
		return $this->_decode32bitAddr($aBytes[$iStart], $aBytes[$iStart+1], $aBytes[$iStart+2], $aBytes[$iStart+3]);

	}	
	/**
	 * Reads a 24 bit int by reading 3 bytes from the current file handel position
	 *
	 * @param int $iFileHandle
	 * @return int
	*/
	private function _read24BitInt(array $aBytes, int $iStart):int
	{
		return $this->_decode24bitAddr($aBytes[$iStart],$aBytes[$iStart+1],$aBytes[$iStart+2]);

	}	

	/**
	 * Reads a 16 bit int by reading 2 bytes from the current file handel position
	 *
	 * @param int $iFileHandle
	 * @return int
	*/
	private function _read16BitInt(array $aBytes, int $iStart):int
	{
		return $this->_decode16bitAddr($aBytes[$iStart],$aBytes[$iStart+1]);

	}	

	private function _readString(array $aBytes, int $iStart, int $iLength):string
	{
		$sReturn = "";
		for($i=0;$i<$iLength;$i++){
			$sReturn = $sReturn.chr($aBytes[$iStart+$i]);
		}
		return $sReturn;
	}

	/**
	 * Gets a number of sectors as a byte array
	 * 
	 * Returns a number of sectors inclusive of the start sector
	 * @param int $iStartSector
	 * @param int $iCount
	 * @return array
	*/
	private function _getSectorsAsByteArray(int $iStartSector,int $iCount):array
	{
		if($iCount==0){
			return [];
		}
		$sBlock = "";
		for($i=0;$i<$iCount;$i++){
			$sBlock .= $this->_getSectorRaw($iStartSector+$i);
		}
		return array_values(unpack('C*',$sBlock));
	}

	/**
	 * Gets a number of sectors as the raw binary 
	 *
	 * Returns a number of sectors inclusive of the start sector
	 * @param int $iStartSector
	 * @param int $iCount
	*/
	private function _getSectorsRaw(int $iStartSector,int $iCount):string
	{
		if($iCount==0){
			return "";
		}
		$sBlock = "";
		for($i=0;$i<$iCount;$i++){
			$sBlock .= $this->_getSectorRaw($iStartSector+$i);
		}
		return $sBlock;
	}

	/**
	 * Decodes the 7bit format used by adfs
	 *
	 * @param int $iByte (0-255)
	 * @return int 
	*/
	private function _decode7bit(int $iByte):int 
	{
		return $iByte & 127;
	}

	/**
	 * Decodes the 32bit int address form used by adfs
	 *
	 * @param int $iByte1
	 * @param int $iByte2
	 * @param int $iByte3
	 * @param int $iByte4
	 * @return int
	*/
	private function _decode32bitAddr(int $iByte1,int $iByte2,int $iByte3,int $iByte4):int
	{
		return ($iByte4 << 24) + ($iByte3 << 16) + ($iByte2 << 8) + $iByte1;
	}

	/**
	 * Decodes the 24bit int form used by adfs
	 *
	 * @param int $iByte1
	 * @param int $iByte2
	 * @param int $iByte3
	 * @return int
	*/
	private function _decode24bitAddr(int $iByte1,int $iByte2,int $iByte3):int
	{
		return ($iByte3 << 16) + ($iByte2 << 8) + $iByte1;
	}

	/**
	 * Decodes the 16bit int form used by adfs
	 *
	 * @param int $iByte1
	 * @param int $iByte2
	 * @return int
	*/
	private function _decode16bitAddr(int $iByte1, int $iByte2):int
	{
		return ($iByte2 << 8) + $iByte1;
	}

	/**
	 * Reads block from the filing system 
	 * 
	 * Starts and the begining of a sector then reads a set number of bytes 
	 * @param int $iStartSector
	 * @param int $iLen
	 * @return string
	*/
	private function getBlocks(int $iStartSector,int $iLen): string
	{
		$iSectors = floor($iLen/self::SECTOR_SIZE);
		$iRemainder = $iLen - $iSectors*self::SECTOR_SIZE;
		$sBlock = $this->_getSectorsRaw($iStartSector,$iSectors);
		
		$sBlock .= substr($this->_getSectorRaw($iStartSector+$iSectors),0,$iRemainder);
		return $sBlock;
	}

	/**
	 * Gets all the data from across the disk pointed to by an AllocationMap
	 *
	 * @param int $iSin System internal name
	 * @return string
	*/ 
	private function _getDataPointedToByAllocationMap(int $iSin)
	{
		$iSector = $iSin;
		$aData = $this->_getSectorsAsByteArray($iSector,1);
		if("JesMap" != $this->_readString($aData,0x0,6)){
			throw new Exception("Invalid Allocation Map");
		}
		if($aData[0x6]!=$aData[0xff]){
			throw new Exception("The first and second chain numbers do not match");
		}
		$sReturn="";
		//Every 5 bytes (starting from 0x0A) is a table entry consisitng of sector (3 bytes) and length (2 Bytes)

		//Set the currenty entry in the chain we are
		$iChainEntry = $aData[0x6];
		$iChainCount = 0;
		while(true){
			//Check the chain entry read from the disk is correct
			if($aData[6]!=$iChainEntry){
				throw new Exception("Expected the next entry in the chain to be ".$iChainEntry." but its numbered ".$aData[6]." in the disk image");
			}
			for($i = 0xA; $i<0xFA; $i=$i+5){
				if($this->_read16BitInt($aData,$i+3)==0){
					//Last entry jump out of the loop
					break;
				}
				$sReturn = $sReturn.$this->_getSectorsRaw($this->_read24BitInt($aData,$i), $this->_read16BitInt($aData,$i+3));
			}
			//Check to see if this is the last AlloctionMap in the chain
			$iNextEntry = $this->_read24BitInt($aData,0xFA);
			
			if($iNextEntry == 0){
				//The map has ended 
				break;
			}
			if($this->_read16BitInt($aData,0xFD)!=1){
				throw new Exception("The next part of the jsemap is more than 1 sectory, don't know how to handle that");
			}
			//Get the next section of the allocation map
			$aData = $this->_getSectorsAsByteArray($iNextEntry,$this->_read16BitInt($aData,0xFD));
			$iChainEntry--;

			//Check we are not running on forever and that the chain had an end
			$iChainCount++;
			if($iChainCount>255){
				throw new Exception("The chain of allocation map entries has run on too long"); 
			}
		}
		return $sReturn;
	}

	/**
	 * Calculates the size of the object from the allocation map
	 *
	 * @param int $iSecotor the sector the maps starts at
	 * @return int Size in bytes
	*/
	private function _getSizeFromAllocationMap(int $iSin):int
	{
		$iSector = $iSin ;
		$aData = $this->_getSectorsAsByteArray($iSector,1);
		if("JesMap" != $this->_readString($aData,0x0,6)){
			throw new Exception("Invalid Allocation Map");
		}
		if($aData[6]!=0){
			throw new Exception("Not the first entry in the chain, but we should be");
		}
		if($aData[6]!=$aData[0xff]){
			throw new Exception("The chain sequence numbers at 0x6 and 0xff should match but they do not");
		}
		$iReturn  = 0;
		//Every 5 bytes (starting from 0x0A) is a table entry consisitng of sector (3 bytes) and length (2 Bytes)

		//Set the currenty entry in the chain we are
		$iChainEntry = 0;
		while(true){
			//Check the chain entry read from the disk is correct
			if($aData[6]!=$iChainEntry){
				throw new Exception("Expected the next entry in the chain to be ".$iChainEntry." but its numbered ".$aData[6]." in the disk image");
			}
			for($i = 0xA; $i<0xFA; $i=$i+5){
				if($this->_read16BitInt($aData,$i+3)==0){
					//Last entry jump out of the loop
					break;
				}
				//Add together the secotor count
				$iReturn = $iReturn+$this->_read16BitInt($aData,$i+3);
			}
			//Check to see if this is the last AlloctionMap in the chain
			$iNextEntry = $this->_read24BitInt($aData,0xFA);
			if($iNextEntry == 0){
				//The map has ended 
				break;
			}
			if($this->_read16BitInt($aData,0xFD)!=1){
				throw new Exception("The next part of the jsemap is more than 1 sectory, don't know how to handle that");
			}
			//Get the next section of the allocation map
			$aData = $this->_getSectorsAsByteArray($iNextEntry,1);
			$iChainEntry++;

			//Check we are not running on forever and that the chain had an end
			if($iChainEntry>255){
				throw new Exception("The chain of allocation map entries has run on too long"); 
			}
		}
		return $iReturn*self::SECTOR_SIZE;
	}

	/**
	 * Gets all the data from across the disk pointed to by an AllocationMap
	 *
	 * @param int $iSin System internal name 
	 * @return array An Array of bytes in the order stored on the disk
	*/
	private function _getDataPointedToByAllocationMapAsByteArray(int $iSin):array
	{
		return array_values(unpack('C*',$this->_getDataPointedToByAllocationMap($iSin)));
	}

	/**
	 * Extracts the disc catalogue 
	 *
	 * A Level3's driectory structure is stored in sectors scattered across the disk with an allocation map joining them all together.
	 *
	 * @return array The array is in the format array('dir'=>array('filename'=>array('loadaddr'=>,'execaddr'=>,'size'=>,'startsector'=>,'attr'=>,'date'=>)));
	*/
	public function getCatalogue():array
	{
		if(is_null($this->aCatalogue)){
			$aCat = array();
			$this->aCatalogue = $this->_getCatalogueStartingAtSector($this->iRootDirSin);
		}		
		return $this->aCatalogue;
	}

	private function _getCatalogueStartingAtSector(int $iSin,int $iDepth=0):array
	{
		if($iDepth==255){
			//return [];
		}
		$aDirectoryTable = $this->_getDataPointedToByAllocationMapAsByteArray($iSin);
		$iEntryCount = $this->_read16BitInt($aDirectoryTable,0x0f);
		$iEntryPointer = $this->_read16BitInt($aDirectoryTable,0x0);
		$sCurrentDir = $this->_readString($aDirectoryTable,0x3,10);
		$aCat=[];
		//The real first entry in the table is 0x11 but that the parent directory and this code will go every wrong is we examine that entry
		$iFirstEntry = 0x11;
		$iRecordLength = 0x1a;
		//Step through every entry
		for($i=0;$i<$iEntryCount;$i++){
			if($iEntryPointer == 0){
				break;
			}
			$aEntry = $this->_readDirectoryEntry(array_slice($aDirectoryTable, $iEntryPointer, $iRecordLength));

			$aCat[$aEntry['filename']]=$aEntry;

			//Recurse the tree if its a directory 
			if($aEntry['type']=='dir'){
				$aCat[$aEntry['filename']]['dir'] = $this->_getCatalogueStartingAtSector($aEntry['sector'],$iDepth+1);
			}
			//Move to the next entry
			$iEntryPointer = $aEntry['next'];
			if(($i+1)==$iEntryCount){
				//This should be the last entry and the ptr to next should be 0
				if($aEntry['next']!=0){
					throw new Exception("Invalid ptr to next for final directory entry");
				}
			}
			//If the next entry is larger that the directory table quietly drop out. 
			if($aEntry['next']>count($aDirectoryTable)){
				break;
			}
		}
		return $aCat;
	}

	function _readDirectoryEntry(array $aData):array
	{
		$aReturn = [];
		$aReturn['filename'] = trim($this->_readString($aData,0x2,10));
		$aReturn['load'] = $this->_read32BitInt($aData,0xC);
		$aReturn['exec'] = $this->_read32BitInt($aData,0x10);
		$aReturn['access'] = $aData[0x14];
		$aReturn['date'] = $this->_read16BitInt($aData,0x15);
		$aReturn['sector'] = $this->_read24BitInt($aData,0x17);
		if($aData[0x14] & 32){
			$aReturn['type'] = 'dir';
		}else {
			$aReturn['type'] = 'file';
		}
		$aReturn['locked'] = $aData[0x14] & 16;
		$aReturn['user_read'] = $aData[0x14] & 4;
		$aReturn['user_write'] = $aData[0x14] & 8;
		$aReturn['public_read'] = $aData[0x14] & 2;
		$aReturn['public_write'] = $aData[0x14] & 1;
		$aReturn['next'] = $this->_read16BitInt($aData, 0x0);
		if($aReturn['type'] == 'file'){
			try {
				$aReturn['size'] = $this->_getSizeFromAllocationMap($aReturn['sector']);
			}catch(Exception $oException){
				$aReturn['size']=0;
			}
		}
		return $aReturn;
	}

	/**
	 * Gets a file from the filing system
	 *
	 * The file name supplied can be read from the root dir of the image or a subdir, the filename supplied
	 * may be a full file path to access files in a subdir
	 * @param string $sFileName
	 * @return string
	*/
	public function getFile(string $sFileName):string
	{
		$aCat = $this->getCatalogue();
		$aParts = explode('.',$sFileName);
		foreach($aParts as $sPart){
			$aKeys = array_keys($aCat);
			$bFound = false;
			foreach($aKeys as $sTestKey){
				if(strtolower($sTestKey)==strtolower($sPart)){
					$bFound=true;
					break;
				}
			}
			if($bFound){
				if($aCat[$sTestKey]['type']=='file'){
					return $this->_getDataPointedToByAllocationMap($aCat[$sTestKey]['sector']);
				}
				if($aCat[$sTestKey]['type']=='dir'){
					$aCat = $aCat[$sTestKey]['dir'];
				}
			}
		}
	}

	/**
	 * Gets the file stats for a given file 
	 *
	 * @param string $sFileName
	 * @return array
	*/
	public function getStat(string $sFileName):array
	{
		$aCat = $this->getCatalogue();
		$aParts = explode('.',$sFileName);
		foreach($aParts as $sPart){
			$aKeys = array_keys($aCat);
			$bFound = false;
			foreach($aKeys as $sTestKey){
				if(strtolower($sTestKey)==strtolower($sPart)){
					$bFound=true;
					break;
				}
			}
			if($bFound){
				if($aCat[$sTestKey]['type']=='file'){
					return array('size'=>$aCat[$sTestKey]['size'],'sector'=>$aCat[$sTestKey]['sector']);
				}
				if($aCat[$sTestKey]['type']=='dir'){
					$aCat = $aCat[$sTestKey];
				}
			}
		}
	}

	/**
	 * Tests if a path is a file or not
	 *
	 * @param string $sFileName
	 * @return boolean Returns true if its a file false if not
	*/
	public function isFile(string $sFileName):bool
	{
		$aCat = $this->getCatalogue();
		$aParts = explode('.',$sFileName);
		$iParts = count($aParts);
		
		foreach($aParts as $iIndex => $sPart){
				$aKeys = array_keys($aCat);
				$bFound = false;
				foreach($aKeys as $sTestKey){
					if(strtolower($sTestKey)==strtolower($sPart)){
						$bFound=true;
						break;
					}
				}
				if($iIndex+1 == $iParts){
					//last entry 
				
					if($bFound){
						if($aCat[$sTestKey]['type']=='file'){
							return true;
						}
					}
				}
				if($bFound){
					if($aCat[$sPart]['type']=='dir'){
						$aCat = $aCat[$sTestKey]['dir'];
					}else{
						return false;
					}
				}
		}
		return false;
	}

	/**
	 * Tests if a path is a directory or not
	 *
	 * @param string $sFileName
	 * @return boolean Returns true if its a directory false if not
	*/
	public function isDir(string $sFileName):bool
	{
		$aCat = $this->getCatalogue();
		$aParts = explode('.',$sFileName);
		$iParts = count($aParts);
		foreach($aParts as $iIndex => $sPart){
			$aKeys = array_keys($aCat);
			$bFound = false;
			foreach($aKeys as $sTestKey){
				if(strtolower($sTestKey)==strtolower($sPart)){
					$bFound=true;
					break;
				}
			}if($iIndex+1 == $iParts){
				//last entry 
				if($bFound){
					if($aCat[$sTestKey]['type']=='dir'){
						return true;
					}
				}
			}
			if($bFound){
				if($aCat[$sPart]['type']=='dir'){
					$aCat = $aCat[$sTestKey]['dir'];
				}else{
					return false;
				}
			}
		}
		return false;
	}
}
