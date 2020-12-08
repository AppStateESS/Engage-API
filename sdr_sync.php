<?php
  /**
   * Script to pull organization and member information from orgsync and update club connect.
   *
   *@author - Ted Eberhard
   *
   *
   */

include ("/etc/ess/sdr_sync.conf");
include ("api_functions.php");

$key = ENGAGE_API_KEY;
$base_url = ENGAGE_BASE_URL; 
$db_user = DB_USER;
$db_pass = DB_PASS;
$log_file = 'sdr_sync_error.log';
$role_log_file = 'org_roles_error.log';
$current_term = "";

// For testing purposes
//$testorg = 284356; //test org 284356
//$result = getOrgMembers(258889);
//$result = getUserByBannerID(900707546);
//$result = getOrgByID(258889);
//$result = getOrgPositions($testorg);
//$id = getIDFromBanner('campusactivities@appstate.edu');
//$result = getUserByID($id);
//var_dump($result);exit;
//$result = getAllUsers();

// Open logs for writing
$log_handle = fopen($log_file, 'w');
//$role_log_handle = fopen($role_log_file, 'r+');

$sdr_term = setCurrentTerm();

// Update the current term in SDR
if(!$sdr_term)
  fwrite($log_handle, "Something went wrong setting the current term in mod_settings for sdr. term: $current_term");

// Run main control function
syncOrganizations();

//initIDMap();exit;

fclose($log_handle); // close log file
fclose($role_log_handle);

function syncOrganizations(){
  $dbconn = DBConn("sdr");
  global $exclude_orgs;
  $orgs = getAllOrganizations();
  
  foreach($orgs as $org){
      if($org->parentId == CSIL_ID){
          if(!in_array($org->organizationId, $exclude_orgs)){
              if($org->status == "Active"){
                  $query = "SELECT * FROM sdr_appsync_id_map WHERE appsync_id=$org->organizationId";
                  $result = pg_query($query);
                  if(pg_num_rows($result) > 0){ // The organization exists in club connect so update it
                      $row = pg_fetch_assoc($result);
                      $sdr_org_id = $row['sdr_id'];
                      if(!sdrOrganizationExists($sdr_org_id)) {
                          $sdr_org_id = createOrganization($org);
                          $appsync_id = $org->organizationId;
                          $update_query = "UPDATE sdr_appsync_id_map set sdr_id=0 where sdr_id=$sdr_org_id";
                          pg_query($update_query);
                          $update_query = "UPDATE sdr_appsync_id_map set sdr_id=$sdr_org_id where appsync_id=$appsync_id";
                          echo "created org and updated orgsync id map for appsync id: $appsync_id ; sdr id: $sdr_org_id \r\n";
                          pg_query($update_query);

                      }
                      updateOrganization($org, $sdr_org_id);
                  }else{ // the organization does not exist in club connect so create it.
                      $sdr_org_id = createOrganization($org);
                  }
                  if($sdr_org_id){
                      syncOrgMemberships($org, $sdr_org_id);
                  }
              }
          }
      }
  }
  pg_close($dbconn);
}

function syncOrgMemberships($org, $sdr_org_id){
  global $log_handle;
  $members = getOrgMembers($org->organizationId);

  foreach($members as $member){
      $user = getUserByID($member->userId);
      $username = $user->username;
      $first_name = $user->firstName;
      $last_name = $user->lastName;
      $banner_id = $user->username;
      if(substr($banner_id, 0, 3) != "900") {
        $banner_id = $user->cardId;
      }

      if(empty($member->positionRecordedEndDate) && !$member->deleted) {      
          if(!empty($banner_id)){
              $query = "SELECT * FROM sdr_member WHERE id=$banner_id"; 
              $result = pg_query($query);
              if($result && pg_num_rows($result) > 0){
                  updateUser($user);
              } else {
                  createUser($user);
              }
              updateMembership($banner_id, $sdr_org_id);
          } else {
              fwrite($log_handle, "Sync Org Memberships Error: Account has no card id. user id: ".$member->userId.", username: $username, first name: $first_name, last name: $last_name"."\r\n");
          }

          if(!empty($member->positionTemplateId) && !empty($banner_id)) {
              updateRole($member, $banner_id, $sdr_org_id);
          }
      }
  }
}

