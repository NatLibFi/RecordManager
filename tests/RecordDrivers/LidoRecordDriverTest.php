<?php
require_once('RecordDriverTest.php');

class LidoRecordDriverTest extends RecordDriverTest
{
	protected $driver = 'Lido';

    public function testMusketti1()
    {
        $fields = $this->processSample('musketti1.xml');
        
        $this->assertContains('metalli', $fields['material']);
        $this->assertContains('kupari', $fields['material']);
        
        $this->assertContains('ruokatalous ja elintarviketeollisuus', $fields['classification_str_mv']);
        $this->assertContains('ruoan valmistus', $fields['classification_str_mv']);
        $this->assertContains('työkalut ja välineet', $fields['classification_str_mv']);
        $this->assertContains('astiat ja välineet', $fields['classification_str_mv']);
        $this->assertContains('kahvipannu', $fields['classification_str_mv']);
        
        $this->assertContains('taloustarvikkeet', $fields['topic']);
        $this->assertContains('nautintoaineet', $fields['topic']);
        $this->assertContains('kahvi', $fields['topic']);
        $this->assertContains('suomalais-ugrilaiset kansat', $fields['topic']);
        $this->assertContains('saamelaiset', $fields['topic']);
        $this->assertContains('porolappalaiset', $fields['topic']);
        $this->assertContains('pannut', $fields['topic']);
        $this->assertContains('kahvipannut', $fields['topic']);
        
        $this->assertEquals('kahvipannu', $fields['title']);
        
        $this->assertEquals('Suomen kansallismuseo/KM', $fields['institution']);
        
        $this->assertEquals('S3168:23', $fields['identifier']);
        
        $this->assertEquals('Kuparinen, mustunut ja kolhiintunut poromiesten käyttämä kahvipannu.', $fields['description']);
        
        $this->assertContains('korkeus, suurin 12.50, halkaisija 13 cm', $fields['measurements']);
        
        $this->assertContains('saamelaiset', $fields['culture']);
        
        $this->assertEquals('http://muisti.nba.fi/m/S3168_23/sa009218.jpg', $fields['thumbnail']);
        
        $this->assertEquals('Seurasaaren ulkomuseon kokoelmat', $fields['collection']);
        
        $this->assertContains('esine', $fields['format']);
        
        $this->assertContains('Utsjoki, Lappi', $fields['allfields']);
        $this->assertContains('teollinen tuote', $fields['allfields']);
        $this->assertContains('Museovirasto/MV', $fields['allfields']);
    }

    public function testMusketti2()
    {
    	$fields = $this->processSample('musketti2.xml');
    	
    	$this->assertContains('valokuva', $fields['classification_str_mv']);
    	
    	$this->assertContains('kuva', $fields['format']);
    
    	$this->assertContains('12 x 17 cm, 12 cm', $fields['measurements']);
    	
    	$this->assertContains('Imatrankoski', $fields['allfields']);
    	$this->assertContains('valokuva', $fields['allfields']);
    	
    	// Inscriptions (merkinnät) not indexed
    	// Could they be?
    	
    	$this->assertEquals('Museoviraston kuva-arkisto/', $fields['institution']);
    	
    	$this->assertEquals('4878:1', $fields['identifier']);
    	
    	$this->assertContains('12 x 17 cm, 12 cm', $fields['measurements']);
    	
    	$this->assertEquals('Hintze Harry', $fields['author']);
    }
    
    public function testLusto1()
    {
    	$fields = $this->processSample('lusto1.xml');
    
    	$this->assertContains('E01025:3', $fields['identifier']);
    	
    	$this->assertContains('muovi', $fields['material']);
    	
    	$this->assertContains('istutus', $fields['topic']);
    	$this->assertContains('kantovälineet', $fields['topic']);
    	$this->assertContains('metsänhoito', $fields['topic']);
    	$this->assertContains('metsänviljely', $fields['topic']);
    	$this->assertContains('metsätalous', $fields['topic']);
    	
    	$this->assertContains('1980-01-01T00:00:00Z,1999-12-31T23:59:59Z', $fields['unit_daterange']);
    	
    	$this->assertContains('Esine', $fields['format']);
    	
    	$this->assertContains('pituus 65 cm, leveys 55 cm, korkeus enimmillään 26 cm', $fields['measurements']);
    }
    
