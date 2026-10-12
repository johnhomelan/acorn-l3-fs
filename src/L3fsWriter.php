<?php
/**
 * Writes to Level 3 fileserver disk images
 *
 * Extends the reader so files and directories can be added to, replaced in, and removed from an image.  The
 * free space bitmaps, the allocation maps (JesMap) and the directories are all kept in step, following the layout
 * described in docs/afs-disc-format.md
 *
 * Nothing in the fileserver partition is checksummed, the things that have to be kept right there are the paired
 * sequence numbers of each map block and each directory.  The only checksums on the disk are those of sectors 0 and 1
 * (the ADFS stub), which this class never writes to, so they stay valid.
 *
 * Every public method that alters the image builds up all the sectors it needs to change in memory, and only writes
 * them to the image once the whole operation has worked.  If an operation fails (e.g. the disk is full) the image is
 * left exactly as it was.
*/

namespace HomeLan\Retro\Acorn\Disk;

use Exception;
use Throwable;

class L3fsWriter extends L3fsReader {

	const ACCESS_DIR = 0x20;
	const ACCESS_LOCKED = 0x10;
	const ACCESS_OWNER_WRITE = 0x08;
	const ACCESS_OWNER_READ = 0x04;
	const ACCESS_PUBLIC_WRITE = 0x02;
	const ACCESS_PUBLIC_READ = 0x01;

	//A freshly created directory is 2 sectors, which is exactly 19 slots
	const DIR_NEW_SECTORS = 2;
	const DIR_MAX_ENTRIES = 255;
	//The dummy entry in the first slot of a directory, that holds the SIN of its parent, has this in place of the link
	const DIR_PARENT_LINK = 0xFFFF;
	//The last byte of a bitmap sector is a count of following cylinders the server can skip as they are full
	const BITMAP_SKIP_OFFSET = 0xFF;
	const MAX_FILE_SIZE = 0xFFFFFF;

	protected $iStartCylinder = NULL;

	//The sectors holding the copies of the info sector, array(sector=>true)
	protected $aInfoSectors = [];

	//Sectors changed by the operation in progress but not yet written to the image, array(sector=>binary string)
	protected $aPending = [];

	//The bitmap sectors read so far, array(cylinder=>binary string)
	protected $aBitmaps = [];

	/**
	 * Creates a new instance of the writer
	 *
	 * @param string $sPath The path to the disk image, which must already hold a Level 3 filesystem
	*/
	public function __construct(string $sPath)
	{
		parent::__construct($sPath);
		if(!is_writable($sPath)){
			throw new Exception("The disk image ".$sPath." is not writable");
		}
		$aInfo = $this->_getSectorAsByteArray($this->iNfsPartitionStart);
		//26 : 0 = one bitmap sector per cylinder, anything else is the packed bitmaps used on floppies
		if($aInfo[0x26]!=0 OR $this->bInterleaved){
			throw new Exception("Writing to images with packed bitmaps (floppy disks) is not supported");
		}
		//What the reader calls the first free track is the first cylinder of the fileserver partition
		$this->iStartCylinder = $this->iFirstFreeTrack;
		if($this->iStartCylinder<1 OR $this->iStartCylinder>=$this->iTrackCount OR ($this->iTrackCount*$this->iSectorsPerTrack)!=$this->iSectorCount OR $this->iSectorsPerTrack>(self::BITMAP_SKIP_OFFSET*8)){
			throw new Exception("The geometry in the info sector is not valid");
		}
		clearstatcache(true,$sPath);
		if(filesize($sPath)<($this->iSectorCount*self::SECTOR_SIZE)){
			throw new Exception("The disk image is smaller than the size of the disk given in the info sector");
		}
		//Both copies of the info sector must never be given out as free space, what ever the bitmaps say
		$this->aInfoSectors[$this->iNfsPartitionStart] = true;
		foreach([0,1] as $iSector){
			$iPointer = $this->_read24BitInt($this->_getSectorAsByteArray($iSector),0xF6) & 0x1FFFFF;
			$this->aInfoSectors[$iPointer] = true;
		}
	}

	//********** The public interface **********

	/**
	 * Writes a file to the image, replacing any existing file of the same name
	 *
	 * When replacing a file any of the load address, execute address and access left as NULL keep their existing value.
	 * A locked file can not be replaced, use setAttributes() to unlock it first.
	 * @param string $sPath e.g. '$.Library.Fred', the directory must already exist
	 * @param string $sData The contents of the file
	 * @param int $iLoad Load address (0 for a new file if not supplied)
	 * @param int $iExec Execute address (0 for a new file if not supplied)
	 * @param int $iAccess A combination of the ACCESS_ constants (owner read and write for a new file if not supplied)
	 * @param int $iDate The date in filing system format, see encodeDate() (today if not supplied)
	 * @return int The SIN of the file
	*/
	public function putFile(string $sPath, string $sData, ?int $iLoad=NULL, ?int $iExec=NULL, ?int $iAccess=NULL, ?int $iDate=NULL):int
	{
		return $this->_transaction(function() use ($sPath,$sData,$iLoad,$iExec,$iAccess,$iDate){
			if(strlen($sData)>self::MAX_FILE_SIZE){
				throw new Exception("The file is too large for the filing system");
			}
			$aParts = $this->_splitPath($sPath);
			$sName = array_pop($aParts);
			$iDirSin = $this->_findDirectory($aParts);
			$sDir = $this->_readDirectory($iDirSin);
			$aExisting = $this->_findEntry($sDir,$sName);
			if(!is_null($aExisting)){
				if($aExisting['access'] & self::ACCESS_DIR){
					throw new Exception($sPath." is a directory");
				}
				if($aExisting['access'] & self::ACCESS_LOCKED){
					throw new Exception($sPath." is locked");
				}
				//The space used by the old copy is released first so it can be reused by the new one
				$this->_deleteObject($aExisting['sin']);
			}else{
				$this->_checkName($sName);
			}
			$iSin = $this->_createObject($sData);
			$sEntry = $this->_encodeInt(is_null($iLoad) ? ($aExisting['load'] ?? 0) : $iLoad,4)
				.$this->_encodeInt(is_null($iExec) ? ($aExisting['exec'] ?? 0) : $iExec,4)
				.chr((is_null($iAccess) ? ($aExisting['access'] ?? (self::ACCESS_OWNER_WRITE | self::ACCESS_OWNER_READ)) : $iAccess) & 0x1F)
				.$this->_encodeInt(is_null($iDate) ? $this->_today() : $iDate,2)
				.$this->_encodeInt($iSin,3);
			if(!is_null($aExisting)){
				$sDir = $this->_poke($sDir,$aExisting['offset']+0xC,$sEntry);
			}else{
				$sDir = $this->_addEntry($sDir,$sName,$sEntry);
			}
			$this->_writeDirectory($iDirSin,$sDir);
			return $iSin;
		});
	}

