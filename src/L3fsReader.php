<?php
/**
 * Reads Level 3 fileserver disk images 
 *
*/

namespace HomeLan\Retro\Acorn\Disk;

use Exception;

class L3fsReader {

	const TRACKS_PER_SIDE = 80;
	const SECTOR_SIZE = 256;
	const FILE_NAME_LEN = 10;	
	const METADATA_SIZE = 26;
	const DIR_HEADER_SIZE = 17;
	//Each map block has 49 five byte entries, the last of which is the link to the next map block
	const MAP_ENTRIES = 49;

	protected $sImagePath = NULL;

	protected $iNfsPartitionStart = NULL;

	protected $sTitle = NULL;
	
	protected $iTrackCount = NULL;
	
	protected $iSectorCount = NULL;

	protected $iDiskCount = NULL ; 
	
	protected $iSectorsPerTrack = NULL;

	protected $iSectorsPerBitMap = NULL;
	
	protected $iIncrementToNextDrive = NULL;
	
	protected $iRootDirSin = NULL;

	protected $iInitData = NULL;

	protected $iFirstFreeTrack  = NULL;

	protected $aCatalogue = NULL;

	protected $bInterleaved = false;

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

	/**
	 * Reads the details of the disk from the AFS0 info sector
	*/
	protected function _readMetaData()
	{
		$this->_parseInfoSector($this->_findInfoSector());
	}

	/**
	 * Finds a copy of the AFS0 info sector using the pointers in sectors 0 and 1
	 *
	 * @return array The info sector as an array of bytes
	*/
	protected function _findInfoSector():array
	{

		//Sectors 0 and 1 each hold a pointer (at 0xF6) to a different copy of the AFS0 info sector.
		//Try the pointer from a sector with a good ADFS checksum first, then fall back to the other.
		$aGoodChecksum = [];
		$aBadChecksum = [];
		foreach([0,1] as $iSector){
			$aSector = $this->_getSectorAsByteArray($iSector);
			if(count($aSector)!=self::SECTOR_SIZE){
				continue;
			}
			//Only the low 21 bits are the sector number, the top 3 are used for the drive number
			$iPointer = $this->_read24BitInt($aSector,0xF6) & 0x1FFFFF;
			if($this->_adfsChecksum($aSector)==$aSector[0xFF]){
				$aGoodChecksum[] = $iPointer;
			}else{
				$aBadChecksum[] = $iPointer;
			}
		}
		$aCandidates = array_merge($aGoodChecksum,$aBadChecksum);

		//Read first 4 bytes to check the image is acctually a L3 fs
		//00-03: "AFS0" identifier.
		$aAFSSector = NULL;
		foreach($aCandidates as $iPointer){
			$aSector = $this->_getSectorAsByteArray($iPointer);
			if(count($aSector)==self::SECTOR_SIZE AND $this->_readString($aSector,0x0,4)=="AFS0"){
				$this->iNfsPartitionStart = $iPointer;
				$aAFSSector = $aSector;
				break;
			}
		}
		if(is_null($aAFSSector)){
			throw new Exception("The volume lable is not AFS0 thus this is not a valid LS filesystem");
		}
		return $aAFSSector;
	}

	/**
	 * Extracts the details of the disk from a copy of the AFS0 info sector
	 *
	 * @param array $aAFSSector The info sector as an array of bytes
	*/
	protected function _parseInfoSector(array $aAFSSector)
	{
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
		//1E : Increment to next logical drive, usually 1.
		//1F-21: System Internal Name (SIN) of root directory.
		$this->iRootDirSin = $this->_read24BitInt($aAFSSector,0x1F);
		//22-23: Initialisation date in filing system standard date format.
		$this->iInitData = $this->_read16BitInt($aAFSSector,0x22);
		//24-25: First free cylinder.
		$this->iFirstFreeTrack  = $this->_read16BitInt($aAFSSector,0x24);
		if ($this->iSectorsPerTrack == 0 OR $this->iSectorCount % $this->iSectorsPerTrack != 0){
			throw new Exception("The number of sectors not on an integer number of tracks");
		}

	}

	/**
	 * Calculates the ADFS old map checksum of a sector
	 *
	 * Used on sectors 0 and 1 only (nothing in the fileserver partition is checksummed), the result should match the last byte of the sector
	 * @param array $aBytes The sector as an array of bytes
	 * @return int
	*/
	protected function _adfsChecksum(array $aBytes):int
	{
		$iSum = 255;
		for($i=254;$i>=0;$i--){
			$iSum = ($iSum & 0xFF) + $aBytes[$i] + ($iSum >> 8);
		}
		return $iSum & 0xFF;
	}

