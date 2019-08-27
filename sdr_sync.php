<?php
  /**
   * Script to pull organization and member information from orgsync and update club connect.
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
$banner_profile_id = BANNER_ELEMENT_ID; // This is OrgSync's custom profile id for banner id.  Will be used for finding banner id in the user extended profile.  This may be different so check. 
$exclude_orgs = array(95550,878950);  // These are some organizations in OrgSync that really didn't belong as a club or greek organization.  You may not need this 
$log_file = 'sdr_sync_error.log';
$role_log_file = 'org_roles_error.log';
$current_term = "";
$organization_cats = array("default"=>106,55007=>102,55031=>10,55016=>'greek',55032=>106,55510=>102,55017=>102,57267=>3,55019=>103,55024=>106,55026=>9,55027=>17,55028=>18,55029=>20,55030=>106,55018=>5,55006=>23,55022=>5);  // associative array to map orgsync categories to sdr org types

// Open logs for writing
//$log_handle = fopen($log_file, 'r+');
//$role_log_handle = fopen($role_log_file, 'r+');


$sdr_term = setCurrentTerm();
// Update the current term in SDR
//if(!$sdr_term)
//  fwrite($log_handle, "Something went wrong setting the current term in mod_settings for sdr. term: $current_term");

// Run main control function
//syncOrganizations();
//$result = getOrgMembers(284356); //test org 284356
getIDFromEmail("eberhardtm@appstate.edu");
//var_dump($result);exit;
//fclose($log_handle); // close log file
//fclose($role_log_handle);

function syncOrganizations(){
  $dbconn = DBConn("sdr");
  global $exclude_orgs;
  $orgs = getAllOrganizations();
  
  foreach($orgs as $value){
    if($value->umbrella_id == CSIL_ID){
      if(!in_array($value->id, $exclude_orgs)){
	$org = getOrgByID($value->id);
	if(!$org->is_disabled){
	  $query = "SELECT * FROM sdr_orgsync_id_map WHERE orgsync_id=$org->id";
	  $result = pg_query($query);
	  if(pg_num_rows($result) > 0){ // The organization exists in club connect so update it
	    $row = pg_fetch_assoc($result);
	    $sdr_org_id = $row['sdr_id'];
	    updateOrganization($org, $sdr_org_id);
	  }else{ // the organization does not exist in club connect so create it.
	    $sdr_org_id = createOrganization($org);
	  }
	  if($sdr_org_id){
	    syncOrgMemberships($org, $sdr_org_id);
        //	    updateOrgRoles($org, $sdr_org_id);
	  }
	}
      }
    }
  }
pg_close($dbconn);
}

function syncOrgMemberships($org, $sdr_org_id){
  global $log_handle;
  $members = $org->account_ids;

  foreach($members as $id){
    $account = getAccountByID($id);
    $username = $account->username;
    $first_name = $account->first_name;
    $last_name = $account->first_name;
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
      if(!empty($banner_id)){
	$query = "SELECT * FROM sdr_member WHERE id=$banner_id"; 
	$result = pg_query($query);
	if($result && pg_num_rows($result) > 0){
	  updateAccount($account);
	}else{
	  createAccount($account);
	}
	updateMembership($banner_id, $sdr_org_id);
      }else{
	fwrite($log_handle, "Sync Org Memberships Error: Account has no banner id. account id: $id, username: $username, first name: $first_name, last name: $last_name"."\r\n");
      }
    }else{
      fwrite($log_handle, "Sync Org Memberships Error: Account has no extended profile. account id: $id, username: $username, first name: $first_name, last name: $last_name"."\r\n");
    }
  }
}

function updateOrgRoles($org, $sdr_org_id){
  // parse organization and find president and the 4 officers.  Mark president as president then officer #2 as VP.  Other officers will be marked as general officers.  Then look to see if there is a treasurer and advisor and set those.  Make sure to update sdr_membership table so you mark the appropriate people as administrators. 
  global $log_handle, $role_log_handle, $current_term;
  $org_role_error = '';
  $log_str = '';
  $success = TRUE;
  $officers_ids = array();
  $groups = $org->groups;
  $profile_responses = $org->profile_responses;
  
  foreach($profile_responses as $value){
    switch ($value->element->id)
      {
      case PRESIDENT_ELEMENT_ID:
	$president_name = $value->data;
	break;
      case PRESIDENT_EMAIL_ELEMENT_ID:
	$president_email = $value->data;
	break;
      case OFFICER2_ELEMENT_ID:
	$officer2_name = $value->data;
	break;
      case OFFICER2_EMAIL_ELEMENT_ID:
	$officer2_email = $value->data;
	break;
      case OFFICER3_ELEMENT_ID:
	$officer3_name = $value->data;
	break;
      case OFFICER3_EMAIL_ELEMENT_ID:
	$officer3_email = $value->data;
	break;
      case OFFICER4_ELEMENT_ID:
	$officer4_name = $value->data;
	break;
      case OFFICER4_EMAIL_ELEMENT_ID:
	$officer4_email = $value->data;
	break;
      case TREASURER_ELEMENT_ID:
	$treasurer_name = $value->data;
	break;
      case TREASURER_EMAIL_ELEMENT_ID:
	$treasurer_email = $value->data;
	break;
      case ADVISOR_ELEMENT_ID:
	$advisor_name = $value->data;
	break;
      case ADVISOR_EMAIL_ELEMENT_ID:
	$advisor_email = $value->data;
	break;
      default:
	break;
      } 
  }

  if(!empty($president_email)){  
    $president = getBannerIDFromEmail($president_email);
    if(!$president){
      $org_role_error .= "There was a problem mapping a member to $org->short_name president role. Org id is $org->id . Member email address is $president_email. Member name is $president_name."."\r\n";
    }else{
      $officers_ids[$president] = 'president';
    }
  }else{
    $org_role_error .= "$org->short_name president email is blank."."\r\n";
  }
  if(!empty($officer2_email)){
    $officer2 = getBannerIDFromEmail($officer2_email);
    if(!$officer2){
      $org_role_error .= "There was a problem mapping a member to $org->short_name vice president role. Org id is $org->id . Member email address is $officer2_email. Member name is $officer2_name."."\r\n";
    }else{
      $officers_ids[$officer2] = 'officer2';
    }
  }else{
    $org_role_error .= "$org->short_name officer 2 email is blank."."\r\n";
  }
  if(!empty($officer3_email)){
    $officer3 = getBannerIDFromEmail($officer3_email);
    if(!$officer3){
      $org_role_error .= "There was a problem mapping a member to $org->short_name officer3 role. Org id is $org->id . Member email address is $officer3_email. Member name is $officer3_name."."\r\n";
    }else{
      $officers_ids[$officer3] = 'officer3';
    }
  }else{
    $org_role_error .= "$org->short_name officer 3 email is blank."."\r\n";
  }
  if(!empty($officer4_email)){
    $officer4 = getBannerIDFromEmail($officer4_email);
    if(!$officer4){
      $org_role_error .= "There was a problem mapping a member to $org->short_name officer4 role. Org id is $org->id . Member email address is $officer4_email. Member name is $officer4_name."."\r\n";
    }else{
      $officers_ids[$officer4] = 'officer4';
    }
  }else{
    $org_role_error .= "$org->short_name officer 4 email is blank."."\r\n";
  }
  if(!empty($treasurer_email)){
    $treasurer = getBannerIDFromEmail($treasurer_email);
    if(!$treasurer){
      $org_role_error .= "There was a problem mapping a member to $org->short_name treasurer role. Org id is $org->id . Member email address is $treasurer_email. Member name is $treasurer_name."."\r\n";
    }else{
      $officers_ids[$treasurer] = 'treasurer';
    }
  }else{
    $org_role_error .= "$org->short_name treasurer email is blank."."\r\n";
  }
  if(!empty($advisor_email)){
    $advisor = getBannerIDFromEmail($advisor_email);
    if(!$advisor){
      $org_role_error .= "There was a problem mapping a member to $org->short_name advisor role. Org id is $org->id . Member email address is $advisor_email. Member name is $advisor_name."."\r\n";
    }else{
      $officers_ids[$advisor] = 'advisor';
    }
  }else{
    $org_role_error .= "$org->short_name advisor email is blank."."\r\n";
  }
 
  foreach($groups as $group){
    if($group->name == ORGSYNC_NEW_MEMBER_GROUP && count($group->account_ids) > 0){
      foreach($group->account_ids as $account_id){
	$account_vars = getAccountVars(getAccountByID($account_id));
	$officers_ids[$account_vars['banner_id']] = 'new_member';
      }
    }
    if($group->name == ORGSYNC_OFFICER_GROUP && count($group->account_ids) > 0){
      foreach($group->account_ids as $account_id){
	$account_vars = getAccountVars(getAccountByID($account_id));
	$officers_ids[$account_vars['banner_id']] = 'officer';
      }
    }
  }
 
  foreach($officers_ids as $key=>$value){
    $role = NULL;
    $query = "SELECT * FROM sdr_membership WHERE member_id=$key AND organization_id=$sdr_org_id AND term='$current_term'";
    $result = pg_query($query);
    if($result && pg_num_rows($result) > 0){
      $row = pg_fetch_assoc($result);
      $membership_id = $row['id'];
      
      switch ($value) 
	{
	case 'president':
	  $role = PRESIDENT_ROLE;
	  break;
	case 'officer2':
	  $role = VP_ROLE;
	  break;
	case 'officer3':
	  $role = OFFICER_ROLE;
	  break;
	case 'officer4':
	  $role = OFFICER_ROLE;
	  break;
	case 'treasurer':
	  $role = TREASURER_ROLE;
	  break;
	case 'advisor':
	  $role = ADVISOR_ROLE;
	  break;
	case 'officer':
	  $role = OFFICER_ROLE;
	  break;
	case 'new_member':
	  $role = NEW_MEMBER_ROLE;
	  break;
	default:
	  $role = NULL;
	  break;
	}

      if(!empty($role)){
	$query = "SELECT * FROM sdr_membership_role WHERE membership_id=$membership_id AND role_id=$role";
	$result = pg_query($query);
	if(pg_num_rows($result) == 0){
	  $query = "INSERT INTO sdr_membership_role (membership_id, role_id) VALUES($membership_id, $role)";
	  if(!pg_query($query))
	    $log_str .= "Update Membership Role Error: Failed to insert $value role. query: $query"."\r\n";
	  if($role != NEW_MEMBER_ROLE){	  
	      $query = "UPDATE sdr_membership SET administrator=1 WHERE member_id=$key AND organization_id=$sdr_org_id AND term=$current_term";
	      if(!pg_query($query))
	        $log_str = "Update Memberhsip Role Error: Failed to update $value to adminstrator. query: $query"."\r\n";	
	  }
	}else{
	  $query = "UPDATE sdr_membership_role SET role_id=$role WHERE membership_id=$membership_id";
	  if(!pg_query($query))
	      $log_str = "Update Memberhsip Role Error: Failed to update membership role. Membership id = $membership_id. Role = $role. Query: $query"."\r\n";	
	}
      }
    }
  }
  
  if($success)
    $log_str .= "Successfully updated membership roles.  sdr org id: $sdr_org_id"."\r\n";
  fwrite($log_handle, $log_str);
  fwrite($role_log_handle, $org_role_error);
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
  if($success)
    $log_str .= "Successfully updated membership. member id = $member_id, sdr org id: $sdr_org_id"."\r\n";
  fwrite($log_handle, $log_str);
}

function createOrganization($org){
  global $log_handle, $current_term, $organization_cats;
  $log_str = '';
  $success = TRUE;
  
  // organization instance parameters
  $long_name = pg_escape_string($org->long_name);
  $short_name = pg_escape_string($org->short_name);
  $org_id = $org->id;
  $sdr_org_id = 0;

  if(!empty($organization_cats[$org->category->id]))
    $org_type = $organization_cats[$org->category->id];
  else
    $org_type = $organization_cats['default'];

  $addresss = NULL; // not setting this
  $bank = "";
  $ein = "";
  //organization profile parameters. Probably don't need any of this but can add it in if needed.
  $profile_responses = $org->profile_responses;
  $purpose = "";
  $club_logo = NULL;  // not setting this
  $meeting_location = NULL; //not setting this
  $meeting_date = ""; 
  $description = pg_escape_string($org->description);
  $description = "<p>".$description."</p>";
  $site_url = $org->website_url;
  $contact_info = NULL; // not setting this

  foreach($profile_responses as $value){
    if($value->element->type == "Meeting Location")
      $meeting_location = pg_escape_string($value->data);
    if($value->element->type == "Name of Bank")
      $bank = pg_escape_string($value->data);
    if($value->element->type == "EIN/Tax ID #")
      $ein = pg_escape_string($value->data);
    if($value->element->type == "Organization Goals")
      $purpose = pg_escape_string($value->data);

  }

  if($org_type == 'greek'){ 
    $org_type = getGreekType($org->id);
  }

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
	$query = "INSERT INTO sdr_organization_instance (id, organization_id, term, name, type, address, bank, ein, shortname) values($instance_id, $sdr_org_id, $current_term, '$long_name', '$org_type', NULL, '$bank', '$ein', '$short_name')";
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
	$query = "INSERT INTO sdr_orgsync_id_map (orgsync_id, sdr_id) values($org_id, $sdr_org_id)";
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
  global $log_file, $log_handle, $current_term, $organization_cats;
  $log_str = '';
  $success = TRUE;
  
  // organization instance parameters
  $long_name = pg_escape_string($org->long_name);
  $short_name = pg_escape_string($org->short_name);
  $org_id = $org->id;

  if(!empty($organization_cats[$org->category->id]))
    $org_type = $organization_cats[$org->category->id];
  else
    $org_type = $organization_cats['default'];

  $addresss = NULL; // not setting this
  $bank = "";
  $ein = "";
  //organization profile parameters. Probably don't need any of this but can add it in if needed.
  $profile_responses = $org->profile_responses;
  $purpose = "";
  $club_logo = NULL;  // not setting this
  $meeting_location = NULL; //not setting this
  $meeting_date = ""; 
  $description = pg_escape_string($org->description);
  $description = "<p>".$description."</p>";
  $site_url = $org->website_url;
  $contact_info = NULL; // not setting this

  foreach($profile_responses as $value){
    if($value->element->type == "Meeting Location")
      $meeting_location = pg_escape_string($value->data);
    if($value->element->type == "Name of Bank")
      $bank = pg_escape_string($value->data);
    if($value->element->type == "EIN/Tax ID #")
      $ein = pg_escape_string($value->data);
    if($value->element->type == "Organization Goals")
      $purpose = pg_escape_string($value->data);

  }

  if($org_type == 'greek'){ 
    $org_type = getGreekType($org->id);
  }
  
  //Add new organization instance. First check if its already been added.  If so do not call nextval just update it.
  $query = "SELECT * FROM sdr_organization_instance WHERE organization_id='$sdr_id' AND term='$current_term'";
  $result = pg_query($query);
  if(pg_num_rows($result) == 0){
    $query = "SELECT NEXTVAL('sdr_organization_instance_seq')";
    $instance_result = pg_query($query);
    if($instance_result){
      $instance_result = pg_fetch_row($instance_result);
      $instance_id = $instance_result[0];
      $query = "INSERT INTO sdr_organization_instance (id, organization_id, term, name, type, address, bank, ein, shortname) values($instance_id, $sdr_id, $current_term, '$long_name', '$org_type', NULL, '$bank', '$ein', '$short_name')";
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

function createAccount($account){
  global $log_file, $log_handle, $current_term, $organization_cats;
  $log_str = '';
  $success = TRUE;  

  $account_vars = getAccountVars($account);
  $banner_id = $account_vars['banner_id'];
    $query = "INSERT INTO sdr_member (id, username, first_name, last_name) VALUES('".$account_vars['banner_id']."','".$account_vars['username']."','".$account_vars['first_name']."','".$account_vars['last_name']."')";
  if(!pg_query($query)){
    $log_str .= "Create Account Error: Unable to create member. query: $query";
    $success = FALSE;
  }
  $query = "INSERT INTO sdr_student (id, gender, ethnicity, citizen, transfer) VALUES('".$account_vars['banner_id']."','".$account_vars['gender']."','".$account_vars['ethnicity']."','".$account_vars['citizen']."','".$account_vars['transfer']."')";
  if(!pg_query($query)){
    $log_str .= "Create Account Error: Unable to create student. query: $query";
    $success = FALSE;
  }
  $query = "INSERT INTO sdr_student_registration (student_id, term, type, level, class, updated) VALUES('".$account_vars['banner_id']."','$current_term','".$account_vars['type']."','".$account_vars['level']."','".$account_vars['class']."','".$account_vars['updated']."')";
  if(!pg_query($query)){
    $log_str .= "Create Account Error: Unable to create student registrationi. query: $query";
    $success = FALSE;
  }
  if($success)
    $log_str .= "Successfully created account. Banner id: ".$account_vars['banner_id'].", orgsync id: $account->id";

  fwrite($log_handle, $log_str);
}

function updateAccount($account){
  global $log_file, $log_handle, $current_term, $organization_cats;
  $log_str = '';
  $success = TRUE;  

  $account_vars = getAccountVars($account);
  $banner_id = $account_vars['banner_id'];

  $query = "UPDATE sdr_member SET (username, first_name, last_name) = ('".$account_vars['username']."','".$account_vars['first_name']."','".$account_vars['last_name']."') WHERE id='$banner_id'";
  if(!pg_query($query)){
    $log_str .= "Update Account Error: Unable to update member. query: $query";
    $success = FALSE;
  }
  $query = "UPDATE sdr_student SET (gender, ethnicity, citizen, transfer) = ('".$account_vars['gender']."','".$account_vars['ethnicity']."','".$account_vars['citizen']."','".$account_vars['transfer']."') WHERE id='$banner_id'";
  if(!pg_query($query)){
    $log_str .= "Update Account Error: Unable to update student. query: $query";
    $success = FALSE;
  }
  $query = "SELECT * FROM sdr_student_registration WHERE student_id='$banner_id' AND term=$current_term";
  $reg_result = pg_query($query);
  if(pg_num_rows($reg_result) == 0){
    $query = "INSERT INTO sdr_student_registration (student_id, term, type, level, class, updated) VALUES('".$account_vars['banner_id']."','$current_term','".$account_vars['type']."','".$account_vars['level']."','".$account_vars['class']."','".$account_vars['updated']."')";
    if(!pg_query($query)){
      $log_str .= "Update Account Error: Unable to update student registration. query: $query";
      $success = FALSE;
    }
  }
  if($success)
    $log_str .= "Successfully updated account. Banner id: ".$account_vars['banner_id'].", orgsync id: $account->id";

  fwrite($log_handle, $log_str);
}

function getAccountVars($account){
  $account_vars = array();
  $gender = $ethnicity = $banner_id = $citizen = $transfer = $class = $type = $level = NULL;
  $parts = explode("@", $account->username);
  $account_vars['username'] = $parts[0];
  $account_vars['first_name'] = pg_escape_string($account->first_name);
  $account_vars['last_name'] = pg_escape_string($account->last_name);
  $account_vars['updated'] = time();
  $profile_responses = $account->profile_responses;

  foreach($profile_responses as $value){
    switch ($value->element->id) 
      {
      case GENDER_ELEMENT_ID:
	if(is_object($value->data)){
	  $gender = $value->data->name;
	  if($gender == "Male")
	    $gender = "M";
	  elseif($gender == "Female")
	    $gender = "F";
	  else
	    $gender = NULL;
	}else{
	  $gender = NULL;
	}
	$account_vars['gender'] = $gender;
	break;
      case ETHNICITY_ELEMENT_ID:
	$account_vars['ethnicity'] = '';
	if(is_object($value->data))
	  $account_vars['ethnicity'] = translateEthnicity($value->data->name);
	break;
      case BANNER_ELEMENT_ID:
	$account_vars['banner_id'] = $value->data;
	break;
      case INTERNATIONAL_STUDENT_ELEMENT_ID:
	$citizen = NULL;
	if(is_object($value->data)){
	  if($value->data->name == "Yes")
	    $citizen = "N";
	  else
	    $citizen = "Y";
	}
	$account_vars['citizen'] = $citizen;
	break;
      case TRANSFER_STUDENT_ELEMENT_ID:
	$transfer = 0;
	if(is_object($value->data)){
	  $transfer = $value->data->name;
	  if($transfer == "Yes"){
	    $transfer = 1;
	    $type = "T";
	  }else{
	    $transfer = 0;
	  }
	}
	$account_vars['transfer'] = $transfer;
	break;
      case CLASSIFICATION_ELEMENT_ID:
	$class = "";
	$type = "";
	$level = "G";
	if(is_object($value->data)){
	  if($value->data->name == "Freshmen"){
	    $class = "FR";
	    $type = "F";
	  }elseif($value->data->name == "Sophmore"){
	    $class = "SO";
	  }elseif($value->data->name == "Junior"){
	    $class = "JR";
	  }elseif($value->data->name == "Senior"){
	    $class = "SR";
	  }
	}
	  if(!empty($class))
	    $level = "U";
	  else
	    $level = "G";
	$account_vars['class'] = $class;
	$account_vars['type'] = $type;
	$account_vars['level'] = $level;
	break;
      default:
	break;
    }
  }
  return $account_vars;
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

function getAllAccounts(){
  global $key, $base_url;
  $curl = curl_init();
  //Request list of all accounts
  curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $base_url."accounts?key=$key"));
  $all_accounts = curl_exec($curl);
  if($all_accounts){
    $all_accounts = json_decode($all_accounts);
  }else{
    $all_accounts = FALSE;
  }

  return $all_accounts;
  curl_close($curl);
}


function getAccountByBannerID($banner_id){
  global $key, $base_url,$banner_profile_id;
  $curl = curl_init();

  curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $base_url."accounts/custom_profile/$banner_profile_id/$banner_id?key=$key"));
  $account_result = curl_exec($curl);

  if($account_result)
    $account_result = json_decode($account_result);
  else
    $account_result = FALSE;
  curl_close($curl);  
  return $account_result;

}

function getAccountByID($id){
  global $key, $base_url;
  $curl = curl_init();
  curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $base_url."accounts/$id?key=$key"));
    $account_result = curl_exec($curl);
    
  if($account_result)
    $account_result = json_decode($account_result);
  else
    $account_result = FALSE;
  
  curl_close($curl);  
  return $account_result;
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

function getGreekType($org_id){
    $members = getOrgMembers($org_id);
    $male = 0;
    $female = 0;
    $org_type = '';

    foreach($members as $member){
        $member_id = $member->id;
      $account = getAccountByID($member_id);
      if($account->profile_responses[3]->element->name != 'Gender'){
	foreach($account->profile_responses as $value){
	  if($value->element->name == 'Gender')
	    $gender = $value->data->name;
	}
      }else{
	if(is_object($account->profile_responses[3]->data))
	  $gender = $account->profile_responses[3]->data->name;
	else
	  $gender = '';
      }

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
  $log_file = 'initidmap.log';
  $log_handle = fopen($log_file, 'r+');
  $dbconn = DBConn("sdr");
  $orgs = getAllOrganizations();
  $total_orgs = count($orgs);
  $log_str = "";
  $count = 0;
  $dup_count = 0;
  foreach($orgs as $org){
    $short_name = pg_escape_string(strtolower($org->short_name));
    $long_name = pg_escape_string(strtolower($org->long_name));
    $org_id = $org->id;
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
	$log_str .= "Duplicate results found for $long_name. Orgsync id = $org_id"."\r\n";
	$dup_count++;
      }else{
	// Add it to the ID map table
	$query = "INSERT INTO sdr_orgsync_id_map (orgsync_id, sdr_id) VALUES($org_id, $prev_sdr_id)";
	
	if(pg_query($dbconn, $query))
	  $log_str .= "Successfully mapped $long_name. Orgsync id => SDR id: $org_id => $prev_sdr_id"."\r\n";
	else
	  $log_str .= "Insert failed for $long_name. query: $query"."\r\n";
	
      }
    }else{
      $log_str .= "No match for $long_name. Orgsync id = $org_id"."\r\n";
    }
  }
  $log_str .= "Total number of organizations from Orgsync: $total_orgs"."\r\n";
  $log_str .= "Total number of organizations with successful matches: $count of which $dup_count had duplicate results."; 
  
  fwrite($log_handle, $log_str);
  fclose($log_handle);
  pg_close($dbconn);
}

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