	/**
	 * Creates a new empty directory
	 *
	 * @param string $sPath e.g. '$.Library', the parent directory must already exist
	 * @param int $iAccess A combination of the ACCESS_ constants (locked if not supplied, as the fileserver creates them)
	 * @param int $iDate The date in filing system format, see encodeDate() (today if not supplied)
	 * @return int The SIN of the directory
	*/
	public function makeDirectory(string $sPath, ?int $iAccess=NULL, ?int $iDate=NULL):int
	{
		return $this->_transaction(function() use ($sPath,$iAccess,$iDate){
			$aParts = $this->_splitPath($sPath);
			$sName = array_pop($aParts);
			$iParentSin = $this->_findDirectory($aParts);
			$sParent = $this->_readDirectory($iParentSin);
			if(!is_null($this->_findEntry($sParent,$sName))){
				throw new Exception($sPath." already exists");
			}
			$this->_checkName($sName);

			$iSlots = intdiv((self::DIR_NEW_SECTORS*self::SECTOR_SIZE)-self::DIR_HEADER_SIZE-1,self::METADATA_SIZE);
			$iSize = self::DIR_HEADER_SIZE+($iSlots*self::METADATA_SIZE)+1;
			$sDir = str_repeat("\0",$iSize);
			//The sequence number, which is repeated in the last byte
			$sDir = $this->_poke($sDir,0x2,chr(1));
			$sDir = $this->_poke($sDir,$iSize-1,chr(1));
			$sDir = $this->_poke($sDir,0x3,str_pad($sName,self::FILE_NAME_LEN," "));
			//The first slot is the dummy entry holding the SIN of the parent, it is not part of either list
			$sDir = $this->_poke($sDir,self::DIR_HEADER_SIZE,$this->_encodeInt(self::DIR_PARENT_LINK,2));
			$sDir = $this->_poke($sDir,self::DIR_HEADER_SIZE+0x17,$this->_encodeInt($iParentSin,3));
			//All the other slots are chained together as the free list
			$sDir = $this->_poke($sDir,0xD,$this->_encodeInt(self::DIR_HEADER_SIZE+self::METADATA_SIZE,2));
			for($i=1;$i<($iSlots-1);$i++){
				$sDir = $this->_poke($sDir,self::DIR_HEADER_SIZE+($i*self::METADATA_SIZE),$this->_encodeInt(self::DIR_HEADER_SIZE+(($i+1)*self::METADATA_SIZE),2));
			}
			$iSin = $this->_createObject($sDir);

			$sEntry = $this->_encodeInt(0,4)
				.$this->_encodeInt(0,4)
				.chr(((is_null($iAccess) ? self::ACCESS_LOCKED : $iAccess) & 0x1F) | self::ACCESS_DIR)
				.$this->_encodeInt(is_null($iDate) ? $this->_today() : $iDate,2)
				.$this->_encodeInt($iSin,3);
			$this->_writeDirectory($iParentSin,$this->_addEntry($sParent,$sName,$sEntry));
			return $iSin;
		});
	}

	/**
	 * Deletes a file or an empty directory
	 *
	 * A locked file can not be deleted, use setAttributes() to unlock it first.  The lock is not enforced for
	 * directories as the fileserver creates every directory locked.
	 * @param string $sPath
	*/
	public function delete(string $sPath)
	{
		$this->_transaction(function() use ($sPath){
			$aParts = $this->_splitPath($sPath);
			$sName = array_pop($aParts);
			$iDirSin = $this->_findDirectory($aParts);
			$sDir = $this->_readDirectory($iDirSin);
			$aEntry = $this->_findEntry($sDir,$sName);
			if(is_null($aEntry)){
				throw new Exception("File not found ".$sPath);
			}
			if($aEntry['access'] & self::ACCESS_DIR){
				if(count($this->_listEntries($this->_readDirectory($aEntry['sin'])))>0){
					throw new Exception("The directory ".$sPath." is not empty");
				}
			}elseif($aEntry['access'] & self::ACCESS_LOCKED){
				throw new Exception($sPath." is locked");
			}
			$this->_deleteObject($aEntry['sin']);
			$this->_writeDirectory($iDirSin,$this->_removeEntry($sDir,$aEntry));
		});
	}

