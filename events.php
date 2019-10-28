<?php
  /**
   * Script to pull event data
   *
   *@author - Ted Eberhard
   *
   *
   */

include ("/etc/ess/sdr_sync.conf");

$key = ENGAGE_API_KEY;
$base_url = ENGAGE_BASE_URL; 
$db_user = DB_USER;
$db_pass = DB_PASS;

// For testing purposes
$testorg = 284356; //test org 284356
//$result = getOrgMembers($testorg);
//$result = getUserByBannerID(900799123);
//$result = getOrgByID($testorg);
//$id = getIDFromEmail('lightfootdl@appstate.edu');
//$result = getUserByID($id);
$params = array("category" => "Service", "currentEventsOnly" => FALSE);
$result = getEvents($params);
$count = 0;
$volunteers = array();
foreach($result as $event){
    $eventID = $event->eventId;
    $event_params = array("eventId" => $eventID);
    echo $event->organizationName.":".$event->organizationId."\n";
    //$attendees = getEventAttendees($event_params);
    $count++;
}
echo $count;
exit;
var_dump($result);exit;

// get student from banner
$import_url = "http://banservice.appstate.edu:8086/api/student/";
$curl = curl_init();
curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $import_url));
$result = curl_exec($curl);

function getUserVars($user){
  $user_vars = array();
  $gender = $ethnicity = $banner_id = $citizen = $transfer = $class = $type = $level = NULL;
  $parts = explode("@", $user->username);
  $user_vars['username'] = $parts[0];
  $user_vars['first_name'] = pg_escape_string($user->firstName);
  $user_vars['last_name'] = pg_escape_string($user->lastName);
  $user_vars['updated'] = time();
  $gender = $user->sex->value;
  if($gender == "Male")
      $gender = "M";
  elseif($gender == "Female")
      $gender = "F";
  else
      $gender = NULL;
  $user_vars['gender'] = $gender;
  $user_vars['ethnicity'] = translateEthnicity($user->ethnicity->value);  
  $user_vars['banner_id'] = $user->cardId;
  $user_vars['citizen'] = "Y";
  if($user->international->value == "True")
      $user_vars['citizen'] = "N";
  $user_vars['transfer'] = 0;
  if($user->transfer->value == "True")
      $user_vars['transfer'] = 1;
  $class = "";
  $type = "";
  $level = "G";
  $class_standing = $user->classStanding->value;
  
  if($class_standing == "Freshmen"){
      $class = "FR";
      $type = "F";
  }elseif($class_standing == "Sophmore"){
      $class = "SO";
  }elseif($class_standing == "Junior"){
      $class = "JR";
  }elseif($class_standing == "Senior"){
      $class = "SR";
  }
  
  if(!empty($class))
      $level = "U";
  else
      $level = "G";
  $user_vars['class'] = $class;
  $user_vars['type'] = $type;
  $user_vars['level'] = $level;

  return $user_vars;
}

function getAllOrganizations(){
    $endpoint = "Organizations";
    $query_string = "pageSize=500";
    $result = curlGet($endpoint, $query_string);
    $all_orgs = FALSE;
    
    if($result && !empty($result->items)){
        $total_pages = $result->totalPages;
        if($total_pages > 1){
            $all_orgs = combinePages($endpoint, $query_string);
        } else {
            $all_orgs = $result->items;
        }
    }

    return $all_orgs;
}

function getOrgByID($org_id){
    $endpoint = "Organizations/$org_id";
    //get organization by orgsync id
    return curlGet($endpoint);
}

function getOrgMembers($org_id){
    $endpoint = "Memberships";
    $query_string = "pageSize=500&organizationId=$org_id";
    //get organization members by organization id
    $org_members = FALSE;
    $result = curlGet($endpoint, $query_string);
    
    if($result && !empty($result->items)){
        $total_pages = $result->totalPages;
        if($total_pages > 1){
            $org_members = combinePages($endpoint, $query_string);
        } else {
            $org_members = $result->items;
        }
    }
    return $org_members;
}

