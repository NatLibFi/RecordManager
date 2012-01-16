<?php

class LidoRousku
{ 
	public static function normalizeRangeStart($date) {
		return self::normalizeRangePoint($date, true);
	}
	public static function normalizeRangeEnd($date) {
		return self::normalizeRangePoint($date, false);
	}
	public static function normalizeRangePoint($date, $first = true) {
		$date = trim($date);
		if(preg_match("/^\d\d\d\d-\d\d-\d\d$/",$date)) {
			return substr($date,0,4) . substr($date,5,2) . substr($date,8,2);
		}
		if(preg_match("/^\d\d\d\d$/",$date)) {
			return $first ? $date . "0101": $date . "1231";
		}
		
		error_log("Unsupported date format: $date", 0);
		return;
	}
	
	
	public static function normalizeDate($aika) {
	if($aika == "ajoittamaton" || $aika == "tuntematon")
		return;
$k = array(
'tammikuu' => '01',
'helmikuu' => '02',
'maaliskuu' => '03',
'huhtikuu' => '04',
'toukokuu' => '05',
'kesäkuu' => '06',
'heinäkuu' => '07',
'elokuu' => '08',
'syyskuu' => '09',
'lokakuu' => '10',
'marraskuu' => '11',
'joulukuu' => '12');

// 1940-1960-luku
// 1930 - 1970-luku
// 30-40-luku
if(preg_match("/(\d?\d?\d\d) ?(-|~) ?(\d?\d?\d\d) ?(-luku)?(\(?\?\)?)?/",$aika,$matches)>0) {
	$alku = $matches[1];
	$loppu = $matches[3];
	
	if(isset($matches[4])) {
	$luku = $matches[4];
		if($loppu%10==0) {
			$loppu+=9;
		}
	}
	
	if(isset($matches[5])) {
		$epavarma = $matches[5];
		$alku -= 2;
		$loppu += 2;
	}
}
else if(preg_match("/(\d?\d?\d\d) ?-luvun (loppupuoli|loppu|lopulta|loppupuolelta)/",$aika,$matches)>0) {
	$vuosi = $matches[1];
	
	// Vuosisata
	if($vuosi % 100 == 0) { 
		$alku = $vuosi + 70;
		$loppu = $vuosi + 99;
	}
	// Vuosikymmen
	else if($vuosi % 10 == 0) { 
		$alku = $vuosi + 7;
		$loppu = $vuosi + 9;
	}
}
else if(preg_match("/(\d?\d?\d\d) (tammikuu|helmikuu|maaliskuu|huhtikuu|toukokuu|kesäkuu|heinäkuu|elokuu|syyskuu|lokakuu|marraskuu|joulukuu)/",$aika,$matches)>0) {
	$vuosi = $matches[1];
	$kuukausi = $k[$matches[2]];
	$alku = $vuosi . $kuukausi . "01";
	$loppu = $vuosi . $kuukausi . "31";
	$noprocess = true;
}
else if(preg_match("/(\d?\d?\d\d) ?-luvun (alkupuolelta|alkupuoli|alku|alusta)/",$aika,$matches)>0) {
	$vuosi = $matches[1];
	
	// Vuosisata
	if($vuosi % 100 == 0) { 
		$alku = $vuosi;
		$loppu = $vuosi + 29;
	}
	// Vuosikymmen
	else if($vuosi % 10 == 0) { 
		$alku = $vuosi;
		$loppu = $vuosi + 3;
	}
}
else if(preg_match("/(\d?\d?\d\d) ?-(luvun|luku) (alkupuolelta|alkupuoli|alku|alusta)/",$aika,$matches)>0) {
	$vuosi = $matches[1];
	
	// Vuosisata
	if($vuosi % 100 == 0) { 
		$alku = $vuosi;
		$loppu = $vuosi + 29;
	}
	// Vuosikymmen
	else if($vuosi % 10 == 0) { 
		$alku = $vuosi;
		$loppu = $vuosi + 3;
	}
}
else if(preg_match("/(\d?\d?\d\d) ?-(luku|luvulta)/",$aika,$matches)>0) {
	$vuosi = $matches[1];
	$alku = $vuosi;
	
	if($vuosi % 100 == 0) 
		$loppu = $vuosi + 99;
	else if($vuosi % 10 == 0)
		$loppu = $vuosi + 9;
	else $loppu = $vuosi;
}
else if(preg_match("/(\d?\d?\d\d) ?jälkeen/",$aika,$matches)>0) {
	$vuosi = $matches[1];
	
	$alku = $vuosi;
	$loppu = $vuosi + 9;
}
else if(preg_match("/(\d?\d?\d\d) ?\?/",$aika,$matches)>0) {
	$vuosi = $matches[1];
	
	$alku = $vuosi-3;
	$loppu = $vuosi+3;
}
else if(preg_match("/(\d?\d?\d\d)/",$aika,$matches)>0) {
	$vuosi = $matches[1];
	
	$alku = $vuosi;
	$loppu = $vuosi;
}
else return;


	if(strlen($alku)==2) 
		$alku = 1900+$alku;
	if(strlen($loppu)==2) 
		$loppu = 1900+$loppu;
	
	
	if(!isset($noprocess) || $noprocess != true) {
		$alku = $alku . "0101";
		$loppu = $loppu . "1231";
		} 	
		$result = $alku . ",". $loppu;
		
	if(preg_match("/\d\d\d\d\d\d\d\d-\d\d\d\d\d\d\d\d/",$result) > 0)
		return $result;
	return;
}

public static function untilSlash($in) {
	$index = strrpos($in, '/');
	if($index === false) return $in;
	return substr($in,0,$index);
}

public static function geoCode($in) {

	$url = "http://maps.googleapis.com/maps/api/geocode/json?region=fi&language=fi&sensor=false&address=".urlencode($in);

	$results = self::curl_download($url);
	$parsed = json_decode($results);
	
	
	$hierarchy = array();
	foreach($parsed->results as $result)
	{
		foreach($result->address_components as $component) {
			if(isset($component->types)) {
				if($component->types[0] == 'locality') {
					$kunta = $component->long_name;
				}
				if($component->types[0] == 'administrative_area_level_2') {
					$maakunta = $component->long_name;
				}
				if($component->types[0] == 'country') {
					$maa = $component->short_name;
				}
			}
		}
		if(isset($result->geometry))
		{
			$lat = $result->geometry->location->lat;
			$lng = $result->geometry->location->lng;
			
			$response = $lat . "," . $lng;
			$response .= ";";
			if(isset($maa))
				$response .= $maa;
			$response .= ";";
			if(isset($maakunta))
				$response .= $maakunta;
			$response .= ";";
			if(isset($kunta))
				$response .= $kunta;
				
			return $response;
		}
	}
	
	return;
}

public static function getKoordinaatit($response) {
	$tokens = explode(";", $response);
	if(isset($tokens[0]))
	return $tokens[0];
}

public static function getKunta($response) {
	$tokens = explode(";", $response);
	if(isset($tokens[3]))
	return $tokens[3];
}

public static function getMaakunta($response) {
	$tokens = explode(";", $response);
	if(isset($tokens[2]))
	return $tokens[2];
}

public static function getMaa($response) {
	$tokens = explode(";", $response);
	if(isset($tokens[1]))
		return $tokens[1];
}