	/**
	 * Changes the load address, execute address and access of a file or directory
	 *
	 * @param string $sPath
	 * @param int $iLoad NULL to leave unchanged
	 * @param int $iExec NULL to leave unchanged
	 * @param int $iAccess A combination of the ACCESS_ constants, NULL to leave unchanged
	*/
	public function setAttributes(string $sPath, ?int $iLoad=NULL, ?int $iExec=NULL, ?int $iAccess=NULL)
	{
		$this->_transaction(function() use ($sPath,$iLoad,$iExec,$iAccess){
			$aParts = $this->_splitPath($sPath);
			$sName = array_pop($aParts);
			$iDirSin = $this->_findDirectory($aParts);
			$sDir = $this->_readDirectory($iDirSin);
			$aEntry = $this->_findEntry($sDir,$sName);
			if(is_null($aEntry)){
				throw new Exception("File not found ".$sPath);
			}
			if(!is_null($iLoad)){
				$sDir = $this->_poke($sDir,$aEntry['offset']+0xC,$this->_encodeInt($iLoad,4));
			}
			if(!is_null($iExec)){
				$sDir = $this->_poke($sDir,$aEntry['offset']+0x10,$this->_encodeInt($iExec,4));
			}
			if(!is_null($iAccess)){
				//An object can not be changed between being a file and a directory
				$sDir = $this->_poke($sDir,$aEntry['offset']+0x14,chr(($iAccess & 0x1F) | ($aEntry['access'] & self::ACCESS_DIR)));
			}
			$this->_writeDirectory($iDirSin,$sDir);
		});
	}

	/**
	 * Gets the amount of free space on the disk
	 *
	 * @return int The free space in bytes (each object also needs a sector for its allocation map)
	*/
	public function getFreeSpace():int
	{
		$iSectors = 0;
		foreach($this->_getCylinders() as $iCylinder){
			$iSectors = $iSectors+count($this->_getFreeSectors($iCylinder));
		}
		return $iSectors*self::SECTOR_SIZE;
	}

	/**
	 * Compares the free space bitmaps with the sectors used by everything that can be reached from the root directory
	 *
	 * A writer should only be let loose on an image where 'in_use_but_free' and 'cross_linked' are both empty, otherwise
	 * new objects can be written over existing ones.  Sectors in 'allocated_but_unused' are only wasted space.
	 * @return array In the format array('in_use_but_free'=>array(sector,..),'allocated_but_unused'=>array(sector,..),'cross_linked'=>array(sector,..))
	*/
	public function checkFreeSpaceMap():array
	{
		$aReturn = ['in_use_but_free'=>[],'allocated_but_unused'=>[],'cross_linked'=>[]];
		$aUsed = [];
		$this->_findUsedSectors($this->iRootDirSin,true,$aUsed,$aReturn['cross_linked']);
		for($iCylinder=$this->iStartCylinder;$iCylinder<$this->iTrackCount;$iCylinder++){
			$sBitmap = $this->_getBitmap($iCylinder);
			for($i=0;$i<$this->iSectorsPerTrack;$i++){
				$iSector = ($iCylinder*$this->iSectorsPerTrack)+$i;
				$bFree = (ord($sBitmap[$i >> 3]) & (1 << ($i & 7)))!=0;
				$bUsed = ($i==0 OR isset($this->aInfoSectors[$iSector]) OR isset($aUsed[$iSector]));
				if($bUsed AND $bFree){
					$aReturn['in_use_but_free'][] = $iSector;
				}elseif(!$bUsed AND !$bFree){
					$aReturn['allocated_but_unused'][] = $iSector;
				}
			}
		}
		return $aReturn;
	}

	/**
	 * Encodes a date in the format used by the filing system
	 *
	 * @param int $iDay
	 * @param int $iMonth
	 * @param int $iYear 1981 to 2108
	 * @return int
	*/
	public static function encodeDate(int $iDay, int $iMonth, int $iYear):int
	{
		$iYear = min(max($iYear-1981,0),127);
		//The low byte is the day with the high bits of the year, the high byte the month with the low bits of the year
		return ($iDay & 0x1F) | (($iYear >> 4) << 5) | ((($iMonth & 0xF) | (($iYear & 0xF) << 4)) << 8);
	}

	//********** Sectors **********

	/**
	 * Runs an operation that alters the image, only writing the changed sectors to the image if it completes
	 *
	 * @param callable $fOperation
	 * @return mixed What ever the operation returned
	*/
	protected function _transaction(callable $fOperation)
	{
		try {
			$mReturn = $fOperation();
			$this->_commit();
		}catch(Throwable $oException){
			//The bitmaps held in memory include the changes being abandoned
			$this->aPending = [];
			$this->aBitmaps = [];
			throw $oException;
		}
		return $mReturn;
	}

	/**
	 * Writes all the changed sectors to the image
	*/
	protected function _commit()
	{
		if(count($this->aPending)>0){
			$iFileHandle = fopen($this->sImagePath,'r+');
			if($iFileHandle===false){
				throw new Exception("Unable to open the disk image ".$this->sImagePath." for writing");
			}
			ksort($this->aPending);
			foreach($this->aPending as $iSector=>$sData){
				if(fseek($iFileHandle,$iSector*self::SECTOR_SIZE)!=0 OR fwrite($iFileHandle,$sData)!==self::SECTOR_SIZE){
					fclose($iFileHandle);
					throw new Exception("Failed writing sector ".$iSector." to the disk image, the image may now be inconsistent");
				}
			}
			fclose($iFileHandle);
		}
		$this->aPending = [];
		$this->aCatalogue = NULL;
	}

