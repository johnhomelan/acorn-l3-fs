<?php
/**
 * Builds small synthetic AFS0 (Level 3 fileserver / Filestore) hard disk images for the unit tests
 *
 * The layout produced follows docs/afs-disc-format.md, so the tests do not depend on having a real disk image to hand.
*/

namespace HomeLan\Retro\Acorn\Disk\Tests;

use Exception;

class AfsImageBuilder {

	const SECTOR_SIZE = 256;
	const DIR_HEADER_SIZE = 17;
	const DIR_ENTRY_SIZE = 26;
	//A freshly created directory is 2 sectors, which is exactly 19 slots
	const DIR_MIN_SLOTS = 19;
	const MAP_DATA_ENTRIES = 48;

	const ACCESS_DIR = 0x20;
	const ACCESS_LOCKED = 0x10;
	const ACCESS_OWNER_WRITE = 0x08;
	const ACCESS_OWNER_READ = 0x04;
	const ACCESS_PUBLIC_WRITE = 0x02;
	const ACCESS_PUBLIC_READ = 0x01;

	private $iSectorsPerCylinder = NULL;

	private $iCylinders = NULL;

	private $sDiscName = NULL;

	private $aRoot = NULL;

	private $aSectors = [];

	private $aAllocated = [];

	private $aSins = [];

	private $aExtents = [];

	/**
	 * @param int $iSectorsPerCylinder
	 * @param int $iCylinders Must be at least 3, as cylinder 0 is the ADFS stub and the next two each hold a copy of the info sector
	 * @param string $sDiscName
	*/
	public function __construct(int $iSectorsPerCylinder=16, int $iCylinders=8, string $sDiscName='TestDisc')
	{
		if($iCylinders<3 OR $iSectorsPerCylinder<8){
			throw new Exception("The geometry is too small to hold a fileserver partition");
		}
		$this->iSectorsPerCylinder = $iSectorsPerCylinder;
		$this->iCylinders = $iCylinders;
		$this->sDiscName = $sDiscName;
		$this->aRoot = ['type'=>'dir','name'=>'$','children'=>[]];
	}

	/**
	 * Adds a directory, the parent must already exist
	 *
	 * @param string $sPath e.g. '$.Library'
	 * @param int $iAccess
	*/
	public function addDirectory(string $sPath, int $iAccess = self::ACCESS_DIR | self::ACCESS_LOCKED): self
	{
		$this->_addNode($sPath,['type'=>'dir','access'=>$iAccess | self::ACCESS_DIR,'load'=>0,'exec'=>0,'children'=>[]]);
		return $this;
	}

	/**
	 * Adds a file, the parent directory must already exist
	 *
	 * @param string $sPath e.g. '$.Library.Fred'
	 * @param string $sData
	 * @param int $iLoad
	 * @param int $iExec
	 * @param int $iAccess
	 * @param boolean $bFragment If true every sector of the file is placed in its own extent
	*/
	public function addFile(string $sPath, string $sData, int $iLoad=0xFFFF0E00, int $iExec=0xFFFF802B, int $iAccess = self::ACCESS_OWNER_WRITE | self::ACCESS_OWNER_READ | self::ACCESS_PUBLIC_READ, bool $bFragment=false): self
	{
		$this->_addNode($sPath,['type'=>'file','access'=>$iAccess & ~self::ACCESS_DIR,'load'=>$iLoad,'exec'=>$iExec,'data'=>$sData,'fragment'=>$bFragment]);
		return $this;
	}

	private function _addNode(string $sPath, array $aNode)
	{
		$aParts = explode('.',$sPath);
		if(array_shift($aParts)!='$' OR count($aParts)==0){
			throw new Exception("Paths must start at the root e.g. \$.Name");
		}
		$sName = array_pop($aParts);
		if(strlen($sName)<1 OR strlen($sName)>10){
			throw new Exception("Names must be between 1 and 10 characters");
		}
		$aDir = &$this->aRoot;
		foreach($aParts as $sPart){
			if(!isset($aDir['children'][$sPart]) OR $aDir['children'][$sPart]['type']!='dir'){
				throw new Exception("The parent directory does not exist for ".$sPath);
			}
			$aDir = &$aDir['children'][$sPart];
		}
		$aNode['name'] = $sName;
		$aDir['children'][$sName] = $aNode;
	}

	/**
	 * Builds the image and writes it to a temporary file
	 *
	 * @return string The path of the image, the caller should unlink it when finished
	*/
	public function write(): string
	{
		$sPath = tempnam(sys_get_temp_dir(),'afs');
		file_put_contents($sPath,$this->build());
		return $sPath;
	}