function updateRole($member, $banner_id, $sdr_org_id){
  global $log_handle, $role_log_handle, $current_term, $officer_types;
  $org_role_error = '';
  $log_str = '';
  $success = TRUE;
  $position_id = $member->positionTemplateId;
  $position_type_id = $member->positionTypeId;
  $role = null;
  $now = time();
  $end_date = $member->positionRecordedEndDate;
  if(!empty($end_date)){
    $end_date = $end_date/1000;
  }
  if(!empty($end_date) && $end_date < $now){
    return;
  }

  // Check if the position template id = new member
  if($position_id == '21019') {
      $role = NEW_MEMBER_ROLE;
  }
  
  $query = "SELECT * FROM sdr_membership WHERE member_id=$banner_id AND organization_id=$sdr_org_id AND term='$current_term'";
  $result = pg_query($query);
  if($result && pg_num_rows($result) > 0){
      $row = pg_fetch_assoc($result);
      $membership_id = $row['id'];
      
      if(in_array($position_type_id, $officer_types)) {
          switch ($position_id) 
          {
          case '16526':
              $role = PRESIDENT_ROLE;
              break;
          case '16528':
              $role = VP_ROLE;
              break;
          case '16529':
              $role = SECRETARY_ROLE;
              break;
          case '16530':
              $role = TREASURER_ROLE;
              break;
          case '16527':
              $role = ADVISOR_ROLE;
              break;
          case '16533':
          case '16532':
          case '16531':
              $role = CHAIR;
              break;
          default:
              $role = OFFICER_ROLE;
              break;
          }
      }

      if(!empty($role)){
          $query = "SELECT * FROM sdr_membership_role WHERE membership_id=$membership_id AND role_id=$role";
          $result = pg_query($query);
          if(pg_num_rows($result) == 0){
              $query = "INSERT INTO sdr_membership_role (membership_id, role_id) VALUES($membership_id, $role)";
              if(!pg_query($query)){
                  $log_str .= "Update Membership Role Error: Failed to insert $position_id role. query: $query"."\r\n";
		  $success = false;
	      }
              if($role != NEW_MEMBER_ROLE){	  
                  $query = "UPDATE sdr_membership SET administrator=1 WHERE member_id=$banner_id AND organization_id=$sdr_org_id AND term=$current_term";
                  if(!pg_query($query)) {
                      $log_str = "Update Memberhsip Role Error: Failed to update $position_id to adminstrator. query: $query"."\r\n";	
		      $success = false;
		  }
              }
          }
      }
  }
  
  if($success)
    $log_str .= "Successfully updated membership role for banner id: $banner_id.  sdr org id: $sdr_org_id"."\r\n";
  fwrite($log_handle, $log_str);
}

function updateMembership($member_id, $sdr_org_id){
  global $current_term, $log_handle;
  $log_str = '';
  $success = TRUE;

  $student_approved = $organization_approved = 1;
  $hidden = $administrator = $administrative_force = 0;
  
  $query = "SELECT * FROM sdr_membership WHERE member_id='$member_id' AND organization_id='$sdr_org_id' AND term='$current_term'";
  $result = pg_query($query);
 
  if(pg_num_rows($result) == 0){
    $query = "SELECT NEXTVAL('sdr_membership_seq')";
    $id_result = pg_query($query);
    
    // create new membership
    if($id_result){
      $id_result = pg_fetch_row($id_result);
      $sdr_membership_id = $id_result[0];
      $query = "INSERT INTO sdr_membership (id, member_id, organization_id, student_approved, hidden, organization_approved, term, administrator, administrative_force) values($sdr_membership_id, $member_id, $sdr_org_id, $student_approved, $hidden, $organization_approved, $current_term, $administrator, $administrative_force)";
      $org_result = pg_query($query);
      if(!$org_result){
	$log_str .= "Update Membership Error: Could not add membership. query: $query"."\r\n";
	$success = FALSE;
      }
    }else{
      $success = FALSE;
      $log_str .= "Update Membership Error: could not get next sequence when adding member id: $member_id to sdr org id: $sdr_org_id"."\r\n";
    }
  }
  //if($success)
  //$log_str .= "Successfully updated membership. member id = $member_id, sdr org id: $sdr_org_id"."\r\n";
  fwrite($log_handle, $log_str);
}