	/**
	 * Gets a raw sector, as it will be once the operation in progress has been written to the image
	 *
	 * @param int $iSector The sector number
	 * @return string Binary String
	*/
	protected function _getSectorRaw(int $iSector):string
	{
		if(isset($this->aPending[$iSector])){
			return $this->aPending[$iSector];
		}
		return parent::_getSectorRaw($iSector);
	}

	/**
	 * Queues a sector to be written to the image
	 *
	 * @param int $iSector The sector number
	 * @param string $sData Exactly one sector of data
	*/
	protected function _putSector(int $iSector, string $sData)
	{
		//Nothing before the fileserver partition (the ADFS stub with its checksums) is ever written
		if($iSector<($this->iStartCylinder*$this->iSectorsPerTrack) OR $iSector>=$this->iSectorCount OR isset($this->aInfoSectors[$iSector])){
			throw new Exception("Refusing to write to sector ".$iSector." as it is not part of the space for files");
		}
		if(strlen($sData)!=self::SECTOR_SIZE){
			throw new Exception("A sector must be ".self::SECTOR_SIZE." bytes");
		}
		$this->aPending[$iSector] = $sData;
	}

	/**
	 * Tests if a sector is one that can hold part of an object (i.e. it is not a bitmap, an info sector or outside the partition)
	*/
	protected function _isUsable(int $iSector):bool
	{
		return ($iSector>=($this->iStartCylinder*$this->iSectorsPerTrack) AND $iSector<$this->iSectorCount AND ($iSector % $this->iSectorsPerTrack)!=0 AND !isset($this->aInfoSectors[$iSector]));
	}

	//********** The free space bitmaps **********

	/**
	 * Gets the bitmap for a cylinder, which is held in the first sector of the cylinder
	 *
	 * @param int $iCylinder
	 * @return string Binary String
	*/
	protected function _getBitmap(int $iCylinder):string
	{
		if(!isset($this->aBitmaps[$iCylinder])){
			$sBitmap = $this->_getSectorRaw($iCylinder*$this->iSectorsPerTrack);
			if(strlen($sBitmap)!=self::SECTOR_SIZE){
				throw new Exception("The bitmap for cylinder ".$iCylinder." is outside the disk image");
			}
			$this->aBitmaps[$iCylinder] = $sBitmap;
		}
		return $this->aBitmaps[$iCylinder];
	}

	/**
	 * Gets the cylinders that may have free space
	 *
	 * A bitmap can flag that a number of the cylinders following it are full, the fileserver never looks at
	 * the bitmaps of those so they are not used here either.
	 * @return array
	*/
	protected function _getCylinders():array
	{
		$aCylinders = [];
		for($iCylinder=$this->iStartCylinder;$iCylinder<$this->iTrackCount;$iCylinder++){
			$aCylinders[] = $iCylinder;
			$iCylinder = $iCylinder+ord($this->_getBitmap($iCylinder)[self::BITMAP_SKIP_OFFSET]);
		}
		return $aCylinders;
	}

	/**
	 * Gets the free sectors of a cylinder in ascending order
	 *
	 * @param int $iCylinder
	 * @return array
	*/
	protected function _getFreeSectors(int $iCylinder):array
	{
		$aFree = [];
		$sBitmap = $this->_getBitmap($iCylinder);
		//Bit 0 is the bitmap its self
		for($i=1;$i<$this->iSectorsPerTrack;$i++){
			$iSector = ($iCylinder*$this->iSectorsPerTrack)+$i;
			//A set bit is a free sector
			if((ord($sBitmap[$i >> 3]) & (1 << ($i & 7))) AND !isset($this->aInfoSectors[$iSector])){
				$aFree[] = $iSector;
			}
		}
		return $aFree;
	}

	protected function _isFree(int $iSector):bool
	{
		if(!$this->_isUsable($iSector)){
			return false;
		}
		$i = $iSector % $this->iSectorsPerTrack;
		return (ord($this->_getBitmap(intdiv($iSector,$this->iSectorsPerTrack))[$i >> 3]) & (1 << ($i & 7)))!=0;
	}

	/**
	 * Marks a sector as free or allocated in the bitmap of its cylinder
	 *
	 * @param int $iSector
	 * @param boolean $bFree
	*/
	protected function _setFree(int $iSector, bool $bFree)
	{
		if(!$this->_isUsable($iSector)){
			throw new Exception("Sector ".$iSector." is not part of the space for files, the image is damaged");
		}
		$iCylinder = intdiv($iSector,$this->iSectorsPerTrack);
		$i = $iSector % $this->iSectorsPerTrack;
		$sBitmap = $this->_getBitmap($iCylinder);
		if($bFree){
			$sBitmap[$i >> 3] = chr(ord($sBitmap[$i >> 3]) | (1 << ($i & 7)));
		}else{
			$sBitmap[$i >> 3] = chr(ord($sBitmap[$i >> 3]) & ~(1 << ($i & 7)));
		}
		$this->_putBitmap($iCylinder,$sBitmap);

		if($bFree){
			//An earlier bitmap must no longer tell the fileserver to skip this cylinder as being full
			for($iEarlier=$this->iStartCylinder;$iEarlier<$iCylinder;$iEarlier++){
				$sEarlier = $this->_getBitmap($iEarlier);
				if(($iEarlier+ord($sEarlier[self::BITMAP_SKIP_OFFSET]))>=$iCylinder){
					$sEarlier[self::BITMAP_SKIP_OFFSET] = chr($iCylinder-$iEarlier-1);
					$this->_putBitmap($iEarlier,$sEarlier);
				}
			}
		}
	}

