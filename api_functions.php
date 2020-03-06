#!/usr/bin/php
<?php

/**
 * Place a user or users into an organization. User can be a single user id or and array of ids
 *
 * @param int $user_id (can be array of user id's), int $org_id (organizations id)
 * @return boolean (success or not)
 */
function userToOrg($user_id, $org_id){
    global $key, $base_url;
    $ids = NULL;
    $import_url = '';
    if(is_array($user_id)){
        foreach($user_id as $value){
            if(!empty($ids))
                $ids .= ",$value";
            else
                $ids = $value;
        }
    }else{
        $ids = $user_id;
    }
    $import_url = $base_url."orgs/$org_id/accounts/add";
    $curl = curl_init();
    curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $import_url, CURLOPT_POST => 1, CURLOPT_POSTFIELDS => "ids=$ids&key=$key"));

    $result = curl_exec($curl);
    curl_close($curl);
    echo var_dump($result); // need to put this result to log

    if($result){
        $result = json_decode($result);
        if(is_object($result) && $result->success == "true")
            return TRUE;
        else
            return FALSE;
    }else{
        return FALSE;
    }

}

/**
 * Place a user or users into a group. User can be a single user id or and array of ids
 *
 * @param int $user_id (can be array of user id's), int $group_id (groups id)
 * @return boolean (success or not)
 */
function userToGroup($user_id, $group_id){
    global $key, $base_url;
    $ids = NULL;
    $import_url = '';
    if(is_array($user_id)){
        foreach($user_id as $value){
            if(!empty($ids))
                $ids .= ",$value";
            else
                $ids = $value;
        }
    }else{
        $ids = $user_id;
    }
    $import_url = $base_url."groups/$group_id/accounts/add";
    echo $import_url;
    $curl = curl_init();
    curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $import_url, CURLOPT_POST => 1, CURLOPT_POSTFIELDS => "ids=$ids&key=$key"));

    $result = curl_exec($curl);
    curl_close($curl);
    echo var_dump($result); // need to put this result to log

    if($result){
        $result = json_decode($result);
        if(is_object($result) && $result->success == "true")
            return TRUE;
        else
            return FALSE;
    }else{
        return FALSE;
    }

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

