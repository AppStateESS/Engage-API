#!/usr/bin/php
<?php
  /**
   * Ramdon functions for using OrgSync API.  As of now the whole file is just a way 
   * to move users into different portals given an input csv file.  csv file must have
   * email address in the first column and portal id in the 4th for org imports.  Optionally you can 
   * have a secondary portal id in the 5th column. Or just rewrite the code to fit
   * the format of your csv file. For group imports specify the group id as the 3rd command line
   * argument. csv format for group import is email,firstname,lastname,banner_id.
   *
   * Eventually there will be more functions in here and I won't have just open code in here.
   * Also, all error echo's need to go to log instead output.
   *
   *@author - Ted Eberhard
   *
   *
   */

include ("/etc/ess/sdr_sync.conf");

$key = ORGSYNC_KEY;
$base_url = BASE_URL; 
$banner_base_url = BANNER_BASE_URL;
$org_id = 103391;

/*
 * example of deleting account
 */
//$email = "hayesvj@appstate.edu";
//$account_id = getIDFromEmail($email);
//$account = getAccountByID($account_id);
//deleteAccount($account_id);
//exit;
 

if(isset($argv) && !isset($argv[2])){
    echo "Usage: api_function.php [input file] [clear group or org] [group id(optional)]";
    exit;
}
    
$import = file($argv[1]);
$clear = $argv[2];  // if you want to remove all members to reset organization roster before import

if(isset($argv[3])){
    $group_id = $argv[3]; //474307(undergrad) 474308(grad) 474310(undergrad and grad) 474309(facutly and staff
    groupImportController($import, $group_id, $clear);
}else{
    orgImportController($import, $clear);
}

function groupImportController($import, $group_id, $clear_group) { 

$user_ids = array();
$missing_acct = 0;
$acct_count = 0;
$failed_accounts = array();

if($clear_group){
    echo "clearing members";
    $members = getGroupMembers($group_id);
    $ids = array();
    foreach($members as $value){
        $ids[] = $value->id;
    }
    if(!removeGroupAccount($ids, $group_id))
        echo "Group roster reset failed"."\n";
}

foreach($import as $value){
    $line = explode(',',$value);
    $acct_count++;
    $email = trim($line[2]);
    $user_id = getIDFromUsername($email);

    if(!$user_id){
        echo "could not find account: $email. Attempting to create account"."\r\n";
	$last_name = trim($line[0]);
	$first_name = trim($line[1]);
    $banner_id = trim($line[3]);
	if(empty($banner_id) || empty($last_name)){
        $student = getStudentFromBanner($email,$banner_id);
        $banner_id = $student->ID;
        $last_name = $student->lastName;
        $first_name = $student->firstName;
	}

        if(!addAccount($email, $first_name, $last_name, $banner_id)){
            echo "Add account failed."."\n";
            $failed_accounts[] = "$email,$first_name,$last_name,$banner_id";
        }
        $missing_acct++;
    }else{
        $user_ids[] = $user_id;
    }
    if($acct_count >= 200){
        echo "importing members"."\n";
        $import_result = userToGroup($user_ids, $group_id);
        $import_try = 0;
        while(!$import_result && $import_try < 10){
            $import_result = userToGroup($user_ids, $group_id);
            $import_try++;
        }
        if(!$import_result)
            echo "import failed for \n".var_dump($user_ids);
        else
            echo "Import sucess"."\n";
        $acct_count = 0;
        unset($user_ids);
    }

}

echo "importing members"."\n";
$import_result = userToGroup($user_ids, $group_id);
if(!$import_result)
    echo "import failed";
else
    echo "Import sucess";
echo "\n";
    
echo "number of missing accounts is $missing_acct"."\n";
echo "Failed account creation"."\n";
echo var_dump($failed_accounts);
}

