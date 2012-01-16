<?php
class LidoGeocode
{
	//self::$Cn = $Cf / (2.0 - $Cf);
	static $Ca,$Cn,$Cf,$Cb,$Ck0,$CE0,$Clo0,$CA1,$Ce,$Ch1,$Ch2,$Ch3,$Ch4,$Ch1p,$Ch2p,$Ch3p,$Ch4p;
	static $init = false;
	public static function init() {
		self::$Ca = 6378137.0;
		self::$Cb = 6356752.314245;
		self::$Cf = 1.0 / 298.257223563;
		self::$Ck0 = 0.9996;
		self::$CE0 = 500000.0;
		self::$Cn = self::$Cf / (2.0 - self::$Cf);
		self::$Clo0 = deg2rad(27.0);
		self::$CA1 = self::$Ca / (1.0 + self::$Cn) * (1.0 + (pow(self::$Cn, 2.0)) / 4.0 + pow(self::$Cn,4.0) / 64.0);
		self::$Ce = sqrt(2.0 * self::$Cf - pow(self::$Cf, 2.0));
		self::$Ch1 = 1.0/2.0 * self::$Cn - 2.0/3.0 * pow(self::$Cn,2.0) + 37.0/96.0 * pow(self::$Cn,3.0) - 1.0/360.0 * pow(self::$Cn,4.0);
		self::$Ch2 = 1.0/48.0 * pow(self::$Cn,2.0) + 1.0/15.0 * pow(self::$Cn,3.0) - 437.0/1440.0 * pow(self::$Cn,4.0);
		self::$Ch3 = 17.0/480.0 * pow(self::$Cn,3.0) - 37.0/840.0 * pow(self::$Cn,4.0);
		self::$Ch4 = 4397.0/161280.0 * pow(self::$Cn,4.0);
		self::$Ch1p = 1.0/2.0 * self::$Cn - 2.0/3.0 * pow(self::$Cn,2.0) + 5.0/16.0 * pow(self::$Cn,3.0) + 41.0/180.0 * pow(self::$Cn,4.0);
		self::$Ch2p = 13.0/48.0 * pow(self::$Cn,2.0) - 3.0/5.0 * pow(self::$Cn,3.0) + 557.0/1440.0 * pow(self::$Cn,4.0);
		self::$Ch3p = 61.0/240.0 * pow(self::$Cn,3.0) - 103.0/140.0 * pow(self::$Cn,4.0);
		self::$Ch4p = 49561.0/161280.0 * pow(self::$Cn,4.0);
		
		/*
		print("Ca: " . self::$Ca . "\n");
		print("Cb: " . self::$Cb . "\n");
		print("Ck0: " . self::$Ck0 . "\n");
		print("CE0: " . self::$CE0 . "\n");
		print("Cn: " . self::$Cn . "\n");
		print("Clo0: " . self::$Clo0 . "\n");
		print("CA1: " . self::$CA1 . "\n");
		print("Ce: " . self::$Ce . "\n");
		print("Ch1: " . self::$Ch1 . "\n");
		print("Ch2: " . self::$Ch2 . "\n");
		print("Ch3: " . self::$Ch3 . "\n");
		print("Ch4: " . self::$Ch4 . "\n");
		print("Ch1p: " . self::$Ch1p . "\n");
		print("Ch2p: " . self::$Ch2p . "\n");
		print("Ch3p: " . self::$Ch3p . "\n");
		print("Ch4p: " . self::$Ch4p . "\n");
		*/
	}
	
	public static function combineCoords($a, $b) {
		return $a.",".$b;
	}

	public static function ETRSTM35FINxy_to_WGS84lalo($etrs_x, $etrs_y) {
		//print "\nInput: $etrs_x ; $etrs_y\n";
		if(self::$init == false)
			self::init();
			
		$E = $etrs_x / (self::$CA1 * self::$Ck0);
		$nn = ($etrs_y - self::$CE0) / (self::$CA1 * self::$Ck0);
	  
		$E1p = self::$Ch1 * sin(2.0 * $E) * cosh(2.0 * $nn);
		$E2p = self::$Ch2 * sin(4.0 * $E) * cosh(4.0 * $nn);
		$E3p = self::$Ch3 * sin(6.0 * $E) * cosh(6.0 * $nn);
		$E4p = self::$Ch4 * sin(8.0 * $E) * cosh(8.0 * $nn);
		$nn1p = self::$Ch1 * cos(2.0 * $E) * sinh(2.0 * $nn);
		$nn2p = self::$Ch2 * cos(4.0 * $E) * sinh(4.0 * $nn);
		$nn3p = self::$Ch3 * cos(6.0 * $E) * sinh(6.0 * $nn);
		$nn4p = self::$Ch4 * cos(8.0 * $E) * sinh(8.0 * $nn);
		$Ep = $E - $E1p - $E2p - $E3p - $E4p;
		$nnp = $nn - $nn1p - $nn2p - $nn3p - $nn4p;
		$be = asin(sin($Ep) / cosh($nnp));
	  
		$Q = asinh(tan($be));
		$Qp = $Q + self::$Ce * atanh(self::$Ce * tanh($Q));
		$Qp = $Q + self::$Ce * atanh(self::$Ce * tanh($Qp));
		$Qp = $Q + self::$Ce * atanh(self::$Ce * tanh($Qp));
		$Qp = $Q + self::$Ce * atanh(self::$Ce * tanh($Qp));
		
		$wgs_la = rad2deg(atan(sinh($Qp)));
		$wgs_lo = rad2deg(self::$Clo0 + asin(tanh($nnp) / cos($be)));

		return $wgs_la. ",". $wgs_lo;
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


public static function defaultCountry($foo, $bar) {
	return "Suomi";
}

public static function _geoCode($in) {

	$url = "http://localhost:8080/solr/geo/select?wt=json&qt=dismax&qf=name+kunta+maakunta+maa&rows=10&bq=type:Kunta^4&mm=67%25&bq=kunta:helsinki^0.1&bq=type:Kaupunginosa^2&q=".urlencode($in);
	$results = self::curl_download($url);
	
	$parsed = json_decode($results);
	
	$hierarchy = array();
	if($parsed) {
		$response = $parsed->response;
		if($response->numFound > 0) {
			foreach($response->docs as $result)
			{
				if($result->kunta)
					$kunta = $result->kunta;
				
				if($result->maakunta) {
					$maakunta = $result->maakunta;
				}
				
				$maa = "FI";
				
				$response = $result->mercator;
				$response .= ";";
				if(isset($maa))
					$response .= $maa;
				$response .= ";";
				if(isset($maakunta))
					$response .= $maakunta	;
				$response .= ";";
				if(isset($kunta))
					$response .= $kunta;
					
				return $response;
			}
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

}
?>
