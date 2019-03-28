#!/usr/bin/php
<?php
/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * This should be moved to a UI but with the api in flux we will leave it here. As of now the whole file is just a way 
 * to move users into different portals given an input csv file.  csv file must have
 * email address in the first column and portal id in the 4th for org imports.  Optionally you can 
 * have a secondary portal id in the 5th column. Or just rewrite the code to fit
 * the format of your csv file. For group imports specify the group id as the 3rd command line
 * argument. csv format for group import is lastname,firstname,email,banner_id,org_id,group_id.
 * Last 2 are optional for org import controller only. You can just provide a group id with the command line for group only import.
 *
 * @author - Ted Eberhard
 *
 *
 */
include ("/etc/ess/sdr_sync.conf");
include ("api_functions.php");

$key = ORGSYNC_KEY;
$base_url = BASE_URL;
$banner_base_url = BANNER_BASE_URL;

/**
 * clear org
  $org_id = 90180;
  $members = getOrgMembers($org_id);
  $ids = array();
  foreach($members as $value){
  $ids[] = $value->id;
  }
  if(!removeAccount($ids, $org_id))
  echo "Organization roster reset failed";
  exit;
 */
/*
 * example of deleting account
 */
//$email = "hayesvj@appstate.edu";
//$account_id = getIDFromEmail($email);
//$account = getAccountByID($account_id);
//deleteAccount($account_id);
//exit;


if (isset($argv) && !isset($argv[2])) {
    echo "Usage: api_function.php [input file] [clear group or org] [group id(optional)]";
    exit;
}

$import = file($argv[1]);
$clear = $argv[2];  // if you want to remove all members to reset organization roster before import
// set true if you want group members to be removed from the portal without clearing the entire portal.
// This will only work if the org import includes groups in the import. 
$org_import_clear_group = false;

if (isset($argv[3])) {
    $group_id = $argv[3]; //474307(undergrad) 474308(grad) 474310(undergrad and grad) 474309(facutly and staff
    groupImportController($import, $group_id, $clear);
} else {
    orgImportController($import, $clear, $org_import_clear_group);
}

/**
 * Controls importing users into groups. Used if your just importing a csv file into 1 group that you specify on the command line.
 *** This function may be obsolete with the most recent changes to the orgImportController ***
 * @param array $import (the input file in and array), int group_id (id of the group to import), boolean $clear_group (do we want to clear the group first. Users will still be in the portal)
 * 
 */

function groupImportController($import, $group_id, $clear_group) {

    $user_ids = array();
    $missing_acct = 0;
    $acct_count = 0;
    $failed_accounts = array();

    if ($clear_group) {
        echo "clearing members";
        $members = getGroupMembers($group_id);
        $ids = array();
        foreach ($members as $value) {
            $ids[] = $value->id;
        }
        if (!removeGroupAccount($ids, $group_id))
            echo "Group roster reset failed" . "\n";
        // Clear them from the portal not just the group
        //if (!removeAccount($ids, 172480))
        //echo "Group roster reset failed" . "\n";
    }

    foreach ($import as $value) {
        $line = explode(',', $value);
        $acct_count++;
        $email = trim($line[2]);
        if(isset($line[3])){
            $banner_id = trim($line[3]);
            }else{
                $banner_id = '';
            }
        if (empty($email)) {
            $student = getStudentFromBanner($email, $banner_id);
            $email = $student->emailAddress;
            $last_name = $student->lastName;
            $first_name = $student->firstName;
        }

        $user_id = getIDFromUsername($email);

        if (!$user_id) {
            echo "could not find account: $email. Attempting to create account" . "\r\n";
            $last_name = trim($line[0]);
            $first_name = trim($line[1]);
            $banner_id = trim($line[3]);
            if (empty($banner_id) || empty($last_name)) {
                $student = getStudentFromBanner($email, $banner_id);
                $banner_id = $student->ID;
                $last_name = $student->lastName;
                $first_name = $student->firstName;
            }
            $add_result = addAccount($email, $first_name, $last_name, $banner_id);
            if (!$add_result) {
                echo "Add account failed." . "\n";
                $failed_accounts[] = "$email,$first_name,$last_name,$banner_id";
            } else {
                $user_ids[] = $add_result;
            }
            $missing_acct++;
        } else {
            $user_ids[] = $user_id;
        }
        if ($acct_count >= 200) {
            echo "importing members" . "\n";
            $import_result = userToGroup($user_ids, $group_id);
            $import_try = 0;
            while (!$import_result && $import_try < 10) {
                $import_result = userToGroup($user_ids, $group_id);
                $import_try++;
            }
            if (!$import_result)
                echo "import failed for \n" . var_dump($user_ids);
            else
                echo "Import sucess" . "\n";
            $acct_count = 0;
            unset($user_ids);
        }
    }

    echo "importing members" . "\n";
    $import_result = userToGroup($user_ids, $group_id);
    if (!$import_result)
        echo "import failed";
    else
        echo "Import sucess";
    echo "\n";
    echo "account count = $acct_count";
    echo "number of missing accounts is $missing_acct" . "\n";
//echo "Failed account creation"."\n";
    echo var_dump($failed_accounts);
}