	protected function _putBitmap(int $iCylinder, string $sBitmap)
	{
		$this->aBitmaps[$iCylinder] = $sBitmap;
		$this->_putSector($iCylinder*$this->iSectorsPerTrack,$sBitmap);
	}

	/**
	 * Claims a number of free sectors, marking them as allocated in the bitmaps
	 *
	 * A single run of sectors is used if there is one, otherwise the sectors come from where ever there is free space.
	 * @param int $iCount
	 * @param int $iPreferred The sector it would be best to start at if it is free (used when extending an object)
	 * @return array The sectors in ascending order
	*/
	protected function _allocate(int $iCount, int $iPreferred=0):array
	{
		if($iCount<1){
			return [];
		}
		$aSectors = NULL;
		$aPreferred = range($iPreferred,$iPreferred+$iCount-1);
		if(count(array_filter($aPreferred,[$this,'_isFree']))==$iCount){
			$aSectors = $aPreferred;
		}
		$aFree = [];
		if(is_null($aSectors)){
			foreach($this->_getCylinders() as $iCylinder){
				$aRun = [];
				foreach($this->_getFreeSectors($iCylinder) as $iSector){
					if(count($aRun)>0 AND ($aRun[count($aRun)-1]+1)!=$iSector){
						$aRun = [];
					}
					$aRun[] = $iSector;
					if(count($aRun)==$iCount){
						$aSectors = $aRun;
						break 2;
					}
					$aFree[] = $iSector;
				}
			}
		}
		if(is_null($aSectors)){
			if(count($aFree)<$iCount){
				throw new Exception("Disk full");
			}
			$aSectors = array_slice($aFree,0,$iCount);
		}
		foreach($aSectors as $iSector){
			$this->_setFree($iSector,false);
		}
		return $aSectors;
	}

	//********** Objects and their allocation maps **********

	/**
	 * Adds a sector to the end of a list of extents
	 *
	 * A sector following on from the last extent is merged into it.  As the bitmap at the start of each cylinder is
	 * never free this can not produce an extent that crosses a cylinder boundary.
	*/
	protected function _appendSector(array $aExtents, int $iSector):array
	{
		$iLast = count($aExtents)-1;
		if($iLast>=0 AND ($aExtents[$iLast]['sector']+$aExtents[$iLast]['count'])==$iSector){
			$aExtents[$iLast]['count']++;
		}else{
			$aExtents[] = ['sector'=>$iSector,'count'=>1];
		}
		return $aExtents;
	}

	/**
	 * Creates a new object (a file or the data of a directory), writing its data and its allocation map
	 *
	 * @param string $sData
	 * @return int The SIN of the object
	*/
	protected function _createObject(string $sData):int
	{
		//The first sector is used for the map so the data follows straight on from it
		$aSectors = $this->_allocate(1+(int) ceil(strlen($sData)/self::SECTOR_SIZE));
		$iSin = array_shift($aSectors);
		$aExtents = [];
		foreach($aSectors as $iSector){
			$aExtents = $this->_appendSector($aExtents,$iSector);
		}
		$this->_writeData($aExtents,$sData);
		$this->_writeMap($iSin,$aExtents,strlen($sData));
		return $iSin;
	}

	/**
	 * Writes the data of an object to the sectors given by its extents
	*/
	protected function _writeData(array $aExtents, string $sData)
	{
		$iPosition = 0;
		foreach($aExtents as $aExtent){
			for($i=0;$i<$aExtent['count'];$i++){
				$this->_putSector($aExtent['sector']+$i,str_pad(substr($sData,$iPosition,self::SECTOR_SIZE),self::SECTOR_SIZE,"\0"));
				$iPosition = $iPosition+self::SECTOR_SIZE;
			}
		}
	}

	/**
	 * Gets the sectors of all the map blocks in the chain for an object
	 *
	 * @param int $iSin System internal name
	 * @return array The sectors, the first is always the SIN
	*/
	protected function _getMapBlocks(int $iSin):array
	{
		//Reading the map first means a broken or looping chain has already thrown an exception
		$this->_readAllocationMap($iSin);
		$aBlocks = [];
		$iBlock = $iSin;
		while($iBlock!=0){
			$aBlocks[] = $iBlock;
			$sBlock = $this->_getSectorRaw($iBlock);
			$iNextBlock = 0;
			for($i=0;$i<self::MAP_ENTRIES;$i++){
				$iStart = $this->_decodeInt($sBlock,0xA+($i*5),3);
				if($iStart==0){
					break;
				}
				if($i==(self::MAP_ENTRIES-1)){
					$iNextBlock = $iStart;
				}
			}
			$iBlock = $iNextBlock;
		}
		return $aBlocks;
	}