function removeMembership($banner_id, $sdr_org_id) {
    global $current_term;
    
    $query = "DELETE FROM sdr_membership WHERE member_id='$banner_id' AND organization_id='$sdr_org_id' AND term='$current_term'";
    $result = pg_query($query);
}

function createOrganization($org){
    global $log_handle, $current_term, $organization_cats, $greek_categories;
  $log_str = '';
  $success = TRUE;
  
  // organization instance parameters
  $long_name = pg_escape_string($org->name);
  $short_name = pg_escape_string($org->shortName);
  $org_id = $org->organizationId;
  $sdr_org_id = 0;

  $org_cat = getOrgCategory($org->categories);
  if(in_array($org_cat, $greek_categories)) {
      $org_cat = getGreekType($org_id);
  } else {
      $org_cat = $organization_cats[$org_cat];
  }
  
  $addresss = NULL; // not setting this
  $bank = "";
  $ein = "";
  //organization profile parameters. Probably don't need any of this but can add it in if needed.
  $custom_fields = $org->customFields;
  $purpose = "";
  $club_logo = NULL;  // not setting this
  $meeting_location = NULL; //not setting this
  $meeting_date = ""; 
  $description = pg_escape_string($org->description);
  $description = "<p>".$description."</p>";
  $site_url = $org->externalWebsite;
  $contact_info = NULL; // not setting this

  $query = "SELECT NEXTVAL('sdr_organization_seq')";
  $id_result = pg_query($query);
  
  // create new organization  
  if($id_result){
    $id_result = pg_fetch_row($id_result);
    $sdr_org_id = $id_result[0];
    if($sdr_org_id < 10000)
      $banner_id = "SDR".$sdr_org_id;
    else
      $banner_id = "SD".$sdr_org_id;

    $query = "INSERT INTO sdr_organization (id, banner_id, student_managed) values($sdr_org_id, '$banner_id', '1')";
    $org_result = pg_query($query);

    if($org_result){
      //INSERT INTO instance
      $query = "SELECT NEXTVAL('sdr_organization_instance_seq')";
      $instance_result = pg_query($query);
      if($instance_result){
	$instance_result = pg_fetch_row($instance_result);
	$instance_id = $instance_result[0];
	$query = "INSERT INTO sdr_organization_instance (id, organization_id, term, name, type, address, bank, ein, shortname) values($instance_id, $sdr_org_id, $current_term, '$long_name', '$org_cat', NULL, '$bank', '$ein', '$short_name')";
	if(!pg_query($query)){
	  $log_str .= "Create Organization Error: Could not create organization instance. query: $query"."\r\n";
	  $success = FALSE;
	}
	//INSERT INTO ogranization profile
	$query = "SELECT NEXTVAL('sdr_organization_instance_seq')";
	$profile_result = pg_query($query);
	if($profile_result){
	  $profile_result = pg_fetch_row($profile_result);
	  $profile_id = $profile_result[0];
	  $query = "INSERT INTO sdr_organization_profile (id, organization_id, purpose, meeting_location, description, site_url) values($profile_id, $sdr_org_id, '$purpose', '$meeting_location', '$description', '$site_url')";
	  if(!pg_query($query)){
	    $log_str .= "Create Organization Error: Insert into sdr_organization_profile failed. query:$query"."\r\n";
	    $success = FALSE;
	  }
	}
	// Insert into mapping table
	$query = "INSERT INTO sdr_appsync_id_map (appsync_id, sdr_id) values($org_id, $sdr_org_id)";
	if(!pg_query($query)){
	  $log_str .= "Create Organization Error: Insert into mapping table failed. query: $query"."\r\n";
	  $success = FALSE;
	}
      }else{
	$log_str .= "Create Organization Error: Could not get next id for sdr_organization_instance.  query: $query"."\r\n";
	$success = FALSE;
      }
    }else{
      // log the failure with query
      $log_str .= "Create Organization Error: Failed to add new organization! query: $query"."\r\n";
      $success = FALSE;
    }
    
  }else{
    $log_str .= "Could not get next sdr_organization sequence. Did not create $long_name.  ID = $org_id"."\r\n";
    $success = FALSE;
  }
  if($success)
    $log_str .= "Successfully created $long_name.  ID = $org_id"."\r\n";
  fwrite($log_handle, $log_str);

  return $sdr_org_id;
}

