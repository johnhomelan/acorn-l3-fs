<?php
/**
 * Reads as much as possible out of a damaged Level 3 fileserver / Filestore disk image
 *
 * Where L3fsReader starts at the root directory and gives up at the first broken structure, this class reads every
 * sector of the image looking for metadata, and then builds the best directory tree it can from what survives.
 * It is intended for images taken from failing disks, where the unreadable sectors have been filled with zero bytes.
 *
 * The catalogue has the same format as the one from L3fsReader, so getFile(), getStat(), isFile() and isDir() all
 * work on the recovered tree.  Every entry has an extra 'recovery' key with the following:
 *
 *   source  'entry'           read from the chain of entries in a directory, as the fileserver would
 *           'salvaged'        found in a slot of a damaged directory, where the chain of entries could not be followed
 *           'parent-pointer'  a directory placed using the pointer it holds to its parent, as the parent no longer lists it
 *           'orphan'          nothing says where the object belongs, so it is in the Lost+Found directory
 *           'fake'            a directory made up to hold other entries, there is nothing on the disk for it
 *   status  'ok'              all the metadata for the object is intact
 *           'suspect'         a file with some sectors that are all zero bytes (may be lost sectors), or a damaged map
 *           'damaged'         a directory where some of its sectors or its header are lost, entries may be missing
 *           'lost'            the entry survives but the map for the object does not, so its contents can not be read
 *           'fake'            see above
 *   zero_sectors              the number of sectors of the object that contain only zero bytes
 *
 * See docs/afs-disc-format.md for the on disk format and the reasoning behind the checks made here.
*/

namespace HomeLan\Retro\Acorn\Disk;

use Exception;

class L3fsRecovery extends L3fsReader {

	const LOST_AND_FOUND = 'Lost+Found';

	protected $sImage = NULL;

	protected $iImageSectors = 0;

	//How the info sector was found 'pointer', 'scan' or 'none'
	protected $sGeometrySource = 'none';

	//True if the disk has a bitmap in the first sector of each cylinder (so no object may use those sectors)
	protected $bCylinderBitmaps = false;

	protected $bScanned = false;

	//Sectors containing only zero bytes, indexed by sector number
	protected $aZeroSectors = [];

	//Every valid map found, indexed by SIN, in the format array('extents'=>,'size'=>,'blocks'=>,'truncated'=>,'overlap'=>)
	protected $aMaps = [];

	//Every directory found, indexed by SIN
	protected $aDirectories = [];

	protected $iDeletedMaps = 0;

	protected $iBrokenMaps = 0;

	protected $iRejectedMaps = 0;

	protected $iRootSin = 0;

	protected $aProblems = [];

	protected $aCounts = [];

	/**
	 * Gets a raw sector from the disk image
	 *
	 * The whole image is held in memory as every sector gets read at least once
	 * @param int $iSector The sector number
	 * @return string Binary String, empty if the sector is beyond the end of the image
	*/
	protected function _getSectorRaw(int $iSector):string
	{
		if(is_null($this->sImage)){
			$sImage = @file_get_contents($this->sImagePath);
			if($sImage===false){
				throw new Exception("Unable to read the disk image ".$this->sImagePath);
			}
			$this->sImage = $sImage;
			$this->iImageSectors = intdiv(strlen($sImage),self::SECTOR_SIZE);
		}
		if($iSector<0 OR $iSector>=$this->iImageSectors){
			return "";
		}
		return substr($this->sImage,$iSector*self::SECTOR_SIZE,self::SECTOR_SIZE);
	}

	/**
	 * Reads the details of the disk from the AFS0 info sector
	 *
	 * If the pointers to the info sectors are lost the whole disk is searched for a copy, and if there is no copy to
	 * be found the recovery carries on without knowing the geometry of the disk or where the root directory is.
	*/
	protected function _readMetaData()
	{
		$aAFSSector = NULL;
		try {
			$aAFSSector = $this->_findInfoSector();
			$this->sGeometrySource = 'pointer';
		}catch(Exception $oException){
			$aAFSSector = $this->_scanForInfoSector();
			if(!is_null($aAFSSector)){
				$this->sGeometrySource = 'scan';
				$this->aProblems[] = "The pointers to the info sectors are lost, a copy was found by searching the disk";
			}
		}
		if(is_null($aAFSSector)){
			$this->aProblems[] = "No info sector could be found, the geometry of the disk and the location of the root directory are unknown";
			return;
		}
		try {
			$this->_parseInfoSector($aAFSSector);
		}catch(Exception $oException){
			$this->aProblems[] = "The info sector is not valid (".$oException->getMessage().")";
			$this->iSectorsPerTrack = NULL;
		}
		//A none zero byte at 0x26 means the bitmaps are packed together (floppy disks), rather than one at the start of each cylinder
		$this->bCylinderBitmaps = (!is_null($this->iSectorsPerTrack) AND $this->iSectorsPerTrack>0 AND $aAFSSector[0x26]==0);
		if(!is_null($this->iRootDirSin) AND $this->iRootDirSin>=$this->iImageSectors){
			$this->aProblems[] = "The info sector places the root directory beyond the end of the image";
			$this->iRootDirSin = NULL;
		}
	}

