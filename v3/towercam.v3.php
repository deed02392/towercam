<?php

class TowerCam {
    /*
      @ TowerCam
      @ ========
      @ Does not require cron scheduling - keeps track of its freshness based on db records
      @
      @ WHAT IT DOES
      @ ============
      @ Takes temperature from Met Office and checks when it was last updated
      @ - If temp data is over an hour old, suffixes an X to the temperature and retries every 5 minutes
      @ - If temp data less than an hour old, normal temp reading and retries again in an hour
      @
      @ Takes tower cam picture and guesses when it was last updated (based on the interval of the image being updated)
      @ - If tower pic over five minutes old, applies an asterisk to the top right corner and retries every minute (default refresh interval)
      @ - If tower pic is under five minutes old, the time until the next predicted update is determined and set as the refresh interval
     */

    // Properties
    public $refresh_in = 300; // 5 minutes
    public $dbfile = 'towercam.sqlite';
    public $temp_unit = '&deg;C';
    public $wind_unit = ' mph';
    protected $db = null;
    private $met_uri = 'http://www.metoffice.gov.uk/weather/uk/se/solent_latest_temp.html';
    private $towercam_uri = 'http://www.forms.portsmouth.gov.uk/webcam/tower.jpg';
    private $tempxpathquery = '((//div[@id=\'obsTable\']/table/tr)[last()-1])/td[3]';
    private $windxpathquery = '((//div[@id=\'obsTable\']/table/tr)[last()-1])/td[6]';
    private $whenxpathquery = '((//div[@id=\'obsTable\']/table/tr)[last()])/td[1]';
    private $met_update_interval = 3600;
    private $met_attempt_interval = 300;
    private $tower_update_interval = 300;
    private $tower_attempt_interval = 60;
    private $build_new = false;
    public $temp = 0.0;
    public $wind_speed = 0;
    public $met_last_updated = null;
    public $tower_last_updated = null;
    public $tower_bin;
    public $tower_md5;

    // End properties

    public function __construct($dbfile)
    {
        // Set properties
        $this->dbfile = !empty($dbfile) ? $dbfile : $this->dbfile;

        $queries = array('CREATE TABLE IF NOT EXISTS towercam (ID INTEGER UNIQUE, LastMetTime INTEGER, LastTowerTime INTEGER, LastMetAttempt INTEGER, LastTowerAttempt INTEGER, Temp REAL, Wind INTEGER, Tower STRING)',
            'INSERT OR IGNORE INTO towercam (ID) VALUES (1)');

        // Construct/check the database and prepare if not ready
        $this->db = new SQLite3($this->dbfile, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);

        if (is_object($this->db))
        {
            foreach ($queries as $q)
            {
                if (!$this->db->exec($q))
                {
                    throw new Exception("Query failed: {$this->db->lastErrorMsg()}");
                }
            }
        }
        else
        {
            throw new Exception("Failed to open SQLite database: {$this->dbfile}");
        }
    }

    public function update($tower_path = 'rawtower.jpg')
    {
        // check if the met needs an update attempt
        $met_updates = $this->db->querySingle('SELECT LastMetAttempt, LastMetTime, Temp, Wind FROM towercam WHERE ID=1', true);

        $this->wind_speed = $met_updates['Wind'];
        $this->temp = $met_updates['Temp'];
        $this->met_last_updated = $met_updates['LastMetTime'];

        if ($met_updates['LastMetTime'] + $this->met_update_interval < $this->db_time())
        {
            // it's probably out of date, we try to update if we didn't attempt too recently
            if ($met_updates['LastMetAttempt'] + $this->met_attempt_interval < $this->db_time())
            {
                $this->update_met();
                $this->build_new = true;
            }
        }

        // check if the tower needs an update attempt

        $tower_updates = $this->db->querySingle('SELECT LastTowerAttempt, LastTowerTime FROM towercam WHERE ID=1', true);

        $this->tower_last_updated = $tower_updates['LastTowerTime'];
        if ($tower_updates['LastTowerTime'] + $this->tower_update_interval < $this->db_time())
        {
            // it's probably out of update, we try to update if we didn't attempt too recently
            if ($tower_updates['LastTowerAttempt'] + $this->tower_attempt_interval < $this->db_time())
            {
                $this->update_tower($tower_path);
                $this->build_new = true;
            }
        }
        else
        {
            // we don't need a more up to date tower, so just load in what we've got
            $this->tower_bin = file_get_contents($tower_path);
            // and make sure the browser knows when to try again
            $this->get_last_tower_seconds_ago();
        }
    }