	/**
	 * Gets a raw sector from the disk image
	 *
	 * This method calculates the location in the disk image of the sector and returns the bytes contained in that sector
	 * @param int $iSector The sector number 
	 * @return string Binary String 
	*/
	protected function _getSectorRaw(int $iSector):string 
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
	protected function _getSectorAsByteArray(int $iSector):array
	{
		return array_values(unpack('C*',$this->_getSectorRaw($iSector)));
	}

	/**
	 * Reads a 32 bit int by reading 4 bytes from the current file handel position
	 *
	 * @param int $iFileHandle
	 * @return int
	*/
	protected function _read32BitInt(array $aBytes, int $iStart):int
	{
		return $this->_decode32bitAddr($aBytes[$iStart], $aBytes[$iStart+1], $aBytes[$iStart+2], $aBytes[$iStart+3]);

	}	
	/**
	 * Reads a 24 bit int by reading 3 bytes from the current file handel position
	 *
	 * @param int $iFileHandle
	 * @return int
	*/
	protected function _read24BitInt(array $aBytes, int $iStart):int
	{
		return $this->_decode24bitAddr($aBytes[$iStart],$aBytes[$iStart+1],$aBytes[$iStart+2]);

	}	

	/**
	 * Reads a 16 bit int by reading 2 bytes from the current file handel position
	 *
	 * @param int $iFileHandle
	 * @return int
	*/
	protected function _read16BitInt(array $aBytes, int $iStart):int
	{
		return $this->_decode16bitAddr($aBytes[$iStart],$aBytes[$iStart+1]);

	}	

	protected function _readString(array $aBytes, int $iStart, int $iLength):string
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
	protected function _getSectorsAsByteArray(int $iStartSector,int $iCount):array
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
	protected function _getSectorsRaw(int $iStartSector,int $iCount):string
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
	protected function _decode7bit(int $iByte):int 
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
	protected function _decode32bitAddr(int $iByte1,int $iByte2,int $iByte3,int $iByte4):int
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
	protected function _decode24bitAddr(int $iByte1,int $iByte2,int $iByte3):int
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
	protected function _decode16bitAddr(int $iByte1, int $iByte2):int
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
	protected function getBlocks(int $iStartSector,int $iLen): string
	{
		$iSectors = floor($iLen/self::SECTOR_SIZE);
		$iRemainder = $iLen - $iSectors*self::SECTOR_SIZE;
		$sBlock = $this->_getSectorsRaw($iStartSector,$iSectors);
		
		$sBlock .= substr($this->_getSectorRaw($iStartSector+$iSectors),0,$iRemainder);
		return $sBlock;
	}

	/**
	 * Reads the allocation map (JesMap) for an object, following the chain of map blocks if there is more than one
	 *
	 * @param int $iSin System internal name
	 * @return array In the format array('extents'=>array(array('sector'=>,'count'=>)),'size'=>) where size is in bytes
	*/
	protected function _readAllocationMap(int $iSin):array
	{
		$aExtents = [];
		$iSectors = 0;
		$iBytesInLastBlock = 0;
		$iBlock = $iSin;
		$iChainCount = 0;
		while(true){
			$aData = $this->_getSectorAsByteArray($iBlock);
			if(count($aData)!=self::SECTOR_SIZE){
				throw new Exception("The allocation map block at sector ".$iBlock." is outside the disk image");
			}
			if($iChainCount==0){
				//Only the first block in a chain carries the identifier and the bytes in last block count
				if("JesMap" != $this->_readString($aData,0x0,6)){
					throw new Exception("Invalid Allocation Map");
				}
				$iBytesInLastBlock = $this->_read16BitInt($aData,0x8);
			}
			//The sequence numbers are per block, a deleted or part written block has them out of step
			if($aData[0x6]!=$aData[0xff]){
				throw new Exception("The sequence numbers at 0x6 and 0xff of the allocation map block at sector ".$iBlock." do not match");
			}
			//Every 5 bytes (starting from 0x0A) is a table entry consisitng of sector (3 bytes) and length (2 Bytes)
			$iNextBlock = 0;
			for($i=0;$i<self::MAP_ENTRIES;$i++){
				$iOffset = 0xA + ($i*5);
				$iStart = $this->_read24BitInt($aData,$iOffset);
				if($iStart==0){
					//Last entry jump out of the loop
					break;
				}
				if($i==(self::MAP_ENTRIES-1)){
					//The final entry is never data, its the sector of the next map block
					$iNextBlock = $iStart;
					break;
				}
				$iCount = $this->_read16BitInt($aData,$iOffset+3);
				$aExtents[] = ['sector'=>$iStart,'count'=>$iCount];
				$iSectors = $iSectors+$iCount;
			}
			if($iNextBlock == 0){
				//The map has ended 
				break;
			}
			$iBlock = $iNextBlock;

			//Check we are not running on forever and that the chain had an end
			$iChainCount++;
			if($iChainCount>255){
				throw new Exception("The chain of allocation map entries has run on too long"); 
			}
		}
		if($iSectors==0){
			$iSize = 0;
		}elseif($iBytesInLastBlock==0){
			$iSize = $iSectors*self::SECTOR_SIZE;
		}else{
			$iSize = (($iSectors-1)*self::SECTOR_SIZE)+$iBytesInLastBlock;
		}
		return ['extents'=>$aExtents,'size'=>$iSize];
	}

