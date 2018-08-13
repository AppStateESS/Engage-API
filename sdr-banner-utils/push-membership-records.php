#!/usr/bin/php
<?php

define('SOAP_USER',      'soap_user');
define('SOAP_USERTYPE',  'type');
define('SOAP_INTERFACE', 'Production');
define('SOAP_CLIENT',    'sdr');
define('DEFAULT_TYPE',   'STORG');

require('common.php');

function item($count, array $ms)
{
    printf("%04d  % 9d %7s %6d %3d\n", $count, $ms['member_id'], $ms['banner_id'], $ms['term'], $ms['role_id']);
}

function subitem($str)
{
    if(!is_string($str)) {
        var_dump($str);
    } else {
        echo "\t$str\n";
    }
}

function leadership($ms)
{
    switch($ms['rank']) {
        case -1: return 'PLEDG';
        case  0: return 'MEMBR';
        case  1: return 'SECTY';
        case  2: return 'VPRES';
        case  3: return 'PRES';
    }

    throw new Exception('Unknown Rank ' . $ms['rank']);
}

function getMemberships(PDO $pdo)
{
    $stmt = $pdo->prepare("
        SELECT
            ms.member_id,
            o.banner_id,
            o.type_id,
            ms.term,
            mr.role_id,
            rr.rank
        FROM
            sdr_membership AS ms
        JOIN
            sdr_organization_full AS o
        ON 
            ms.organization_id = o.id AND ms.term = o.term
        LEFT OUTER JOIN
            sdr_membership_role AS mr
        ON
            ms.id = mr.membership_id
        LEFT OUTER JOIN
            sdr_role AS rr
        ON
            mr.role_id = rr.id
        WHERE
            ms.term >= 199510 AND 
            (mr.role_id is null OR rr.rank < 10)
        ORDER BY
            o.id
            ");

    /** Limit query
       WHERE
       ms.term IN (201340, 201410) AND
       o.type_id >= 100 AND 
       (mr.role_id is null OR rr.rank < 10)
    */
    wrapPDO($stmt, 'Could not select memberships.');

    return $stmt;
}

function reportStudentActivity(SoapClient $soap, $ms)
{
    global $config;

    $vals = array(
        'User'         => SOAP_USER,
        'UserType'     => SOAP_USERTYPE,
        'BannerID'     => $ms['member_id'],
        'TermCode'     => $ms['term'],
        'ActivityCode' => $ms['banner_id']
    );

    if($config['dryrun']) {
        subitem(reportSoap('CreateStudentActivityRecord', 'DRYRUN', $vals));
        return;
    }

    $result = $soap->CreateStudentActivityRecord($vals);

    $n = $result->CreateStudentActivityRecordResult;
    subitem(reportSoap('CreateStudentActivityRecord', $n, $vals));

    return $result;
}

function reportAdvancementActivity(SoapClient $soap, $ms)
{
    global $config;

    $vals = array(
        'User'           => SOAP_USER,
        'UserType'       => SOAP_USERTYPE,
        'BannerID'       => $ms['member_id'],
        'TermCode'       => $ms['term'],
        'ActivityCode'   => $ms['banner_id'],
        'LeadershipCode' => leadership($ms)
    );

    if($config['dryrun']) {
        subitem(reportSoap('CreateAdvancementActivityRecord', 'DRYRUN', $vals));
        return;
    }

    $result = $soap->CreateAdvancementActivityRecord($vals);

    $n = $result->CreateAdvancementActivityRecordResult;
    subitem(reportSoap('CreateAdvancementActivityRecord', $n, $vals));

    return $result;
}

function pushMembershipRecords(PDO $pdo, SoapClient $soap)
{
    $memberships = getMemberships($pdo);

    $count = 0;
    while($ms = $memberships->fetch(PDO::FETCH_ASSOC)) {
        item(++$count, $ms);

        try {
            reportStudentActivity($soap, $ms);
            reportAdvancementActivity($soap, $ms);
        } catch(Exception $e) {
            subitem("FAILED - {$e->getMessage()}");
            continue;
        }

        subitem("SUCCESS");
    }
}

$pdo  = require_once('initdb.php');
$soap = require_once('initsoap.php');

$config = array();
$config['dryrun'] = false;

foreach($argv as $arg) {
    if($arg == '--dryrun') $config['dryrun'] = true;
}

pushMembershipRecords($pdo, $soap);

?>
