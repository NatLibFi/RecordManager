<?php
require_once("classes/RecordFactory.php");
require_once("classes/MetadataUtils.php");

abstract class RecordDriverTest extends PHPUnit_Framework_TestCase
{
	// Override this from subclass
	protected $driver;
	
    /**
     * Standard setup method.
     *
     * @return void
     * @access public
     */
    public function setUp()
    {
    	if(empty($this->driver))
    		$this->markTestIncomplete('Record driver needs to be set in subclass.');
    }
    
    protected function processSample($sample) {
    	$actualdir = dirname(__FILE__);
    	$sample = file_get_contents($actualdir . "/../samples/" . $sample);
    	$record = RecordFactory::createRecord($this->driver, $sample, "__unit_test_no_id__", "__unit_test_no_source__");
    	return $record->toSolrArray();
    }
}
?>