    public function update_met()
    {
        // scrape from the met and if we successfully scrape mark this as an attempt
        try
        {
            // scrape the latest info
            $this->_scrape_from_met();
            // unless something physically went wrong, like malformed html meant we couldn't find wind speed, mark as attempt
            if (!$this->db->exec('UPDATE towercam SET LastMetAttempt=' . $this->db_time() . ' WHERE ID=1'))
            {
                throw new Exception("Query error: {$this->db->lastErrorMsg()}");
            }
        }
        catch (Exception $e)
        {
            throw $e;
        }

        // get the last update time
        $db_met_time = $this->db->querySingle('SELECT LastMetTime FROM towercam WHERE ID=1');

        // update the database with retrieved data if it is more recent than the last update
        if ($db_met_time < $this->met_last_updated)
        {
            // The database has older data than we just retrieved. Update:
            $stmt = $this->db->prepare('UPDATE towercam SET LastMetTime=:met_last_updated, Temp=:temp, Wind=:wind_speed WHERE ID=1');
            $stmt->bindValue(':met_last_updated', $this->met_last_updated, SQLITE3_INTEGER);
            $stmt->bindValue(':temp', $this->temp);
            $stmt->bindValue(':wind_speed', $this->wind_speed, SQLITE3_INTEGER);

            if (!$stmt->execute())
            {
                throw new Exception("Query error: {$this->db->lastErrorMsg()}");
            }
        }
        // else we don't have newer information
    }

    private function _scrape_from_met()
    {
        //* Downloads the met office web page and gets temperature, wind speed and the time these values were updated
        //***********************************************************************************************************
        $html = new DOMDocument();
        if (is_object($html))
        {
            if ($html->loadHtmlFile($this->met_uri) !== false)
            {
                $xpath = new DOMXPath($html);
                if (is_object($xpath))
                {
                    $this->temp = $this->_get_temperature($xpath);
                    $this->wind_speed = $this->_get_wind_speed($xpath);
                    $this->met_last_updated = $this->_get_met_last_updated($xpath);
                }
                else
                {
                    throw new Exception("Failed to create an XPath object.");
                }
            }
            else
            {
                throw new Exception("Failed to get HTML from {$this->met_uri}");
            }
        }
        else
        {
            throw new Exception("Failed to create a DOMDocument object.");
        }
    }

    private function _get_temperature($xpath)
    {
        if (($nodelist = $xpath->query($this->tempxpathquery)) !== false)
        {
            if ($nodelist->length == 1)
            {
                foreach ($nodelist as $n)
                {
                    $temp = $n->nodeValue;
                }
            }
            else
            {
                $temp = false;
            }
        }
        else
        {
            throw new Exception("Temperature XPath query failed: {$this->tempxpathquery}");
        }

        if ($temp !== false)
        {
            return $temp;
        }
        else
        {
            throw new Exception("Failed to determine temperature from Met document.");
        }
    }

    private function _get_wind_speed($xpath)
    {
        if (($nodelist = $xpath->query($this->windxpathquery)) !== false)
        {
            if ($nodelist->length == 1)
            {
                foreach ($nodelist as $n)
                {
                    $wind = $n->nodeValue;
                }
            }
            else
            {
                $wind = false;
            }
        }
        else
        {
            throw new Exception("Wind speed XPath query failed: {$this->tempxpathquery}");
        }

        if ($wind !== false)
        {
            return $wind;
        }
        else
        {
            throw new Exception("Failed to determine wind speed from Met document.");
        }
    }

    private function _get_met_last_updated($xpath)
    {
        if (($nodelist = $xpath->query($this->whenxpathquery)) !== false)
        {
            if ($nodelist->length == 1)
            {
                foreach ($nodelist as $n)
                {
                    $lastMetUpdateString = $n->nodeValue;
                    $dateTime = datetime::createFromFormat('\L\a\s\t \u\p\d\a\t\e\d\: Hi \o\n D d M Y', $lastMetUpdateString);
                    $lastmetupdate = is_object($dateTime) ? $dateTime->getTimestamp() : false;
                }
            }
            else
            {
                $lastmetupdate = false;
            }
        }
        else
        {
            throw new Exception("Last Met update time XPath query failed: {$this->tempxpathquery}");
        }

        if ($lastmetupdate !== false)
        {
            return $lastmetupdate;
        }
        else
        {
            throw new Exception("Last Met update failed to determine time.");
        }
    }

