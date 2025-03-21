<?php

require_once 'idObfuscation.php';

define('TELEMETRY_SETTINGS_FILE', 'telemetry_settings.php');
$tz = getenv('TZ');
//Set timezone to UTC if Environment variables are not set
if ($tz !== false) {
    // If the TZ environment variable exists, set the default timezone for PHP to its value
    date_default_timezone_set($tz);
} else {
    // If the TZ environment variable does not exist, set a default timezone or handle the error
    // Set it to the UTC timezone
    date_default_timezone_set('UTC');
}
/**
 * @return PDO|false
 */
function getPdo($returnErrorMessage = false)
{
    if (
        !file_exists(TELEMETRY_SETTINGS_FILE)
        || !is_readable(TELEMETRY_SETTINGS_FILE)
    ) {
		if($returnErrorMessage){
			return 'missing TELEMETRY_SETTINGS_FILE';
		}
        return false;
    }

    require TELEMETRY_SETTINGS_FILE;

    if (!isset($db_type)) {
		if($returnErrorMessage){
			return "db_type not set in '" . TELEMETRY_SETTINGS_FILE . "'";
		}
        return false;
    }

    $pdoOptions = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ];

    try {
        if ('mssql' === $db_type) {
            if (!isset(
                $MsSql_server,
                $MsSql_databasename,
				$MsSql_WindowsAuthentication
            )) {
				if($returnErrorMessage){
					return "Required MSSQL database settings missing in '" . TELEMETRY_SETTINGS_FILE . "'";
				}
                return false;
            }
			
			if (!$MsSql_WindowsAuthentication and
			    !isset(
						$MsSql_username,
						$MsSql_password
						)
				) {
				if($returnErrorMessage){
					return "Required MSSQL database settings missing in '" . TELEMETRY_SETTINGS_FILE . "'";
				}
                return false;
            }
            $dsn = 'sqlsrv:'
                .'server='.$MsSql_server
                .';Database='.$MsSql_databasename;
			
			if($MsSql_TrustServerCertificate === true){
				$dsn = $dsn . ';TrustServerCertificate=1';
			}
			if($MsSql_TrustServerCertificate === false){
				$dsn = $dsn . ';TrustServerCertificate=0';
			}
			
			if($MsSql_WindowsAuthentication){
				return new PDO($dsn, "", "", $pdoOptions);
			} else {
				return new PDO($dsn, $MsSql_username, $MsSql_password, $pdoOptions);
			}
        }

        if ('mysql' === $db_type) {
            if (!isset(
                $MySql_hostname,
                $MySql_port,
                $MySql_databasename,
                $MySql_username,
                $MySql_password
            )) {
                if($returnErrorMessage){
					return "Required mysql database settings missing in '" . TELEMETRY_SETTINGS_FILE . "'";
				}
				return false;
            }

            $dsn = 'mysql:'
                .'host='.$MySql_hostname
                .';port='.$MySql_port
                .';dbname='.$MySql_databasename;

            return new PDO($dsn, $MySql_username, $MySql_password, $pdoOptions);
        }

        if ('sqlite' === $db_type) {
            if (!isset($Sqlite_db_file)) {
				if($returnErrorMessage){
					return "Required sqlite database settings missing in '" . TELEMETRY_SETTINGS_FILE . "'";
				}
                return false;
            }

            $pdo = new PDO('sqlite:'.$Sqlite_db_file, null, null, $pdoOptions);

            # TODO: Why create table only in sqlite mode?
            $pdo->exec('
                CREATE TABLE IF NOT EXISTS `speedtest_users` (
                `id`        INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                `ispinfo`   text,
                `extra`     text,
                `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `ip`        text NOT NULL,
                `ua`        text NOT NULL,
                `lang`      text NOT NULL,
                `dl`        text,
                `ul`        text,
                `ping`      text,
                `jitter`    text,
                `log`       longtext
                );
            ');

            return $pdo;
        }

        if ('postgresql' === $db_type) {
            if (!isset(
                $PostgreSql_hostname,
                $PostgreSql_databasename,
                $PostgreSql_username,
                $PostgreSql_password
            )) {
                if($returnErrorMessage){
					return "Required postgresql database settings missing in '" . TELEMETRY_SETTINGS_FILE . "'";
				}
				return false;
            }

            $dsn = 'pgsql:'
                .'host='.$PostgreSql_hostname
                .';dbname='.$PostgreSql_databasename;

            return new PDO($dsn, $PostgreSql_username, $PostgreSql_password, $pdoOptions);
        }
    } catch (Exception $e) {
		if($returnErrorMessage){
			return $e->getMessage();
		}
        return false;
    }

	if($returnErrorMessage){
		return "db_type '" . $db_type . "' not supported";
	}
    return false;
}

/**
 * @return bool
 */
function isObfuscationEnabled()
{
    require TELEMETRY_SETTINGS_FILE;

    return
        isset($enable_id_obfuscation)
        && true === $enable_id_obfuscation;
}

/**
 * @return string|false returns the id of the inserted column or false on error if returnErrorMessage is false or a error message if returnErrorMessage is true
 */
function insertSpeedtestUser($ip, $ispinfo, $extra, $ua, $lang, $dl, $ul, $ping, $jitter, $log, $returnExceptionOnError = false)
{
    $pdo = getPdo();
    if (!($pdo instanceof PDO)) {
		if($returnExceptionOnError){
			return new Exception("Failed to get database connection object");
		}
        return false;
    }

    try {
        $currentTimestamp = new DateTime('now'); // This will automatically use the timezone configured in PHP
        $formattedTimestamp = $currentTimestamp->format('Y-m-d H:i:s');
    
        $stmt = $pdo->prepare(
            'INSERT INTO speedtest_users
            (ip,ispinfo,extra,ua,lang,dl,ul,ping,jitter,log,timestamp)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)' // Added the timestamp field here
        );
        $stmt->execute([
            $ip, $ispinfo, $extra, $ua, $lang, $dl, $ul, $ping, $jitter, $log, $formattedTimestamp // Added $formattedTimestamp in the execute parameters
        ]);
        $id = $pdo->lastInsertId();   
    } catch (Exception $e) {
		if($returnExceptionOnError){
			return $e;
		}
        return false;
    }

    if (isObfuscationEnabled()) {
        return obfuscateId($id);
    }

    return $id;
}

/**
 * @param int|string $id
 *
 * @return array|null|false|exception returns the speedtest data as array, null
 *                          if no data is found for the given id or
 *                          false or an exception if there was an error (based on returnExceptionOnError)
 *
 * @throws RuntimeException
 */
function getSpeedtestUserById($id,$returnExceptionOnError = false)
{
    $pdo = getPdo();
    if (!($pdo instanceof PDO)) {
		if($returnExceptionOnError){
			return new Exception("Failed to get database connection object");
		}
        return false;
    }

    if (isObfuscationEnabled()) {
        $id = deobfuscateId($id);
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT
            id, timestamp, ip, ispinfo, ua, lang, dl, ul, ping, jitter, log, extra
            FROM speedtest_users
            WHERE id = :id'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
		if($returnExceptionOnError){
			return $e;
		}
        return false;
    }

    if (!is_array($row)) {
        return null;
    }

    $row['id_formatted'] = $row['id'];
    if (isObfuscationEnabled()) {
        $row['id_formatted'] = obfuscateId($row['id']).' (deobfuscated: '.$row['id'].')';
    }

    return $row;
}

/**
 * @return array|false
 */
function getLatestSpeedtestUsers()
{
    $pdo = getPdo();
    if (!($pdo instanceof PDO)) {
        return false;
    }

    require TELEMETRY_SETTINGS_FILE;
	
    try {
		$sql = 'SELECT ';
		
		if('mssql' === $db_type) {$sql .= ' TOP(100) ';}
		
		$sql .= ' id, timestamp, ip, ispinfo, ua, lang, dl, ul, ping, jitter, log, extra
            FROM speedtest_users
            ORDER BY timestamp DESC ';
			
		if('mssql' !== $db_type) {$sql .= ' LIMIT 100 ';}
		
        $stmt = $pdo->query($sql);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $i => $row) {
            $rows[$i]['id_formatted'] = $row['id'];
            if (isObfuscationEnabled()) {
                $rows[$i]['id_formatted'] = obfuscateId($row['id']).' (deobfuscated: '.$row['id'].')';
            }
        }
    } catch (Exception $e) {
        return false;
    }

    return $rows;
}