function getOrgPositions($org_id){
    $endpoint = "Positions";
    $query_string = "organizationId=$org_id";
    $result = curlGet($endpoint, $query_string);

    if($result && !empty($result->items)){
        $total_pages = $result->totalPages;
        if($total_pages > 1){
            $positions = combinePages($endpoint, $query_string);
        } else {
            $positions = $result->items;
        }
    }

    return $positions;
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

/**
 * Remove an account or multiple accounts from an organization.  $ids can be one id or and array of ids.
 *
 *
 */

/** NEEDS TO BE REWRITTEN FOR NEW API **/
function removeAccount($user_ids, $org_id){
    global $key, $base_url;
    $url = $base_url."/orgs/$org_id/accounts/remove";
    $curl = curl_init();
    $count = 0;	 
    $ids = '';
    if(is_array($user_ids)){
        foreach($user_ids as $value){
            if(!empty($ids))
                $ids .= ',';
            $ids .= $value;
            $count++;
            if($count >=300){ // orgsync can't hadle large groups of remove so limit it to 300 per api call
                curl_setopt_array($curl, array(CURLOPT_TIMEOUT => 900, CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $url, CURLOPT_POST => 1, CURLOPT_POSTFIELDS => "ids=$ids&key=$key"));           
                $result = curl_exec($curl); // need to handle error checking here and log the event if these individual calls fail.
                $ids = '';
                $count = 0;
            }
        }
    }else{
    $ids = $user_ids;
    }

    curl_setopt_array($curl, array(CURLOPT_TIMEOUT => 900, CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $url, CURLOPT_POST => 1, CURLOPT_POSTFIELDS => "ids=$ids&key=$key"));           
    $result = curl_exec($curl); 
    curl_close($curl);
    echo var_dump($result); // need to put this in log
    if($result){
        $result = json_decode($result);
        if(is_object($result) && $result->success == "true")
            return TRUE;
        else
            return FALSE;
        
    }
}

/** NEEDS TO BE REWRITTEN FOR NEW **/
function removeGroupAccount($user_ids, $group_id){
    global $key, $base_url;
    $ids = '';
    $count = 0;
    $url = $base_url."/groups/$group_id/accounts/remove";
    $curl = curl_init();
// orgsync's server can't handle large add or removes.  We are going to send chunks of 300
    if(is_array($user_ids)){
        foreach($user_ids as $value){
            if(!empty($ids))
                $ids .= ',';
            $ids .= $value;
            $count++;
            if($count >=300){
                curl_setopt_array($curl, array(CURLOPT_TIMEOUT => 900, CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $url, CURLOPT_POST => 1, CURLOPT_POSTFIELDS => "ids=$ids&key=$key"));           
                $result = curl_exec($curl); // need to handle error checking here and log the event if these individual calls fail.
                $ids = '';
                $count = 0;
            }
        }
    }
    curl_setopt_array($curl, array(CURLOPT_TIMEOUT => 900, CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $url, CURLOPT_POST => 1, CURLOPT_POSTFIELDS => "ids=$ids&key=$key"));
    $result = curl_exec($curl); 
    curl_close($curl);
    echo var_dump($result); // need to put this in log
    if($result){
        $result = json_decode($result);
        if(is_object($result) && $result->success == "true")
            return TRUE;
        else
            return FALSE;
        
    }
}

/**
 * Add an account to OrgSync.  Remember that you must be setup for SSO and know the proper
 * username format for your university.  Usually is the email but it could be different.
 *
 * OLD API SCRIPT
 */
function addAccount($username, $first_name, $last_name, $student_id=NULL, $send_welcome=FALSE){
    global $key, $base_url;
    
    $json_data = array("username" => $username, "send_welcome" => $send_welcome, "account_attributes" => array("email_address" => $username, "first_name" => $first_name, "last_name" => $last_name),"identification_card_numbers" => array($student_id));
//    $json_data = array("username" => $username, "send_welcome" => true, "account_attributes" => array("email_address" => $username, "first_name" => $first_name, "last_name" => $last_name));
    $json_data = json_encode($json_data);
    $url = $base_url."/accounts?key=$key";
    $curl = curl_init();
    curl_setopt_array($curl, array(CURLOPT_TIMEOUT => 900, CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $url, CURLOPT_POST => 1, CURLOPT_POSTFIELDS => $json_data));           
    $result = curl_exec($curl); 
    curl_close($curl);
    if($result){
        $result = json_decode($result);
        if(!empty($result->id)){
            return $result->id;
        }else{
            echo var_dump($result); //need to write this to log instead of echo
            return FALSE;
        }
    }
}

/**
 * Remove an account to OrgSync. OLD API
 *
 *
 */
function deleteAccount($account_id){
    global $key, $base_url;
    
    $json_data = array("account_id" => $account_id);
    $json_data = json_encode($json_data);
    $url = $base_url."/accounts/$account_id?key=$key";
    $curl = curl_init();
    curl_setopt_array($curl, array(CURLOPT_CUSTOMREQUEST => "DELETE", CURLOPT_TIMEOUT => 900, CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $url));           
    $result = curl_exec($curl); 
    curl_close($curl);
    if($result){
        $result = json_decode($result);
        if($result->$success){
            return TRUE;
        }else{
            echo var_dump($result); //need to write this to log instead of echo
            return FALSE;
        }
    }
}

function getIDFromBanner($banner_id){
    $endpoint = "Users/";
    $query_string = "username=".urlencode($banner_id);
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

function getUserByCardID($banner_id){
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

/**
 * Get banner id from orgsync by email address
 * 
 * @param type $email
 * @return boolean
 */
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

/**
 * This retrieves a student from banner by banner id
 * @global type $banner_base_url
 * @param type $email
 * @param type $banner_id
 * @return student object
 */
function getStudentFromBanner($email, $banner_id){
    global $banner_base_url;
    if(empty($banner_id)){
        $email = explode('@',$email);
        $user_id = $email[0];
    }else{
        $user_id = $banner_id;
    }
    $curl = curl_init();
    curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $banner_base_url."student/$user_id"));
    $result = curl_exec($curl);
    curl_close($curl);
    $student = json_decode($result);
    return $student;
}

/**
 * This retrieves all students from banner
 * @global type $banner_base_url
 * @return student list
 */
function getAllStudentsFromBanner(){
    global $banner_base_url;
    $curl = curl_init();
    curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $banner_base_url."student"));
    $result = curl_exec($curl);
    curl_close($curl);
    $students = json_decode($result);
    return $students;
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

//////////////////////// OLD API FUNCTIONS //////////////////////////
// old api. needs to be rewritten
function getAccountFromEmail($email){
    global $key, $base_url;    

    $curl = curl_init();
    curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $base_url."accounts/email/$email?key=$key"));
    $result = curl_exec($curl);
    curl_close($curl);
  
    if($result){
        $result = json_decode($result);
        return $result;
    }
    
    return false;
    
}

/** OLD API **/
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

/** OLD API **/
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

?>