	/**
	 * Writes the allocation map (JesMap) for an object, chaining map blocks if there are too many extents for one
	 *
	 * @param int $iSin System internal name, the sector of the first map block
	 * @param array $aExtents In the format array(array('sector'=>,'count'=>))
	 * @param int $iSize The size of the object in bytes
	 * @param array $aOldBlocks The map blocks the object already has (from _getMapBlocks), empty for a new object
	*/
	protected function _writeMap(int $iSin, array $aExtents, int $iSize, array $aOldBlocks=[])
	{
		//The last entry of each block is the link to the next, so is not available for an extent
		$aChunks = array_chunk($aExtents,self::MAP_ENTRIES-1);
		if(count($aChunks)==0){
			$aChunks = [[]];
		}
		$aBlocks = count($aOldBlocks)>0 ? $aOldBlocks : [$iSin];
		//Release any blocks no longer needed
		foreach(array_splice($aBlocks,count($aChunks)) as $iBlock){
			$this->_deleteMapBlock($iBlock);
		}
		if(count($aChunks)>count($aBlocks)){
			$aBlocks = array_merge($aBlocks,$this->_allocate(count($aChunks)-count($aBlocks)));
		}
		foreach($aChunks as $iIndex=>$aChunk){
			$sBlock = str_repeat("\0",self::SECTOR_SIZE);
			//Only the first block in a chain carries the identifier
			if($iIndex==0){
				$sBlock = $this->_poke($sBlock,0x0,"JesMap");
			}
			//Both sequence numbers go up by one each time a block is written, the blocks of a chain are not related
			$iSequence = 1;
			if(in_array($aBlocks[$iIndex],$aOldBlocks)){
				$iSequence = (ord($this->_getSectorRaw($aBlocks[$iIndex])[0x6])+1) & 0xFF;
			}
			$sBlock = $this->_poke($sBlock,0x6,chr($iSequence));
			$sBlock = $this->_poke($sBlock,0xFF,chr($iSequence));
			//The bytes used in the last sector, the fileserver reads this from the first block but it is put in all of them
			$sBlock = $this->_poke($sBlock,0x8,$this->_encodeInt($iSize % self::SECTOR_SIZE,2));
			foreach($aChunk as $iEntry=>$aExtent){
				$sBlock = $this->_poke($sBlock,0xA+($iEntry*5),$this->_encodeInt($aExtent['sector'],3).$this->_encodeInt($aExtent['count'],2));
			}
			if(isset($aBlocks[$iIndex+1])){
				$sBlock = $this->_poke($sBlock,0xA+((self::MAP_ENTRIES-1)*5),$this->_encodeInt($aBlocks[$iIndex+1],3).$this->_encodeInt(1,2));
			}
			$this->_putSector($aBlocks[$iIndex],$sBlock);
		}
	}

	/**
	 * Marks a map block as deleted the way the fileserver does, and frees its sector
	 *
	 * The identifier is left in place, the extents are cleared and the sequence numbers are left out of step (0 and 1)
	*/
	protected function _deleteMapBlock(int $iBlock)
	{
		$this->_setFree($iBlock,true);
		$this->_putSector($iBlock,substr($this->_getSectorRaw($iBlock),0,6).str_repeat("\0",self::SECTOR_SIZE-7).chr(1));
	}

	/**
	 * Deletes an object, freeing all the sectors used by its data and its allocation map
	 *
	 * @param int $iSin System internal name
	*/
	protected function _deleteObject(int $iSin)
	{
		$aMap = $this->_readAllocationMap($iSin);
		$aBlocks = $this->_getMapBlocks($iSin);
		foreach($aMap['extents'] as $aExtent){
			for($i=0;$i<$aExtent['count'];$i++){
				$this->_setFree($aExtent['sector']+$i,true);
			}
		}
		foreach($aBlocks as $iBlock){
			$this->_deleteMapBlock($iBlock);
		}
	}

	/**
	 * Collects the sectors used by an object, and if it is a directory everything below it
	 *
	 * @param int $iSin System internal name
	 * @param boolean $bDirectory
	 * @param array $aUsed Added to, in the format array(sector=>true)
	 * @param array $aCrossLinked Added to, the sectors found to be used more than once
	*/
	protected function _findUsedSectors(int $iSin, bool $bDirectory, array &$aUsed, array &$aCrossLinked, int $iDepth=0)
	{
		if($iDepth==255){
			throw new Exception("The directory tree is nested too deep, it probably loops back on its self");
		}
		$aSectors = $this->_getMapBlocks($iSin);
		foreach($this->_readAllocationMap($iSin)['extents'] as $aExtent){
			for($i=0;$i<$aExtent['count'];$i++){
				$aSectors[] = $aExtent['sector']+$i;
			}
		}
		foreach($aSectors as $iSector){
			if(isset($aUsed[$iSector]) OR !$this->_isUsable($iSector)){
				$aCrossLinked[] = $iSector;
			}
			$aUsed[$iSector] = true;
		}
		if($bDirectory){
			foreach($this->_listEntries($this->_readDirectory($iSin)) as $aEntry){
				$this->_findUsedSectors($aEntry['sin'],($aEntry['access'] & self::ACCESS_DIR)!=0,$aUsed,$aCrossLinked,$iDepth+1);
			}
		}
	}

	//********** Directories **********

	/**
	 * Splits a path into its parts, the leading $ for the root directory is optional
	 *
	 * @param string $sPath e.g. '$.Library.Fred'
	 * @return array e.g. array('Library','Fred')
	*/
	protected function _splitPath(string $sPath):array
	{
		$aParts = explode('.',$sPath);
		if($aParts[0]=='$'){
			array_shift($aParts);
		}
		if(count($aParts)==0 OR in_array('',$aParts,true)){
			throw new Exception("Invalid path ".$sPath);
		}
		return $aParts;
	}

	/**
	 * Checks a name is acceptable for a new file or directory
	*/
	protected function _checkName(string $sName)
	{
		if(strlen($sName)<1 OR strlen($sName)>self::FILE_NAME_LEN){
			throw new Exception("Names must be between 1 and ".self::FILE_NAME_LEN." characters");
		}
		//Printable characters only, less those with a meaning in a path or as a wildcard
		if(!preg_match('/^[\x21-\x7E]+$/',$sName) OR strpbrk($sName,'.:*#"$')!==false){
			throw new Exception("The name ".$sName." contains characters that are not allowed");
		}
	}