	/**
	 * Builds the image
	 *
	 * @return string The binary image
	*/
	public function build(): string
	{
		$this->aSectors = [];
		$this->aAllocated = [];
		$this->aSins = [];
		$this->aExtents = [];
		$iSpc = $this->iSectorsPerCylinder;

		//The whole of the ADFS stub cylinder, every bitmap sector, and both info sectors are never available for objects
		for($i=0;$i<$iSpc;$i++){
			$this->aAllocated[$i] = true;
		}
		for($iCylinder=1;$iCylinder<$this->iCylinders;$iCylinder++){
			$this->aAllocated[$iCylinder*$iSpc] = true;
		}
		$this->aAllocated[$this->getInfoSector(1)] = true;
		$this->aAllocated[$this->getInfoSector(2)] = true;

		$iRootSin = $this->_buildDirectory($this->aRoot,'$',0);

		//Sectors 0 and 1 look like a small full ADFS disk, and each points at one copy of the info sector
		$sSector0 = $this->_blank();
		$sSector0 = $this->_poke($sSector0,0x00,$this->_int(7,3));
		$sSector0 = $this->_poke($sSector0,0xF6,$this->_int($this->getInfoSector(1),3));
		$sSector0 = $this->_poke($sSector0,0xFC,$this->_int($iSpc,3));
		$this->aSectors[0] = $this->_poke($sSector0,0xFF,chr(self::adfsChecksum($sSector0)));
		$sSector1 = $this->_blank();
		$sSector1 = $this->_poke($sSector1,0x00,$this->_int($iSpc-7,3));
		$sSector1 = $this->_poke($sSector1,0xF6,$this->_int($this->getInfoSector(2),3));
		$sSector1 = $this->_poke($sSector1,0xFE,chr(3));
		$this->aSectors[1] = $this->_poke($sSector1,0xFF,chr(self::adfsChecksum($sSector1)));

		$sInfo = "AFS0".str_pad($this->sDiscName,16," ")
			.$this->_int($this->iCylinders,2)
			.$this->_int($this->getSectorCount(),3)
			.chr(1)
			.$this->_int($iSpc,2)
			.chr(1).chr(1).chr(1)
			.$this->_int($iRootSin,3)
			.$this->_date()
			.$this->_int(1,2)
			.chr(0);
		$this->aSectors[$this->getInfoSector(1)] = str_pad($sInfo,self::SECTOR_SIZE,"\0");
		$this->aSectors[$this->getInfoSector(2)] = str_pad($sInfo,self::SECTOR_SIZE,"\0");

		//The first sector of each cylinder is its bitmap, a set bit is a free sector
		for($iCylinder=1;$iCylinder<$this->iCylinders;$iCylinder++){
			$sBitmap = $this->_blank();
			for($i=0;$i<$iSpc;$i++){
				if(!isset($this->aAllocated[($iCylinder*$iSpc)+$i])){
					$sBitmap[$i >> 3] = chr(ord($sBitmap[$i >> 3]) | (1 << ($i & 7)));
				}
			}
			$this->aSectors[$iCylinder*$iSpc] = $sBitmap;
		}

		$sImage = "";
		for($i=0;$i<$this->getSectorCount();$i++){
			$sImage .= isset($this->aSectors[$i]) ? $this->aSectors[$i] : $this->_blank();
		}
		return $sImage;
	}

	/**
	 * Gets the sector holding a copy of the info sector
	 *
	 * @param int $iCopy 1 or 2
	*/
	public function getInfoSector(int $iCopy): int
	{
		return ($this->iSectorsPerCylinder*$iCopy)+1;
	}

	public function getSectorCount(): int
	{
		return $this->iSectorsPerCylinder*$this->iCylinders;
	}

	/**
	 * Gets the SIN (the sector of the first map block) for an object, only valid after the image has been built
	 *
	 * @param string $sPath e.g. '$.Library.Fred'
	*/
	public function getSin(string $sPath): int
	{
		if(!isset($this->aSins[$sPath])){
			throw new Exception("No such object ".$sPath);
		}
		return $this->aSins[$sPath];
	}

	/**
	 * Gets the extents holding the data of an object, only valid after the image has been built
	 *
	 * @param string $sPath
	 * @return array In the format array(array('sector'=>,'count'=>))
	*/
	public function getExtents(string $sPath): array
	{
		if(!isset($this->aExtents[$sPath])){
			throw new Exception("No such object ".$sPath);
		}
		return $this->aExtents[$sPath];
	}

