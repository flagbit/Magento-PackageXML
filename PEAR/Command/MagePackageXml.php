<?php

// Looks like PEAR is being developed without E_NOTICE (1.7.0RC1)
error_reporting(E_ALL & ~E_NOTICE);

define('DS', DIRECTORY_SEPARATOR);
define('PS', PATH_SEPARATOR);

require_once 'PEAR/Command/Common.php';
require_once 'PEAR/Command/Mage.php';
require_once 'PEAR/PackageFileManager2.php';

class PEAR_Command_MagePackageXml extends PEAR_Command_Mage
{
    var $commands = array(
        'mage-package-xml' => array(
            'summary' => 'Build Magento Package XML',
            'function' => 'doPackage',
            'shortcut' => 'mpx',
            'doc' => '[descfile]
Creates a Magento specific PEAR package file.
'
            ),
        );

    protected $_pfm;        
        
    protected $_data = array(
        'options' => array(
            'baseinstalldir'=>'.',
            'filelistgenerator'=>'file',
            'packagedirectory'=>'.',
            'outputdirectory'=>'.',
        ),
        'package' => array(),
        'release' => array(),
    );
    
    protected $_allreadyIncludedFiles = array();

    protected $_ignoreRoles = array('mageweb', 'mage');

    /**
     * PEAR_Command_Package constructor.
     *
     * @access public
     */
    function PEAR_Command_MagePackageXml(&$ui, &$config)
    {
        parent::PEAR_Command_Common($ui, $config);
    }

    function doPackage($command, $options, $params)
    {
        $this->pkginfofile = isset($params[0]) ? $params[0] : 'package.xml';

        $this->xml = simplexml_load_file($this->pkginfofile);
    	
    	$this->_options = $options;
        $result = '';
        if ($result instanceof PEAR_Error) {
            return $result;
        }
        
        $this->_pfm = $this->getPfm();
        $this->_pfm->setOptions(array(
            'packagedirectory'=>'.',
            'baseinstalldir'=>'.',
            'simpleoutput'=>true,
        ));
        
	    $this->_setPackage($this->_pfm);

        $this->_setRelease($this->_pfm);
        $this->_setMaintainers($this->_pfm);
        $this->_setDependencies($this->_pfm);
         

        $this->_setContents($this->_pfm);        

        if (!$this->_pfm->validate(PEAR_VALIDATE_NORMAL)) {
            $message = $this->_pfm->getValidationWarnings();
            $this->raiseError($message[0]['message']);

            return $this;
        }

        //$this->output = $this->_pfm->getDefaultGenerator()->toXml(PEAR_VALIDATE_NORMAL);  
        $result = $this->_pfm->writePackageFile(); 
        if($result === true){     
        	$this->output .= 'package.xml file successfully updated!';
        }

        if ($this->output) {
            $this->ui->outputData($this->output, $command);
        }

        return true;
    }

    public function getData($key){
    	return (string) $this->xml->$key;
    }