function updateOrganization($org, $sdr_id){
    global $log_file, $log_handle, $current_term, $organization_cats, $greek_categories;
  $log_str = '';
  $success = TRUE;
  
  // organization instance parameters
  $long_name = pg_escape_string($org->name);
  $short_name = pg_escape_string($org->shortName);
  $org_id = $org->organizationId;

  $org_cat = getOrgCategory($org->categories);
  if(in_array($org_cat, $greek_categories)) {
      $org_cat = getGreekType($org_id);
  } else {
      $org_cat = $organization_cats[$org_cat];
  }

  $addresss = NULL; // not setting this
  $bank = "";
  $ein = "";
  //organization profile parameters. Probably don't need any of this but can add it in if needed.
  $custom_fields = $org->customFields;
  $purpose = "";
  $club_logo = NULL;  // not setting this
  $meeting_location = NULL; //not setting this
  $meeting_date = ""; 
  $description = pg_escape_string($org->description);
  $description = "<p>".$description."</p>";
  $site_url = $org->externalWebsite;
  $contact_info = NULL; // not setting this

  //Add new organization instance. First check if its already been added.  If so do not call nextval just update it.
  $query = "SELECT * FROM sdr_organization_instance WHERE organization_id='$sdr_id' AND term='$current_term'";
  $result = pg_query($query);
  if(pg_num_rows($result) == 0){
    $query = "SELECT NEXTVAL('sdr_organization_instance_seq')";
    $instance_result = pg_query($query);
    if($instance_result){
      $instance_result = pg_fetch_row($instance_result);
      $instance_id = $instance_result[0];
      $query = "INSERT INTO sdr_organization_instance (id, organization_id, term, name, type, address, bank, ein, shortname) values($instance_id, $sdr_id, $current_term, '$long_name', '$org_cat', NULL, '$bank', '$ein', '$short_name')";
      if(!pg_query($query)){
	$log_str .= "Update Organization Error: Could not create organization instance. query: $query"."\r\n";
	$success = FALSE;
      }
      
    }else{
      $log_str .= "Update Organization Error: NEXTVAL failed to get next instance id for $long_name. orgsync id: $org_id , sdr id: $sdr_id";
      $success = FALSE;
    }
  }
  //Update ogranization profile
  $query = "UPDATE sdr_organization_profile SET (purpose, meeting_location, description, site_url) = ('$purpose', '$meeting_location', '$description', '$site_url') WHERE organization_id='$sdr_id'";
  if(!pg_query($query)){
    $log_str .= "Update Organization Error: update sdr_organization_profile failed. query:$query"."\r\n";
    $success = FALSE;
  }
  
  if($success)
    $log_str .= "Successfully updated $long_name.  ID = $org_id"."\r\n";
  fwrite($log_handle, $log_str);
}

