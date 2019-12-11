<?php

  /**
   * This is a temporary script to import orgsync postions to sdr roles. As of now orgsync has 
   * no api for the positions module. So we have do use the orgsync databrowser to get the 
   * positions data and export it to csv. This script will read the csv into an array for 
   * processing. As soon as they build an api for positions this script will not be needed.
   * So yes this script will be very hacky because I just need to get it done asap.
   *
   */

include ("sdr_sync.conf");

$db_user = DB_USER;
$db_pass = DB_PASS;
$db = "sdr";
$csv_file = "positions-2019-04-14.csv";
$handle = fopen($csv_file, "r");
$term = "201910";
$dbconn = pg_connect("user=$db_user password=$db_pass dbname=$db") or die('connection failed');

 
// first lets clear out the current positions for this term because we have bad data
/**
$query = "SELECT * FROM sdr_membership_role WHERE term=$term";
$result = pg_query($query);
$count = 0;
while($row = pg_fetch_assoc($result)){
  $membership_id = $row['id'];
  $query = "DELETE FROM sdr_membership_role where membership_id=$membership_id";
  $delete_result = pg_query($query);
  $count++;
}
*/

// Read the file line by line and set the appropriate role
$count = 0;
while(($line = fgets($handle)) !== false){
  $lines = explode(",", $line);
  $position = strtolower(trim($lines[0]));
  $email = $lines[1];
  $pieces = explode("@",$email);
  $username = $pieces[0];
  $banner_id = $lines[2];
  $org_id = $lines[3];

  $query = "SELECT * FROM sdr_orgsync_id_map WHERE orgsync_id=$org_id";
  $org_result = pg_query($query);
  if($org_result && pg_num_rows($org_result) > 0){ // The organization exists in club connect so update it
    $row = pg_fetch_assoc($org_result);
    $sdr_org_id = $row['sdr_id'];
  }else{ // the organization does not exist in club connect so we don't do anything. This really shouldn't happen.
    echo "The organization does not exist in sdr. This shouldn't have happened"."\n";
    $sdr_org_id = NULL;
  }
  if(!empty($banner_id) && !empty($sdr_org_id)){

  $query = "SELECT * FROM sdr_membership WHERE member_id=$banner_id AND organization_id=$sdr_org_id AND term='$term'";

  $membership_result = pg_query($query);
  if($membership_result && pg_num_rows($membership_result) > 0){
    $row = pg_fetch_assoc($membership_result);
    $membership_id = $row['id'];

    switch($position){
    case "president":
      $sdr_role = 34;
      break;
    case "vice president":
      $sdr_role = 47;
      break;
    case "advisor":
      $sdr_role = 53;
      break;
    case "secretary":
      $sdr_role = 41;
      break;
    case "treasurer":
      $sdr_role = 45;
      break;
    case "marketing/publicity chair":
      $sdr_role = 51;
      break;
    case "social chair":
    case "service & philanthropy chair":
    case "recruitment chair":
      $sdr_role = 6;
    break;
    default:
      $sdr_role = 0;
      break;
    }

    if($sdr_role){
      $query = "SELECT * FROM sdr_membership_role WHERE membership_id=$membership_id AND role_id=$sdr_role";
      $role_result = pg_query($query);
      if(pg_num_rows($role_result) == 0){
	$query = "INSERT INTO sdr_membership_role (membership_id, role_id) VALUES($membership_id, $sdr_role)";
	if(!pg_query($query))
	  $log_str .= "Insert Membership Role Error: Failed to insert role. query: $query"."\r\n";
	else
	  $count++;
	$query = "UPDATE sdr_membership SET administrator=1 WHERE member_id=$banner_id AND organization_id=$sdr_org_id AND term=$term";
	  if(!pg_query($query))
	    $log_str = "Update Memberhsip Role Error: Failed to update $value to adminstrator. query: $query"."\r\n";	
      }
    }
  }
  }
}
echo "$count roles inserted";
pg_close($dbconn);
fclose($handle);