    protected function _setDependencies($pfm)
    {
        $pfm->clearDeps();
        $pfm->setPhpDep((string) $this->xml->dependencies->required->php->min, (string) $this->xml->dependencies->required->php->max);
        $pfm->setPearinstallerDep('1.6.2');
             
        $dependencies = array();

        if(!isset($this->xml->dependencies) or !($this->xml->dependencies instanceof SimpleXMLElement)){
        	return;
        }
        
        foreach($this->xml->dependencies as $dependency){        	
        	foreach($dependency as $debtype => $packages){			
        		foreach($packages as $packagetype => $deps){
        			if(!in_array($packagetype, array('package', 'subpackage', 'extension'))){
        				continue;
        			}
        			$dependencies[$packagetype]['name'][] = (string) $deps->name;
        			$dependencies[$packagetype]['channel'][] = (string) $deps->channel;
        			$dependencies[$packagetype]['min'][] = (string) $deps->min; 
        			$dependencies[$packagetype]['max'][] = (string) $deps->max; 
        			$dependencies[$packagetype]['recommended'][] = (string) $deps->recommended; 
        			$dependencies[$packagetype]['exclude'][] = (string) $deps->exclude;
        			$dependencies[$packagetype]['type'][] = isset($deps->conflicts) ? 'conflicts' :$debtype;
        		}
        	}       	
        }

        foreach ($dependencies as $deptype=>$deps) {
            foreach ($deps['type'] as $i=>$type) {
                if (0===$i) {
                    continue;
                }
                $name = $deps['name'][$i];
                $min = !empty($deps['min'][$i]) ? $deps['min'][$i] : false;
                $max = !empty($deps['max'][$i]) ? $deps['max'][$i] : false;
                $recommended = !empty($deps['recommended'][$i]) ? $deps['recommended'][$i] : false;
                $exclude = !empty($deps['exclude'][$i]) ? explode(',', $deps['exclude'][$i]) : false;
                if ($deptype!=='extension') {
                    $channel = !empty($deps['channel'][$i]) ? $deps['channel'][$i] : 'connect.magentocommerce.com/core';
                }
                switch ($deptype) {
                    case 'package':
                        if ($type==='conflicts') {
                            $pfm->addConflictingPackageDepWithChannel(
                                $name, $channel, false, $min, $max, $recommended, $exclude);
                        } else {
                            $pfm->addPackageDepWithChannel(
                                $type, $name, $channel, $min, $max, $recommended, $exclude);
                        }
                        break;

                    case 'subpackage':
                        if ($type==='conflicts') {
                            Mage::throwException(Mage::helper('adminhtml')->__("Subpackage cannot be conflicting."));
                        }
                        $pfm->addSubpackageDepWithChannel(
                            $type, $name, $channel, $min, $max, $recommended, $exclude);
                        break;

                    case 'extension':
                        $pfm->addExtensionDep(
                            $type, $name, $min, $max, $recommended, $exclude);
                        break;
                }
            }
        }

    }
    
    /**
     * 
     * @param PEAR_PackageFileManager2 $pfm
     */
    protected function _setMaintainers($pfm)
    {
    	
    	$maintainers = array();
    	if(is_array($this->_pfm->_oldPackageFile->getLeads())){
    		$lead = $this->_pfm->_oldPackageFile->getLeads();
    		$maintainers['role'][] = 'lead';
    		$maintainers['name'][] = $lead['name'];
    		$maintainers['handle'][] = $lead['user']; 
    		$maintainers['email'][] = $lead['email'];
    		$maintainers['active'][] = $lead['active'] == 'yes' ? 1 : 0;
    	}else{
    		$maintainers['role'][] = 'lead';
    		$maintainers['name'][] = 'Flagbit GmbH & Co. KG';
    		$maintainers['handle'][] = 'flagbit'; 
    		$maintainers['email'][] = 'magento@flagbit.de';
    		$maintainers['active'][] = '1';    		
    	}
    	
        foreach ($maintainers['role'] as $i=>$role) {
            $handle = $maintainers['handle'][$i];
            $name = $maintainers['name'][$i];
            $email = $maintainers['email'][$i];
            $active = !empty($maintainers['active'][$i]) ? 'yes' : 'no';
            $pfm->addMaintainer($role, $handle, $name, $email, $active);
        }
    }    
    
    protected function _setPackage($pfm)
    {
        $pfm->setPackageType('php');
        $pfm->setChannel($this->_pfm->_oldPackageFile->getChannel());

		$pfm->setLicense($this->_pfm->_oldPackageFile->getLicense());

        $pfm->setPackage($this->_pfm->_oldPackageFile->getPackage());
        $pfm->setSummary($this->_pfm->_oldPackageFile->getSummary());
        $pfm->setDescription($this->_pfm->_oldPackageFile->getDescription());
    }

    protected function _setRelease($pfm)
    {
    	
    	$data = $this->_pfm->_oldPackageFile->getArray();
    	
        $pfm->addRelease();
        $pfm->setDate(date('Y-m-d'));
  
        $pfm->setAPIVersion($data['version']['api']);
        $pfm->setReleaseVersion($data['version']['release']);
        $pfm->setAPIStability($data['stability']['api']);
        $pfm->setReleaseStability($data['stability']['release']);
        $pfm->setNotes($data['notes']);
    }    
    
    public function getRoleDir($role)
    {
        $roles = $this->getRoles();
        return $this->config->get($roles[$role]['dir_config']);
    }
    
