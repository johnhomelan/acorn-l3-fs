<?php
/**
 * Writes all the files in a Level 3 fileserver / Filestore disk image to a tar archive
 *
 * The layout inside the archive is the one used by the LocalFile plugin of aun-filestore, so the archive can be
 * unpacked straight into the directory that plugin serves:
 *
 *  - Directories are directories, and files are files holding just the contents.
 *  - A '/' in an Acorn name becomes a '.' in the name used in the archive.
 *  - Each file has a sidecar file with .inf added to its name, holding "TAPE file LLLLLLLL EEEEEEEE" where
 *    LLLLLLLL and EEEEEEEE are the load and exec addresses as 8 hex digits.
 *  - The access is carried in the unix mode, owner read/write as 0400/0200 and public read/write as 0004/0002.
 *  - The date of the entry is used as the modification time.
 *
 * The archive is written directly in ustar format, so there is no need for phar.readonly to be off.
*/

namespace HomeLan\Retro\Acorn\Disk;

use Exception;

class L3fsTarExporter {

	const BLOCK_SIZE = 512;

	protected $oReader = NULL;

	protected $hTar = NULL;

	protected $iNow = 0;

	protected $aResult = [];

	/**
	 * @param L3fsReader $oReader The image to export, use a L3fsRecovery to get as much as possible out of a damaged image
	*/
	public function __construct(L3fsReader $oReader)
	{
		$this->oReader = $oReader;
	}

	/**
	 * Writes the archive
	 *
	 * @param string $sTarPath Where to write the archive
	 * @param string $sPrefix A directory inside the archive to put everything in, by default the contents of the root directory are at the top level
	 * @return array array('directories'=>,'files'=>,'bytes'=>,'suspect'=>,'skipped'=>) where suspect is a list of the files that may have parts missing,
	 *               and skipped is a list of array('path'=>,'reason'=>) for the entries that could not be written
	*/
	public function export(string $sTarPath, string $sPrefix=''):array
	{
		$aCatalogue = $this->oReader->getCatalogue();
		$this->aResult = ['directories'=>0,'files'=>0,'bytes'=>0,'suspect'=>[],'skipped'=>[]];
		$this->iNow = time();
		$this->hTar = @fopen($sTarPath,'w');
		if($this->hTar===false){
			throw new Exception("Unable to write to ".$sTarPath);
		}
		$sPrefix = trim($sPrefix,'/');
		if($sPrefix!=''){
			$this->_writeEntry($sPrefix.'/',NULL,0755,$this->iNow);
			$sPrefix = $sPrefix.'/';
		}
		$this->_exportDirectory($aCatalogue,$sPrefix,'$');
		//The end of an archive is marked by two empty blocks
		fwrite($this->hTar,str_repeat("\0",self::BLOCK_SIZE*2));
		fclose($this->hTar);
		$this->hTar = NULL;
		return $this->aResult;
	}

	/**
	 * Writes the contents of a directory
	 *
	 * @param array $aDirectory The catalogue for the directory
	 * @param string $sTarPath The path of the directory in the archive, ending in a / unless its the top level
	 * @param string $sAcornPath The path of the directory on the disk, used for reporting
	*/
	protected function _exportDirectory(array $aDirectory, string $sTarPath, string $sAcornPath)
	{
		$aUsed = [];
		foreach($aDirectory as $sName=>$aEntry){
			$sAcornName = $sAcornPath.'.'.$sName;
			$sHostName = $this->_hostName((string) $sName);
			//The names are not case sensitive on the fileserver, and a name ending in .inf would be taken for a sidecar file
			if($sHostName=='' OR $sHostName=='.' OR $sHostName=='..' OR isset($aUsed[strtolower($sHostName)]) OR stripos($sHostName,'.inf')!==false){
				$this->aResult['skipped'][] = ['path'=>$sAcornName,'reason'=>'the name can not be used in the archive'];
				continue;
			}
			$iTime = $this->_timestamp($aEntry['date']);
			if($aEntry['type']=='dir'){
				if(!$this->_writeEntry($sTarPath.$sHostName.'/',NULL,0755,$iTime)){
					$this->aResult['skipped'][] = ['path'=>$sAcornName,'reason'=>'the path is too long for a tar archive'];
					continue;
				}
				$aUsed[strtolower($sHostName)] = true;
				$this->aResult['directories']++;
				$this->_exportDirectory(isset($aEntry['dir']) ? $aEntry['dir'] : [],$sTarPath.$sHostName.'/',$sAcornName);
				continue;
			}
			if(isset($aEntry['recovery']) AND $aEntry['recovery']['status']=='lost'){
				$this->aResult['skipped'][] = ['path'=>$sAcornName,'reason'=>'the contents of the file are lost'];
				continue;
			}
			try {
				$sData = $this->oReader->getFileBySin($aEntry['sector']);
			}catch(Exception $oException){
				$this->aResult['skipped'][] = ['path'=>$sAcornName,'reason'=>$oException->getMessage()];
				continue;
			}
			//Check the sidecar will fit before writing the file, so one is never written without the other
			if(is_null($this->_splitPath($sTarPath.$sHostName.'.inf'))){
				$this->aResult['skipped'][] = ['path'=>$sAcornName,'reason'=>'the path is too long for a tar archive'];
				continue;
			}
			$iMode = $this->_mode($aEntry);
			$this->_writeEntry($sTarPath.$sHostName,$sData,$iMode,$iTime);
			$this->_writeEntry($sTarPath.$sHostName.'.inf',sprintf("TAPE file %08x %08x",$aEntry['load'],$aEntry['exec']),0644,$iTime);
			$aUsed[strtolower($sHostName)] = true;
			$this->aResult['files']++;
			$this->aResult['bytes'] = $this->aResult['bytes']+strlen($sData);
			if(isset($aEntry['recovery']) AND $aEntry['recovery']['status']!='ok'){
				$this->aResult['suspect'][] = $sAcornName;
			}
		}
	}