	/**
	 * Follows a path of directory names down from the root
	 *
	 * @param array $aParts The names of the directories, an empty array being the root
	 * @return int The SIN of the directory
	*/
	protected function _findDirectory(array $aParts):int
	{
		$iSin = $this->iRootDirSin;
		foreach($aParts as $sPart){
			$aEntry = $this->_findEntry($this->_readDirectory($iSin),$sPart);
			if(is_null($aEntry) OR !($aEntry['access'] & self::ACCESS_DIR)){
				throw new Exception("Directory not found ".$sPart);
			}
			$iSin = $aEntry['sin'];
		}
		return $iSin;
	}

	/**
	 * Reads the whole of a directory
	 *
	 * @param int $iSin System internal name
	 * @return string Binary String
	*/
	protected function _readDirectory(int $iSin):string
	{
		$sDir = $this->_getDataPointedToByAllocationMap($iSin);
		$iSize = strlen($sDir);
		//A directory is the header, a number of slots, then a copy of the sequence number
		if($iSize<(self::DIR_HEADER_SIZE+self::METADATA_SIZE+1) OR (($iSize-self::DIR_HEADER_SIZE-1) % self::METADATA_SIZE)!=0){
			throw new Exception("The object with SIN ".$iSin." is not the right size to be a directory");
		}
		if($sDir[0x2]!=$sDir[$iSize-1]){
			throw new Exception("Broken directory, the sequence numbers at the start and end of the directory with SIN ".$iSin." do not match");
		}
		return $sDir;
	}

	/**
	 * Tests if an offset into a directory is the start of a slot
	*/
	protected function _isSlotOffset(string $sDir, int $iOffset):bool
	{
		return ($iOffset>=self::DIR_HEADER_SIZE AND (($iOffset-self::DIR_HEADER_SIZE) % self::METADATA_SIZE)==0 AND ($iOffset+self::METADATA_SIZE)<=(strlen($sDir)-1));
	}

	/**
	 * Gets the in use entries of a directory, in the order they are chained together
	 *
	 * @param string $sDir The directory as read by _readDirectory
	 * @return array In the format array(array('offset'=>,'previous'=>,'next'=>,'name'=>,'load'=>,'exec'=>,'access'=>,'date'=>,'sin'=>)) where previous is the offset of the entry linking to this one (0 if its the first)
	*/
	protected function _listEntries(string $sDir):array
	{
		$aEntries = [];
		$iPrevious = 0;
		$iOffset = $this->_decodeInt($sDir,0x0,2);
		while($iOffset!=0){
			if(!$this->_isSlotOffset($sDir,$iOffset) OR count($aEntries)>self::DIR_MAX_ENTRIES){
				throw new Exception("Broken directory, invalid ptr to a directory entry (".$iOffset.")");
			}
			$aEntries[] = [
				'offset'=>$iOffset,
				'previous'=>$iPrevious,
				'next'=>$this->_decodeInt($sDir,$iOffset,2),
				'name'=>rtrim(substr($sDir,$iOffset+0x2,self::FILE_NAME_LEN)," \0"),
				'load'=>$this->_decodeInt($sDir,$iOffset+0xC,4),
				'exec'=>$this->_decodeInt($sDir,$iOffset+0x10,4),
				'access'=>ord($sDir[$iOffset+0x14]),
				'date'=>$this->_decodeInt($sDir,$iOffset+0x15,2),
				'sin'=>$this->_decodeInt($sDir,$iOffset+0x17,3)
			];
			$iPrevious = $iOffset;
			$iOffset = $aEntries[count($aEntries)-1]['next'];
		}
		return $aEntries;
	}

	/**
	 * Finds the entry for a name in a directory, names are not case sensitive
	 *
	 * @return array The entry in the format used by _listEntries, or NULL if there is no such entry
	*/
	protected function _findEntry(string $sDir, string $sName)
	{
		foreach($this->_listEntries($sDir) as $aEntry){
			if(strtoupper($aEntry['name'])==strtoupper($sName)){
				return $aEntry;
			}
		}
		return NULL;
	}

	/**
	 * Adds an entry to a directory, making the directory larger if it has no free slots
	 *
	 * @param string $sDir The directory as read by _readDirectory
	 * @param string $sName
	 * @param string $sDetails The 14 bytes following the name in an entry (load, exec, access, date and SIN)
	 * @return string The altered directory
	*/
	protected function _addEntry(string $sDir, string $sName, string $sDetails):string
	{
		$aEntries = $this->_listEntries($sDir);
		if(count($aEntries)>=self::DIR_MAX_ENTRIES){
			throw new Exception("The directory is full");
		}
		if($this->_decodeInt($sDir,0xD,2)==0){
			$sDir = $this->_growDirectory($sDir);
		}
		//Take the first slot from the free list
		$iOffset = $this->_decodeInt($sDir,0xD,2);
		if(!$this->_isSlotOffset($sDir,$iOffset) OR in_array($iOffset,array_column($aEntries,'offset'))){
			throw new Exception("Broken directory, invalid ptr to a free directory entry (".$iOffset.")");
		}
		$sDir = $this->_poke($sDir,0xD,substr($sDir,$iOffset,2));

		//The in use list is kept in alphabetical order, ignoring case
		$iPrevious = 0;
		$iNext = $this->_decodeInt($sDir,0x0,2);
		foreach($aEntries as $aEntry){
			if(strcmp(strtoupper($aEntry['name']),strtoupper($sName))>0){
				break;
			}
			$iPrevious = $aEntry['offset'];
			$iNext = $aEntry['next'];
		}
		$sDir = $this->_poke($sDir,$iOffset,$this->_encodeInt($iNext,2).str_pad($sName,self::FILE_NAME_LEN," ").$sDetails);
		$sDir = $this->_poke($sDir,$iPrevious,$this->_encodeInt($iOffset,2));
		return $this->_poke($sDir,0xF,$this->_encodeInt(count($aEntries)+1,2));
	}

