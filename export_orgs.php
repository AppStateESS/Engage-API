#!/usr/bin/php
<?php

define("CSIL_ID", 12345); // this is used to limit to just coil umbrella
define("BANNER_ELEMENT_ID", 1300096);

include ("/etc/ess/sdr_sync.conf");
include ("api_functions.php");

$key = ORGSYNC_KEY;
$base_url = BASE_URL;
$exclude_orgs = array(95550,878950); 
$orgs = getAllOrganizations();
$org_header = "Org Short Name, Org Long Name\r";
$member_header = "Appsync username, First Name, Last Name, Email, Banner ID\r";

foreach($orgs as $org){
$data = $org_header;
$org_id = $org->id;
if(in_array($org_id, $exclude_orgs))
    continue;
$org_short_name = $org->short_name;
$org_long_name = $org->long_name;
$org_description = $org->description;
$data .= "$org_short_name,$org_long_name"."\r";
$data .= "\r--- MEMBERS ---\r";
$data .= $member_header;
$members = getOrgMembers($org_id);
foreach($members as $member){
$username = $member->username;
$first_name = $member->first_name;
$last_name = $member->last_name;
$email = $member->email;
$account = getAccountByID($member->id);

if($account){
$profile_responses = $account->profile_responses;
$banner_id = null;

foreach($profile_responses as $value){
   if($value->element->id == BANNER_ELEMENT_ID)
       $banner_id = $value->data;
}
}
$line = "$username,$first_name,$last_name,$email,$banner_id"."\r";
$data .= $line;
}
$file_name = str_replace(" ", "_", trim($org_short_name)).".csv";
$file = fopen("orgs/$file_name", 'x+');
fwrite($file, $data);
fclose($file);
}