/**
 * Controls importing users into orgs/groups. Can handle multiple orgs and groups in one file
 *
 * @param array $import (the import input file in an array), boolean $clear_organization (to clear the organizations before importing)
 * 
 */

function orgImportController($import, $clear_organization, $clear_group = false) {
    $portal_id = NULL;
    $org_id = NULL;
    $import_id = NULL;
    $next_import_id = NULL;
    $user_ids = array();
    $sec_portal_id = NULL;
    $missing_acct = 0;
    $acct_count = 0;
    $group_import = FALSE;
    $clear_org = $clear_organization;
    $clear_org_group = $clear_group;
    
    foreach ($import as $value) {
        $line = explode(',', $value);
        $acct_count++;
        // If the file has group ids and not org ids we treat it as a group import file
        if (!empty($line[5]) && !$group_import) {
            $group_import = TRUE;
        }
        $next_import_id = $line[4];
        if ($group_import) {
            $next_import_id = $line[5];
        }
        $next_import_id = str_replace(PHP_EOL, '', $next_import_id);

        if (!empty($import_id) && $import_id != $next_import_id) {
            //call api to add accounts
            if ($group_import) {
                $import_result = userToGroup($user_ids, $import_id);
            } else {
                $import_result = userToOrg($user_ids, $import_id);
            }
            if (!$import_result)
                echo "import failed";
            unset($user_ids);
            $clear_org_group = $clear_group;
            
            if ($org_id != str_replace(PHP_EOL, '', $line[4])) {
                $clear_org = $clear_organization;
            }
        }
        $email = trim($line[2]);
        $banner_id = trim($line[3]);

        if (empty($email)) {
            $student = getStudentFromBanner($email, $banner_id);
            $email = $student->emailAddress;
            $last_name = $student->lastName;
            $first_name = $student->firstName;
        }
        
        $user_id = getIDFromUsername($email);
        $org_id = $line[4]; // column in csv file where org id is
        $import_id = $next_import_id;

        if (!empty($org_id) && ($clear_org || $clear_org_group)) {
            if($clear_org){
                echo "clearing org members";
                $members = getOrgMembers($org_id);
            }elseif($clear_org_group && !empty($import_id)){
                echo "clearing group members";
                $members = getGroupMembers($import_id);
            }
                
            $ids = array();
            foreach ($members as $value) {
                $ids[] = $value->id;
            }
            if (!removeAccount($ids, $org_id))
                echo "Organization roster reset failed" . "\n";
            $clear_org = 0;
            $clear_org_group = 0;
        }

        if (!$user_id) {
            echo "could not find account: $email. Attempting to create account" . "\r\n";
            $first_name = trim($line[1]);
            $last_name = trim($line[0]);

            if (empty($banner_id) || empty($last_name)) {
                $student = getStudentFromBanner($email, $banner_id);
                $banner_id = $student->ID;
                $last_name = $student->lastName;
                $first_name = $student->firstName;
            }
            $add_result = addAccount($email, $first_name, $last_name, $banner_id);
            if (!$add_result) {
                echo "Add account failed." . "\n";
            } else {
                $user_ids[] = $add_result;
            }
            $missing_acct++;
        } else {
            $user_ids[] = $user_id;
        }
        if ($acct_count >= 200) {
            echo "importing members";
            if ($group_import) {
                $import_result = userToGroup($user_ids, $import_id);
            } else {
                $import_result = userToOrg($user_ids, $import_id);
            }
            if (!$import_result)
                echo "import failed";
            else
                echo "Import sucess";
            $acct_count = 0;
            unset($user_ids);
        }
    }

    if ($clear_org) {
        echo "clearing members";
        $members = getOrgMembers($org_id);
        $ids = array();
        foreach ($members as $value) {
            $ids[] = $value->id;
        }
        if (!removeAccount($ids, $org_id))
            echo "Organization roster reset failed" . "\n";
    }
    echo "importing members";
    if ($group_import) {
        $import_result = userToGroup($user_ids, $import_id);
    } else {
        $import_result = userToOrg($user_ids, $import_id);
    }
    if (!$import_result)
        echo "import failed";
    else
        echo "Import sucess";
    echo "number of missing accounts is $missing_acct" . "\n";
}