	/**
	 * Calculates the ADFS old map checksum for a sector
	*/
	public static function adfsChecksum(string $sSector): int
	{
		$iSum = 255;
		for($i=254;$i>=0;$i--){
			$iSum = ($iSum & 0xFF) + ord($sSector[$i]) + ($iSum >> 8);
		}
		return $iSum & 0xFF;
	}

	/**
	 * Overwrites bytes in an image file, used by tests to damage an image
	 *
	 * @param string $sImagePath
	 * @param int $iSector
	 * @param int $iOffset The offset into the sector
	 * @param string $sBytes
	*/
	public static function patch(string $sImagePath, int $iSector, int $iOffset, string $sBytes)
	{
		$iFileHandle = fopen($sImagePath,'r+');
		fseek($iFileHandle,($iSector*self::SECTOR_SIZE)+$iOffset);
		fwrite($iFileHandle,$sBytes);
		fclose($iFileHandle);
	}

	/**
	 * Reads bytes from an image file
	*/
	public static function peek(string $sImagePath, int $iSector, int $iOffset, int $iLength): string
	{
		$iFileHandle = fopen($sImagePath,'r');
		fseek($iFileHandle,($iSector*self::SECTOR_SIZE)+$iOffset);
		$sBytes = fread($iFileHandle,$iLength);
		fclose($iFileHandle);
		return $sBytes;
	}

	private function _buildDirectory(array $aDir, string $sPath, int $iParentSin): int
	{
		//Entries are kept in case insensitive alphabetical order
		$aNames = array_keys($aDir['children']);
		usort($aNames,function($sA,$sB){ return strcmp(strtoupper($sA),strtoupper($sB)); });

		//Slot 0 is the dummy entry holding the parent's SIN
		$iSlots = max(self::DIR_MIN_SLOTS,count($aNames)+1);
		$iSize = self::DIR_HEADER_SIZE+($iSlots*self::DIR_ENTRY_SIZE)+1;

		//The space for the directory is claimed first so the children know the SIN of their parent
		$iSin = $this->_allocate();
		$aExtents = $this->_allocateExtents((int) ceil($iSize/self::SECTOR_SIZE),false);
		$this->aSins[$sPath] = $iSin;
		$this->aExtents[$sPath] = $aExtents;

		$iSequence = 3;
		$sData = str_repeat("\0",$iSize);
		$sData = $this->_poke($sData,0x02,chr($iSequence));
		$sData = $this->_poke($sData,0x03,str_pad($aDir['name'],10," "));
		$sData = $this->_poke($sData,0x0F,$this->_int(count($aNames),2));
		$sData = $this->_poke($sData,$iSize-1,chr($iSequence));

		$sData = $this->_poke($sData,self::DIR_HEADER_SIZE,"\xFF\xFF");
		$sData = $this->_poke($sData,self::DIR_HEADER_SIZE+23,$this->_int($iParentSin,3));

		foreach($aNames as $iIndex=>$sName){
			$aNode = $aDir['children'][$sName];
			if($aNode['type']=='dir'){
				$iChildSin = $this->_buildDirectory($aNode,$sPath.'.'.$sName,$iSin);
			}else{
				$iChildSin = $this->_buildFile($aNode,$sPath.'.'.$sName);
			}
			$iOffset = $this->_slotOffset($iIndex+1);
			$iNext = ($iIndex+1)<count($aNames) ? $this->_slotOffset($iIndex+2) : 0;
			$sEntry = $this->_int($iNext,2)
				.str_pad($sName,10," ")
				.$this->_int($aNode['load'],4)
				.$this->_int($aNode['exec'],4)
				.chr($aNode['access'])
				.$this->_date()
				.$this->_int($iChildSin,3);
			$sData = $this->_poke($sData,$iOffset,$sEntry);
		}
		if(count($aNames)>0){
			$sData = $this->_poke($sData,0x00,$this->_int($this->_slotOffset(1),2));
		}

		//Chain together the unused slots
		$iFirstFree = count($aNames)+1;
		if($iFirstFree<$iSlots){
			$sData = $this->_poke($sData,0x0D,$this->_int($this->_slotOffset($iFirstFree),2));
			for($i=$iFirstFree;$i<($iSlots-1);$i++){
				$sData = $this->_poke($sData,$this->_slotOffset($i),$this->_int($this->_slotOffset($i+1),2));
			}
		}

		$this->_writeObject($iSin,$aExtents,$sData);
		return $iSin;
	}

	private function _buildFile(array $aFile, string $sPath): int
	{
		$iSin = $this->_allocate();
		$aExtents = $this->_allocateExtents((int) ceil(strlen($aFile['data'])/self::SECTOR_SIZE),$aFile['fragment']);
		$this->aSins[$sPath] = $iSin;
		$this->aExtents[$sPath] = $aExtents;
		$this->_writeObject($iSin,$aExtents,$aFile['data']);
		return $iSin;
	}