	/**
	 * Searches the whole disk for a copy of the info sector
	 *
	 * @return array|null The info sector as an array of bytes
	*/
	protected function _scanForInfoSector()
	{
		$this->_getSectorRaw(0);
		for($i=0;$i<$this->iImageSectors;$i++){
			$sRaw = $this->_getSectorRaw($i);
			if(substr($sRaw,0,4)!="AFS0"){
				continue;
			}
			$aSector = array_values(unpack('C*',$sRaw));
			$iSectorsPerTrack = $this->_read16BitInt($aSector,0x1A);
			$iSectorCount = $this->_read24BitInt($aSector,0x16);
			$iRootSin = $this->_read24BitInt($aSector,0x1F);
			//Guard against file data that happens to start with AFS0
			if($iSectorsPerTrack>0 AND ($iSectorCount % $iSectorsPerTrack)==0 AND $iRootSin>0 AND $iRootSin<$this->iImageSectors){
				$this->iNfsPartitionStart = $i;
				return $aSector;
			}
		}
		return NULL;
	}

	/**
	 * Extracts the disc catalogue, recovering as much of it as possible
	 *
	 * @return array The same format as L3fsReader::getCatalogue() with an extra 'recovery' key on every entry
	*/
	public function getCatalogue():array
	{
		if(is_null($this->aCatalogue)){
			$this->_scan();
			$this->_findDirectories();
			$this->aCatalogue = $this->_buildTree();
		}
		return $this->aCatalogue;
	}

	/**
	 * Gets a summary of what was found on the disk
	 *
	 * @return array
	*/
	public function getRecoveryReport():array
	{
		$this->getCatalogue();
		return array_merge([
			'geometry_source'=>$this->sGeometrySource,
			'sectors'=>$this->iImageSectors,
			'sectors_per_cylinder'=>$this->iSectorsPerTrack,
			'zero_sectors'=>count($this->aZeroSectors),
			'maps'=>count($this->aMaps),
			'deleted_maps'=>$this->iDeletedMaps,
			'broken_maps'=>$this->iBrokenMaps,
			'rejected_maps'=>$this->iRejectedMaps,
			'root_sin'=>$this->iRootSin,
		],$this->aCounts,['problems'=>$this->aProblems]);
	}

	/**
	 * Gets the parts of a file that are in sectors containing only zero bytes, which may be sectors that could not be read from the disk
	 *
	 * @param string $sFileName The path of the file in the recovered tree
	 * @return array In the format array(array('offset'=>,'length'=>)) with the offset being in bytes from the start of the file
	*/
	public function getZeroRanges(string $sFileName):array
	{
		$aStat = $this->getStat($sFileName);
		if(!isset($this->aMaps[$aStat['sector']])){
			throw new Exception("The map for ".$sFileName." is lost");
		}
		$iSize = $this->aMaps[$aStat['sector']]['size'];
		$aRanges = [];
		$iOffset = 0;
		foreach($this->aMaps[$aStat['sector']]['extents'] as $aExtent){
			for($i=0;$i<$aExtent['count'];$i++){
				if(isset($this->aZeroSectors[$aExtent['sector']+$i]) AND $iOffset<$iSize){
					$iLength = min(self::SECTOR_SIZE,$iSize-$iOffset);
					$iLast = count($aRanges)-1;
					if($iLast>=0 AND ($aRanges[$iLast]['offset']+$aRanges[$iLast]['length'])==$iOffset){
						$aRanges[$iLast]['length'] = $aRanges[$iLast]['length']+$iLength;
					}else{
						$aRanges[] = ['offset'=>$iOffset,'length'=>$iLength];
					}
				}
				$iOffset = $iOffset+self::SECTOR_SIZE;
			}
		}
		return $aRanges;
	}

	/**
	 * Gets all the data for an object
	 *
	 * Uses the maps found by the scan, which unlike the strict reader copes with a chain of map blocks that has been cut short.
	 * @param int $iSin System internal name
	 * @return string
	*/
	protected function _getDataPointedToByAllocationMap(int $iSin)
	{
		$this->_scan();
		if(!isset($this->aMaps[$iSin])){
			throw new Exception("There is no valid allocation map at sector ".$iSin);
		}
		$sReturn = "";
		foreach($this->aMaps[$iSin]['extents'] as $aExtent){
			$sReturn .= substr($this->sImage,$aExtent['sector']*self::SECTOR_SIZE,$aExtent['count']*self::SECTOR_SIZE);
		}
		return substr($sReturn,0,$this->aMaps[$iSin]['size']);
	}

