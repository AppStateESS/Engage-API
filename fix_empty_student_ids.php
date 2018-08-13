<?php
/**
   * Script to find and fix empty student id’s in orgsync.  This is dependent on having sir updated with the most current enrolled students.
   *
   *@author - Ted Eberhard
   *
   *
   */

include ("sdr_sync.conf");

$key = ORGSYNC_KEY;
$base_url = BASE_URL; 
$db_user = DB_USER;
$db_pass = DB_PASS;
$db = "sdr";
$banner_profile_id = 1300096; // This is the custom profile id for banner id.  Will be used for 
$token_response = NULL;
$download_file = "account_data_".date("Ymd-H:i:s",time()).".gz";
$account_data = NULL;
$dbconn = pg_connect("user=$db_user password=$db_pass dbname=$db") or die('connection failed');
$log_dir = "/var/log/";
$log_file = $log_dir."fix_banner_id_".date("Ymd-H:i:s",time()).".log";
$missing_log = "missing_students_".date("Ymd-H:i:s",time()).".csv";
$no_profile_log = "missing_profile_".date("Ymd-H:i:s",time()).".csv";
$missing_handle = fopen($missing_log, "x+");
$no_profile_handle = fopen($no_profile_log, "x+");
$log_handle = fopen($log_file, "x+");
$log_str = "";
$missing_str = "First Name,Last Name,Email,Banner ID,Type"."\r\n";
$no_profile_str = "First Name,Last Name,Email,Banner ID,Type"."\r\n";
$success_count = 0;
$empty_id_count = 0;
$no_user_count = 0;
$no_profile_count = 0;

$curl = curl_init();

// Request data export for all accounts. Get the redeem token.

curl_setopt_array($curl, array(CURLOPT_HTTPGET => 1, CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $base_url."exports/accounts?key=$key"));
$token_response = curl_exec($curl);
if(!empty($token_response)){
  $token = json_decode($token_response)->export_token;
}else{
  exit("No token provided by API");
}
$log_str .= "API Token = $token"."\r\n";

//Request download url. Pause for 10 seconds between requests while waiting for download to be prepared.

if(!empty($token)){
  curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $base_url."exports/redeem?key=$key&export_token=$token"));
  $redeem_response = "null";
  while($redeem_response == "null"){
    sleep(10);
    $redeem_response = curl_exec($curl);
  }

  $download_url = json_decode($redeem_response)->download_url;
  curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $download_url));
  $account_data = curl_exec($curl);
}else{
  echo "API gave a response to the download request but no token was provided.";
}

if(!empty($account_data)){
  // Save gzip file just in case
  $handle = fopen($download_file, "x+");
  fwrite($handle, $account_data);
  fclose($handle);
  $accounts = gzfile($download_file);

  if(!empty($accounts)){
    //loop through json data and find accounts with no banner id.  Then do something smart.
    foreach($accounts as $account){
      $account = json_decode($account);
      $email = $account->username;
      $first_name = $account->first_name;
      $last_name = $account->last_name;
      $account_id = $account->id;
      $profile_responses = $account->profile_responses;
      if(!empty($profile_responses)){
	if($profile_responses[6]->element->id != BANNER_ELEMENT_ID){
	  foreach($profile_responses as $value){
	    if($value->element->id == BANNER_ELEMENT_ID)
	      $banner_id = $value->data;
	  }
	}else{
	  $banner_id = $profile_responses[6]->data;
	}
	if(empty($banner_id)){
	  $sdr_banner_id = "";
	  $email_parts = explode("@", $account->username);
	  $username = NULL;
	  if($email_parts[1] == "appstate.edu" || $email_parts[1] == "mail.appstate.edu"){
	    $username = $email_parts[0];
	    $query = "SELECT * FROM sdr_member WHERE username='$username'";
	  }else{
	    $query = "SELECT * FROM sdr_member WHERE first_name='$first_name' AND last_name='$last_name'";
	  }
	  $result = pg_query($query);
	  if($result && pg_num_rows($result) == 1){
	    $row = pg_fetch_row($result);
	    $sdr_first_name = $row[3];
	    $sdr_last_name = $row[5];
	    $sdr_banner_id = $row[0];
	  }else{
	    $log_str .= "Query Error: Cannot find user $first_name $last_name with email: $email"."\r\n";
	    $missing_str .="$first_name,$last_name,$email,,,"."\r\n";
	    $no_user_count++;
	  }
	  $empty_id_count++;
	  if(!empty($sdr_banner_id)){
	    curl_setopt_array($curl, array(CURLOPT_PUT => 1, CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $base_url."accounts/$account_id?key=$key&profile_responses[".BANNER_ELEMENT_ID."]=$sdr_banner_id"));
	    $update_response = curl_exec($curl);
	    if(!$update_response){
	      $log_str .= "API Error: Could not update account. First name: $first_name, Last name: $last_name, email: $email"."\r\n";
	    }else{
	      $log_str .= "API Success: Updated account. Banner id: $sdr_banner_id, First name: $first_name, Last name: $last_name, email: $email"."\r\n";
	      $success_count++;
	    }
	  }
	}
      }else{
	$no_profile_count++;
	$log_str .= "Account does not have a profile_response. First name: $first_name, Last name: $last_name, email: $email"."\r\n";
	$no_profile_str .="$first_name,$last_name,$email,,,"."\r\n";
      }
    }
  }
}
//  $all_accounts = json_decode($all_accounts);
echo "<br />finished. Found $empty_id_count accounts without banner ids and $no_user_count cannot be found. $no_profile_count accounts do not have profiles.";
$log_str .=  "\r\n"."finished. Found $empty_id_count accounts without banner ids and $no_user_count cannot be found. Successfully updated $success_count accounts. $no_profile_count accounts do not have profiles.";

fwrite($log_handle, $log_str);
fclose($log_handle);
fwrite($missing_handle, $missing_str);
fclose($missing_handle);
fwrite($no_profile_handle, $no_profile_str);
fclose($no_profile_handle);
curl_close($curl);
pg_close($dbconn);


/** find error of json_decode
  switch (json_last_error()) {
  case JSON_ERROR_NONE:
    echo ' - No errors';
    break;
  case JSON_ERROR_DEPTH:
    echo ' - Maximum stack depth exceeded';
    break;
  case JSON_ERROR_STATE_MISMATCH:
    echo ' - Underflow or the modes mismatch';
    break;
  case JSON_ERROR_CTRL_CHAR:
    echo ' - Unexpected control character found';
    break;
  case JSON_ERROR_SYNTAX:
    echo ' - Syntax error, malformed JSON';
    break;
  case JSON_ERROR_UTF8:
    echo ' - Malformed UTF-8 characters, possibly incorrectly encoded';
    break;
  default:
    echo ' - Unknown error';
    break;
  }
*/
?>