function createUser($user){
  global $log_file, $log_handle, $current_term, $organization_cats;
  $log_str = '';
  $success = TRUE;  

  $user_vars = getUserVars($user);
  $banner_id = $user_vars['banner_id'];
    $query = "INSERT INTO sdr_member (id, username, first_name, last_name) VALUES('".$user_vars['banner_id']."','".$user_vars['username']."','".$user_vars['first_name']."','".$user_vars['last_name']."')";
  if(!pg_query($query)){
    $log_str .= "Create member Error: Unable to create member. query: $query";
    $success = FALSE;
  }
  $query = "INSERT INTO sdr_student (id, gender, ethnicity, citizen, transfer) VALUES('".$user_vars['banner_id']."','".$user_vars['gender']."','".$user_vars['ethnicity']."','".$user_vars['citizen']."','".$user_vars['transfer']."')";
  if(!pg_query($query)){
    $log_str .= "Create Student Error: Unable to create student. query: $query";
    $success = FALSE;
  }
  $query = "INSERT INTO sdr_student_registration (student_id, term, type, level, class, updated) VALUES('".$user_vars['banner_id']."','$current_term','".$user_vars['type']."','".$user_vars['level']."','".$user_vars['class']."','".$user_vars['updated']."')";
  if(!pg_query($query)){
    $log_str .= "Create Student registration Error: Unable to create student registration. query: $query";
    $success = FALSE;
  }
  //if($success)
  //$log_str .= "Successfully created member. Banner id: ".$user_vars['banner_id'].", orgsync id: $user->userId";

  fwrite($log_handle, $log_str);
}

function updateUser($user){
  global $log_file, $log_handle, $current_term, $organization_cats;
  $log_str = '';
  $success = TRUE;  

  $user_vars = getUserVars($user);
  $banner_id = $user_vars['banner_id'];

  $query = "UPDATE sdr_member SET (username, first_name, last_name) = ('".$user_vars['username']."','".$user_vars['first_name']."','".$user_vars['last_name']."') WHERE id='$banner_id'";
  if(!pg_query($query)){
    $log_str .= "Update User Error: Unable to update member. query: $query";
    $success = FALSE;
  }
  $query = "UPDATE sdr_student SET (gender, ethnicity, citizen, transfer) = ('".$user_vars['gender']."','".$user_vars['ethnicity']."','".$user_vars['citizen']."','".$user_vars['transfer']."') WHERE id='$banner_id'";
  if(!pg_query($query)){
    $log_str .= "Update User Error: Unable to update student. query: $query";
    $success = FALSE;
  }
  $query = "SELECT * FROM sdr_student_registration WHERE student_id='$banner_id' AND term=$current_term";
  $reg_result = pg_query($query);
  if(pg_num_rows($reg_result) == 0){
    $query = "INSERT INTO sdr_student_registration (student_id, term, type, level, class, updated) VALUES('".$user_vars['banner_id']."','$current_term','".$user_vars['type']."','".$user_vars['level']."','".$user_vars['class']."','".$user_vars['updated']."')";
    if(!pg_query($query)){
      $log_str .= "Update User Error: Unable to update student registration. query: $query";
      $success = FALSE;
    }
  }

  //if($success)
  //$log_str .= "Successfully updated user. Banner id: ".$user_vars['banner_id'].", appsync id: $user->userId";

  fwrite($log_handle, $log_str);
}

function sdrOrganizationExists($sdr_org_id) {
    $query = "SELECT * FROM sdr_organization where id=$sdr_org_id";
    $result = pg_query($query);

    if(pg_num_rows($result)) {
        return true;
    } else {
        return false;
    }
}

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