	//********** Stage 1, read every sector and find the maps **********

	/**
	 * Reads every sector, noting those that are all zero bytes and collecting every valid map
	*/
	protected function _scan()
	{
		if($this->bScanned){
			return;
		}
		$this->bScanned = true;
		$this->_getSectorRaw(0);
		$sZeroSector = str_repeat("\0",self::SECTOR_SIZE);
		$aCandidates = [];
		for($i=0;$i<$this->iImageSectors;$i++){
			$sRaw = substr($this->sImage,$i*self::SECTOR_SIZE,self::SECTOR_SIZE);
			if($sRaw===$sZeroSector){
				$this->aZeroSectors[$i] = true;
			}elseif(strncmp($sRaw,"JesMap",6)==0){
				$aCandidates[] = $i;
			}
		}
		foreach($aCandidates as $iSin){
			$aMap = $this->_readMap($iSin);
			if(!is_null($aMap)){
				$this->aMaps[$iSin] = $aMap;
			}
		}

		//Work out which object owns each sector, two objects claiming the same sector means at least one of the maps is wrong
		$aOwner = [];
		$iOverlaps = 0;
		foreach($this->aMaps as $iSin=>$aMap){
			$aSectors = $aMap['blocks'];
			foreach($aMap['extents'] as $aExtent){
				for($i=0;$i<$aExtent['count'];$i++){
					$aSectors[] = $aExtent['sector']+$i;
				}
			}
			foreach($aSectors as $iSector){
				if(isset($aOwner[$iSector]) AND $aOwner[$iSector]!=$iSin){
					$this->aMaps[$iSin]['overlap'] = true;
					$this->aMaps[$aOwner[$iSector]]['overlap'] = true;
					$iOverlaps++;
				}else{
					$aOwner[$iSector] = $iSin;
				}
			}
		}
		if($iOverlaps>0){
			$this->aProblems[] = $iOverlaps." sectors are claimed by more than one object";
		}
	}

	/**
	 * Reads and checks the map starting at a sector that begins with the map identifier
	 *
	 * @param int $iSin
	 * @return array|null Null if this is not a usable map (deleted, part written, or file data that looks like a map)
	*/
	protected function _readMap(int $iSin)
	{
		$aExtents = [];
		$aBlocks = [];
		$iSectors = 0;
		$iBytesInLastBlock = 0;
		$bTruncated = false;
		$iBlock = $iSin;
		while(true){
			$bHead = (count($aBlocks)==0);
			$sRaw = $this->_getSectorRaw($iBlock);
			$aBlock = (strlen($sRaw)==self::SECTOR_SIZE AND !in_array($iBlock,$aBlocks) AND !$this->_isBitmapSector($iBlock)) ? $this->_parseMapBlock($sRaw) : NULL;
			//A link is only made when there are more extents to hold, so an empty block (such as a lost sector) is not part of a chain
			if(!$bHead AND !is_null($aBlock) AND count($aBlock['extents'])==0){
				$aBlock = NULL;
			}
			if(is_null($aBlock)){
				if($bHead){
					return NULL;
				}
				//A later block in the chain is lost, keep the part of the object the earlier blocks describe
				$bTruncated = true;
				$this->aProblems[] = "The chain of map blocks for the object with SIN ".$iSin." is broken at sector ".$iBlock.", the end of the object is lost";
				break;
			}
			if($bHead){
				$iBytesInLastBlock = $aBlock['bytes_in_last_block'];
			}
			$aBlocks[] = $iBlock;
			foreach($aBlock['extents'] as $aExtent){
				$aExtents[] = $aExtent;
				$iSectors = $iSectors+$aExtent['count'];
			}
			if($aBlock['next']==0){
				break;
			}
			$iBlock = $aBlock['next'];
		}
		if($iSectors==0){
			$iSize = 0;
		}elseif($iBytesInLastBlock==0 OR $bTruncated){
			$iSize = $iSectors*self::SECTOR_SIZE;
		}else{
			$iSize = (($iSectors-1)*self::SECTOR_SIZE)+$iBytesInLastBlock;
		}
		return ['extents'=>$aExtents,'size'=>$iSize,'blocks'=>$aBlocks,'truncated'=>$bTruncated,'overlap'=>false];
	}