	/**
	 * Removes an entry from a directory, moving its slot to the free list
	 *
	 * @param string $sDir The directory as read by _readDirectory
	 * @param array $aEntry The entry as returned by _findEntry
	 * @return string The altered directory
	*/
	protected function _removeEntry(string $sDir, array $aEntry):string
	{
		//The first entry is linked to from the start of the header, so an offset of 0 does for that case too
		$sDir = $this->_poke($sDir,$aEntry['previous'],$this->_encodeInt($aEntry['next'],2));
		//Only the link of a free slot means anything, the rest of the old entry is left as the fileserver leaves it
		$sDir = $this->_poke($sDir,$aEntry['offset'],substr($sDir,0xD,2));
		$sDir = $this->_poke($sDir,0xD,$this->_encodeInt($aEntry['offset'],2));
		return $this->_poke($sDir,0xF,$this->_encodeInt(count($this->_listEntries($sDir)),2));
	}

	/**
	 * Makes a directory one sector larger, adding the slots that fit in the new space to the free list
	 *
	 * @param string $sDir The directory as read by _readDirectory
	 * @return string The altered directory
	*/
	protected function _growDirectory(string $sDir):string
	{
		$iOldSize = strlen($sDir);
		$iSectors = ((int) ceil($iOldSize/self::SECTOR_SIZE))+1;
		$iSlots = intdiv(($iSectors*self::SECTOR_SIZE)-self::DIR_HEADER_SIZE-1,self::METADATA_SIZE);
		$iNewSize = self::DIR_HEADER_SIZE+($iSlots*self::METADATA_SIZE)+1;
		//The first new slot starts where the copy of the sequence number was
		$iFirstNew = $iOldSize-1;
		$sDir = substr($sDir,0,$iFirstNew).str_repeat("\0",$iNewSize-$iFirstNew);
		$iFree = $this->_decodeInt($sDir,0xD,2);
		for($iOffset=$iNewSize-1-self::METADATA_SIZE;$iOffset>=$iFirstNew;$iOffset=$iOffset-self::METADATA_SIZE){
			$sDir = $this->_poke($sDir,$iOffset,$this->_encodeInt($iFree,2));
			$iFree = $iOffset;
		}
		$sDir = $this->_poke($sDir,0xD,$this->_encodeInt($iFree,2));
		return $this->_poke($sDir,$iNewSize-1,$sDir[0x2]);
	}

	/**
	 * Writes a directory back to the disk, claiming more space for it if it has grown
	 *
	 * @param int $iSin System internal name
	 * @param string $sDir The whole directory
	*/
	protected function _writeDirectory(int $iSin, string $sDir)
	{
		//Both copies of the sequence number go up by one each time the directory is written
		$sSequence = chr((ord($sDir[0x2])+1) & 0xFF);
		$sDir = $this->_poke($sDir,0x2,$sSequence);
		$sDir = $this->_poke($sDir,strlen($sDir)-1,$sSequence);

		$aMap = $this->_readAllocationMap($iSin);
		$aExtents = $aMap['extents'];
		if($aMap['size']!=strlen($sDir)){
			$aBlocks = $this->_getMapBlocks($iSin);
			$iNeeded = ((int) ceil(strlen($sDir)/self::SECTOR_SIZE))-array_sum(array_column($aExtents,'count'));
			$iPreferred = 0;
			if(count($aExtents)>0){
				$iPreferred = $aExtents[count($aExtents)-1]['sector']+$aExtents[count($aExtents)-1]['count'];
			}
			foreach($this->_allocate($iNeeded,$iPreferred) as $iSector){
				$aExtents = $this->_appendSector($aExtents,$iSector);
			}
			$this->_writeMap($iSin,$aExtents,strlen($sDir),$aBlocks);
		}
		$this->_writeData($aExtents,$sDir);
	}

	//********** Helpers **********

	/**
	 * Encodes a little endian int
	*/
	protected function _encodeInt(int $iValue, int $iBytes):string
	{
		$sReturn = "";
		for($i=0;$i<$iBytes;$i++){
			$sReturn .= chr(($iValue >> (8*$i)) & 0xFF);
		}
		return $sReturn;
	}

	/**
	 * Decodes a little endian int from a binary string
	*/
	protected function _decodeInt(string $sData, int $iOffset, int $iBytes):int
	{
		$iReturn = 0;
		for($i=0;$i<$iBytes;$i++){
			$iReturn = $iReturn | (ord($sData[$iOffset+$i]) << (8*$i));
		}
		return $iReturn;
	}

	protected function _poke(string $sData, int $iOffset, string $sBytes):string
	{
		return substr_replace($sData,$sBytes,$iOffset,strlen($sBytes));
	}

	/**
	 * Todays date in the format used by the filing system
	*/
	protected function _today():int
	{
		return self::encodeDate((int) date('j'),(int) date('n'),(int) date('Y'));
	}
}