function getOrgCategory($org_cats) {
    global $admin_categories;
    $category = 'default';
    foreach($org_cats as $cat){
        if(in_array($cat->categoryId, $admin_categories)){
            $category = $cat->categoryId;
            break;
        }
    }

    return $category;
}

function getGreekType($org_id){
    $members = getOrgMembers($org_id);
    $male = 0;
    $female = 0;
    $org_type = '';

    foreach($members as $member){
        $member_id = $member->userId;
        $user = getUserByID($member_id);
        $gender = $user->sex->value;
        
        if($gender == 'Female')
            $female++;
        elseif($gender == 'Male')
            $male++;
    }
    if($male > $female)
        $org_type = FRATERNITY;
    else
        $org_type = SORORITY;
    
    return $org_type;
}

function translateEthnicity($ethnicity){

  switch ($ethnicity) {
    case "Caucasian/White":
      $code = 'W';
      break;
    case "African American/Black":
      $code = 'B';
      break;
    case "Hispanic/Latino":
      $code = 'H';
      break;
    case "Asian":
      $code = 'O';
      break;
    case "Middle eastern":
      $code = 'O';
      break;
    case "Native American/Alaskan":
      $code = 'I';
      break;
    case "Multiracial":
      $code = 'X';
      break;
    default:
      $code = 'N';
      break;
    }
  return $code;
}

function setCurrentTerm(){
  $dbconn = DBConn("sdr");
  global $current_term;
  $year = date("Y",time());
  $month = date("n",time());
  if($month < 6)
    $current_term = $year."10";
  else
    $current_term = $year."40";

  $query = "UPDATE mod_settings SET large_num=$current_term WHERE module='sdr' AND setting_name='current_term'";
  $result = pg_query($query);
  pg_close($dbconn);
  return $result;
}

function initIDMap(){
  $dbconn = DBConn("sdr");
  $orgs = getAllOrganizations();
  $total_orgs = count($orgs);
  $log_str = "";
  $count = 0;
  $dup_count = 0;
  foreach($orgs as $org){
      if($org->parentId != CSIL_ID || $org->status =="Inactive"){
          continue;
      }
    $short_name = pg_escape_string(strtolower($org->shortName));
    $long_name = pg_escape_string(strtolower($org->name));
    $org_id = $org->organizationId;
    $query = "select * from sdr_organization_instance where LOWER(name)='$short_name' or LOWER(name) like '%$long_name%' order by organization_id asc";
    $result = pg_query($dbconn, $query);
    $result_count = 0;
    $row = pg_fetch_assoc($result);
    if($row){
        $count++;
        // check to see if we have more then one organization returned in the result set.  If so log it and do not put it in the map table.
        $prev_sdr_id = $row['organization_id'];
        while($row = pg_fetch_assoc($result)){
            $next_sdr_id = $row['organization_id'];
            if($prev_sdr_id != $next_sdr_id){
                $result_count++;
                $prev_sdr_id = $next_sdr_id;
            }
        }
        if($result_count > 0){
            $log_str .= "Duplicate results found for $long_name. Appsync id = $org_id"."\r\n";
            $dup_count++;
        }else{
            // Add it to the ID map table
            $query = "INSERT INTO sdr_appsync_id_map (appsync_id, sdr_id) VALUES($org_id, $prev_sdr_id)";
            
            if(!pg_query($dbconn, $query))
                $log_str .= "Insert failed for $long_name. query: $query"."\r\n";
        }
    }else{
        $log_str .= "No match for $long_name. Appsync id = $org_id"."\r\n";
    }
  }
  $log_str .= "Total number of organizations from Appsync: $total_orgs"."\r\n";
  $log_str .= "Total number of organizations with successful matches: $count of which $dup_count had duplicate results."; 

  echo $log_str;
  pg_close($dbconn);
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

function DBConn($db){
  global $db_user, $db_pass;
  $dbconn = pg_connect("user=$db_user password=$db_pass dbname=$db") or die('connection failed');
  return $dbconn;
}
?>