	/**
	 * Checks and decodes a single map block
	 *
	 * @param string $sRaw The sector
	 * @return array|null Null if the sector is not a valid map block
	*/
	protected function _parseMapBlock(string $sRaw)
	{
		$bIdentified = (strncmp($sRaw,"JesMap",6)==0);
		$iLeadingSequence = ord($sRaw[0x6]);
		$iTrailingSequence = ord($sRaw[0xFF]);
		if($iLeadingSequence!=$iTrailingSequence){
			if($bIdentified){
				//The fileserver leaves the sequence numbers as 0 and 1 when it deletes an object
				if($iLeadingSequence==0 AND $iTrailingSequence==1){
					$this->iDeletedMaps++;
				}else{
					$this->iBrokenMaps++;
				}
			}
			return NULL;
		}
		//The flag byte and the high byte of the bytes in last block count are always zero
		if(ord($sRaw[0x7])!=0 OR ord($sRaw[0x9])!=0){
			if($bIdentified){
				$this->iRejectedMaps++;
			}
			return NULL;
		}
		$aExtents = [];
		$iNext = 0;
		$iEnd = 0xFF;
		for($i=0;$i<self::MAP_ENTRIES;$i++){
			$iOffset = 0xA+($i*5);
			$iStart = ord($sRaw[$iOffset]) + (ord($sRaw[$iOffset+1]) << 8) + (ord($sRaw[$iOffset+2]) << 16);
			$iCount = ord($sRaw[$iOffset+3]) + (ord($sRaw[$iOffset+4]) << 8);
			if($iStart==0){
				$iEnd = $iOffset;
				break;
			}
			if($i==(self::MAP_ENTRIES-1)){
				//The final entry is the link to the next map block
				if($iStart>=$this->iImageSectors){
					$iStart = -1;
				}
				$iNext = $iStart;
				break;
			}
			if(!$this->_isValidExtent($iStart,$iCount)){
				if($bIdentified){
					$this->iRejectedMaps++;
				}
				return NULL;
			}
			$aExtents[] = ['sector'=>$iStart,'count'=>$iCount];
		}
		//Everything after the end of the list is zero in a real map
		if(trim(substr($sRaw,$iEnd,0xFF-$iEnd),"\0")!=""){
			if($bIdentified){
				$this->iRejectedMaps++;
			}
			return NULL;
		}
		return ['extents'=>$aExtents,'next'=>$iNext,'bytes_in_last_block'=>ord($sRaw[0x8])];
	}

	protected function _isBitmapSector(int $iSector):bool
	{
		return ($this->bCylinderBitmaps AND ($iSector % $this->iSectorsPerTrack)==0);
	}

	/**
	 * Tests if an extent could exist on this disk
	*/
	protected function _isValidExtent(int $iStart, int $iCount):bool
	{
		if($iCount<1 OR $iStart<1 OR ($iStart+$iCount)>$this->iImageSectors){
			return false;
		}
		if($this->bCylinderBitmaps){
			//The first sector of every cylinder is its bitmap, so an extent can not start on one or run over one
			if(($iStart % $this->iSectorsPerTrack)==0 OR intdiv($iStart,$this->iSectorsPerTrack)!=intdiv($iStart+$iCount-1,$this->iSectorsPerTrack)){
				return false;
			}
		}
		return true;
	}

	/**
	 * Counts the sectors of an object that contain only zero bytes
	*/
	protected function _countZeroSectors(int $iSin):int
	{
		$iCount = 0;
		foreach($this->aMaps[$iSin]['extents'] as $aExtent){
			for($i=0;$i<$aExtent['count'];$i++){
				if(isset($this->aZeroSectors[$aExtent['sector']+$i])){
					$iCount++;
				}
			}
		}
		return $iCount;
	}

	//********** Stage 2, find the directories **********

	/**
	 * Works out which of the objects are directories and reads their entries
	*/
	protected function _findDirectories()
	{
		//Those that can be recognised from their own contents
		foreach(array_keys($this->aMaps) as $iSin){
			$aDirectory = $this->_readDirectory($iSin,false);
			if(!is_null($aDirectory)){
				$this->aDirectories[$iSin] = $aDirectory;
			}
		}

		//Those that have lost their header, but which something else on the disk says are directories
		$aForce = [];
		if(!is_null($this->iRootDirSin)){
			$aForce[] = $this->iRootDirSin;
		}
		while(true){
			foreach($this->aDirectories as $aDirectory){
				if(!is_null($aDirectory['parent'])){
					$aForce[] = $aDirectory['parent'];
				}
				foreach($aDirectory['entries'] as $aEntry){
					if($aEntry['type']=='dir'){
						$aForce[] = $aEntry['sector'];
					}
				}
			}
			$bFound = false;
			foreach($aForce as $iSin){
				if(isset($this->aMaps[$iSin]) AND !isset($this->aDirectories[$iSin])){
					$this->aDirectories[$iSin] = $this->_readDirectory($iSin,true);
					$bFound = true;
				}
			}
			if(!$bFound){
				break;
			}
			$aForce = [];
		}
	}

