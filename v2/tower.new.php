<?php
// connect!
$mysqli = new mysqli('127.0.0.1', 'deed_towercam', '', 'deed_towercam');
if (mysqli_connect_errno()) {
    printf("Connect failed: %s\n", mysqli_connect_error());
    die();
}

// up to date?
var_dump(isuptodate());
foreach(isuptodate() as $what => $is) {
	if($is==='0') {
		update($what);
	}
}

// get latest info
if($result = $mysqli->query("SELECT LastTemp FROM towercam WHERE ID='1' LIMIT 1")) {
	if(is_object($result)) {
		list($temp) = $result->fetch_row();
		$result->close();
	}
}
$tower = file_get_contents('rawtower.jpg');

// build image
$image = imagecreatefromstring($tower);
if(strpos('X', $temp) === false) {
	$colour = imagecolorallocate($image, 255, 255, 255);
} else {
	$colour = imagecolorallocate($image, 248, 128, 23);
}
imagestring($image, 5, 10, 265, $temp.'°C', $colour);
imagepng($image, 'tower.png');
?>

<html>
	<head>
		<title>Towercam</title>
		<meta name="author" content="George Hafiz, george.hafiz -.at.- baesystems.com" />
		<meta http-equiv="pragma" content="no-cache" />
		<meta http-equiv="cache-control" content="no-cache" />
		<meta http-equiv="expires" content="0" /><!-- immediately -->
		<meta http-equiv="refresh" content="300" />
	</head>
	<style type="text/css">
		* {
		margin:0px;
		padding:0px;
		}
		html,body {
		width:352px;
		height:288px;
		overflow:hidden;
		}
		#author {
		color: white;
		font-size: 12px;
		text-align: right;
		}
		#tower {
		display: block;
		width: 352px;
		height: 288px;
		background-image: url('tower.png');
		}
		#loading {
		width: 352px;
		margin: 0 auto;
		text-align:center;
		}
        </style>
	<body>
	<div id="tower">
		<div id="timestamp">NO:JS</div>
	</div>
	</body>
</html>

<?php

function update($what='') {
	global $mysqli;
	if(empty($what)) { die(); }
	$supported = array_flip(array('temp', 'tower'));

	if(isset($supported[$what])) {
		switch($what) {
			case 'temp':
/* 				$xpathquery = '((//div[@id=\'obsTable\']/table/tr)[last()-1])/td[3]';
				$mettemp = 'http://www.metoffice.gov.uk/weather/uk/se/solent_latest_temp.html';

				$html = new DOMDocument();
				if($html->loadHtmlFile($mettemp)) {
					$xpath = new DOMXPath($html);
					$nodelist = $xpath->query($xpathquery);
					if($nodelist->length==1) {
						foreach($nodelist as $n) {
							$temp = $n->nodeValue;
						}
 */						
				$rdr = new XMLReader;
				$rdr->open('http://i.wxbug.net/REST/SP/getLiveWeatherRSS.aspx?api_key=ne6mkup39kt7j3dpahu9rrwv&stationid=03874&unittype=1&outputtype=1');
				while($rdr->read() && $rdr->name !== 'aws:temp');
				$temp = is_numeric($rdr->readInnerXML()) ? $rdr->readInnerXML() : false;
				if($temp) {
					if($result = $mysqli->query("INSERT INTO towercam (ID, LastTempTime, LastTempAttempt, LastTemp) VALUES ('1', NOW(), NOW(), '$temp')
												ON DUPLICATE KEY UPDATE LastTempTime=NOW(), LastTempAttempt=NOW(), LastTemp='$temp'")) {
					}
				} else {
					if($result = $mysqli->query("INSERT INTO towercam (ID, LastTempAttempt, LastTemp) VALUES ('1', NOW(), 0.0X)
												ON DUPLICATE KEY UPDATE LastTempAttempt=NOW(), LastTemp=CONCAT(LastTemp,'X')")) {
					}
				}
				break;
			case 'tower':
				$tower = 'http://www.forms.portsmouth.gov.uk/webcam/tower.jpg';
				$towerbin = file_get_contents($tower,0,stream_context_create(array('http'=>array('timeout'=>10))));
				$oldtowermd5 = md5(file_get_contents('rawtower.jpg'));
				$towermd5 = md5($towerbin);
				if($towerbin && ($towermd5 !== $oldtowermd5)) {
					file_put_contents('rawtower.jpg', $towerbin);
					if($result = $mysqli->query("INSERT INTO towercam (ID, LastTowerTime, LastTowerAttempt) VALUES ('1', NOW(), NOW())
												ON DUPLICATE KEY UPDATE LastTowerTime=NOW(), LastTowerAttempt=NOW()")) {
					}
				} else {
					if($result = $mysqli->query("INSERT INTO towercam (ID, LastTowerAttempt) VALUES ('1', NOW())
												ON DUPLICATE KEY UPDATE LastTowerAttempt=NOW()")) {
					}
				}				
				break;
		}
	}
}

function isuptodate() {
	global $mysqli;
	if($result = $mysqli->query("SELECT ((LastTempTime > NOW() - INTERVAL 60 MINUTE),
										 (LastTowerTime > NOW() - INTERVAL 5 MINUTE))
										 FROM towercam LIMIT 1")) {
		if(is_object($result)) {
			if($result->num_rows == 1) {
				list($updatetemp, $updatetower) = $result->fetch_row();
				$return = array('temp' => $updatetemp, 'tower' => $updatetower);
			} else {
				$return = array('temp' => '0', 'tower' => '0');
			}
			$result->close();
		}
		return $return;
	} else {
		printf("Query error: %s\n", $mysqli->error);
		die();
	}
}
?>