    public function update_tower($tower_path = 'rawtower.jpg')
    {
        // Try and scrape the new tower image
        try
        {
            $this->_scrape_from_towercam();
            // unless something physically went wrong, like a server timeout, we register this as a valid attempt to update
            if (!$this->db->exec('UPDATE towercam SET LastTowerAttempt=' . $this->db_time()))
            {
                throw new Exception("Query error: {$this->db->lastErrorMsg()}");
            }
        }
        catch (Exception $e)
        {
            // unsuccessful attempt
            throw $e;
        }

        // is the newly scraped tower actually newer than the last one?
        $old_tower_md5 = $this->db->querySingle('SELECT Tower FROM towercam WHERE ID=1');

        // update the database with retrieved data if it is DIFFERENT than the last update
        if ($old_tower_md5 != $this->tower_md5)
        {
            // so we are assuming we have a NEW tower picture, which is a fair assumption because we expect only new tower images to be uploaded to this location
            // hence let us determine the age of this new picture as if it were created at the last update interval (6, 11, 16, 21, ... minutes passed the hour)
            $seconds_ago = $this->get_last_tower_seconds_ago();

            $this->tower_last_updated = floor(($this->db_time() - $seconds_ago) / 60) * 60; // round down to minute
            // The database has older data than we just retrieved. Update:
            $stmt = $this->db->prepare('UPDATE towercam SET LastTowerTime=:last_tower_updated, Tower=:tower_md5');
            $stmt->bindValue(':last_tower_updated', $this->tower_last_updated, SQLITE3_INTEGER);
            $stmt->bindValue(':tower_md5', $this->tower_md5);

            if (!$stmt->execute())
            {
                throw new Exception("Query error: {$this->db->lastErrorMsg()}");
            }

            if (!file_put_contents($tower_path, $this->tower_bin, LOCK_EX))
            {
                throw new Exception("Failed to aquire lock or write file: " . $tower_path);
            }
        }
    }

    public function get_last_tower_seconds_ago()
    {
        $last_update_minute = $current_minute = (int) date('i');
        $minutes_ago = 0;

        while ($last_update_minute % 5 != 1)
        {
            $last_update_minute -= 1;
            $minutes_ago += 1;
            if ($last_update_minute < 0)
            {
                $last_update_minute += 60;
            }
        }
        
        $seconds_ago = $minutes_ago * 60;
        $this->refresh_in = 300 - $seconds_ago;
        return $seconds_ago;
    }

    public function build_image($built_tower_path = 'tower.png')
    {
        if (!$this->build_new)
        {
            if (file_exists($built_tower_path))
            {
                $this->tower_object = imagecreatefrompng($built_tower_path);
                return;
            }
            else
            {
                mkdir(dirname($built_tower_path), null, true);
                touch($built_tower_path);
                throw new Exception("Our sources are up-to-date but I can't access the cached tower image: " . $built_tower_path);
                return; // necessary?
            }
        }

        // build the final output image and store in the class
        if (!$image_object = imagecreatefromstring($this->tower_bin))
        {
            throw new Exception("Image object could not be created.");
        }

        // set the text colour to white
        $colour = imagecolorallocate($image_object, 255, 255, 255);
        // write out the temperature
        imagestring($image_object, 5, 10, 265, $this->temp . html_entity_decode($this->temp_unit), $colour);
        // right align and write out the wind speed
        $wind_string = $this->wind_speed . $this->wind_unit;
        $wind_width = imagefontwidth(5) * strlen($wind_string);
        // x-coord = img width (352) - right margin (10) - string width (var)
        imagestring($image_object, 5, 352 - 10 - $wind_width, 265, $wind_string, $colour);
        $this->tower_object = $image_object;
    }

    public function output($context, $built_tower_path = 'tower.png')
    {
        if (!imagepng($this->tower_object, $built_tower_path))
        {
            throw new Exception("Couldn't write built image to file: " . $built_tower_path);
        }

        switch ($context)
        {
            case "html":
                // write out file and let the class handler do the html
                // set properties for class handler ?
                break;
            case "stream":
                // set output headers, write then fpassthru
                header('Content-Type: image/png');
                $fp = fopen($built_tower_path, 'rb');
                fpassthru($fp);
                break;
        }
    }

    private function _scrape_from_towercam()
    {
        //* Downloads the tower cam picture and gets its md5 value
        //******************************************************************
        $stream_context = stream_context_create(array('http' => array('timeout' => 10)));

        if (($this->tower_bin = file_get_contents($this->towercam_uri, 0, $stream_context)) !== false)
        {
            $this->tower_md5 = md5($this->tower_bin);
        }
        else
        {
            throw new Exception("Failed to scrape the Towercam image.");
        }
    }

    public function db_time()
    {
        return $this->db->querySingle('SELECT strftime(\'%s\',\'now\')'); // return current unix timestamp
    }

    public function get_met_last_updated()
    {
        return $this->met_last_updated;
    }

    public function get_tower_last_updated()
    {
        return $this->tower_last_updated;
    }

}