	/**
	 * Tests if an object is the right size to be a directory (a header, a whole number of entries and a sequence number)
	*/
	protected function _isDirectorySized(int $iSin):bool
	{
		$iSize = $this->aMaps[$iSin]['size'];
		return ($iSize>=(self::DIR_HEADER_SIZE+self::METADATA_SIZE+1) AND (($iSize-self::DIR_HEADER_SIZE-1) % self::METADATA_SIZE)==0);
	}

	/**
	 * Tests if a value is a valid pointer to an entry in a directory
	*/
	protected function _isEntryPointer(int $iPointer, int $iSize):bool
	{
		return ($iPointer>=self::DIR_HEADER_SIZE AND (($iPointer-self::DIR_HEADER_SIZE) % self::METADATA_SIZE)==0 AND ($iPointer+self::METADATA_SIZE)<=($iSize-1));
	}

	/**
	 * Tests if 10 bytes are a valid space padded object name
	*/
	protected function _isName(string $sName):bool
	{
		return (strlen($sName)==self::FILE_NAME_LEN AND preg_match('/^[\x21-\x7E]+ *$/',$sName)==1);
	}

	/**
	 * Reads an object as a directory
	 *
	 * @param int $iSin
	 * @param boolean $bForce True when the object is known to be a directory, so it is read as one however damaged it is
	 * @return array|null Null if the object is not a directory, otherwise array('sin'=>,'name'=>,'parent'=>,'damaged'=>,'entries'=>)
	*/
	protected function _readDirectory(int $iSin, bool $bForce)
	{
		$aMap = $this->aMaps[$iSin];
		$iSize = $aMap['size'];
		$bSized = $this->_isDirectorySized($iSin);
		if(!$bSized AND !$bForce){
			return NULL;
		}
		$sData = $this->_getDataPointedToByAllocationMap($iSin);
		$iSize = strlen($sData);
		$aDirectory = ['sin'=>$iSin,'name'=>NULL,'parent'=>NULL,'damaged'=>true,'entries'=>[]];
		if($iSize<(self::DIR_HEADER_SIZE+1)){
			return $aDirectory;
		}

		//Is the header usable
		$bFirstSectorLost = (count($aMap['extents'])>0 AND isset($this->aZeroSectors[$aMap['extents'][0]['sector']]));
		$iFirst = ord($sData[0x0]) + (ord($sData[0x1]) << 8);
		$iFree = ord($sData[0xD]) + (ord($sData[0xE]) << 8);
		$iEntryCount = ord($sData[0xF]) + (ord($sData[0x10]) << 8);
		$sName = substr($sData,0x3,self::FILE_NAME_LEN);
		$bHeader = (!$bFirstSectorLost
			AND $this->_isName($sName)
			AND ($iFirst==0 OR $this->_isEntryPointer($iFirst,$iSize))
			AND ($iFree==0 OR $this->_isEntryPointer($iFree,$iSize))
			AND $iEntryCount<=255);
		if(!$bHeader AND !$bForce AND !$bFirstSectorLost){
			//Just a file that happens to be the right size
			return NULL;
		}

		$aUsed = [];
		$bChainComplete = false;
		if($bHeader){
			$aDirectory['name'] = rtrim($sName," ");
			//The first slot can be an unchained dummy entry holding the SIN of the parent directory
			if($iSize>=(self::DIR_HEADER_SIZE+self::METADATA_SIZE) AND substr($sData,self::DIR_HEADER_SIZE,2)=="\xFF\xFF"){
				$aParent = $this->_decodeEntry(substr($sData,self::DIR_HEADER_SIZE,self::METADATA_SIZE));
				if($aParent['sector']>0){
					$aDirectory['parent'] = $aParent['sector'];
				}
				$aUsed[self::DIR_HEADER_SIZE] = true;
			}
			//Follow the chain of entries in use, as the fileserver would
			$iPointer = $iFirst;
			while($iPointer!=0 AND !isset($aUsed[$iPointer]) AND $this->_isEntryPointer($iPointer,$iSize)){
				$sEntry = substr($sData,$iPointer,self::METADATA_SIZE);
				if(!$this->_isName(substr($sEntry,0x2,self::FILE_NAME_LEN))){
					break;
				}
				$aUsed[$iPointer] = true;
				$aEntry = $this->_decodeEntry($sEntry);
				$aEntry['recovery'] = ['source'=>'entry'];
				$aDirectory['entries'][] = $aEntry;
				$iPointer = $aEntry['next'];
			}
			$bChainComplete = ($iPointer==0 AND count($aDirectory['entries'])==$iEntryCount);
			//The slots on the free chain hold nothing of use, though they may still have the remains of deleted entries
			$iPointer = $iFree;
			while($iPointer!=0 AND !isset($aUsed[$iPointer]) AND $this->_isEntryPointer($iPointer,$iSize)){
				$aUsed[$iPointer] = true;
				$iPointer = ord($sData[$iPointer]) + (ord($sData[$iPointer+1]) << 8);
			}
		}

		$aDirectory['damaged'] = (!$bHeader
			OR !$bChainComplete
			OR $sData[0x2]!==$sData[$iSize-1]
			OR $this->_countZeroSectors($iSin)>0
			OR $aMap['truncated']);

		if($aDirectory['damaged']){
			//The chain can not be trusted to have found everything, so look in every slot for anything that is still a believable entry
			for($iPointer=self::DIR_HEADER_SIZE;$this->_isEntryPointer($iPointer,$iSize);$iPointer=$iPointer+self::METADATA_SIZE){
				if(isset($aUsed[$iPointer])){
					continue;
				}
				$sEntry = substr($sData,$iPointer,self::METADATA_SIZE);
				if(substr($sEntry,0,2)=="\xFF\xFF" OR !$this->_isName(substr($sEntry,0x2,self::FILE_NAME_LEN))){
					continue;
				}
				$aEntry = $this->_decodeEntry($sEntry);
				//The remains of deleted entries are told apart from live ones by no longer leading to a valid object
				if(($aEntry['access'] & 0xC0)!=0 OR $aEntry['sector']==$iSin OR !isset($this->aMaps[$aEntry['sector']])){
					continue;
				}
				if($aEntry['next']!=0 AND !$this->_isEntryPointer($aEntry['next'],$iSize)){
					continue;
				}
				if($aEntry['type']=='dir' AND !$this->_isDirectorySized($aEntry['sector'])){
					continue;
				}
				$aEntry['recovery'] = ['source'=>'salvaged'];
				$aDirectory['entries'][] = $aEntry;
			}
		}

		if(!$bHeader AND !$bForce AND count($aDirectory['entries'])==0){
			//The first sector is all zeros and nothing else in it looks like a directory, so treat it as a file
			return NULL;
		}
		return $aDirectory;
	}