    protected function _setContents($pfm)
    {
        $baseDir = $this->getRoleDir('mage').DS;

        $contents = array();
        foreach($this->getRoles() as $role => $roleConfig){
        	if(!strstr($role, 'mage') or in_array($role, $this->_ignoreRoles)){
        		continue;
        	}
        	$contents['role'][] = $role;
        	$contents['path'][] = '';
        	$contents['type'][] = 'dir';
        	$contents['include'][] = '';
        	$contents['ignore'][] = '';
        }
        
        $contents['role'][] = 'mage';
        $contents['path'][] = '';
        $contents['type'][] = 'dir';
        $contents['include'][] = '';
        $contents['ignore'][] = '';        
        
        $pfm->clearContents();
        
        $usesRoles = array();
        foreach ($contents['role'] as $i=>$role) {
            if (0===$i) {
                continue;
            }

            $usesRoles[$role] = 1;

            $roleDir = $this->getRoleDir($role).DS;
            $fullPath = $roleDir.$contents['path'][$i];
            
            switch ($contents['type'][$i]) {
                case 'file':
                    if (!is_file($fullPath)) {
                        $this->raiseError("Invalid file: %s", $fullPath);
                    }
                    $pfm->addFile('/', $contents['path'][$i], array('role'=>$role, 'md5sum'=>md5_file($fullPath)));
                    break;

                case 'dir':
                    if (!is_dir($fullPath)) {
                    	continue;
                        $this->raiseError("Invalid directory: " . $fullPath);
                    }
                    $path = $contents['path'][$i];
                    $include = $contents['include'][$i];
                    $ignore = $contents['ignore'][$i];
                    $this->_addDir($pfm, $role, $roleDir, $path, $include, $ignore);
                    break;
            }
        }

        $pearRoles = $this->getRoles();

        foreach ($usesRoles as $role=>$dummy) {
            if (empty($pearRoles[$role]['package'])) {
                continue;
            }
            $pfm->addUsesrole($role, $pearRoles[$role]['package']);
        }
    }

    protected function _addDir($pfm, $role, $roleDir, $path, $include, $ignore)
    {
        $roleDirLen = strlen($roleDir);
        $entries = @glob($roleDir.$path.DS."*");
        if (!empty($entries)) {
            foreach ($entries as $entry) {
            	$entry = str_replace('//', '/', $entry);
                $filePath = substr($entry, $roleDirLen);
                if (!empty($include) && !preg_match($include, $filePath)) {
                    continue;
                }
                if (!empty($ignore) && preg_match($ignore, $filePath)) {
                    continue;
                }
                if (is_dir($entry)) {
                    $baseName = basename($entry);
                    if ('.'===$baseName || '..'===$baseName) {
                        continue;
                    }
                    $this->_addDir($pfm, $role, $roleDir, $filePath, $include, $ignore);
                } elseif (is_file($entry) && strpos($entry, 'package.xml') === false) {
                	$md5 = md5_file($entry);
                	if(!in_array($md5, $this->_allreadyIncludedFiles)){
	                	$this->_allreadyIncludedFiles[] = $md5;
	                    $pfm->addFile('/', $filePath, array('role'=>$role, 'md5sum'=>$md5));
                	}
                }
            }
        }
    }    
    
    /**
     * Get PackageFileManager2 instance
     *
     * @param string|PEAR_PackageFile_v1 $package
     * @return PEAR_PackageFileManager2|PEAR_Error
     */
    public function getPfm($package=null)
    {
        if (!$this->_pfm) {
            if (is_null($package)) {
                $this->_pfm = new PEAR_PackageFileManager2;
                $this->_pfm->setOptions($this->get('options'));
            } else {
                $this->defineData();

                $this->_pfm = PEAR_PackageFileManager2::importOptions($package, $this->get('options'));
                if ($this->_pfm instanceof PEAR_Error) {
                    $e = PEAR_Exception('Could not instantiate PEAR_PackageFileManager2');
                    $e->errorObject = $this->_pfm;
                    throw $e;
                }
            }
        }
        return $this->_pfm;
    }


    public function get($key)
    {
        if (''===$key) {
            return $this->_data;
        }

        // accept a/b/c as ['a']['b']['c']
        $keyArr = explode('/', $key);
        $data = $this->_data;
        foreach ($keyArr as $i=>$k) {
            if ($k==='') {
                return null;
            }
            if (is_array($data)) {
                if (!isset($data[$k])) {
                    return null;
                }
                $data = $data[$k];
            } else {
                return null;
            }
        }
        return $data;
    }
    
    
}