    public function testVtm1()
    {
    	$fields = $this->processSample('vtm1.xml');
    
    	$this->assertContains('kangas', $fields['material']);
    	$this->assertContains('öljy', $fields['material']);
    
    	$this->assertContains('maalaus', $fields['classification_str_mv']);
    
    	$this->assertEquals('Venetsia', $fields['title']);
    
    	$this->assertEquals('Ateneumin taidemuseo', $fields['institution']);
    
    	$this->assertEquals('A V 4724', $fields['identifier']);
    
    	$this->assertContains(' 41 x 51 cm', $fields['measurements']);
    
    	$this->assertEquals('http://ndl.fng.fi/ndl/zoomview/muusa01/0051A721-E48D-42B4-BD02-98D8C4681A50.jpg', $fields['thumbnail']);
    
    	$this->assertEquals('Richter', $fields['collection']);
    
    	$this->assertContains('maalaus', $fields['format']);
    	
    	$this->assertEquals('Salokivi, Santeri', $fields['author']);
    	$this->assertEquals('1911-01-01T00:00:00Z,1911-12-31T23:59:59Z', $fields['unit_daterange']);
    }
    
    public function testTuusula1()
    {
    	$fields = $this->processSample('tuusula1.xml');
    
    	$this->assertContains('kangas', $fields['material']);
    	$this->assertContains('pahvi', $fields['material']);
    	$this->assertContains('öljy', $fields['material']);
    
    	$this->assertContains('maalaus', $fields['classification_str_mv']);
    
    	$this->assertEquals('Rantakiviä', $fields['title']);
    
    	$this->assertEquals('Tuusulan taidemuseo', $fields['institution']);
    	
    	$this->assertEquals('Rantamaisema, jonka matalassa vedessä näkyy maalauksen etuosassa punervanharmaita kiviä sekä rantakalliota harmaansinisen vaalean veden rannalla. Taustana pelkää vettä, mikä valtaa suurimman osa kuva-alasta. Veden kuvaus heijastuksineen on kiinnostanut Pekka Halosta koko hänen taiteellisen uransa aikana. Hänen viimeisten vuosien maisemissa vesiaiheet ovat lähinnä rantaviivojen, vesien ja jäänpinnan heijastusten kuvauksia. Horisontti on usein laskenut hyvin alas, sitä tuskin enää näkyy ollenkaan, kuten tässä teoksessa. Halosen maisemat muuttuvat yhä seesteisemmiksi ja pelkistetyimmiksi ', $fields['description']);
    
    	$this->assertEquals('Tla TM T 374', $fields['identifier']);
    	
    	$this->assertNotContains('25H112', $fields['topic']);
    	$this->assertNotContains('Rantamaisema, jonka matalassa vedessä näkyy maalauksen etuosassa punervanharmaita kiviä sekä rantakalliota harmaansinisen vaalean veden rannalla. Taustana pelkää vettä, mikä valtaa suurimman osa kuva-alasta.', $fields['topic']);
    
    	$this->assertContains(' 50 x 41 cm', $fields['measurements']);
    
    	$this->assertEquals('http://ndl.fng.fi/ndl/zoomview/muusa24/3D313279-45A5-469A-885E-766C66F0F6DC.jpg', $fields['thumbnail']);
    
    	$this->assertEquals('Pekka Halosen seuran kokoelma / Antti Halosen kokoelma', $fields['collection']);
    	
    	$this->assertContains('Pystymetsän Pekka. Pekka Halosen maalauksia vuosilta 1887-1932. Halosenniemi, Tuusula 17.4.-17.10.2010', $fields['exhibition_str_mv']);
    
    	$this->assertContains('maalaus', $fields['format']);
    	 
    	$this->assertEquals('Halonen, Pekka', $fields['author']);
    	$this->assertEquals('1930-01-01T00:00:00Z,1930-12-31T23:59:59Z', $fields['unit_daterange']);
    }
}

?>