	/**
	 * Decodes a directory entry, the result is in the same format as L3fsReader::_readDirectoryEntry()
	 *
	 * @param string $sEntry The 26 bytes of the entry
	*/
	protected function _decodeEntry(string $sEntry):array
	{
		$aFields = unpack('vnext/a10name/Vload/Vexec/Caccess/vdate/vsinlow/Csinhigh',$sEntry);
		$aReturn = [];
		$aReturn['filename'] = rtrim($aFields['name']," \0");
		$aReturn['load'] = $aFields['load'];
		$aReturn['exec'] = $aFields['exec'];
		$aReturn['access'] = $aFields['access'];
		$aReturn['date'] = $aFields['date'];
		$aReturn['sector'] = $aFields['sinlow'] + ($aFields['sinhigh'] << 16);
		$aReturn['type'] = ($aFields['access'] & 32) ? 'dir' : 'file';
		$aReturn['locked'] = $aFields['access'] & 16;
		$aReturn['user_read'] = $aFields['access'] & 4;
		$aReturn['user_write'] = $aFields['access'] & 8;
		$aReturn['public_read'] = $aFields['access'] & 1;
		$aReturn['public_write'] = $aFields['access'] & 2;
		$aReturn['next'] = $aFields['next'];
		return $aReturn;
	}

	/**
	 * Makes up an entry for an object that no surviving directory entry describes
	*/
	protected function _makeEntry(string $sName, int $iSin, string $sType, string $sSource):array
	{
		$aEntry = $this->_decodeEntry(str_repeat("\0",self::METADATA_SIZE));
		$aEntry['filename'] = $sName;
		$aEntry['sector'] = $iSin;
		$aEntry['type'] = $sType;
		if($sType=='dir'){
			$aEntry['access'] = 32;
		}
		$aEntry['recovery'] = ['source'=>$sSource];
		return $aEntry;
	}

	//********** Stage 3, join everything together into a tree **********

