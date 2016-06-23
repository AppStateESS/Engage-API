<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

include("/etc/ess/sdr_sync.conf");

$key = ORGSYNC_KEY;
$banner_base_url = BANNER_BASE_URL;
$orgsync_base_url = BASE_URL;
if(!isset($_SERVER["argv"][1])){
    echo "Usage: orientation_feed.php <portal-id> <term> "."\n";
    exit();
}else{
    $org_id = $argv[1];
    $term = $argv[2];
}

// check if group exists
$curl = curl_init();
curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $orgsync_base_url."orgs/$org_id?key=$key"));
$result = curl_exec($curl);
curl_close($curl);

if(count(get_object_vars(json_decode($result))) < 2){
    echo "Organization does not exist";
    exit();
}
// What terms do we need to check?
$new_students = getOrientationStudents($term);
$student_count = 0;
if($new_students){
    foreach($new_students as $student){
        $temp_id = getIDFromEmail($student->emailAddress);
        if($temp_id) // Make sure we have an id for the user. we will not worry about if a student does not have an account in orgsync. That will be the job for another script.
            $student_ids[] = $temp_id;
        if($student_count >= 200){
            $add_result = userToOrg($student_ids, $org_id);
            if(!$add_result){
                echo "There was a problem with the import group.".var_dump($student_ids)."\n";
            }else{
                echo "import success"."\n";
            }
            $student_count = 0;
            unset($student_ids);
        }
        $student_count++;
    }
}
else{
    echo "No freshmen were returned from the banner API."."\n";
}
/**
 * 
 * @param type $term the specified term
 * 
 */
function getOrientationStudents($term){
    global $banner_base_url;
    $import_url = $banner_base_url . "orientation/$term";
    $curl = curl_init();
    curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $import_url));
    $result = curl_exec($curl);
    curl_close($curl);
    $students = json_decode($result);
    if(empty($students)) 
        return FALSE;
    else
        return $students;    
}

/**
 * Get an orgsync user id from the users email address.
 *
 * @param string $email
 * @return int (id if success), boolean false if not
 */
function getIDFromEmail($email){
    global $key, $orgsync_base_url;    
    $curl = curl_init();
    curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $orgsync_base_url."accounts/email/$email?key=$key"));
    $result = curl_exec($curl);
    curl_close($curl);
  
    if($result){
        $result = json_decode($result);
        if(!empty($result->id))
            return $result->id;
    }
    
    return false;
    
}

/**
 * Place a user or users into an organization. User can be a single user id or and array of ids
 *
 * @param int $user_id (can be array of user id's), int $org_id (organizations id)
 * @return boolean (success or not)
 */
function userToOrg($user_id, $org_id) {
    global $key, $orgsync_base_url;
    $ids = NULL;
    $import_url = '';
    if (is_array($user_id)) {
        foreach ($user_id as $value) {
            if (!empty($ids))
                $ids .= ",$value";
            else
                $ids = $value;
        }
    }else {
        $ids = $user_id;
    }
    $import_url = $orgsync_base_url . "orgs/$org_id/accounts/add";
    $curl = curl_init();
    curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $import_url, CURLOPT_POST => 1, CURLOPT_POSTFIELDS => "ids=$ids&key=$key"));
    $result = curl_exec($curl);
    curl_close($curl);
    if ($result) {
        $result = json_decode($result);
        if (is_object($result) && $result->success == "true")
            return TRUE;
        else
            return FALSE;
    }else {
        return FALSE;
    }
}