function orgImportController($import, $clear_organization) { 
// This block of code outside of the functions is for use with command line.  It takes an input of a csv file and processes the entries and puts users into specified portals.  It will clear the roster first if $clear_organization is 1.  You will need to format the csv file correctly and make sure the column numbers are correct for the $line array calls.
$portal_id = NULL;
$user_ids = array();
$sec_portal_id = NULL;
$missing_acct = 0;
$acct_count = 0;

foreach($import as $value){
    $line = explode(',',$value);
    $acct_count++;
    if(!empty($org_id) && $org_id != str_replace(PHP_EOL,'',$line[4])){
        // should we reset roster before import?
        if($clear_organization){
            $members = getOrgMembers($org_id);
            $ids = array();
            foreach($members as $value){
                $ids[] = $value->id;
            }
            if(!removeAccount($ids, $org_id))
                echo "Organization roster reset failed";
        }    
        //call api to add accounts
        $import_result = userToOrg($user_ids, $org_id);
        if(!$import_result)
            echo "import failed";
        if(!empty($sec_org_id))
            $import_result = userToOrg($user_ids, $sec_org_id);
        unset($user_ids);
        $sec_org_id = NULL;
    }
    $email = $line[0];
    $user_id = getIDFromUsername($email);
    $org_id = $line[4];// column in csv file where org id is
    $org_id = str_replace(PHP_EOL, '', $org_id);
/**
    if(count($line) == 5)
        $sec_org_id = str_replace(PHP_EOL, '', $line[4]);
    else
        $sec_org_id = NULL;
*/
    if(!$user_id){
        echo "could not find account: $email. Attempting to create account"."\r\n";
        $temp_name = explode(" ",trim($line[2]));
        $first_name = $temp_name[0];
        $last_name = $line[3];
        $banner_id = $line[1];
        if(!addAccount($email, $first_name, $last_name, $banner_id))
            echo "Add account failed."."\n";
        $missing_acct++;
    }else{
        $user_ids[] = $user_id;
    }
    if($acct_count >= 200){
        if($clear_organization){
            echo "clearing members";
            $members = getOrgMembers($org_id);
            $ids = array();
            foreach($members as $value){
                $ids[] = $value->id;
            }
            if(!removeAccount($ids, $org_id))
                echo "Organization roster reset failed"."\n";
            $clear_organization = 0;
        }
        echo "importing members";
        $import_result = userToOrg($user_ids, $org_id);
        if(!$import_result)
            echo "import failed";
        else
            echo "Import sucess";
        $acct_count = 0;
        unset($user_ids);
    }
}

if($clear_organization){
    echo "clearing members";
    $members = getOrgMembers($org_id);
    $ids = array();
    foreach($members as $value){
        $ids[] = $value->id;
    }
    if(!removeAccount($ids, $org_id))
        echo "Organization roster reset failed"."\n";
}
echo "importing members";
$import_result = userToOrg($user_ids, $org_id);
if(!$import_result)
    echo "import failed";
else
    echo "Import sucess";
if(!empty($sec_org_id))
    $import_result = userToOrg($user_ids, $sec_org_id);

echo "number of missing accounts is $missing_acct"."\n";
}

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

function getGroupMembers($group_id) {
  global $key, $base_url;
  $curl = curl_init();
  //get organization members by organization id
  curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $base_url."groups/$group_id/accounts?key=$key"));
  $group_members = curl_exec($curl);
  if($group_members){
    $group_members = json_decode($group_members);
  }else{
    $group_members = FALSE;
  }
  curl_close($curl);
  return $group_members;
}

function getOrgMembers($org_id){
  global $key, $base_url;
  $curl = curl_init();
  //get organization members by organization id
  curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $base_url."orgs/$org_id/accounts?key=$key"));
  $org_members = curl_exec($curl);
  if($org_members){
    $org_members = json_decode($org_members);
  }else{
    $org_members = FALSE;
  }
  curl_close($curl);
  return $org_members;
}

/**
 * Remove an account or multiple accounts from an organization.  $ids can be one id or and array of ids.
 *
 *
 */
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
 *
 */
function addAccount($username, $first_name, $last_name, $student_id, $send_welcome=FALSE){
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
            return TRUE;
        }else{
            echo var_dump($result); //need to write this to log instead of echo
            return FALSE;
        }
    }
}

/**
 * Remove an account to OrgSync. 
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

function getIDFromUsername($username){
    global $key, $base_url;    

    $curl = curl_init();
    curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $base_url."accounts/username/$username?key=$key"));
    $result = curl_exec($curl);
    curl_close($curl);
  
    if($result){
        $result = json_decode($result);
        if(!empty($result->id))
            return $result->id;
    }
    
    return false;
    
}

function getIDFromEmail($email){
    global $key, $base_url;    

    $curl = curl_init();
    curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $base_url."accounts/email/$email?key=$key"));
    $result = curl_exec($curl);
    curl_close($curl);
  
    if($result){
        $result = json_decode($result);
        if(!empty($result->id))
            return $result->id;
    }
    
    return false;
    
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
?>