	/**
	 * Builds the catalogue from the directories and maps that were found
	 *
	 * @return array The contents of the root directory
	*/
	protected function _buildTree():array
	{
		//Every entry that leads to a valid object is a claim that the object belongs in that directory, pick the best claim for each object
		$aClaims = [];
		foreach($this->aDirectories as $iDirSin=>$aDirectory){
			foreach($aDirectory['entries'] as $iIndex=>$aEntry){
				$iSin = $aEntry['sector'];
				if(!isset($this->aMaps[$iSin])){
					continue;
				}
				$iParent = isset($this->aDirectories[$iSin]) ? $this->aDirectories[$iSin]['parent'] : NULL;
				if($aEntry['recovery']['source']=='salvaged'){
					//Salvaged entries could be the remains of a deleted entry, so the object must not contradict them
					if(!is_null($iParent) AND $iParent!=$iDirSin){
						continue;
					}
					if($aEntry['type']=='file' AND isset($this->aDirectories[$iSin]) AND !is_null($this->aDirectories[$iSin]['name'])){
						continue;
					}
					$iScore = 1;
				}else{
					$iScore = 3;
				}
				if(!is_null($iParent) AND $iParent==$iDirSin){
					$iScore++;
				}
				if(!isset($aClaims[$iSin]) OR $iScore>$aClaims[$iSin]['score']){
					$aClaims[$iSin] = ['score'=>$iScore,'dir'=>$iDirSin,'index'=>$iIndex];
				}
			}
		}

		//The children of each directory, indexed by the SIN of the directory (which may be one with nothing left on the disk)
		$aChildren = [];
		foreach($this->aDirectories as $iDirSin=>$aDirectory){
			foreach($aDirectory['entries'] as $iIndex=>$aEntry){
				$iSin = $aEntry['sector'];
				if(isset($this->aMaps[$iSin])){
					if($aClaims[$iSin]['dir']!=$iDirSin OR $aClaims[$iSin]['index']!=$iIndex){
						if($aEntry['recovery']['source']=='entry'){
							$this->aProblems[] = "The entry ".$aEntry['filename']." in the directory with SIN ".$iDirSin." leads to an object (SIN ".$iSin.") that is also in another directory, it was left out";
						}
						continue;
					}
				}elseif($aEntry['recovery']['source']!='entry'){
					continue;
				}
				$aChildren[$iDirSin][] = $aEntry;
			}
		}

		//Work out which directory is the root
		$this->iRootSin = 0;
		if(!is_null($this->iRootDirSin) AND $this->iRootDirSin>0){
			$this->iRootSin = $this->iRootDirSin;
		}else{
			foreach($this->aDirectories as $iDirSin=>$aDirectory){
				if($aDirectory['name']=='$' AND !isset($aClaims[$iDirSin])){
					$this->iRootSin = $iDirSin;
					$this->aProblems[] = "The directory with SIN ".$iDirSin." was taken to be the root, as it is named \$";
					break;
				}
			}
		}

		//Directories that are not listed anywhere are placed using their pointer to their parent
		$aOrphans = [];
		foreach($this->aDirectories as $iDirSin=>$aDirectory){
			if(isset($aClaims[$iDirSin]) OR $iDirSin==$this->iRootSin){
				continue;
			}
			$sName = is_null($aDirectory['name']) ? 'dir-'.$iDirSin : $aDirectory['name'];
			if(!is_null($aDirectory['parent'])){
				$aChildren[$aDirectory['parent']][] = $this->_makeEntry($sName,$iDirSin,'dir','parent-pointer');
			}else{
				$aOrphans[] = $this->_makeEntry($sName,$iDirSin,'dir','orphan');
			}
		}
		//Files that are not listed anywhere
		foreach(array_keys($this->aMaps) as $iSin){
			if(!isset($aClaims[$iSin]) AND !isset($this->aDirectories[$iSin])){
				$aOrphans[] = $this->_makeEntry('file-'.$iSin,$iSin,'file','orphan');
			}
		}

		$this->aCounts = ['directories'=>0,'damaged_directories'=>0,'fake_directories'=>0,'files'=>0,'suspect_files'=>0,'lost_files'=>0,'salvaged_entries'=>0,'parent_pointer_directories'=>0,'orphans'=>0];
		$aVisited = [$this->iRootSin=>true];
		$aRoot = $this->_buildDirectory($this->iRootSin,$aChildren,$aVisited);
		if($this->iRootSin==0){
			$this->aProblems[] = "The root directory could not be found, a fake one was made";
		}elseif(!isset($this->aDirectories[$this->iRootSin])){
			$this->aProblems[] = "The map for the root directory (SIN ".$this->iRootSin.") is lost, a fake root was made from the directories that point to it as their parent";
		}elseif($this->aDirectories[$this->iRootSin]['damaged']){
			$this->aProblems[] = "The root directory (SIN ".$this->iRootSin.") is damaged, some of its entries may be missing";
		}

		//Everything that could not be joined to the tree goes in a made up directory
		$aLost = [];
		foreach($aOrphans as $aEntry){
			if($aEntry['type']=='file' OR !isset($aVisited[$aEntry['sector']])){
				$aLost[] = $aEntry;
			}
		}
		$aLostCatalogue = $this->_buildEntries($aLost,$aChildren,$aVisited);
		//Directories with nothing left on the disk, that are only known of as their children point to them
		$aLost = [];
		foreach(array_keys($aChildren) as $iDirSin){
			if(!isset($aVisited[$iDirSin]) AND !isset($this->aDirectories[$iDirSin])){
				$aLost[] = $this->_makeEntry('dir-'.$iDirSin,$iDirSin,'dir','fake');
			}
		}
		$aLostCatalogue = $aLostCatalogue + $this->_buildEntries($aLost,$aChildren,$aVisited);
		//Anything still not reached is part of a loop of directories that each claim another as their parent
		foreach($this->aDirectories as $iDirSin=>$aDirectory){
			if(!isset($aVisited[$iDirSin])){
				$sName = is_null($aDirectory['name']) ? 'dir-'.$iDirSin : $aDirectory['name'];
				$aLostCatalogue = $aLostCatalogue + $this->_buildEntries([$this->_makeEntry($sName.'~'.$iDirSin,$iDirSin,'dir','orphan')],$aChildren,$aVisited);
			}
		}
		if(count($aLostCatalogue)>0){
			$this->aCounts['directories']++;
			$this->aCounts['fake_directories']++;
			$sName = isset($aRoot[self::LOST_AND_FOUND]) ? self::LOST_AND_FOUND.'~0' : self::LOST_AND_FOUND;
			$aEntry = $this->_makeEntry($sName,0,'dir','fake');
			$aEntry['recovery']['status'] = 'fake';
			$aEntry['recovery']['zero_sectors'] = 0;
			$aEntry['dir'] = $aLostCatalogue;
			$aRoot[$sName] = $aEntry;
		}
		return $aRoot;
	}