	/**
	 * Gets all the data from across the disk pointed to by an AllocationMap
	 *
	 * @param int $iSin System internal name
	 * @return string
	*/ 
	protected function _getDataPointedToByAllocationMap(int $iSin)
	{
		$aMap = $this->_readAllocationMap($iSin);
		$sReturn="";
		foreach($aMap['extents'] as $aExtent){
			$sReturn = $sReturn.$this->_getSectorsRaw($aExtent['sector'],$aExtent['count']);
		}
		if(strlen($sReturn)<$aMap['size']){
			throw new Exception("The object with SIN ".$iSin." runs off the end of the disk image");
		}
		//The last sector is only part used
		return substr($sReturn,0,$aMap['size']);
	}

	/**
	 * Calculates the size of the object from the allocation map
	 *
	 * @param int $iSecotor the sector the maps starts at
	 * @return int Size in bytes
	*/
	protected function _getSizeFromAllocationMap(int $iSin):int
	{
		return $this->_readAllocationMap($iSin)['size'];
	}

	/**
	 * Gets all the data from across the disk pointed to by an AllocationMap
	 *
	 * @param int $iSin System internal name 
	 * @return array An Array of bytes in the order stored on the disk
	*/
	protected function _getDataPointedToByAllocationMapAsByteArray(int $iSin):array
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

	protected function _getCatalogueStartingAtSector(int $iSin,int $iDepth=0):array
	{
		if($iDepth==255){
			throw new Exception("The directory tree is nested too deep, it probably loops back on its self");
		}
		$aDirectoryTable = $this->_getDataPointedToByAllocationMapAsByteArray($iSin);
		$iDirectorySize = count($aDirectoryTable);
		$iRecordLength = self::METADATA_SIZE;
		if($iDirectorySize<(self::DIR_HEADER_SIZE+1)){
			throw new Exception("The directory with SIN ".$iSin." is too short to be a directory");
		}
		//The sequence number at 0x2 is repeated in the last byte of the directory, they differ if the directory was not fully written
		if($aDirectoryTable[0x2]!=$aDirectoryTable[$iDirectorySize-1]){
			throw new Exception("Broken directory, the sequence numbers at the start and end of the directory with SIN ".$iSin." do not match");
		}
		$iEntryCount = $this->_read16BitInt($aDirectoryTable,0x0f);
		$iEntryPointer = $this->_read16BitInt($aDirectoryTable,0x0);
		$sCurrentDir = $this->_readString($aDirectoryTable,0x3,10);
		$aCat=[];
		//The entry at 0x11 can be an unchained dummy entry holding the SIN of the parent directory, as it is not in the chain its never examined here
		//Step through every entry
		for($i=0;$i<$iEntryCount;$i++){
			if($iEntryPointer == 0){
				break;
			}
			//Entries are at fixed positions and must fit before the trailing sequence number
			if($iEntryPointer<self::DIR_HEADER_SIZE OR (($iEntryPointer-self::DIR_HEADER_SIZE) % $iRecordLength)!=0 OR ($iEntryPointer+$iRecordLength)>($iDirectorySize-1)){
				throw new Exception("Invalid ptr to a directory entry (".$iEntryPointer.") in the directory with SIN ".$iSin);
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
		}
		return $aCat;
	}

	protected function _readDirectoryEntry(array $aData):array
	{
		$aReturn = [];
		//Names are padded with spaces (occasionally nulls)
		$aReturn['filename'] = rtrim($this->_readString($aData,0x2,10)," \0");
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
		$aReturn['public_read'] = $aData[0x14] & 1;
		$aReturn['public_write'] = $aData[0x14] & 2;
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
		throw new Exception("File not found ".$sFileName);
	}

	/**
	 * Gets the contents of an object given its SIN (the 'sector' value of its entry in the catalogue)
	 *
	 * @param int $iSin System internal name
	 * @return string
	*/
	public function getFileBySin(int $iSin):string
	{
		return $this->_getDataPointedToByAllocationMap($iSin);
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
					$aCat = $aCat[$sTestKey]['dir'];
				}
			}
		}
		throw new Exception("File not found ".$sFileName);
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
					if($aCat[$sTestKey]['type']=='dir'){
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
				if($aCat[$sTestKey]['type']=='dir'){
					$aCat = $aCat[$sTestKey]['dir'];
				}else{
					return false;
				}
			}
		}
		return false;
	}
}