function getIDFromEmail($email){
    $endpoint = "Users/";
    $query_string = "username=".urlencode($email);
    $id = FALSE;
    
    $result = curlGet($endpoint, $query_string);

    if(!empty($result->items)) {
        $user = $result->items;
        $id = $user[0]->userId;
    }

    return $id;
}

function getAllUsers(){
    $endpoint = "Users";
    $query_string = "pageSize=500";
    $all_members = FALSE;
    
    $result = curlGet($endpoint, $query_string);

    if($result && !empty($result->items)){
        $total_pages = $result->totalPages;

        if($total_pages > 1){
            $all_members = combinePages($endpoint, $query_string);
        } else {
            $all_members = $result->items;
        }
    }
    return $all_members;
}

function getUserByBannerID($banner_id){
    $endpoint = "Users/";
    $query_string = "cardId=$banner_id";
    $user = FALSE;
    
    $result = curlGet($endpoint, $query_string);

    if(!empty($result->items)) {
        $user = $result->items;
    }

    return $user;    
}

function getUserByID($id){
    $endpoint = "Users/$id";
    return curlGet($endpoint);
}

function getBannerIDFromEmail($email){
  $parts = explode("@", $email);
  $username = strtolower($parts[0]);
  if(!empty($username)){
    $query = "SELECT * FROM sdr_member WHERE username='$username' ORDER BY id DESC";
    $result = pg_query($query);
    if($result && pg_num_rows($result) > 0){
      $row = pg_fetch_assoc($result);
      return $row['id'];
    }else{
      return false;
    }
  }else{
    return false;
  }
}

function getEvents($params=NULL){
    $endpoint = "Events";
    $query_string = "pageSize=500";
    $events = FALSE;
    if(!empty($params)){
        foreach($params as $key=>$value){
            $query_string .="&$key=$value";
        }
    }

    $result = curlGet($endpoint, $query_string);

    if($result && !empty($result->items)){
        $total_pages = $result->totalPages;

        if($total_pages > 1){
            $events = combinePages($endpoint, $query_string);
        } else {
            $events = $result->items;
        }
    }
    return $events;
}

function getEventAttendees($params){
    $endpoint = "Attendees";
    $query_string = "pageSize=500";
    $attendees = FALSE;
    if(!empty($params)){
        foreach($params as $key=>$value){
            $query_string .="&$key=$value";
        }
    }

    $result = curlGet($endpoint, $query_string);

    if($result && !empty($result->items)){
        $total_pages = $result->totalPages;

        if($total_pages > 1){
            $attendees = combinePages($endpoint, $query_string);
        } else {
            $attendees = $result->items;
        }
    }
    return $attendees;
}

// Not being used
function getOrgsyncNewAccount(){
  $dbconn = DBConn("sdr");
  $all_accounts = getAllAccounts();
  foreach($all_accounts as $account){
    $acc = getAccountByID($account->id);
    $b_id = $acc->profile_responses[6]->data;
    $query = "select * from sdr_member where id='$b_id'";
    $result = pg_query($query);
    if(pg_num_rows($result) == 0){
      return $account->id;
      break;
    }
    
  }
  pg_close($dbconn);
}

function combinePages($endpoint, $query_string) {
    $result = curlGet($endpoint, $query_string);
    $combined = array();
    if(!empty($query_string)) {
        $query_string .= "&";
    }

    if($result) {
        $totalPages = $result->totalPages;
        for($i = 1; $i <= $totalPages; $i++) {
            $page = curlGet($endpoint, $query_string."page=$i");
            $combined = array_merge($combined, $page->items);
        }
    } else {
        return false;
    }

    return $combined;
}

function curlGet($endpoint, $query_string="") {
    global $key, $base_url;
    if(!empty($query_string)){
        $query_string = "?$query_string";
    }
    $curl = curl_init();
    curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $base_url.$endpoint.$query_string));
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'X-Engage-Api-Key: ' . $key));
    $result = curl_exec($curl);
    curl_close($curl);
    return json_decode($result);
}

function DBConn($db){
  global $db_user, $db_pass;
  $dbconn = pg_connect("user=$db_user password=$db_pass dbname=$db") or die('connection failed');
  return $dbconn;
}
?>