	/**
	 * Converts an Acorn name to the name used on the host, as the LocalFile plugin of aun-filestore does
	*/
	protected function _hostName(string $sName):string
	{
		return str_replace('/','.',$sName);
	}

	/**
	 * Converts the access byte of an entry to a unix mode
	 *
	 * The owner is always given read access, so the files can be read once they are unpacked.
	*/
	protected function _mode(array $aEntry):int
	{
		$iMode = 0400;
		if($aEntry['user_write'] AND !$aEntry['locked']){
			$iMode = $iMode | 0200;
		}
		if($aEntry['public_read']){
			$iMode = $iMode | 0004;
		}
		if($aEntry['public_write'] AND !$aEntry['locked']){
			$iMode = $iMode | 0002;
		}
		return $iMode;
	}

	/**
	 * Converts the 2 byte filing system date to a unix timestamp
	 *
	 * @return int Midday on the date, or the time of the export if there is no valid date
	*/
	protected function _timestamp(int $iDate):int
	{
		$iDay = $iDate & 0x1F;
		$iMonth = ($iDate >> 8) & 0x0F;
		$iYear = 1981 + (($iDate >> 12) & 0x0F) + ((($iDate >> 5) & 0x07) << 4);
		if($iDate==0 OR !checkdate($iMonth,$iDay,$iYear)){
			return $this->iNow;
		}
		return gmmktime(12,0,0,$iMonth,$iDay,$iYear);
	}

	/**
	 * Splits a path into the prefix and name fields of a ustar header
	 *
	 * @return array|null array(prefix,name) or null if the path is too long to be stored
	*/
	protected function _splitPath(string $sPath)
	{
		if(strlen($sPath)<=100){
			return ['',$sPath];
		}
		//The split has to be at a directory separator, with the separator its self not being stored
		$iPosition = strpos($sPath,'/',max(0,strlen($sPath)-101));
		while($iPosition!==false){
			if($iPosition<=155 AND $iPosition>0 AND (strlen($sPath)-$iPosition-1)<=100 AND (strlen($sPath)-$iPosition-1)>0){
				return [substr($sPath,0,$iPosition),substr($sPath,$iPosition+1)];
			}
			$iPosition = strpos($sPath,'/',$iPosition+1);
		}
		return NULL;
	}

	/**
	 * Writes a file or directory to the archive
	 *
	 * @param string $sPath The path in the archive, ending in a / for a directory
	 * @param string|null $sData The contents of the file, null for a directory
	 * @param int $iMode
	 * @param int $iTime
	 * @return boolean False if the path is too long to be stored
	*/
	protected function _writeEntry(string $sPath, $sData, int $iMode, int $iTime):bool
	{
		$aPath = $this->_splitPath($sPath);
		if(is_null($aPath)){
			return false;
		}
		$iSize = is_null($sData) ? 0 : strlen($sData);
		$sHeader = pack('a100a8a8a8a12a12',$aPath[1],sprintf("%07o",$iMode),sprintf("%07o",0),sprintf("%07o",0),sprintf("%011o",$iSize),sprintf("%011o",$iTime))
			.str_repeat(' ',8)
			.(is_null($sData) ? '5' : '0')
			.pack('a100a6a2a32a32a8a8a155a12','',"ustar",'00','','',sprintf("%07o",0),sprintf("%07o",0),$aPath[0],'');
		$iChecksum = 0;
		for($i=0;$i<self::BLOCK_SIZE;$i++){
			$iChecksum = $iChecksum+ord($sHeader[$i]);
		}
		$sHeader = substr_replace($sHeader,sprintf("%06o\0 ",$iChecksum),148,8);
		fwrite($this->hTar,$sHeader);
		if($iSize>0){
			fwrite($this->hTar,$sData);
			$iPadding = (self::BLOCK_SIZE-($iSize % self::BLOCK_SIZE)) % self::BLOCK_SIZE;
			fwrite($this->hTar,str_repeat("\0",$iPadding));
		}
		return true;
	}
}
