<?php
  /**
   * Ramdon functions for using OrgSync API.  As of now the whole file is just a way 
   * to move users into different portals given an input csv file.  csv file must have
   * email address in the first column and portal id in the 4th.  Optionally you can 
   * have a secondary portal id in the 5th column. Or just rewrite the code to fit
   * the format of your csv file.  
   *
   *@author - Ted Eberhard
   *
   *
   */

include ("sdr_sync.conf");

$key = ORGSYNC_KEY;
$base_url = BASE_URL; 

if(!isset($_SERVER["argv"][1])){
    echo "You need to specify a data file";
    exit;
}
    
$import = file($argv[1]);
$portal_id = NULL;
$user_ids = array();
$sec_portal_id = NULL;

foreach($import as $value){
    $line = explode(',',$value);

    if(!empty($org_id) && $org_id != str_replace(PHP_EOL,'',$line[3])){
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
    $org_id = $line[3];
    $org_id = str_replace(PHP_EOL, '', $org_id);
    if(count($line) == 5)
        $sec_org_id = str_replace(PHP_EOL, '', $line[4]);
    else
        $sec_org_id = NULL;

    if(!$user_id){
        echo "could not find account: $email"."\r\n";
    }else{
        $user_ids[] = $user_id;
    }
    
}

$import_result = userToOrg($user_ids, $org_id);
if(!$import_result)
    echo "import failed";
else
    echo "Import sucess";
if(!empty($sec_org_id))
    $import_result = userToOrg($user_ids, $sec_org_id);

exit;

/**
 * Place a user or users into an organization.
 */
function userToOrg($user_id, $org_id){
    echo " in function ";
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

    if($result){
        $result = json_decode($result);
        if($result->success == "true")
            return TRUE;
        else
            return FALSE;
    }else{
        return FALSE;
    }

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
?>