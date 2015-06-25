#!/usr/bin/php
<?php

define('SOAP_USER',      'soap_user');
define('SOAP_USERTYPE',  'type');
define('SOAP_INTERFACE', 'Production');
define('SOAP_CLIENT',    'sdr');
define('DEFAULT_TYPE',   'STORG');

require('common.php');

function item($count, array $org)
{
    printf("%04d  %04d  %s\n", $count, $org['id'], fixName($org));
}

function subitem($str)
{
    if(!is_string($str)) {
        var_dump($str);
    } else {
        echo "\t$str\n";
    }
}

function makeBannerId(array $org)
{
    return sprintf("SDR%04d", $org['id']);
}

function getActivitiesStatement(PDO $pdo)
{
    $stmt = $pdo->prepare("
        SELECT
            *
        FROM
            sdr_organization_recent AS o
        WHERE o.term >= 199510 ORDER BY RANDOM();
    ");
    //        WHERE o.term IN (201340, 201410) AND o.type_id >= 100 ORDER BY RANDOM();
  


    wrapPDO($stmt, 'Could not select activities.');

    return $stmt;
}

function checkActivitySanity($org)
{
    extract($org);

    if(!in_array($type_id, array(100, 101, 102, 103, 104, 105, 106)))
        return "$id has sgaelection $type_id which makes no sense";

    return TRUE;
}

function resolveCategory($org)
{
    extract($org);
    $type = 'type';

    switch($type_id) {
    case 11:
    case 100:
      $type = 'FRAT';
      break;
    case 12:
    case 101:
      $type = 'SORO';
      break;
    case 1:				     
    case 2:
    case 4:
    case 5:
    case 7:
    case 13:
    case 25:
    case 102:
      $type = 'HONAC';
      break;
    case 103: 
      $type = 'MULTI';
      break;
    case 6:
    case 10:
    case 14:
    case 104: 
      $type = 'SPORT';
      break;
    case 16:
    case 20:
    case 105: 
      $type = 'SERV';
      break;
    default: 
      $type = 'SPINT';
      break;
    }
    return $type;
}

function getActivityRecords(SoapClient $soap, array $org)
{
    $vals = array(
        'User'         => SOAP_USER,
        'UserType'     => SOAP_USERTYPE,
        'ActivityCode' => $org['banner_id']
    );

    $result = $soap->GetActivityByCode($vals);

    $n = $result->GetActivityByCodeResult->error_num;

    if($n == 1103) return false;
    if($n != 0) {
        $c = $result->GetActivityByCodeResult->error_desc;
        throw new Exception(reportSoap('GetActivityByCode', $n, $vals, $c));
    }

    subitem(reportSoap('GetActivityByCode', $n, $vals));
        
    return $result->GetActivityByCodeResult;
}

function equalTrim($a, $b)
{
    return trim($a) == trim($b);
}

function setActivityDescription(SoapClient $soap, array $org)
{
    $vals = array(
        'User'         => SOAP_USER,
        'ActivityCode' => $org['banner_id'],
        'Description'  => $org['name']
    );

    $result = $soap->SetActivityDescription($vals);

    $n = $result->SetActivityDescriptionResult;
    $report = reportSoap('SetActivityDescription', $n, $vals);
    if($n != 0) {
        throw new Exception($report);
    }

    subitem($report);

    return TRUE;
}

function setActivityType(SoapClient $soap, array $org)
{
    $vals = array(
        'User'         => SOAP_USER,
        'ActivityCode' => $org['banner_id'],
        'TypeCode'     => DEFAULT_TYPE
    );

    $result = $soap->SetActivityType($vals);

    $n = $result->SetActivityTypeResult;
    $report = reportSoap('SetActivityType', $n, $vals);
    if($n != 0) {
        throw new Exception($report);
    }

    subitem($report);

    return TRUE;
}

function setActivityCategory(SoapClient $soap, array $org)
{
    $vals = array(
        'User'         => SOAP_USER,
        'ActivityCode' => $org['banner_id'],
        'CategoryCode' => resolveCategory($org)
    );

    $result = $soap->SetActivityCategory($vals);

    $n = $result->SetActivityCategoryResult;
    $report = reportSoap('SetActivityCategory', $n, $vals);
    if($n != 0) {
        throw new Exception($report);
    }

    subitem($report);

    return TRUE;
}

function fixActivityRecord(SoapClient $soap, array $org)
{
    $banner = getActivityRecords($soap, $org);

    if(!equalTrim($banner->activity_type, DEFAULT_TYPE)) {
        setActivityType($soap, $org);
    }

    if(!equalTrim($banner->activity_category, resolveCategory($org))) {
        setActivityCategory($soap, $org);
    }
}

function fixName(array $org)
{
    $name = $org['name'];

    $name = preg_replace('/^appalachian State University/i', '', $name);
    $name = preg_replace('/^appalachian/i', '', $name);
    $name = preg_replace('/^asu/i', '', $name);
    $name = preg_replace('/^appstate/i', '', $name);

    // Take care of extraneous spaces if any
    $name = preg_replace('/  +/', ' ', $name);
    $name = trim($name);

    // Banner is limited to 30 characters
    $name = substr($name, 0, 30);

    // One more time just for good measure
    $name = trim($name);

    return $name;
}

function handleNewClub(PDO $pdo, SoapClient $soap, array &$org)
{
    if(empty($org['banner_id'])) {
      $org['banner_id'] = makeBannerId($org);
    }
    
    $vals = array(
        'User'         => SOAP_USER,
        'UserType'     => SOAP_USERTYPE,
        'ActivityCode' => $org['banner_id']
    );

    $result = $soap->GetActivityByCode($vals);

    $n = $result->GetActivityByCodeResult->error_num;

    if($n == 1103){
    
      $stmt = $pdo->prepare('UPDATE sdr_organization SET banner_id=? WHERE id=?');
      wrapPDO($stmt, "Could not update {$org['id']} to {$org['banner_id']}", array($org['banner_id'], $org['id']));
      
      $vals = array(
		    'User'         => SOAP_USER,
		    'ActivityCode' => $org['banner_id'],
		    'Description'  => fixName($org),
		    'ActivityType' => DEFAULT_TYPE,
		    'Category'     => resolveCategory($org)
		    );
      
      $result = $soap->CreateActivityRecord($vals);
      
      $n = $result->CreateActivityRecordResult;
      $report = reportSoap('CreateActivityRecord', $n, $vals);
      if($n != 0) {
        throw new Exception($report);
      }
      
      subitem($result);
      
    }else if($n != 0) {
        $c = $result->GetActivityByCodeResult->error_desc;
        throw new Exception(reportSoap('GetActivityByCode', $n, $vals, $c));
    }
    
    return $org;
    
}

function fixActivityRecords(PDO $pdo, SoapClient $soap)
{
    $activities = getActivitiesStatement($pdo);

    $count = 0;
    while($org = $activities->fetch(PDO::FETCH_ASSOC)) {
        item(++$count, $org);
	/** limits organizations to new sga election types
        if(($result = checkActivitySanity($org)) !== TRUE) {
            subitem("FAILED - $result");
            continue;
        }
	*/
        try {
            handleNewClub($pdo, $soap, $org);
            fixActivityRecord($soap, $org);
        } catch(Exception $e) {
            subitem("FAILED - {$e->getMessage()}");
            continue;
        }

        subitem("SUCCESS");
    }
}

$pdo  = include('initdb.php');
$soap = include('initsoap.php');

fixActivityRecords($pdo, $soap);


?>