	public static function curl_download($Url){
 
    // is cURL installed yet?
    if (!function_exists('curl_init')){
        die('Sorry cURL is not installed!');
    }
 
    // OK cool - then let's create a new cURL resource handle
    $ch = curl_init();
 
    // Now set some options (most are optional)
 
    // Set URL to download
    curl_setopt($ch, CURLOPT_URL, $Url);
 
    // Include header in result? (0 = yes, 1 = no)
    curl_setopt($ch, CURLOPT_HEADER, 0);
 
    // Should cURL return or print out the data? (true = return, false = print)
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
 
    // Timeout in seconds
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
 
    // Download the given URL, and return output
    $output = curl_exec($ch);
 
    // Close the cURL resource, and free system resources
    curl_close($ch);
 
    return $output;
}

   public static function normalizeId($in)
    {
        $text = str_replace(":", "_", $in);

        return $text;
    }	

	public static function mapEventType($in)
    {
		$eventType = trim($in);
        switch ($eventType) {
			case "löytyminen":
				return "finding";
			case "valmistus":
			case "valmistaminen":
				return "creation";
			case "käyttö":
			case "use":
				return "use";	
			case "näyttely":
			case "näyttelyssä":
			case "exhibition":
				return "exhibition";
		}
		return;
    }	

	public static function normalizeSanasto($in)
    {
        $sanasto = trim($in);
		
		switch ($sanasto) {
			case "Museoalan asiasanasto":
				return "mao:";
			case "Yleinen suomalainen asiasanasto":
				return "ysa:";
			case "Vapaa avainsana":
				return "";			
		}
		
		return "";
    }
	
		public static function getHierarchy($key, $sanasto, $asiasana) 
	{
		$sanasto = trim($sanasto);
		
		switch ($sanasto) {
			case "Museoalan asiasanasto":
				$sanastoId = "mao";
				break;
			case "Yleinen suomalainen asiasanasto":
				$sanastoId = "ysa";
				break;
		}
		if(!isset($sanastoId))
			return $asiasana;
				
		$sanastoIdUpper = strtoupper($sanastoId);
		
		$url = "http://onki.fi/key-".$key."/api/v2/http/onto/$sanastoId/getConceptHierarchy?u=http://yso.fi/$sanastoIdUpper%23$asiasana&s=0&c=0";
		
		die($url);
		$results = self::curl_download($url);
		
		$parsed = json_decode($results);
		
		$hierarchy = array();
		foreach($parsed as $result)
		{
			if(isset($result->label))
			{
				$label = $result->label;
				$label = str_replace("_"," ",$label);
				if(!empty($label))
					$hierarchy[] = $label;
			}
		}
		
		return implode(":",$hierarchy);
	}
	

}
?>