	/**
	 * Writes the data sectors and the map block(s) for an object
	*/
	private function _writeObject(int $iSin, array $aExtents, string $sData)
	{
		$iPosition = 0;
		foreach($aExtents as $aExtent){
			for($i=0;$i<$aExtent['count'];$i++){
				$this->aSectors[$aExtent['sector']+$i] = str_pad(substr($sData,$iPosition,self::SECTOR_SIZE),self::SECTOR_SIZE,"\0");
				$iPosition = $iPosition+self::SECTOR_SIZE;
			}
		}

		//Each map block holds 48 extents, with the 49th entry linking to the next block
		$aBlocks = array_chunk($aExtents,self::MAP_DATA_ENTRIES);
		if(count($aBlocks)==0){
			$aBlocks = [[]];
		}
		$iBlockSector = $iSin;
		foreach($aBlocks as $iIndex=>$aBlockExtents){
			$sBlock = $this->_blank();
			//Only the first block has the identifier, and the sequence numbers of each block are unrelated
			$iSequence = ($iIndex==0) ? 2 : 6+$iIndex;
			if($iIndex==0){
				$sBlock = $this->_poke($sBlock,0x00,"JesMap");
			}
			$sBlock = $this->_poke($sBlock,0x06,chr($iSequence));
			$sBlock = $this->_poke($sBlock,0x08,$this->_int(strlen($sData) % self::SECTOR_SIZE,2));
			foreach($aBlockExtents as $iEntry=>$aExtent){
				$sBlock = $this->_poke($sBlock,0x0A+($iEntry*5),$this->_int($aExtent['sector'],3).$this->_int($aExtent['count'],2));
			}
			$iNextBlock = 0;
			if(($iIndex+1)<count($aBlocks)){
				$iNextBlock = $this->_allocate();
				$sBlock = $this->_poke($sBlock,0xFA,$this->_int($iNextBlock,3).$this->_int(1,2));
			}
			$sBlock = $this->_poke($sBlock,0xFF,chr($iSequence));
			$this->aSectors[$iBlockSector] = $sBlock;
			$iBlockSector = $iNextBlock;
		}
	}

	/**
	 * Claims the next free sector
	*/
	private function _allocate(): int
	{
		for($i=$this->iSectorsPerCylinder;$i<$this->getSectorCount();$i++){
			if(!isset($this->aAllocated[$i])){
				$this->aAllocated[$i] = true;
				return $i;
			}
		}
		throw new Exception("The test image is full, use a larger geometry");
	}

	/**
	 * Claims a number of sectors, returning them as extents
	 *
	 * Runs of neighbouring sectors are merged into a single extent, so extents never cross a bitmap sector (and thus never cross a cylinder)
	 * @param int $iCount
	 * @param boolean $bFragment If true a sector is skipped after each one claimed, so every sector ends up in its own extent
	*/
	private function _allocateExtents(int $iCount, bool $bFragment): array
	{
		$aExtents = [];
		$aSkipped = [];
		for($i=0;$i<$iCount;$i++){
			$iSector = $this->_allocate();
			$iLast = count($aExtents)-1;
			if($iLast>=0 AND ($aExtents[$iLast]['sector']+$aExtents[$iLast]['count'])==$iSector){
				$aExtents[$iLast]['count']++;
			}else{
				$aExtents[] = ['sector'=>$iSector,'count'=>1];
			}
			if($bFragment){
				$aSkipped[] = $this->_allocate();
			}
		}
		//The skipped sectors where only claimed to force a gap
		foreach($aSkipped as $iSector){
			unset($this->aAllocated[$iSector]);
		}
		return $aExtents;
	}

	private function _slotOffset(int $iSlot): int
	{
		return self::DIR_HEADER_SIZE+($iSlot*self::DIR_ENTRY_SIZE);
	}

	private function _blank(): string
	{
		return str_repeat("\0",self::SECTOR_SIZE);
	}

	private function _poke(string $sData, int $iOffset, string $sBytes): string
	{
		return substr_replace($sData,$sBytes,$iOffset,strlen($sBytes));
	}

	/**
	 * Encodes a little endian int
	*/
	private function _int(int $iValue, int $iBytes): string
	{
		$sReturn = "";
		for($i=0;$i<$iBytes;$i++){
			$sReturn .= chr(($iValue >> (8*$i)) & 0xFF);
		}
		return $sReturn;
	}

	/**
	 * The 15th of March 1986 in the filing system date format
	*/
	private function _date(): string
	{
		return "\x0F\x53";
	}
}