	/**
	 * Builds the catalogue for a directory
	 *
	 * @param int $iDirSin
	 * @param array $aChildren The entries for every directory, indexed by the SIN of the directory
	 * @param array $aVisited The directories already in the tree, indexed by SIN, so loops are not followed
	*/
	protected function _buildDirectory(int $iDirSin, array $aChildren, array &$aVisited):array
	{
		return $this->_buildEntries(isset($aChildren[$iDirSin]) ? $aChildren[$iDirSin] : [],$aChildren,$aVisited);
	}

	/**
	 * Builds the catalogue for a list of entries, filling in the details of each and recursing into the directories
	*/
	protected function _buildEntries(array $aEntries, array $aChildren, array &$aVisited):array
	{
		usort($aEntries,function($aA,$aB){ return strcasecmp($aA['filename'],$aB['filename']); });
		$aCat = [];
		foreach($aEntries as $aEntry){
			$iSin = $aEntry['sector'];
			$bMap = isset($this->aMaps[$iSin]);
			$aEntry['recovery']['zero_sectors'] = $bMap ? $this->_countZeroSectors($iSin) : 0;
			switch($aEntry['recovery']['source']){
				case 'salvaged':
					$this->aCounts['salvaged_entries']++;
					break;
				case 'parent-pointer':
					$this->aCounts['parent_pointer_directories']++;
					break;
				case 'orphan':
					$this->aCounts['orphans']++;
					break;
			}
			if($aEntry['type']=='dir'){
				$this->aCounts['directories']++;
				if(!isset($this->aDirectories[$iSin])){
					//Either made up, or listed in its parent but with nothing left of its own
					$aEntry['recovery']['status'] = ($aEntry['recovery']['source']=='fake') ? 'fake' : 'lost';
					$this->aCounts['fake_directories']++;
				}elseif($this->aDirectories[$iSin]['damaged']){
					$aEntry['recovery']['status'] = 'damaged';
					$this->aCounts['damaged_directories']++;
				}else{
					$aEntry['recovery']['status'] = 'ok';
				}
				if(isset($aVisited[$iSin])){
					$this->aProblems[] = "The directory ".$aEntry['filename']." (SIN ".$iSin.") is already in the tree, the second copy was left empty";
					$aEntry['dir'] = [];
				}else{
					$aVisited[$iSin] = true;
					$aEntry['dir'] = $this->_buildDirectory($iSin,$aChildren,$aVisited);
				}
			}else{
				$this->aCounts['files']++;
				if(!$bMap){
					$aEntry['recovery']['status'] = 'lost';
					$aEntry['size'] = 0;
					$this->aCounts['lost_files']++;
				}else{
					$aEntry['size'] = $this->aMaps[$iSin]['size'];
					if($aEntry['recovery']['zero_sectors']>0 OR $this->aMaps[$iSin]['truncated'] OR $this->aMaps[$iSin]['overlap']){
						$aEntry['recovery']['status'] = 'suspect';
						$this->aCounts['suspect_files']++;
					}else{
						$aEntry['recovery']['status'] = 'ok';
					}
				}
			}
			//Two entries of the same name can not both be reached, so make the later one unique
			$sName = $aEntry['filename'];
			if(isset($aCat[$sName])){
				$sName = $sName.'~'.$iSin;
				$aEntry['filename'] = $sName;
			}
			$aCat[$sName] = $aEntry;
		}
		return $aCat;
	}
}
